/**
 * Deterministic frame renderer for the promo video.
 *   node marketing/promo/render.mjs <outDir> [--times=1,9.8,...] [--from=0 --to=40]
 * Each frame calls window.render(t) and takes a screenshot, so output does not depend on machine speed.
 */
import { createRequire } from 'node:module';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const out = process.argv[2] || 'frames';
const arg = (k) => process.argv.find((a) => a.startsWith(`--${k}=`))?.split('=')[1];
mkdirSync(out, { recursive: true });
const scenePath = arg('scene') || 'marketing/promo/scene.html';
const timeline = JSON.parse(readFileSync(arg('timeline') || 'marketing/promo/timeline.json', 'utf8'));
const iconsSrc = readFileSync('src/shared/icons.ts', 'utf8');
const icons = JSON.parse(iconsSrc.match(/= (\{[\s\S]*?\});\n/)[1]);
// Extra Font Awesome Free icons (CC BY 4.0) from the reference's bundled package.
for (const [name, file] of [['arrow-left', 'solid/arrow-left'], ['reply', 'solid/reply']]) {
  const svg = readFileSync(`refrence-html/assets/vendor/fontawesome-free/svgs/${file}.svg`, 'utf8');
  icons[name] = [svg.match(/viewBox="([^"]+)"/)[1], svg.match(/<path d="([^"]+)"/)[1]];
}
// Bale logo only if the user supplied the official file; otherwise the scene shows a text badge.
const logoFile = 'marketing/assets/bale-logo.png';
const baleLogo = existsSync(logoFile) ? 'data:image/png;base64,' + readFileSync(logoFile).toString('base64') : null;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: timeline.width, height: timeline.height }, deviceScaleFactor: 1 });
await page.addInitScript(({ timeline, icons, baleLogo }) => {
  window.TIMELINE = timeline;
  window.ICONS = icons;
  window.BALE_LOGO = baleLogo;
}, { timeline, icons, baleLogo });
await page.goto('file://' + resolve(scenePath));
await page.waitForLoadState('load');
await page.evaluate(() => document.fonts.ready);
await page.waitForTimeout(300);

const times = arg('times')
  ? arg('times').split(',').map(Number)
  : (() => {
      const from = Number(arg('from') || 0), to = Number(arg('to') || timeline.duration);
      const list = [];
      for (let f = Math.round(from * timeline.fps); f < Math.round(to * timeline.fps); f++) list.push(f / timeline.fps);
      return list;
    })();

for (const t of times) {
  await page.evaluate((t) => window.render(t), t);
  const name = arg('times') ? `t${t.toFixed(2)}.jpg` : `f${String(Math.round(t * timeline.fps)).padStart(5, '0')}.jpg`;
  await page.screenshot({ path: `${out}/${name}`, type: 'jpeg', quality: 93 });
}
await browser.close();
console.log(`rendered ${times.length} frames → ${out}`);
