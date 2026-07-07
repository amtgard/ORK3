import { test, expect } from '@playwright/test';
import { activePageIds, loadPagesRegistry, resolveRequestedPages, validatePagesRegistry } from './lib/pages';

test.describe('pages registry', () => {
  test('contains pilot page ids', () => {
    const registry = loadPagesRegistry();
    const ids = registry.pages.map((page) => page.id);
    expect(ids).toContain('home-anonymous');
    expect(ids).toContain('home-authenticated');
    expect(ids).toContain('player-profile');
  });

  test('has at least twenty registry entries', () => {
    const registry = loadPagesRegistry();
    expect(registry.pages.length).toBeGreaterThanOrEqual(20);
  });

  test('activePageIds excludes skipped entries', () => {
    const registry = loadPagesRegistry();
    const active = activePageIds(registry);
    expect(active).not.toContain('health-endpoint');
    expect(active.length).toBeGreaterThanOrEqual(20);
  });

  test('validatePagesRegistry returns no errors for committed registry', () => {
    const registry = loadPagesRegistry();
    expect(validatePagesRegistry(registry)).toEqual([]);
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
