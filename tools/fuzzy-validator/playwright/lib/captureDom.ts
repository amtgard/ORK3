/**
 * Fuzzy Validator — raw DOM HTML capture (FU-8).
 */
import fs from 'fs';
import path from 'path';
import type { Page } from '@playwright/test';

export async function captureDomHtml(page: Page, outPath: string): Promise<void> {
  const html = await page.content();
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, html, 'utf-8');
}
