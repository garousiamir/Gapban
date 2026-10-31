/**
 * Copies built assets from the HTML template into the WordPress theme, trimming
 * vendor trees down to what actually ships.
 *
 * Run after `tailwindcss` + `build/tokenize.mjs`. Usage: node build/assets.mjs
 */
import { copyFileSync, mkdirSync, readFileSync, writeFileSync, statSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';

const THEME = 'wordpress/sevi';
const kb = bytes => (bytes / 1024).toFixed(1) + ' KB';

const copied = [];
function copy(from, to) {
  mkdirSync(dirname(to), { recursive: true });
  copyFileSync(from, to);
  copied.push([to, statSync(to).size]);
}

/*
 * The stylesheet is NOT copied from the preview build. src/theme.css compiles
 * straight into the theme, scanning PHP instead of HTML, so the two stylesheets
 * hold different utility sets from one shared component layer.
 */

/* -------------------------------------------------------------------- script */
/*
 * Alpine ships minified with no licence banner, and the npm package carries no
 * LICENSE file either -- only "license": "MIT" in its manifest. A reviewer
 * opening the bundled file has nothing to go on, so the attribution is written
 * onto the copy that ships. The version is read rather than typed.
 */
const alpineVersion = JSON.parse(
  readFileSync('node_modules/alpinejs/package.json', 'utf8')
).version;

const alpineBanner = `/*! Alpine.js v${alpineVersion} | MIT License | (c) Caleb Porzio and contributors | https://alpinejs.dev */
`;
const alpineSrc = readFileSync('assets/js/alpine.min.js', 'utf8');
const alpineOut = `${THEME}/assets/js/alpine.min.js`;
const alpineFile = alpineSrc.startsWith('/*!') ? alpineSrc : alpineBanner + alpineSrc;

mkdirSync(dirname(alpineOut), { recursive: true });
writeFileSync(alpineOut, alpineFile);
copied.push([alpineOut, Buffer.byteLength(alpineFile)]);

/* ---------------------------------------------------------------------- font */
/* Only the WOFF2 ships. The TTF stays in the repo as the conversion source. */
copy('assets/fonts/Estedad-VariableFont_wght.woff2', `${THEME}/assets/fonts/Estedad-VariableFont_wght.woff2`);

/* ------------------------------------------------------------- Font Awesome */
/*
 * The vendored package is 24 MB, almost all of it source material we never ship:
 * svgs/, metadata/, js/, sprites/, less/, scss/.
 *
 * We keep the FULL icon set rather than subsetting to the 214 icons this template
 * happens to use. Subsetting would shrink the fonts to roughly 50 KB, but it would
 * also break the icon picker for buyers -- somebody who builds a page with an icon
 * we did not anticipate would get an empty box. For a theme sold to other people,
 * a working icon picker is worth the bytes.
 *
 * Only the WOFF2 files ship; the TTF fallbacks are stripped from the @font-face
 * rules so nothing references a file that is not in the package.
 */
const FA_SRC = 'assets/vendor/fontawesome-free';
const FA_DST = `${THEME}/assets/vendor/fontawesome`;

const faCss = readFileSync(join(FA_SRC, 'css/all.min.css'), 'utf8');
const trimmedCss = faCss.replace(/,url\([^)]*\.ttf\) format\("truetype"\)/g, '');

mkdirSync(join(FA_DST, 'css'), { recursive: true });
writeFileSync(join(FA_DST, 'css/all.min.css'), trimmedCss);
copied.push([join(FA_DST, 'css/all.min.css'), Buffer.byteLength(trimmedCss)]);

if (/\.ttf/.test(trimmedCss)) {
  console.error('  FAIL  Font Awesome CSS still references a .ttf that will not ship');
  process.exit(1);
}

/* Ship exactly the webfonts the trimmed CSS asks for, and nothing else. */
const referenced = [...new Set([...trimmedCss.matchAll(/([\w-]+\.woff2)/g)].map(m => m[1]))].sort();
for (const file of referenced) {
  const from = join(FA_SRC, 'webfonts', file);
  if (!existsSync(from)) {
    console.error(`  FAIL  Font Awesome CSS references a missing webfont: ${file}`);
    process.exit(1);
  }
  copy(from, join(FA_DST, 'webfonts', file));
}

/* Envato requires a licence file for every bundled third-party asset. */
copy(join(FA_SRC, 'LICENSE.txt'), join(FA_DST, 'LICENSE.txt'));

/* ------------------------------------------------------------------- report */
let total = 0;
for (const [path, size] of copied) {
  total += size;
  console.log(`  ${path.padEnd(58)} ${kb(size).padStart(10)}`);
}
console.log(`  ${''.padEnd(58, '-')} ${''.padStart(10, '-')}`);
console.log(`  ${'theme assets total'.padEnd(58)} ${kb(total).padStart(10)}`);
console.log(`  webfonts shipped: ${referenced.join(', ')}`);
