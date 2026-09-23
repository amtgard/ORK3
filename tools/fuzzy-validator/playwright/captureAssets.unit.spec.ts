import { test, expect } from '@playwright/test';
import {
  assetManifestFileName,
  canonicalizeAssetUrl,
  serializeAssetManifest,
  sha256Hex,
  slugFromUrl,
  type AssetManifest,
} from './lib/captureAssets';

test.describe('captureAssets helpers', () => {
  test('canonicalizeAssetUrl resolves relative paths and strips fragments', () => {
    const pageUrl = 'http://localhost:19080/orkui/index.php?Route=';
    expect(canonicalizeAssetUrl('./template/revised.css#v1', pageUrl)).toBe(
      'http://localhost:19080/orkui/template/revised.css',
    );
    expect(
      canonicalizeAssetUrl('http://localhost:19080/orkui/app.js?cache=1#hash', pageUrl),
    ).toBe('http://localhost:19080/orkui/app.js?cache=1');
  });

  test('sha256Hex matches known digest', () => {
    expect(sha256Hex('hello')).toBe(
      '2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824',
    );
  });

  test('slugFromUrl sanitizes path basename', () => {
    expect(slugFromUrl('http://example.com/path/revised.css')).toBe('revised.css');
    expect(slugFromUrl('http://example.com/weird%20name.js')).toBe('weird-20name.js');
  });

  test('serializeAssetManifest emits stable JSON with trailing newline', () => {
    const manifest: AssetManifest = {
      schemaVersion: 1,
      pageId: 'home-anonymous',
      capturedAt: '2026-07-07T20:00:00Z',
      runLabel: 'run-001',
      assets: [
        {
          id: 'css-000',
          kind: 'css',
          url: 'http://localhost:19080/orkui/revised.css',
          inline: false,
          sha256: sha256Hex('body{}'),
          byteLength: 6,
        },
        {
          id: 'inline-style-0',
          kind: 'css',
          url: null,
          inline: true,
          sha256: sha256Hex('.x{color:red}'),
          byteLength: 14,
        },
      ],
    };

    const serialized = serializeAssetManifest(manifest);
    expect(serialized.endsWith('\n')).toBe(true);
    const parsed = JSON.parse(serialized) as AssetManifest;
    expect(parsed.pageId).toBe('home-anonymous');
    expect(parsed.assets).toHaveLength(2);
    expect(parsed.assets[0].sha256).toBe(sha256Hex('body{}'));
  });

  test('assetManifestFileName maps candidate and calibration runs', () => {
    expect(assetManifestFileName('run-003')).toBe('run-003.assets.json');
    expect(assetManifestFileName('candidate')).toBe('candidate.assets.json');
  });
});
