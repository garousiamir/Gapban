/**
 * Build the buyer download package for the Rtl-Theme (راست‌چین) marketplace.
 *
 *   node tools/package-rtl-theme.mjs            # rebuilds release/gapban.zip first
 *   node tools/package-rtl-theme.mjs --no-build # reuse the existing release/gapban.zip
 *
 * Output: release/gapban-rtl-theme-<version>.zip
 *   gapban.zip              installable plugin (upload in WordPress)
 *   documentation/          offline Persian guide (open index.html)
 *   readme-first.txt        Persian quick pointer
 *   changelog.txt           Persian changelog
 *   licenses/               GPL + third-party licences
 *   source/                 human-readable source of the minified bundles (GPL)
 */
import { execFileSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { marked } from 'marked';

const run = (cmd, args, opts = {}) => execFileSync(cmd, args, { stdio: 'inherit', ...opts });
const version = JSON.parse(readFileSync('package.json', 'utf8')).version;
if (!process.argv.includes('--no-build')) run('node', ['tools/package.mjs']);
if (!existsSync('release/gapban.zip')) throw new Error('release/gapban.zip missing');

const tmp = 'build-rtl';
const root = `${tmp}/gapban-${version}`;
rmSync(tmp, { recursive: true, force: true });
mkdirSync(`${root}/documentation/images`, { recursive: true });
mkdirSync(`${root}/documentation/fonts`, { recursive: true });

// ---------- documentation ----------
// Screenshots of the real plugin, re-encoded small for an offline guide.
const shots = {
  'site-chat': 'marketing/assets/shots/web-05-sent.png',
  'site-reply': 'marketing/assets/shots/panel-06-reply.png',
  'mobile-reply': 'marketing/assets/shots/mobile-02-reply.png',
  'admin-setup': 'marketing/assets/shots/admin-setup.png',
  'admin-inbox': 'marketing/assets/shots/admin-inbox.png',
  'admin-appearance': 'marketing/assets/shots/admin-appearance.png',
  'admin-reports': 'marketing/assets/shots/admin-reports.png',
};
run('python3', ['-c', `
import sys, json
from PIL import Image
for name, src in json.loads(sys.argv[1]).items():
    im = Image.open(src).convert('RGB')
    im.thumbnail((1440, 1440))
    im.save(sys.argv[2] + '/' + name + '.jpg', quality=82, optimize=True)
`, JSON.stringify(shots), `${root}/documentation/images`]);
cpSync('gapban/assets/fonts/Estedad-VariableFont_wght.woff2', `${root}/documentation/fonts/Estedad.woff2`);

const fig = (img, cap) => `<figure><img src="images/${img}.jpg" alt="${cap}" loading="lazy"><figcaption>${cap}</figcaption></figure>`;
const quick = `
# شروع سریع (۱۰ دقیقه)

گپ‌بان یک ابزارک چت روی سایت شما می‌گذارد. پیام بازدیدکننده در **گروه پشتیبانی شما در بله** ظاهر می‌شود و کافی است روی همان پیام **ریپلای** کنید تا پاسخ روی سایت برسد. همهٔ گفتگوها در پایگاه دادهٔ سایت خودتان ذخیره می‌شوند و از صندوق گفتگوهای وردپرس هم قابل پاسخ‌اند.

1. فایل \`gapban.zip\` را از «افزونه‌ها ← افزودن ← بارگذاری افزونه» نصب و فعال کنید.
2. به «گپ‌بان ← راه‌اندازی و اتصال» بروید.
3. در بله از \`@BotFather\` یک ربات بسازید و توکن آن را در گام «ربات بله» وارد کنید.
4. ربات را عضو گروه پشتیبانی کنید و کد اتصال را با فرمان \`/gapban_bind\` در گروه بفرستید.
5. «ارسال پیام آزمایشی» را بزنید و در بله روی آن ریپلای کنید.
6. کرون سرور را طبق بخش «کرون» تنظیم کنید تا صف ارسال همیشه پردازش شود.

FIG:admin-setup:صفحهٔ راه‌اندازی و اتصال

FIG:site-chat:ابزارک چت روی سایت

FIG:site-reply:پاسخ اپراتور روی سایت

FIG:mobile-reply:نمای موبایل

FIG:admin-inbox:صندوق گفتگوهای وردپرس
`;
const extra = `
# ظاهر و گزارش‌ها

در «گپ‌بان ← ظاهر ابزارک» رنگ، جایگاه، فاصله از لبه‌ها، متن‌ها و رفتار دکمهٔ گفتگو را با پیش‌نمایش زنده تنظیم کنید. در «گزارش‌ها» تعداد گفتگو، زمان پاسخ و رضایت مشتری را ببینید.

FIG:admin-appearance:تنظیم ظاهر ابزارک

FIG:admin-reports:گزارش‌ها و رضایت مشتری
`;
const support = `
# پشتیبانی

- پشتیبانی فقط از طریق **بخش پشتیبانی/تیکت محصول در راست‌چین** انجام می‌شود.
- پیش از ارسال تیکت، بخش «رفع اشکال» و صفحهٔ «گپ‌بان ← صف ارسال و عیب‌یابی ← بررسی سیستم» را ببینید و نتیجهٔ آن را در تیکت بفرستید.
- **هرگز توکن ربات بله یا رمز پیشخوان را در تیکت ننویسید.** اگر توکنی به اشتباه منتشر شد، از \`@BotFather\` آن را باطل و توکن تازه بگیرید.
- به‌روزرسانی‌ها از همان صفحهٔ دانلود محصول در راست‌چین در دسترس است (راهنمای ارتقا را ببینید).
`;
const sections = [
  ['quick', quick],
  ['install', readFileSync('docs/fa/installation.md', 'utf8')],
  ['bale', readFileSync('docs/fa/bale-bot.md', 'utf8')],
  ['operators', readFileSync('docs/fa/operators.md', 'utf8')],
  ['cron', readFileSync('docs/fa/cron.md', 'utf8')],
  ['look', extra],
  ['troubleshooting', readFileSync('docs/fa/troubleshooting.md', 'utf8')],
  ['privacy', readFileSync('docs/fa/privacy.md', 'utf8')],
  ['upgrade', readFileSync('docs/fa/upgrade.md', 'utf8')],
  ['support', support],
];
const toc = [];
const body = sections
  .map(([id, md]) => {
    const title = md.match(/^#\s+(.+)$/m)[1].trim();
    toc.push(`<a href="#${id}">${title}</a>`);
    // Persian-numbered steps (۱. ۲.) are plain text to Markdown; turn them into real lists.
    const html = marked.parse(md.replace(/^#\s+.+$/m, '').replace(/^[۰-۹]+\. /gm, '1. ').trim()).replace(/<p>FIG:([a-z-]+):(.+?)<\/p>/g, (_, i, c) => fig(i, c));
    return `<section id="${id}"><h1>${title}</h1>${html}</section>`;
  })
  .join('\n');

writeFileSync(
  `${root}/documentation/index.html`,
  `<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>راهنمای گپ‌بان ${version}</title>
<style>
@font-face { font-family: Estedad; src: url(fonts/Estedad.woff2) format('woff2'); font-weight: 100 900; }
:root { --red: #d33c3c; --teal: #0a9d79; --ink: #1b2433; --muted: #5b6775; --line: #e6e9ef; --bg: #f7f8fb; }
* { box-sizing: border-box; }
body { margin: 0; font-family: Estedad, Tahoma, sans-serif; color: var(--ink); background: var(--bg); line-height: 2; font-size: 16px; }
header { background: linear-gradient(135deg, #ef4b4b, #b52f2f); color: #fff; padding: 36px 24px; }
header h1 { margin: 0; font-size: 34px; } header p { margin: 4px 0 0; opacity: .9; }
.wrap { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 32px; max-width: 1180px; margin: 0 auto; padding: 32px 16px; }
nav { position: sticky; top: 16px; align-self: start; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 14px; }
nav a { display: block; padding: 6px 12px; border-radius: 10px; color: var(--ink); text-decoration: none; font-weight: 600; }
nav a:hover { background: #fdeaea; color: var(--red); }
section { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 8px 32px 24px; margin-bottom: 24px; }
section h1 { font-size: 26px; color: var(--red); } h2 { font-size: 20px; margin-top: 28px; }
code { font-family: ui-monospace, Consolas, monospace; background: #f1f3f7; padding: 1px 6px; border-radius: 6px; direction: ltr; unicode-bidi: embed; font-size: 14px; }
ol { list-style: persian; } ul, ol { padding-right: 24px; padding-left: 0; }
pre { background: #0f1630; color: #e8ecf8; padding: 16px; border-radius: 12px; overflow-x: auto; direction: ltr; text-align: left; }
pre code { background: none; color: inherit; padding: 0; }
table { width: 100%; border-collapse: collapse; font-size: 15px; display: block; overflow-x: auto; }
th, td { border: 1px solid var(--line); padding: 8px 12px; text-align: right; vertical-align: top; }
th { background: #f4f6fa; }
blockquote { margin: 16px 0; padding: 8px 16px; border-right: 4px solid #f5a524; background: #fff8e8; border-radius: 8px; }
figure { margin: 20px 0; } figure img { width: 100%; border-radius: 12px; border: 1px solid var(--line); box-shadow: 0 8px 24px rgba(0,0,0,.06); }
figcaption { text-align: center; color: var(--muted); font-size: 14px; }
@media (max-width: 800px) { .wrap { grid-template-columns: 1fr; } nav { position: static; } section { padding: 4px 18px 16px; } }
</style>
</head>
<body>
<header><h1>راهنمای گپ‌بان</h1><p>افزونهٔ چت آنلاین وردپرس با پاسخ‌گویی از داخل بله · نسخهٔ ${version}</p></header>
<div class="wrap"><nav>${toc.join('')}</nav><main>${body}</main></div>
</body>
</html>
`,
);

// ---------- package files ----------
cpSync('release/gapban.zip', `${root}/gapban.zip`);
cpSync('gapban/licenses', `${root}/licenses`, { recursive: true });
cpSync('gapban/license.txt', `${root}/licenses/GPL-2.0-or-later.txt`);
mkdirSync(`${root}/source`, { recursive: true });
for (const p of ['src', 'tools', 'package.json', 'package-lock.json', 'tsconfig.json']) cpSync(p, `${root}/source/${p}`, { recursive: true });
writeFileSync(
  `${root}/readme-first.txt`,
  `گپ‌بان ${version} — افزونهٔ چت آنلاین وردپرس با پاسخ‌گویی از داخل بله

۱. نصب: فایل gapban.zip را در «افزونه‌ها ← افزودن ← بارگذاری افزونه» بارگذاری و فعال کنید.
   (این بستهٔ کامل را مستقیم نصب نکنید؛ فقط gapban.zip.)
۲. راهنما: documentation/index.html را در مرورگر باز کنید (بدون اینترنت هم کار می‌کند).
۳. پیش‌نیاز: وردپرس ۶٫۵+، PHP ۸٫۱+، دسترسی خروجی سرور به tapi.bale.ai، و برای وب‌هوک HTTPS.

پوشهٔ source/ کد منبع خوانای فایل‌های فشرده‌شدهٔ جاوااسکریپت است (برای توسعه‌دهندگان؛ برای نصب لازم نیست).
مجوز: GPL-2.0-or-later. مجوز فونت و آیکن‌ها در پوشهٔ licenses/ است.
`,
);
writeFileSync(
  `${root}/changelog.txt`,
  `تغییرات گپ‌بان

نسخهٔ ${version}
- نخستین انتشار.
- ابزارک چت فارسی و راست‌به‌چپ (پشتیبانی کامل از انگلیسی و چپ‌به‌راست).
- پاسخ‌گویی از داخل گروه بله با ریپلای روی پیام گفتگو.
- صندوق گفتگوهای وردپرس، اپراتورها و بخش‌ها، واگذاری خودکار.
- صف ارسال مطمئن با تلاش دوباره و نمایش صادقانهٔ وضعیت تحویل.
- قوانین نمایش و پیام خودکار، گزارش‌ها و نظرسنجی رضایت، ابزارهای حریم خصوصی.
`,
);

const out = `release/gapban-rtl-theme-${version}.zip`;
rmSync(out, { force: true });
run('zip', ['-qrX', `../${out}`, `gapban-${version}`], { cwd: tmp });
rmSync(tmp, { recursive: true, force: true });
console.log(out, statSync(out).size, 'bytes');
