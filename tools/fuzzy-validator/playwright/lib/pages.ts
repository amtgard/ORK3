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

const PAGE_ID_PATTERN = /^[a-z0-9-]+$/;
const VALID_AUTH = new Set(['none', 'login']);

export function loadPagesRegistry(manifestPath = registryPath()): PagesRegistry {
  const raw = fs.readFileSync(manifestPath, 'utf8');
  const registry = JSON.parse(raw) as PagesRegistry;
  const errors = validatePagesRegistry(registry);
  if (errors.length > 0) {
    throw new Error(`invalid pages registry:\n${errors.map((err) => `  - ${err}`).join('\n')}`);
  }
  return registry;
}

export function validatePagesRegistry(registry: PagesRegistry): string[] {
  const errors: string[] = [];
  const seenIds = new Set<string>();

  if (!registry.defaults?.viewport?.width || !registry.defaults?.viewport?.height) {
    errors.push('defaults.viewport requires width and height');
  }
  if (registry.defaults?.repeat === undefined) {
    errors.push('defaults.repeat is required');
  }

  for (const page of registry.pages) {
    if (!PAGE_ID_PATTERN.test(page.id)) {
      errors.push(`invalid id "${page.id}"`);
    }
    if (seenIds.has(page.id)) {
      errors.push(`duplicate id "${page.id}"`);
    }
    seenIds.add(page.id);

    if (!page.url) {
      errors.push(`page "${page.id}" missing url`);
    }

    const auth = page.auth ?? registry.defaults.auth;
    if (!VALID_AUTH.has(auth)) {
      errors.push(`page "${page.id}" has invalid auth "${auth}"`);
    }
  }

  return errors;
}

export function activePageIds(registry: PagesRegistry): string[] {
  return registry.pages.filter((page) => !page.skip).map((page) => page.id);
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
