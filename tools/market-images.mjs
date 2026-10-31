/**
 * Render the marketplace images (cover, square, icon, gallery) from market/rtl-theme/src/images.html.
 *   NODE_PATH=<global node_modules> node tools/market-images.mjs [outDir]
 * Uses the real plugin screenshots in marketing/assets/shots and the client-supplied Bale logo.
 */
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const out = process.argv[2] || 'market/rtl-theme/images';
mkdirSync(out, { recursive: true });
const icons = JSON.parse(readFileSync('src/shared/icons.ts', 'utf8').match(/= (\{[\s\S]*?\});\n/)[1]);
const BALE_LOGO = 'data:image/png;base64,' + readFileSync('marketing/assets/bale-logo.png').toString('base64');

const G = [1600, 1000];
const slides = [
  ['cover', 1600, 900, 'cover-1600x900'],
  ['square', 1080, 1080, 'square-1080'],
  ['icon', 512, 512, 'icon-512'],
  ['g1-site', ...G, 'gallery-01-site'],
  ['g2-bale', ...G, 'gallery-02-bale'],
  ['g3-reply', ...G, 'gallery-03-reply'],
  ['g4-inbox', ...G, 'gallery-04-inbox'],
  ['g5-setup', ...G, 'gallery-05-setup'],
  ['g6-look', ...G, 'gallery-06-appearance'],
  ['g7-reports', ...G, 'gallery-07-reports'],
  ['g8-features', ...G, 'gallery-08-features'],
];
const browser = await chromium.launch();
for (const [id, w, h, name] of slides) {
  const page = await browser.newPage({ viewport: { width: w, height: h } });
  await page.addInitScript((d) => Object.assign(window, d), { SLIDE: { id, w, h }, ICONS: icons, BALE_LOGO });
  await page.goto('file://' + resolve('market/rtl-theme/src/images.html'));
  await page.waitForLoadState('load');
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(200);
  if (id === 'icon') await page.screenshot({ path: `${out}/${name}.png`, omitBackground: true });
  else await page.screenshot({ path: `${out}/${name}.jpg`, type: 'jpeg', quality: 90 });
  await page.close();
}
await browser.close();
console.log('rendered', slides.length, 'images →', out);
