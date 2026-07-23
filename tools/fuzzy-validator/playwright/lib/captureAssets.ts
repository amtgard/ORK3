/**
 * Fuzzy Validator — CSS/JS asset capture (FU-6).
 * Collects network stylesheets/scripts and inline style/script bodies per run.
 */
import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import type { Page, Response } from '@playwright/test';

export type AssetKind = 'css' | 'js';

export interface AssetRecord {
  id: string;
  kind: AssetKind;
  url: string | null;
  inline: boolean;
  sha256: string;
  byteLength: number;
}

export interface AssetManifest {
  schemaVersion: 1;
  pageId: string;
  capturedAt: string;
  runLabel: string;
  assets: AssetRecord[];
}

const CSS_MIME = new Set(['text/css']);
const JS_MIME = new Set([
  'application/javascript',
  'text/javascript',
  'application/x-javascript',
]);

export function canonicalizeAssetUrl(rawUrl: string, pageUrl: string): string {
  const resolved = new URL(rawUrl, pageUrl);
  resolved.hash = '';
  return resolved.href;
}

export function sha256Hex(data: Buffer | string): string {
  return crypto.createHash('sha256').update(data).digest('hex');
}

export function slugFromUrl(url: string): string {
  const pathname = new URL(url).pathname;
  const base = path.basename(pathname) || 'asset';
  return base.replace(/[^a-zA-Z0-9._-]+/g, '-');
}

export function serializeAssetManifest(manifest: AssetManifest): string {
  return `${JSON.stringify(manifest, null, 2)}\n`;
}

export function assetManifestFileName(runLabel: string): string {
  return runLabel === 'candidate' ? 'candidate.assets.json' : `${runLabel}.assets.json`;
}

class AssetCollector {
  private cssCount = 0;

  private jsCount = 0;

  private seenUrls = new Set<string>();

  private records: AssetRecord[] = [];

  private bytesById = new Map<string, Buffer>();

  addNetworkAsset(kind: AssetKind, url: string, body: Buffer): void {
    if (this.seenUrls.has(url)) {
      return;
    }
    this.seenUrls.add(url);
    const id =
      kind === 'css'
        ? `css-${String(this.cssCount++).padStart(3, '0')}`
        : `js-${String(this.jsCount++).padStart(3, '0')}`;
    const record: AssetRecord = {
      id,
      kind,
      url,
      inline: false,
      sha256: sha256Hex(body),
      byteLength: body.length,
    };
    this.bytesById.set(id, body);
    this.records.push(record);
  }

  addInlineAsset(kind: AssetKind, id: string, body: Buffer): void {
    const record: AssetRecord = {
      id,
      kind,
      url: null,
      inline: true,
      sha256: sha256Hex(body),
      byteLength: body.length,
    };
    this.bytesById.set(id, body);
    this.records.push(record);
  }

  getRecords(): AssetRecord[] {
    return this.records;
  }

  getBytes(id: string): Buffer | undefined {
    return this.bytesById.get(id);
  }
}

export class AssetCaptureSession {
  private collector = new AssetCollector();

  private handler: (response: Response) => void;

  constructor(
    private page: Page,
    private pageUrl: string,
  ) {
    this.handler = (response) => {
      void this.onResponse(response);
    };
    page.on('response', this.handler);
  }

  private async onResponse(response: Response): Promise<void> {
    try {
      const contentType =
        response.headers()['content-type']?.split(';')[0]?.trim().toLowerCase() ?? '';
      const isCss = CSS_MIME.has(contentType);
      const isJs = JS_MIME.has(contentType);
      if (!isCss && !isJs) {
        return;
      }
      if (!response.ok()) {
        return;
      }
      const canonical = canonicalizeAssetUrl(response.url(), this.pageUrl);
      const body = Buffer.from(await response.body());
      this.collector.addNetworkAsset(isCss ? 'css' : 'js', canonical, body);
    } catch {
      // Ignore unreadable or aborted responses.
    }
  }

  async finish(options: {
    pageId: string;
    runLabel: string;
    outDir: string;
  }): Promise<AssetManifest> {
    this.page.off('response', this.handler);

    const inline = await this.page.evaluate(() => {
      const styles: string[] = [];
      document.querySelectorAll('style').forEach((element) => {
        const text = element.textContent ?? '';
        if (text.trim()) {
          styles.push(text);
        }
      });
      const scripts: string[] = [];
      document.querySelectorAll('script:not([src])').forEach((element) => {
        const text = element.textContent ?? '';
        if (text.trim()) {
          scripts.push(text);
        }
      });
      return { styles, scripts };
    });

    inline.styles.forEach((text, index) => {
      this.collector.addInlineAsset('css', `inline-style-${index}`, Buffer.from(text, 'utf-8'));
    });
    inline.scripts.forEach((text, index) => {
      this.collector.addInlineAsset('js', `inline-script-${index}`, Buffer.from(text, 'utf-8'));
    });

    const manifest: AssetManifest = {
      schemaVersion: 1,
      pageId: options.pageId,
      capturedAt: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
      runLabel: options.runLabel,
      assets: this.collector.getRecords(),
    };

    writeAssetArtifacts(options.outDir, options.runLabel, manifest, this.collector);
    return manifest;
  }
}

export function writeAssetArtifacts(
  outDir: string,
  runLabel: string,
  manifest: AssetManifest,
  collector: AssetCollector,
): void {
  const assetsRunDir = path.join(outDir, 'assets', runLabel);
  fs.mkdirSync(assetsRunDir, { recursive: true });

  for (const record of manifest.assets) {
    const bytes = collector.getBytes(record.id);
    if (!bytes) {
      continue;
    }
    const ext = record.kind === 'css' ? '.css' : '.js';
    const fileBase = record.inline
      ? record.id
      : `${record.id}-${slugFromUrl(record.url ?? record.id)}`;
    fs.writeFileSync(path.join(assetsRunDir, `${fileBase}${ext}`), bytes);
  }

  fs.writeFileSync(
    path.join(outDir, assetManifestFileName(runLabel)),
    serializeAssetManifest(manifest),
  );
}

export function startAssetCapture(page: Page, pageUrl: string): AssetCaptureSession {
  return new AssetCaptureSession(page, pageUrl);
}
