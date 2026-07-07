import { test, expect } from '@playwright/test';
import { loadPagesRegistry, resolveRequestedPages } from './lib/pages';

test.describe('pages registry', () => {
  test('contains pilot page ids', () => {
    const registry = loadPagesRegistry();
    const ids = registry.pages.map((page) => page.id);
    expect(ids).toContain('home-anonymous');
    expect(ids).toContain('home-authenticated');
    expect(ids).toContain('player-profile');
  });

  test('resolveRequestedPages returns a single entry', () => {
    const registry = loadPagesRegistry();
    const pages = resolveRequestedPages(registry, ['home-anonymous']);
    expect(pages).toHaveLength(1);
    expect(pages[0].id).toBe('home-anonymous');
    expect(pages[0].auth).toBe('none');
  });

  test('defaults include five calibration runs', () => {
    const registry = loadPagesRegistry();
    expect(registry.defaults.repeat).toBe(5);
  });
});
