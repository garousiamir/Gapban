// Unit tests for src/shared/rules.ts and src/shared/format.ts (bundled on the fly with esbuild).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as esbuild from 'esbuild';

async function load(entry) {
  const out = await esbuild.build({ entryPoints: [entry], bundle: true, format: 'esm', write: false, platform: 'node' });
  return import('data:text/javascript;base64,' + Buffer.from(out.outputFiles[0].text).toString('base64'));
}
const R = await load('src/shared/rules.ts');
const F = await load('src/shared/format.ts');

const now = Date.UTC(2026, 9, 31, 10, 0, 0);
function ctx(over = {}) {
  return {
    page: { id: 42, post_type: 'page', template: 'default', kinds: ['page', 'singular'], terms: { category: [3] }, wc: { pages: [], product_cats: [] } },
    url: { href: 'https://shop.example/pricing?utm_source=ad', path: '/pricing', query: { utm_source: 'ad' } },
    visitor: { logged_in: false, roles: [], returning: false, language: 'fa-IR', device: 'desktop', viewport: 1440 },
    referrer: '',
    utm: { utm_source: 'ad' },
    availability: { state: 'online', business_hours: true },
    conversation: 'none',
    chat_opened: false,
    cart: null,
    now,
    campaignLastShown: () => null,
    ...over,
  };
}
const freq0 = () => ({ visitorShows: [], sessionShows: 0, pageShows: 0, dismissedAt: null });
function rule(over = {}) {
  return {
    id: 1, name: 'r', enabled: true, priority: 10,
    conditions: { op: 'and', items: [{ type: 'site' }] },
    exclusions: { op: 'or', items: [] },
    trigger: { type: 'immediate', seconds: 0, percent: 50 },
    actions: [{ type: 'show_launcher' }],
    frequency: { per_page: 1, per_session: 1, per_visitor: 0, cooldown_hours: 24, expires_at: '', stop_after_dismiss: true },
    mobile: 'allow',
    ...over,
  };
}

test('pricing acceptance example: matches on the page, blocked for 24h after showing, dismissed never again', () => {
  const r = rule({
    conditions: { op: 'and', items: [{ type: 'page_id', value: [42] }, { type: 'chat_opened', value: false }] },
    trigger: { type: 'time_on_page', seconds: 15, percent: 50 },
    actions: [{ type: 'automated_message', text: 'برای انتخاب پلن مناسب راهنمایی‌تان کنیم؟' }],
  });
  let d = R.decide([r], ctx(), freq0);
  assert.equal(d[0].matched, true);
  assert.equal(d[0].blockedBy, null);
  // Shown 2 hours ago (stored in localStorage; survives refresh/navigation).
  d = R.decide([r], ctx(), () => ({ ...freq0(), visitorShows: [now - 2 * 3600e3] }));
  assert.equal(d[0].blockedBy, 'cooldown');
  // 25 hours later it may show again.
  d = R.decide([r], ctx(), () => ({ ...freq0(), visitorShows: [now - 25 * 3600e3] }));
  assert.equal(d[0].blockedBy, null);
  // Chat already opened -> no match.
  d = R.decide([r], ctx({ chat_opened: true }), freq0);
  assert.equal(d[0].matched, false);
  // Other page -> no match.
  d = R.decide([r], ctx({ page: { ...ctx().page, id: 7 } }), freq0);
  assert.equal(d[0].matched, false);
  // Dismissed -> never again.
  d = R.decide([r], ctx(), () => ({ ...freq0(), dismissedAt: now - 1 }));
  assert.equal(d[0].blockedBy, 'dismissed');
  // Per-session cap counts refreshes in the same tab session.
  d = R.decide([r], ctx(), () => ({ ...freq0(), sessionShows: 1 }));
  assert.equal(d[0].blockedBy, 'per_session');
});

test('exclusions always win and priority ordering is deterministic', () => {
  const a = rule({ id: 2, priority: 5, actions: [{ type: 'hide_launcher' }] });
  const b = rule({ id: 1, priority: 5, actions: [{ type: 'show_launcher' }] });
  const c = rule({ id: 3, priority: 9, actions: [{ type: 'show_launcher' }], exclusions: { op: 'or', items: [{ type: 'url', match: 'path_wildcard', value: '/pricing*' }] } });
  const d = R.decide([a, b, c], ctx(), freq0);
  assert.deepEqual(d.map((x) => x.rule.id), [3, 1, 2]);
  assert.equal(d[0].excluded, true);
  const out = R.staticOutcome(d);
  assert.equal(out.launcher, 'show');
  assert.equal(out.sources.launcher, 1);
});

test('nested AND/OR groups and NOT', () => {
  const g = { op: 'and', items: [{ type: 'device', value: ['desktop'] }, { op: 'or', items: [{ type: 'logged_in', value: true }, { type: 'utm', key: 'source', match: 'equals', value: 'ad' }] }, { type: 'page_kind', value: ['404'], not: true }] };
  assert.equal(R.evalGroup(g, ctx()).ok, true);
  assert.equal(R.evalGroup(g, ctx({ utm: {} })).ok, false);
  assert.equal(R.evalGroup(g, ctx({ utm: {}, visitor: { ...ctx().visitor, logged_in: true } })).ok, true);
});

test('url matching: wildcard, exact, contains; unknown condition never matches', () => {
  assert.equal(R.evalCondition({ type: 'url', match: 'path_wildcard', value: '/pric*' }, ctx()), true);
  assert.equal(R.evalCondition({ type: 'url', match: 'exact', value: '/pricing/' }, ctx()), true);
  assert.equal(R.evalCondition({ type: 'url', match: 'contains', value: 'shop.example' }, ctx()), true);
  assert.equal(R.evalCondition({ type: 'evil', value: 'x' }, ctx()), false);
});

test('mobile skip and expiry block proactive rules', () => {
  const r = rule({ actions: [{ type: 'show_teaser', text: 'hi' }], mobile: 'skip' });
  assert.equal(R.decide([r], ctx({ visitor: { ...ctx().visitor, device: 'mobile' } }), freq0)[0].blockedBy, 'mobile_skipped');
  const e = rule({ actions: [{ type: 'show_teaser', text: 'hi' }], frequency: { ...rule().frequency, expires_at: new Date(now - 1000).toISOString() } });
  assert.equal(R.decide([e], ctx(), freq0)[0].blockedBy, 'expired');
});

test('woo cart conditions are false when WooCommerce data is absent', () => {
  assert.equal(R.evalCondition({ type: 'wc_cart_count', compare: 'gte', value: 1 }, ctx()), false);
  assert.equal(R.evalCondition({ type: 'wc_cart_value', compare: 'gt', value: 100 }, ctx({ cart: { count: 2, value: 250 } })), true);
});

test('sensitive query keys are filtered', () => {
  assert.deepEqual(R.filterSensitive({ token: 'x', utm_source: 'a', user_email: 'e' }, ['token', 'email']), { utm_source: 'a' });
});

test('digits, sprintf and Jalali display', () => {
  assert.equal(F.normalizeDigits('۰۹۱۲٣٤٥'), '0912345');
  assert.equal(F.toPersianDigits('2026'), '۲۰۲۶');
  assert.equal(F.sprintf('%2$s-%1$s %%', 'a', 'b'), 'b-a %');
  const day = F.formatDay('2026-03-21T12:00:00Z', { locale: 'fa', persianDigits: true, jalali: true, timeZone: 'Asia/Tehran' });
  assert.match(day, /فروردین/);
  assert.match(day, /۱۴۰۵/);
  // Day key uses the display time zone (23:00 UTC is next day in Tehran).
  assert.equal(F.dayKey('2026-10-31T23:00:00Z', { locale: 'fa', persianDigits: true, jalali: true, timeZone: 'Asia/Tehran' }), '2026-11-01');
});

test('summary is readable', () => {
  const s = R.summarize(rule({ trigger: { type: 'time_on_page', seconds: 15, percent: 0 }, actions: [{ type: 'automated_message', text: 'x' }] }), (k, ...a) => k + (a.length ? '(' + a.join(',') + ')' : ''), (c) => c.type);
  assert.match(s, /trigger_after_seconds\(15\)/);
  assert.match(s, /cap_cooldown\(24\)/);
});
