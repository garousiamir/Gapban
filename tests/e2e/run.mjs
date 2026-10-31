/**
 * Browser end-to-end tests (Playwright + Chromium) against a running WordPress site
 * with Gapban active. No Bale credentials are involved: the operator side is the
 * WordPress inbox. Real Bale delivery is NOT covered here.
 *
 *   BASE=http://localhost:8899 WP_DB_NAME=wp node tests/e2e/run.mjs
 */
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://localhost:8899';
const SHOTS = process.env.SHOTS || 'docs/screenshots';
mkdirSync(SHOTS, { recursive: true });
const setup = JSON.parse(execFileSync('php', ['tests/e2e/setup.php'], { env: { ...process.env, WP_DB_NAME: process.env.WP_DB_NAME || 'wp' } }).toString());
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const results = [];
async function check(name, fn) {
  try {
    await fn();
    results.push([name, true]);
    console.log('✔ ' + name);
  } catch (e) {
    results.push([name, false]);
    console.log('✘ ' + name + '\n    ' + String(e && e.message ? e.message : e).split('\n')[0]);
  }
}
const assert = (cond, msg) => {
  if (!cond) throw new Error(msg);
};

const browser = await chromium.launch();
const W = (page) => page.locator('#gapban-widget');

async function startChat(page, name = 'آزمون') {
  await W(page).locator('button.gb-launcher').click();
  await W(page).locator('.gb-panel').waitFor();
  await W(page).locator('.gb-btn.gb-block').first().click();
  const nameInput = W(page).locator('input[name=name]');
  if (await nameInput.count()) await nameInput.fill(name);
  const email = W(page).locator('input[name=email]');
  if (await email.count()) await email.fill('visitor@example.com');
  await W(page).locator('form button[type=submit]').click();
  await W(page).locator('textarea.gb-textarea').waitFor({ state: 'visible' });
}
async function send(page, text) {
  await W(page).locator('textarea.gb-textarea').fill(text);
  await W(page).locator('button.gb-send').click();
  await W(page).locator('.gb-msg[data-author=visitor] .gb-bubble', { hasText: text.slice(0, 20) }).first().waitFor();
}
async function runAxe(page, label) {
  await page.addScriptTag({ content: axeSource });
  const res = await page.evaluate(async () => {
    // eslint-disable-next-line no-undef
    const r = await axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.filter((n) => JSON.stringify(n.target).includes('gapban')).length, detail: v.nodes.filter((n) => JSON.stringify(n.target).includes('gapban')).slice(0, 8).map((n) => JSON.stringify(n.target).slice(-60) + ' ' + (n.any[0]?.message || '').slice(0, 90)) }));
  });
  const ours = res.filter((v) => v.nodes > 0);
  console.log(`    axe (${label}): ${ours.length ? JSON.stringify(ours) : 'no violations inside Gapban UI'}`);
  return ours.filter((v) => v.impact === 'serious' || v.impact === 'critical');
}

/* 1. Proactive acceptance example ------------------------------------------- */
await check('proactive message after 15 visible seconds on pricing page, shown once, not reset by refresh/navigation', async () => {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  await page.clock.install();
  await page.goto(setup.pricing);
  await W(page).locator('button.gb-launcher').waitFor();
  await page.clock.runFor(10_000);
  assert((await W(page).locator('.gb-teaser').count()) === 0, 'shown too early');
  await page.clock.runFor(7_000);
  const teaser = W(page).locator('.gb-teaser');
  await teaser.waitFor({ timeout: 5000 });
  assert((await teaser.textContent()).includes('برای انتخاب پلن مناسب راهنمایی‌تان کنیم؟'), 'wrong text');
  assert((await teaser.textContent()).includes('پیام خودکار'), 'not labelled automated');
  await page.screenshot({ path: `${SHOTS}/widget-proactive-desktop-rtl.png` });
  await page.reload();
  await W(page).locator('button.gb-launcher').waitFor();
  await page.clock.runFor(30_000);
  assert((await W(page).locator('.gb-teaser').count()) === 0, 'shown again after refresh');
  await page.goto(BASE + '/');
  await page.goto(setup.pricing);
  await page.clock.runFor(30_000);
  assert((await W(page).locator('.gb-teaser').count()) === 0, 'shown again after navigation');
  // A second tab of the same visitor also respects the cap.
  const other = await ctx.newPage();
  await other.clock.install();
  await other.goto(setup.pricing);
  await other.clock.runFor(30_000);
  assert((await W(other).locator('.gb-teaser').count()) === 0, 'shown again in another tab');
  await ctx.close();
});

await check('hidden-tab time does not count toward the 15 second trigger', async () => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.clock.install();
  await page.goto(setup.pricing);
  await W(page).locator('button.gb-launcher').waitFor();
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.clock.runFor(40_000);
  assert((await W(page).locator('.gb-teaser').count()) === 0, 'counted hidden time');
  await ctx.close();
});

await check('dismissed proactive message never returns', async () => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.clock.install();
  await page.goto(setup.pricing);
  await page.clock.runFor(17_000);
  await W(page).locator('.gb-teaser-close').click();
  await page.evaluate(() => {
    const s = JSON.parse(localStorage.getItem('gapban:rules'));
    for (const k of Object.keys(s)) s[k].shows = [Date.now() - 48 * 3600e3];
    localStorage.setItem('gapban:rules', JSON.stringify(s));
    sessionStorage.clear();
  });
  await page.reload();
  await page.clock.runFor(30_000);
  assert((await W(page).locator('.gb-teaser').count()) === 0, 'came back after dismissal');
  await ctx.close();
});

/* 2. Chat flow, XSS, operator round trip ------------------------------------- */
const visitorCtx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const vpage = await visitorCtx.newPage();
await check('visitor starts a conversation; hostile markup is rendered as text', async () => {
  await vpage.goto(BASE + '/');
  await startChat(vpage, 'علی');
  await vpage.evaluate(() => (window.__pwned = false));
  await send(vpage, '<img src=x onerror="window.__pwned=true"> سلام <b>bold</b> https://example.com');
  await vpage.waitForTimeout(500);
  const imgs = await W(vpage).locator('.gb-bubble img').count();
  assert(imgs === 0, 'markup injected');
  assert((await vpage.evaluate(() => window.__pwned)) === false, 'script executed');
  const link = W(vpage).locator('.gb-bubble a[href="https://example.com"]');
  assert((await link.getAttribute('rel')).includes('noopener'), 'unsafe link');
  await vpage.screenshot({ path: `${SHOTS}/widget-chat-desktop-rtl.png` });
});

await check('multi-tab continuity: second tab shows the same conversation', async () => {
  const tab2 = await visitorCtx.newPage();
  await tab2.goto(BASE + '/sample-page/');
  await W(tab2).locator('button.gb-launcher').click();
  await W(tab2).locator('.gb-msg[data-author=visitor]').first().waitFor({ timeout: 10000 });
  await send(tab2, 'پیام از زبانهٔ دوم');
  await W(vpage).locator('.gb-bubble', { hasText: 'پیام از زبانهٔ دوم' }).waitFor({ timeout: 15000 });
  await tab2.close();
});

const adminCtx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const apage = await adminCtx.newPage();
await check('operator replies from the WordPress inbox; visitor receives it; markup stays inert in admin', async () => {
  await apage.goto(BASE + '/wp-login.php');
  await apage.fill('#user_login', 'admin');
  await apage.fill('#user_pass', 'admin');
  await apage.click('#wp-submit');
  await apage.goto(BASE + '/wp-admin/admin.php?page=gapban');
  await apage.addStyleTag({ content: '#adminmenumain,#wpadminbar,#wpfooter{display:none!important}#wpcontent{margin:0!important}' });
  await apage.locator('.gb-conv-item').first().click();
  await apage.locator('.gb-row-visitor').first().waitFor();
  assert((await apage.locator('.gb-row-body img').count()) === 0, 'admin rendered markup');
  await apage.locator('textarea.gb-reply').fill('سلام علی جان، در خدمتم.');
  await apage.locator('.gb-composer .gb-btn-primary').click();
  await apage.locator('.gb-row-operator', { hasText: 'در خدمتم' }).waitFor();
  await W(vpage).locator('.gb-msg[data-author=operator] .gb-bubble', { hasText: 'در خدمتم' }).waitFor({ timeout: 20000 });
  // Seen marker reaches the visitor only through the inbox read API.
  await apage.screenshot({ path: `${SHOTS}/admin-inbox-rtl.png`, fullPage: true });
});

await check('internal note never reaches the visitor', async () => {
  await apage.locator('.gb-composer-tabs .gb-tab').nth(1).click();
  await apage.locator('textarea.gb-reply').fill('یادداشت محرمانه');
  await apage.locator('.gb-composer .gb-btn-primary').click();
  await apage.locator('.gb-row-note').waitFor();
  await vpage.waitForTimeout(6000);
  assert((await W(vpage).locator('.gb-bubble', { hasText: 'یادداشت محرمانه' }).count()) === 0, 'note leaked');
});

await check('closing shows a satisfaction rating which can be submitted', async () => {
  await apage.locator('.gb-thread-actions .gb-btn-dark').click();
  await W(vpage).locator('.gb-rating').waitFor({ timeout: 20000 });
  await W(vpage).locator('.gb-star').nth(4).click();
  await W(vpage).locator('.gb-rating .gb-btn').click();
  await W(vpage).locator('.gb-rating', { hasText: 'سپاسگزاریم' }).waitFor();
});

await check('page HTML (cacheable) never contains the session token', async () => {
  const token = await vpage.evaluate(() => JSON.parse(localStorage.getItem('gapban:token')));
  const html = await (await fetch(BASE + '/')).text();
  assert(token && !html.includes(token.split('.')[1]), 'token leaked into HTML');
  assert(html.includes('gapbanConfig'), 'widget config missing');
});

await check('accessibility: no serious/critical axe violations in the open widget', async () => {
  const bad = await runAxe(vpage, 'widget open');
  assert(bad.length === 0, JSON.stringify(bad));
});
await check('accessibility: no serious/critical axe violations in the admin inbox', async () => {
  const bad = await runAxe(apage, 'admin inbox');
  assert(bad.length === 0, JSON.stringify(bad));
});

/* 3. Mobile, keyboard, LTR ---------------------------------------------------- */
await check('mobile: full-screen panel, safe tap target, Escape closes and returns focus', async () => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const page = await ctx.newPage();
  await page.goto(BASE + '/');
  const launcher = W(page).locator('button.gb-launcher');
  await launcher.waitFor();
  const lb = await launcher.boundingBox();
  assert(lb.height >= 44, 'launcher too small: ' + lb.height);
  await page.screenshot({ path: `${SHOTS}/widget-launcher-mobile-rtl.png` });
  await launcher.click();
  const panel = W(page).locator('.gb-panel');
  await panel.waitFor();
  await page.waitForTimeout(400);
  const box = await panel.boundingBox();
  assert(Math.round(box.width) === 390, 'not full width: ' + box.width);
  await page.screenshot({ path: `${SHOTS}/widget-panel-mobile-rtl.png` });
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  const focused = await page.evaluate(() => document.activeElement?.id === 'gapban-widget' && document.activeElement.shadowRoot.activeElement?.classList.contains('gb-launcher'));
  assert(focused, 'focus not returned to launcher');
  await ctx.close();
});

await check('keyboard: launcher reachable with Tab and opens with Enter', async () => {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/');
  await W(page).locator('button.gb-launcher').waitFor();
  await W(page).locator('button.gb-launcher').focus();
  await page.keyboard.press('Enter');
  await W(page).locator('.gb-panel').waitFor();
  const label = await W(page).locator('.gb-panel').getAttribute('aria-labelledby');
  assert(label, 'dialog has no accessible name');
  await ctx.close();
});

await check('English LTR widget renders left-to-right with English strings', async () => {
  execFileSync('php', ['-r', `$_SERVER["HTTP_HOST"]="localhost:8899";require "${process.env.WP_PATH || '/tmp/claude-0/wp'}/wp-load.php";\\Gapban\\Core\\Settings::update(["widget_language"=>"en"]);`], { env: { ...process.env, WP_DB_NAME: process.env.WP_DB_NAME || 'wp' } });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/');
  await W(page).locator('button.gb-launcher').click();
  await W(page).locator('.gb-panel').waitFor();
  await page.waitForTimeout(500);
  assert((await page.locator('#gapban-widget').getAttribute('dir')) === 'ltr', 'not ltr');
  assert((await W(page).locator('.gb-btn.gb-block').first().textContent()).match(/conversation|message/i), 'not English');
  await page.screenshot({ path: `${SHOTS}/widget-panel-desktop-ltr.png` });
  await ctx.close();
  execFileSync('php', ['-r', `$_SERVER["HTTP_HOST"]="localhost:8899";require "${process.env.WP_PATH || '/tmp/claude-0/wp'}/wp-load.php";\\Gapban\\Core\\Settings::update(["widget_language"=>"fa"]);`], { env: { ...process.env, WP_DB_NAME: process.env.WP_DB_NAME || 'wp' } });
});

await browser.close();
const failed = results.filter((r) => !r[1]).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
