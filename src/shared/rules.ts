/**
 * Declarative targeting/automation rule evaluator shared by the widget and the
 * admin preview. Rules are data only (validated server-side by RuleValidator);
 * nothing here executes rule-provided code.
 *
 * Precedence:
 *  1. Rules are ordered by priority (desc) then id (asc) — deterministic.
 *  2. A matching exclusion group removes the rule regardless of its conditions.
 *  3. Visibility (show/hide launcher), department and greeting actions: the first
 *     (highest-precedence) matching rule that declares them wins.
 *  4. Proactive display (teaser / automated message / open chat): at most one per
 *     page view; the highest-precedence rule whose trigger fires first wins, and
 *     later triggers are suppressed while a proactive message is visible.
 */

export type Condition = {
  type: string;
  not?: boolean;
  value?: unknown;
  match?: string;
  key?: string;
  compare?: 'gt' | 'gte' | 'lt' | 'lte' | 'eq';
  taxonomy?: string;
  rule_id?: number;
  within_hours?: number;
};

export type Group = { op: 'and' | 'or'; items: Array<Condition | Group> };

export type Action =
  | { type: 'show_launcher' | 'hide_launcher' | 'open_chat' }
  | { type: 'show_teaser' | 'automated_message'; text: string }
  | { type: 'select_department'; team_id: number }
  | { type: 'greeting_variant'; title: string; subtitle: string };

export type Rule = {
  id: number;
  name: string;
  enabled: boolean;
  priority: number;
  conditions: Group;
  exclusions: Group;
  trigger: { type: 'immediate' | 'time_on_page' | 'scroll_depth' | 'inactivity' | 'exit_intent'; seconds: number; percent: number };
  actions: Action[];
  frequency: {
    per_page: number;
    per_session: number;
    per_visitor: number;
    cooldown_hours: number;
    expires_at: string;
    stop_after_dismiss: boolean;
  };
  mobile: 'allow' | 'skip';
};

export type PageContext = {
  id: number;
  post_type: string;
  template: string;
  kinds: string[];
  terms: Record<string, number[]>;
  wc: { pages: string[]; product_cats: number[] };
};

export type RuleContext = {
  page: PageContext;
  url: { href: string; path: string; query: Record<string, string> };
  visitor: { logged_in: boolean; roles: string[]; returning: boolean; language: string; device: 'mobile' | 'tablet' | 'desktop'; viewport: number };
  referrer: string;
  utm: Record<string, string>;
  availability: { state: 'online' | 'away' | 'offline'; business_hours: boolean };
  conversation: 'none' | 'active' | 'closed';
  chat_opened: boolean;
  cart: { count: number; value: number } | null;
  now: number;
  campaignLastShown: (ruleId: number) => number | null;
};

export type Explanation = { label: string; ok: boolean; children?: Explanation[] };

const cmp = (a: number, op: Condition['compare'], b: number): boolean => {
  switch (op) {
    case 'gt': return a > b;
    case 'gte': return a >= b;
    case 'lt': return a < b;
    case 'lte': return a <= b;
    case 'eq': return a === b;
    default: return false;
  }
};

const asArray = (v: unknown): unknown[] => (Array.isArray(v) ? v : v === undefined || v === null ? [] : [v]);
const nums = (v: unknown): number[] => asArray(v).map((x) => Number(x)).filter((x) => Number.isFinite(x));
const strs = (v: unknown): string[] => asArray(v).map((x) => String(x));

/** Path wildcard: "*" matches within a segment run, "**" is not special; trailing "/*" matches any depth. */
export function wildcardToRegExp(pattern: string): RegExp {
  const escaped = pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
  return new RegExp('^' + escaped + '$', 'i');
}

function normPath(p: string): string {
  if (!p) return '/';
  let out = p.split('#')[0].split('?')[0];
  try {
    out = decodeURI(out);
  } catch {
    /* keep raw */
  }
  return out.length > 1 ? out.replace(/\/+$/, '') : out;
}

export function evalCondition(c: Condition, ctx: RuleContext): boolean {
  const raw = ((): boolean => {
    switch (c.type) {
      case 'site':
        return true;
      case 'page_id':
        return nums(c.value).includes(ctx.page.id);
      case 'post_type':
        return strs(c.value).includes(ctx.page.post_type);
      case 'template':
        return strs(c.value).includes(ctx.page.template);
      case 'taxonomy_term': {
        const terms = ctx.page.terms[c.taxonomy || ''] || [];
        return nums(c.value).some((id) => terms.includes(id));
      }
      case 'page_kind':
        return strs(c.value).some((k) => ctx.page.kinds.includes(k));
      case 'url': {
        const value = String(c.value || '');
        if (c.match === 'exact') {
          if (/^https?:\/\//i.test(value)) return ctx.url.href.split('#')[0].replace(/\/$/, '') === value.replace(/\/$/, '');
          return normPath(ctx.url.path) === normPath(value);
        }
        if (c.match === 'contains') return ctx.url.href.toLowerCase().includes(value.toLowerCase());
        return wildcardToRegExp(normPath(value)).test(normPath(ctx.url.path));
      }
      case 'query_param':
      case 'utm': {
        const source = c.type === 'utm' ? ctx.utm : ctx.url.query;
        const key = c.type === 'utm' && c.key && !c.key.startsWith('utm_') ? 'utm_' + c.key : c.key || '';
        const has = Object.prototype.hasOwnProperty.call(source, key);
        if (c.match === 'exists') return has;
        if (!has) return false;
        const v = String(source[key]).toLowerCase();
        const want = String(c.value || '').toLowerCase();
        return c.match === 'contains' ? v.includes(want) : v === want;
      }
      case 'logged_in':
        return ctx.visitor.logged_in === Boolean(c.value);
      case 'role':
        return strs(c.value).some((r) => ctx.visitor.roles.includes(r));
      case 'device':
        return strs(c.value).includes(ctx.visitor.device);
      case 'viewport_width':
        return cmp(ctx.visitor.viewport, c.compare, Number(c.value));
      case 'language':
        return strs(c.value).some((l) => ctx.visitor.language.toLowerCase().startsWith(l.toLowerCase()));
      case 'returning_visitor':
        return ctx.visitor.returning === Boolean(c.value);
      case 'referrer': {
        const ref = ctx.referrer.toLowerCase();
        if (c.match === 'none') return ref === '';
        if (c.match === 'host') {
          try {
            return new URL(ctx.referrer).hostname.toLowerCase().endsWith(String(c.value || '').toLowerCase());
          } catch {
            return false;
          }
        }
        return ref !== '' && ref.includes(String(c.value || '').toLowerCase());
      }
      case 'availability':
        return strs(c.value).includes(ctx.availability.state);
      case 'business_hours':
        return ctx.availability.business_hours === Boolean(c.value);
      case 'campaign_seen': {
        const last = ctx.campaignLastShown(Number(c.rule_id || 0));
        const hours = Number(c.within_hours || c.value || 24);
        return last !== null && ctx.now - last < hours * 3600_000;
      }
      case 'conversation_state':
        return strs(c.value).includes(ctx.conversation);
      case 'chat_opened':
        return ctx.chat_opened === Boolean(c.value);
      case 'wc_page':
        return strs(c.value).some((p) => ctx.page.wc.pages.includes(p));
      case 'wc_product_cat':
        return nums(c.value).some((id) => ctx.page.wc.product_cats.includes(id));
      case 'wc_cart_count':
        return ctx.cart !== null && cmp(ctx.cart.count, c.compare, Number(c.value));
      case 'wc_cart_value':
        return ctx.cart !== null && cmp(ctx.cart.value, c.compare, Number(c.value));
      default:
        return false; // Unknown conditions never match.
    }
  })();
  return c.not ? !raw : raw;
}

const isGroup = (x: Condition | Group): x is Group => Array.isArray((x as Group).items);

export function evalGroup(g: Group, ctx: RuleContext, describe?: (c: Condition) => string): { ok: boolean; explain: Explanation[] } {
  const explain: Explanation[] = [];
  const results = g.items.map((item) => {
    if (isGroup(item)) {
      const sub = evalGroup(item, ctx, describe);
      explain.push({ label: item.op, ok: sub.ok, children: sub.explain });
      return sub.ok;
    }
    const ok = evalCondition(item, ctx);
    explain.push({ label: describe ? describe(item) : item.type, ok });
    return ok;
  });
  if (results.length === 0) return { ok: g.op === 'and', explain };
  return { ok: g.op === 'and' ? results.every(Boolean) : results.some(Boolean), explain };
}

export type FrequencyState = {
  visitorShows: number[]; // timestamps (ms) across sessions
  sessionShows: number;
  pageShows: number;
  dismissedAt: number | null;
};

export type Decision = {
  rule: Rule;
  matched: boolean;
  excluded: boolean;
  blockedBy: string | null;
  explain: { conditions: Explanation[]; exclusions: Explanation[] };
};

/** Frequency/cooldown/expiry/dismissal checks for proactive display. */
export function frequencyBlock(rule: Rule, st: FrequencyState, now: number, isMobile: boolean): string | null {
  const f = rule.frequency;
  if (f.expires_at && Date.parse(f.expires_at) <= now) return 'expired';
  if (rule.mobile === 'skip' && isMobile) return 'mobile_skipped';
  if (f.stop_after_dismiss && st.dismissedAt !== null) return 'dismissed';
  if (f.per_page > 0 && st.pageShows >= f.per_page) return 'per_page';
  if (f.per_session > 0 && st.sessionShows >= f.per_session) return 'per_session';
  if (f.per_visitor > 0 && st.visitorShows.length >= f.per_visitor) return 'per_visitor';
  if (f.cooldown_hours > 0) {
    const last = st.visitorShows.length ? Math.max(...st.visitorShows) : null;
    if (last !== null && now - last < f.cooldown_hours * 3600_000) return 'cooldown';
  }
  return null;
}

export function sortRules(rules: Rule[]): Rule[] {
  return rules.slice().sort((a, b) => b.priority - a.priority || a.id - b.id);
}

export function decide(rules: Rule[], ctx: RuleContext, freq: (id: number) => FrequencyState, describe?: (c: Condition) => string): Decision[] {
  return sortRules(rules.filter((r) => r.enabled !== false)).map((rule) => {
    const exc = evalGroup(rule.exclusions || { op: 'or', items: [] }, ctx, describe);
    const excluded = (rule.exclusions?.items?.length ?? 0) > 0 && exc.ok;
    const cond = evalGroup(rule.conditions, ctx, describe);
    const proactive = rule.actions.some((a) => a.type === 'show_teaser' || a.type === 'automated_message' || a.type === 'open_chat');
    const blockedBy = !excluded && cond.ok && proactive ? frequencyBlock(rule, freq(rule.id), ctx.now, ctx.visitor.device === 'mobile') : null;
    return { rule, matched: cond.ok && !excluded, excluded, blockedBy, explain: { conditions: cond.explain, exclusions: exc.explain } };
  });
}

/** Static (non-proactive) outcome: launcher visibility, department and greeting variant. */
export function staticOutcome(decisions: Decision[]): {
  launcher: 'show' | 'hide' | null;
  department: number | null;
  greeting: { title: string; subtitle: string } | null;
  sources: Record<string, number>;
} {
  const out = { launcher: null as 'show' | 'hide' | null, department: null as number | null, greeting: null as { title: string; subtitle: string } | null, sources: {} as Record<string, number> };
  for (const d of decisions) {
    if (!d.matched || d.rule.trigger.type !== 'immediate') continue;
    for (const a of d.rule.actions) {
      if ((a.type === 'show_launcher' || a.type === 'hide_launcher') && out.launcher === null) {
        out.launcher = a.type === 'show_launcher' ? 'show' : 'hide';
        out.sources.launcher = d.rule.id;
      }
      if (a.type === 'select_department' && out.department === null) {
        out.department = a.team_id;
        out.sources.department = d.rule.id;
      }
      if (a.type === 'greeting_variant' && out.greeting === null) {
        out.greeting = { title: a.title, subtitle: a.subtitle };
        out.sources.greeting = d.rule.id;
      }
    }
  }
  return out;
}

/** Remove sensitive values from URL query maps before they are used or stored. */
export function filterSensitive(query: Record<string, string>, sensitive: string[]): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(query)) {
    const lk = k.toLowerCase();
    if (!sensitive.some((s) => s && lk.includes(s))) out[k] = v.slice(0, 190);
  }
  return out;
}

export function parseQuery(search: string): Record<string, string> {
  const out: Record<string, string> = {};
  const params = new URLSearchParams(search);
  params.forEach((v, k) => {
    if (!(k in out)) out[k] = v;
  });
  return out;
}

export type T = (key: string, ...args: Array<string | number>) => string;

/** Readable one-paragraph summary of a rule in the admin language. */
export function summarize(rule: Rule, t: T, describe: (c: Condition) => string): string {
  const group = (g: Group): string => {
    if (!g.items.length) return t('rule_any_page');
    const joiner = g.op === 'and' ? ` ${t('and')} ` : ` ${t('or')} `;
    return g.items.map((i) => (isGroup(i) ? `(${group(i)})` : describe(i))).join(joiner);
  };
  const trig = rule.trigger;
  const when =
    trig.type === 'time_on_page'
      ? t('trigger_after_seconds', trig.seconds)
      : trig.type === 'scroll_depth'
        ? t('trigger_after_scroll', trig.percent)
        : trig.type === 'inactivity'
          ? t('trigger_after_inactivity', trig.seconds)
          : trig.type === 'exit_intent'
            ? t('trigger_exit_intent')
            : t('trigger_immediately');
  const actions = rule.actions
    .map((a) => {
      if (a.type === 'show_teaser' || a.type === 'automated_message') return t('action_' + a.type) + ` «${a.text}»`;
      if (a.type === 'select_department') return t('action_select_department') + ' #' + a.team_id;
      if (a.type === 'greeting_variant') return t('action_greeting_variant') + ` «${a.title}»`;
      return t('action_' + a.type);
    })
    .join('، ');
  let text = `${t('rule_if')} ${group(rule.conditions)}`;
  if (rule.exclusions?.items?.length) text += ` ${t('rule_except')} ${group(rule.exclusions)}`;
  text += `، ${when}: ${actions}.`;
  const f = rule.frequency;
  const caps: string[] = [];
  if (f.per_page) caps.push(t('cap_per_page', f.per_page));
  if (f.per_session) caps.push(t('cap_per_session', f.per_session));
  if (f.per_visitor) caps.push(t('cap_per_visitor', f.per_visitor));
  if (f.cooldown_hours) caps.push(t('cap_cooldown', f.cooldown_hours));
  if (f.stop_after_dismiss) caps.push(t('cap_stop_dismiss'));
  if (caps.length) text += ' ' + t('rule_limits') + ' ' + caps.join('، ') + '.';
  return text;
}
