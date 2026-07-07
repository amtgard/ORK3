import fs from 'fs';
import path from 'path';

export interface PageEntry {
  id: string;
  url: string;
  auth?: 'none' | 'login';
  viewport?: { width: number; height: number };
  repeat?: number;
  waitAfterMs?: number;
  readySelector?: string;
  skip?: boolean;
  notes?: string;
}

export interface PagesRegistry {
  defaults: {
    viewport: { width: number; height: number };
    repeat: number;
    waitAfterMs: number;
    auth: 'none' | 'login';
  };
  pages: PageEntry[];
}

export function registryPath(): string {
  return path.join(__dirname, '..', '..', 'manifests', 'pages.json5');
}

export function loadPagesRegistry(manifestPath = registryPath()): PagesRegistry {
  const raw = fs.readFileSync(manifestPath, 'utf8');
  return JSON.parse(raw) as PagesRegistry;
}

export function resolveRequestedPages(registry: PagesRegistry, requestedIds: string[]): PageEntry[] {
  if (requestedIds.length === 0) {
    return registry.pages.filter((page) => !page.skip);
  }

  const byId = new Map(registry.pages.map((page) => [page.id, page]));
  return requestedIds.map((id) => {
    const page = byId.get(id);
    if (!page) {
      throw new Error(`Unknown page id: ${id}`);
    }
    return page;
  });
}

export function parsePageIdList(raw: string | undefined): string[] {
  if (!raw) {
    return [];
  }
  return raw.split(',').map((id) => id.trim()).filter(Boolean);
}
