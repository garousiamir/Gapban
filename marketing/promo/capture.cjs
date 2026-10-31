/**
 * Captures REAL screenshots of the running Gapban plugin for the promo video:
 * the production widget on a Persian demo page (refrence-html template) talking to
 * the real WordPress REST backend, and the real admin screens.
 *   NODE_PATH=... node marketing/promo/capture.cjs <outDir>
 */
const { chromium } = require('playwright');
const out = process.argv[2] || 'marketing/assets/shots';
const BASE = process.env.BASE || 'http://localhost:8899';
require('fs').mkdirSync(out, { recursive: true });

(async () => {
  const browser = await chromium.launch();
  const shot = (page, name) => page.screenshot({ path: `${out}/${name}.png` });

  // ---------- visitor, desktop ----------
  const v = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const page = await v.newPage();
  const W = page.locator('#gapban-widget');
  await page.goto(`${BASE}/demo/index-1.html`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2500);
  await shot(page, 'web-01-launcher');
  await W.locator('button.gb-launcher').click();
  await page.waitForTimeout(1200);
  await shot(page, 'web-02-open');
  await W.locator('.gb-btn.gb-block').first().click();
  await page.waitForTimeout(500);
  await W.locator('input[name=name]').fill('سارا');
  await shot(page, 'web-03-prechat');
  await W.locator('form button[type=submit]').click();
  await W.locator('textarea.gb-textarea').waitFor({ state: 'visible' });
  await page.waitForTimeout(800);
  await W.locator('textarea.gb-textarea').fill('سلام! سفارش ۱۲۸۴ کِی ارسال می‌شه؟');
  await page.waitForTimeout(300);
  await shot(page, 'web-04-typing');
  await W.locator('button.gb-send').click();
  await W.locator('.gb-msg[data-author=visitor]').first().waitFor();
  await page.waitForTimeout(1500);
  await shot(page, 'web-05-sent');

  // ---------- operator replies from the real WordPress inbox ----------
  const a = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
  const ap = await a.newPage();
  await ap.goto(`${BASE}/wp-login.php`);
  await ap.fill('#user_login', 'admin');
  await ap.fill('#user_pass', 'admin');
  await ap.click('#wp-submit');
  const hideChrome = () => ap.addStyleTag({ content: '#adminmenumain,#wpadminbar,#wpfooter,.screen-reader-shortcut,#wp-auth-check-wrap{display:none!important}#wpcontent{margin:0!important;padding:0 24px!important}html.wp-toolbar{padding-top:0!important}body{background:#f7f7f8!important}' });
  await ap.goto(`${BASE}/wp-admin/admin.php?page=gapban`);
  await hideChrome();
  await ap.locator('.gb-conv-item').first().click();
  await ap.locator('.gb-row-visitor').first().waitFor();
  await ap.locator('textarea.gb-reply').fill('سلام سارا جان 🌸 سفارش ۱۲۸۴ امروز ساعت ۱۶ تحویل پست می‌شه و کد رهگیری براتون پیامک می‌شه.');
  await ap.locator('.gb-composer .gb-btn-primary').click();
  await ap.locator('.gb-row-operator').first().waitFor();
  await ap.waitForTimeout(1500);
  await ap.screenshot({ path: `${out}/admin-inbox.png` });

  // Visitor sees the reply.
  await W.locator('.gb-msg[data-author=operator]').first().waitFor({ timeout: 20000 });
  await page.waitForTimeout(1200);
  await shot(page, 'web-06-reply');

  for (const [slug, name] of [['gapban-setup', 'admin-setup'], ['gapban-appearance', 'admin-appearance'], ['gapban-reports', 'admin-reports']]) {
    await ap.goto(`${BASE}/wp-admin/admin.php?page=${slug}`);
    await hideChrome();
    await ap.waitForTimeout(2000);
    await ap.screenshot({ path: `${out}/${name}.png` });
  }

  // ---------- visitor, mobile ----------
  const m = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
  const mp = await m.newPage();
  // Reuse the same visitor session so the mobile view shows the real conversation.
  const token = await page.evaluate(() => localStorage.getItem('gapban:token'));
  await mp.goto(`${BASE}/demo/index-1.html`);
  await mp.evaluate((t) => localStorage.setItem('gapban:token', t), token);
  await mp.goto(`${BASE}/demo/index-1.html`, { waitUntil: 'networkidle' });
  await mp.waitForTimeout(2000);
  await mp.screenshot({ path: `${out}/mobile-01-launcher.png` });
  await mp.locator('#gapban-widget button.gb-launcher').click();
  await mp.locator('#gapban-widget .gb-msg[data-author=operator]').first().waitFor({ timeout: 15000 });
  await mp.waitForTimeout(1200);
  await mp.screenshot({ path: `${out}/mobile-02-reply.png` });

  await browser.close();
  console.log('captured →', out);
})();
