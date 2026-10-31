/**
 * Build the installable plugin ZIP and the distribution package.
 *
 *   node tools/package.mjs
 *
 * Outputs:
 *   release/gapban.zip               — single top-level "gapban/" directory, ready to upload
 *   release/gapban-distribution.zip  — gapban.zip + Persian docs + changelog + licenses + source
 */
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readdirSync, rmSync, statSync, writeFileSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const run = (cmd, args, opts = {}) => execFileSync(cmd, args, { stdio: 'inherit', ...opts });

run('node', ['tools/build.mjs']);
run('python3', ['tools/l10n/build_po.py']);
run('node', ['tools/i18n.mjs']);

const tmp = 'build-tmp';
rmSync(tmp, { recursive: true, force: true });
mkdirSync(`${tmp}/plugin`, { recursive: true });

// 1. Plugin: copy gapban/ minus development leftovers.
cpSync('gapban', `${tmp}/plugin/gapban`, {
  recursive: true,
  dereference: true,
  filter: (src) => !/(\.map$|\.DS_Store$|\/\.git|node_modules)/.test(src),
});
// Sanity checks: compiled assets and translations must be present; no secrets.
for (const f of ['assets/dist/widget-loader.js', 'assets/dist/widget-chat.js', 'assets/dist/admin.js', 'assets/dist/admin.css', 'assets/dist/manifest.json', 'languages/gapban-fa_IR.mo', 'languages/gapban.pot', 'assets/fonts/Estedad-VariableFont_wght.woff2', 'license.txt', 'readme.txt']) {
  if (!existsSync(`${tmp}/plugin/gapban/${f}`)) throw new Error('missing ' + f);
}
const walk = (d) => readdirSync(d).flatMap((f) => (statSync(join(d, f)).isDirectory() ? walk(join(d, f)) : [join(d, f)]));
for (const f of walk(`${tmp}/plugin/gapban`)) {
  const s = readFileSync(f).toString('latin1');
  if (/\b\d{6,12}:[A-Za-z0-9_-]{30,}\b/.test(s) && !f.endsWith('.js')) throw new Error('possible bot token in ' + f);
}
mkdirSync('release', { recursive: true });
rmSync('release/gapban.zip', { force: true });
run('zip', ['-qrX', '../../release/gapban.zip', 'gapban'], { cwd: `${tmp}/plugin` });

// 2. Distribution package.
const dist = `${tmp}/dist/gapban-${JSON.parse(readFileSync('package.json')).version}`;
mkdirSync(dist, { recursive: true });
cpSync('release/gapban.zip', `${dist}/gapban.zip`);
cpSync('docs', `${dist}/docs`, { recursive: true });
cpSync('CHANGELOG.md', `${dist}/CHANGELOG.md`);
cpSync('gapban/licenses', `${dist}/licenses`, { recursive: true });
cpSync('gapban/license.txt', `${dist}/licenses/GPL-2.0-or-later.txt`);
cpSync('docs/settings-sample.json', `${dist}/settings-sample.json`);
mkdirSync(`${dist}/source`, { recursive: true });
for (const p of ['src', 'tools', 'tests', 'package.json', 'package-lock.json', 'tsconfig.json', 'README.md']) cpSync(p, `${dist}/source/${p}`, { recursive: true });
cpSync('gapban', `${dist}/source/gapban`, { recursive: true, dereference: true, filter: (s) => !s.includes('/assets/dist') });
writeFileSync(
  `${dist}/README-FIRST.txt`,
  'گپ‌بان — بستهٔ توزیع\n\n' +
    'gapban.zip  → همین فایل را در وردپرس (افزونه‌ها ← افزودن ← بارگذاری) نصب کنید.\n' +
    'docs/fa/    → راهنمای فارسی نصب، ربات بله، اپراتورها، کرون، رفع اشکال، حریم خصوصی و ارتقا.\n' +
    'docs/       → مستندات فنی (طرح پایگاه داده، REST، مسیریابی، امنیت، گزارش آزمون، محدودیت‌ها).\n' +
    'source/     → کد منبع کامل برای ساخت دوباره: npm ci && node tools/package.mjs\n',
);
rmSync('release/gapban-distribution.zip', { force: true });
run('zip', ['-qrX', '../../release/gapban-distribution.zip', '.'], { cwd: `${tmp}/dist` });
rmSync(tmp, { recursive: true, force: true });
for (const f of ['release/gapban.zip', 'release/gapban-distribution.zip']) console.log(f, statSync(f).size, 'bytes');
