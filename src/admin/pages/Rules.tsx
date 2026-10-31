import { useMemo, useState } from 'react';
import { api, cfg, errorText, num, t } from '../lib';
import { Badge, Button, Card, Empty, IconButton, Modal, Notice, PageHeader, Select, Spinner, TextInput, Toggle, useAsync, useToast } from '../ui';
import { decide, summarize, type Action, type Condition, type Explanation, type Group, type Rule, type RuleContext } from '../../shared/rules';

type Lookup = {
  posts: Array<{ id: number; title: string; type: string; url: string }>;
  post_types: Array<{ name: string; label: string }>;
  taxonomies: Record<string, { label: string; terms: Array<{ id: number; name: string }> }>;
  roles: Array<{ name: string; label: string }>;
  templates: string[];
  teams: Array<{ id: number; name: string }>;
  woocommerce: boolean;
};

const CONDITION_TYPES = (): Array<{ value: string; label: string; group: string; woo?: boolean }> => [
  { value: 'site', label: t('Entire site'), group: t('Pages') },
  { value: 'page_id', label: t('Specific pages or posts'), group: t('Pages') },
  { value: 'post_type', label: t('Content type'), group: t('Pages') },
  { value: 'template', label: t('Page template'), group: t('Pages') },
  { value: 'taxonomy_term', label: t('Category / tag / term'), group: t('Pages') },
  { value: 'page_kind', label: t('Kind of page (front, blog, archive, search, 404…)'), group: t('Pages') },
  { value: 'url', label: t('Address (URL)'), group: t('Pages') },
  { value: 'query_param', label: t('URL query parameter'), group: t('Pages') },
  { value: 'logged_in', label: t('Visitor is logged in'), group: t('Visitor') },
  { value: 'role', label: t('WordPress role'), group: t('Visitor') },
  { value: 'device', label: t('Device type'), group: t('Visitor') },
  { value: 'viewport_width', label: t('Screen width'), group: t('Visitor') },
  { value: 'language', label: t('Browser/page language'), group: t('Visitor') },
  { value: 'returning_visitor', label: t('Returning visitor'), group: t('Visitor') },
  { value: 'referrer', label: t('Came from (referrer)'), group: t('Visitor') },
  { value: 'utm', label: t('Campaign (UTM) parameter'), group: t('Visitor') },
  { value: 'availability', label: t('Team availability'), group: t('Availability') },
  { value: 'business_hours', label: t('Within business hours'), group: t('Availability') },
  { value: 'campaign_seen', label: t('Saw another campaign recently'), group: t('History') },
  { value: 'conversation_state', label: t('Conversation state'), group: t('History') },
  { value: 'chat_opened', label: t('Opened the chat in this session'), group: t('History') },
  { value: 'wc_page', label: t('WooCommerce page'), group: 'WooCommerce', woo: true },
  { value: 'wc_product_cat', label: t('Product category'), group: 'WooCommerce', woo: true },
  { value: 'wc_cart_count', label: t('Items in cart'), group: 'WooCommerce', woo: true },
  { value: 'wc_cart_value', label: t('Cart value (store currency)'), group: 'WooCommerce', woo: true },
];

const ENUMS = (): Record<string, Array<[string, string]>> => ({
  page_kind: [['front', t('Front page')], ['home', t('Blog page')], ['archive', t('Archive')], ['search', t('Search results')], ['404', t('Not found (404)')], ['singular', t('Any single item')], ['page', t('Page')], ['post', t('Post')]],
  device: [['mobile', t('Mobile')], ['tablet', t('Tablet')], ['desktop', t('Desktop')]],
  availability: [['online', t('Online')], ['away', t('Away')], ['offline', t('Offline / closed')]],
  conversation_state: [['none', t('Never chatted')], ['active', t('Has an open conversation')], ['closed', t('Last conversation closed')]],
  wc_page: [['product', t('Product')], ['product_category', t('Product category')], ['shop', t('Shop')], ['cart', t('Cart')], ['checkout', t('Checkout')], ['account', t('My account')]],
});
const BOOL_TYPES = ['logged_in', 'returning_visitor', 'business_hours', 'chat_opened'];
const COMPARE_TYPES = ['viewport_width', 'wc_cart_count', 'wc_cart_value'];

function emptyRule(): Rule {
  return {
    id: 0,
    name: '',
    enabled: true,
    priority: 10,
    conditions: { op: 'and', items: [{ type: 'site' }] },
    exclusions: { op: 'or', items: [] },
    trigger: { type: 'time_on_page', seconds: 10, percent: 50 },
    actions: [{ type: 'show_teaser', text: '' }],
    frequency: { per_page: 1, per_session: 1, per_visitor: 0, cooldown_hours: 24, expires_at: '', stop_after_dismiss: true },
    mobile: 'allow',
  };
}

/** The acceptance example from the specification, as a ready-made template. */
function pricingTemplate(pageId: number | null): Rule {
  return {
    ...emptyRule(),
    name: t('Pricing page help offer'),
    conditions: { op: 'and', items: [pageId ? { type: 'page_id', value: [pageId] } : { type: 'url', match: 'path_wildcard', value: '/pricing*' }, { type: 'chat_opened', value: false }] },
    trigger: { type: 'time_on_page', seconds: 15, percent: 50 },
    actions: [{ type: 'automated_message', text: 'برای انتخاب پلن مناسب راهنمایی‌تان کنیم؟' }],
    frequency: { per_page: 1, per_session: 1, per_visitor: 0, cooldown_hours: 24, expires_at: '', stop_after_dismiss: true },
  };
}

export function describeCondition(c: Condition, lookup?: Lookup | null): string {
  const type = CONDITION_TYPES().find((x) => x.value === c.type)?.label || c.type;
  const not = c.not ? t('not') + ' ' : '';
  const list = (v: unknown) => (Array.isArray(v) ? v : [v]).map(String);
  let value = '';
  if (c.type === 'site') return not + type;
  if (BOOL_TYPES.includes(c.type)) return `${not}${type}: ${c.value ? t('yes') : t('no')}`;
  if (ENUMS()[c.type]) value = list(c.value).map((v) => ENUMS()[c.type].find((e) => e[0] === v)?.[1] || v).join(t(' or '));
  else if (c.type === 'page_id') value = list(c.value).map((id) => lookup?.posts.find((p) => String(p.id) === id)?.title || '#' + num(id)).join(t(' or '));
  else if (c.type === 'url') value = `${c.match === 'exact' ? t('is') : c.match === 'contains' ? t('contains') : t('matches')} «${c.value}»`;
  else if (c.type === 'query_param' || c.type === 'utm') value = `${c.key} ${c.match === 'exists' ? t('exists') : (c.match === 'contains' ? t('contains') : '=') + ' «' + c.value + '»'}`;
  else if (COMPARE_TYPES.includes(c.type)) value = `${{ gt: '>', gte: '≥', lt: '<', lte: '≤', eq: '=' }[c.compare || 'gte']} ${num(String(c.value))}`;
  else if (c.type === 'campaign_seen') value = t('rule #%s within %s hours', num(String(c.rule_id || '')), num(String(c.within_hours || c.value)));
  else if (c.type === 'referrer') value = c.match === 'none' ? t('direct visit') : `«${c.value}»`;
  else value = list(c.value).join('، ');
  return `${not}${type}: ${value}`;
}

const ruleT = (key: string, ...args: Array<string | number>): string => {
  const map: Record<string, string> = {
    rule_any_page: t('any page'),
    and: t('and'),
    or: t('or'),
    trigger_after_seconds: t('after %s seconds of viewing the page'),
    trigger_after_scroll: t('after scrolling %s%% of the page'),
    trigger_after_inactivity: t('after %s seconds of inactivity'),
    trigger_exit_intent: t('when the mouse leaves the page (desktop only)'),
    trigger_immediately: t('immediately'),
    action_show_launcher: t('show the launcher'),
    action_hide_launcher: t('hide the launcher'),
    action_show_teaser: t('show the bubble'),
    action_open_chat: t('open the chat window'),
    action_automated_message: t('show the automated message'),
    action_select_department: t('select department'),
    action_greeting_variant: t('use the greeting'),
    rule_if: t('If'),
    rule_except: t('except when'),
    rule_limits: t('Limits:'),
    cap_per_page: t('%s time(s) per page'),
    cap_per_session: t('%s time(s) per visit'),
    cap_per_visitor: t('%s time(s) per visitor'),
    cap_cooldown: t('not again within %s hours'),
    cap_stop_dismiss: t('never again after dismissal'),
  };
  let s = map[key] || key;
  let i = 0;
  s = s.replace(/%s/g, () => num(String(args[i++] ?? '')));
  return s.replace(/%%/g, '%');
};

export default function Rules() {
  const toast = useToast();
  const rules = useAsync<Array<Rule & { stats?: Record<string, number> }>>(() => api('GET', 'admin/rules'), []);
  const lookup = useAsync<Lookup>(() => api('GET', 'admin/targeting-data'), []);
  const [edit, setEdit] = useState<Rule | null>(null);
  const [preview, setPreview] = useState(false);

  return (
    <div>
      <PageHeader
        title={t('Display rules & automated messages')}
        intro={t('Decide where the launcher appears and when to offer help. Rules run in the visitor’s browser using only first-party data; they never change who may read a conversation.')}
        actions={
          <>
            <Button kind="ghost" icon="eye" onClick={() => setPreview(true)}>
              {t('Test rules')}
            </Button>
            <Button kind="ghost" icon="wand" onClick={() => setEdit(pricingTemplate(null))}>
              {t('Pricing page template')}
            </Button>
            <Button icon="plus" onClick={() => setEdit(emptyRule())}>
              {t('New rule')}
            </Button>
          </>
        }
      />
      <Notice>{t('Order: higher priority first (ties by creation). A matching exclusion always wins. Only one proactive message is shown per page view; launcher visibility, department and greeting come from the highest matching rule.')}</Notice>
      <Card>
        {rules.loading ? (
          <Spinner />
        ) : !rules.data?.length ? (
          <Empty icon="wand" title={t('No rules yet')}>
            {t('Without rules the launcher appears on every page and no proactive messages are shown.')}
          </Empty>
        ) : (
          <ul className="gb-rule-list">
            {rules.data.map((r) => (
              <li key={r.id} className="gb-rule-item">
                <div>
                  <h3>
                    {r.name} {!r.enabled && <Badge tone="dark">{t('Disabled')}</Badge>} <Badge>{t('Priority %s', num(r.priority))}</Badge>
                  </h3>
                  <p className="gb-rule-summary">{summarize(r, ruleT, (c) => describeCondition(c, lookup.data))}</p>
                  <p className="gb-hint">{t('Last 30 days: shown %s, clicked %s, dismissed %s, chats %s', num(r.stats?.shown || 0), num(r.stats?.clicked || 0), num(r.stats?.dismissed || 0), num(r.stats?.converted || 0))}</p>
                </div>
                <div className="gb-row-actions">
                  <Button small kind="ghost" onClick={() => setEdit(r)}>
                    {t('Edit')}
                  </Button>
                  <Button
                    small
                    kind="ghost"
                    onClick={async () => {
                      try {
                        await api('POST', `admin/rules/${r.id}`, { ...r, enabled: !r.enabled });
                        rules.reload();
                      } catch (e) {
                        toast(errorText(e), 'error');
                      }
                    }}
                  >
                    {r.enabled ? t('Disable') : t('Enable')}
                  </Button>
                  <IconButton
                    icon="trash"
                    label={t('Delete %s', r.name)}
                    onClick={async () => {
                      if (!window.confirm(t('Delete this rule and its statistics?'))) return;
                      await api('DELETE', `admin/rules/${r.id}`);
                      rules.reload();
                    }}
                  />
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {edit && <RuleEditor rule={edit} lookup={lookup.data} onClose={() => setEdit(null)} onSaved={() => (setEdit(null), rules.reload())} />}
      {preview && <PreviewTester rules={rules.data || []} lookup={lookup.data} onClose={() => setPreview(false)} />}
    </div>
  );
}

function RuleEditor({ rule, lookup, onClose, onSaved }: { rule: Rule; lookup: Lookup | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [r, setR] = useState<Rule>(JSON.parse(JSON.stringify(rule)));
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const summary = useMemo(() => summarize(r, ruleT, (c) => describeCondition(c, lookup)), [r, lookup]);
  const save = async () => {
    setBusy(true);
    setErr(null);
    try {
      await api('POST', rule.id ? `admin/rules/${rule.id}` : 'admin/rules', r);
      toast(t('Rule saved.'));
      onSaved();
    } catch (e) {
      setErr(errorText(e));
    } finally {
      setBusy(false);
    }
  };
  const setAction = (i: number, a: Action) => setR({ ...r, actions: r.actions.map((x, j) => (j === i ? a : x)) });
  return (
    <Modal
      title={rule.id ? t('Edit rule') : t('New rule')}
      onClose={onClose}
      footer={
        <>
          <Button kind="ghost" onClick={onClose}>
            {t('Cancel')}
          </Button>
          <Button onClick={save} busy={busy}>
            {t('Save rule')}
          </Button>
        </>
      }
    >
      {err && <Notice tone="error">{err}</Notice>}
      <TextInput label={t('Rule name')} required value={r.name} onChange={(v) => setR({ ...r, name: v })} />
      <div className="gb-grid-2 gb-compact">
        <TextInput type="number" label={t('Priority')} value={r.priority} onChange={(v) => setR({ ...r, priority: Number(v) })} help={t('Higher numbers win.')} />
        <Toggle label={t('Enabled')} checked={r.enabled} onChange={(v) => setR({ ...r, enabled: v })} />
      </div>
      <h3 className="gb-subtitle">{t('Show when')}</h3>
      <GroupEditor group={r.conditions} lookup={lookup} onChange={(g) => setR({ ...r, conditions: g })} depth={1} />
      <h3 className="gb-subtitle">{t('Except when (exclusions)')}</h3>
      <GroupEditor group={r.exclusions} lookup={lookup} onChange={(g) => setR({ ...r, exclusions: g })} depth={1} />
      <h3 className="gb-subtitle">{t('Trigger')}</h3>
      <div className="gb-grid-2 gb-compact">
        <Select
          label={t('When')}
          value={r.trigger.type}
          onChange={(v) => setR({ ...r, trigger: { ...r.trigger, type: v as Rule['trigger']['type'] } })}
          options={[
            { value: 'immediate', label: t('Immediately on page load') },
            { value: 'time_on_page', label: t('After time on page (visible tab only)') },
            { value: 'scroll_depth', label: t('After scrolling') },
            { value: 'inactivity', label: t('After inactivity') },
            { value: 'exit_intent', label: t('Exit intent (desktop only)') },
          ]}
        />
        {(r.trigger.type === 'time_on_page' || r.trigger.type === 'inactivity') && <TextInput type="number" label={t('Seconds')} value={r.trigger.seconds} onChange={(v) => setR({ ...r, trigger: { ...r.trigger, seconds: Number(v) } })} />}
        {r.trigger.type === 'scroll_depth' && <TextInput type="number" label={t('Percent of page')} value={r.trigger.percent} onChange={(v) => setR({ ...r, trigger: { ...r.trigger, percent: Number(v) } })} />}
      </div>
      {r.trigger.type === 'exit_intent' && <p className="gb-hint">{t('Exit intent cannot be detected reliably on phones and tablets, so this rule never fires there.')}</p>}
      <h3 className="gb-subtitle">{t('Actions')}</h3>
      {r.actions.map((a, i) => (
        <div key={i} className="gb-action-row">
          <Select
            label={t('Action %s', num(i + 1))}
            value={a.type}
            onChange={(v) => setAction(i, (v === 'show_teaser' || v === 'automated_message' ? { type: v, text: (a as any).text || '' } : v === 'select_department' ? { type: v, team_id: lookup?.teams[0]?.id || 0 } : v === 'greeting_variant' ? { type: v, title: '', subtitle: '' } : { type: v }) as Action)}
            options={[
              { value: 'show_launcher', label: t('Show the launcher') },
              { value: 'hide_launcher', label: t('Hide the launcher') },
              { value: 'show_teaser', label: t('Show a bubble next to the launcher') },
              { value: 'automated_message', label: t('Show an automated message (labelled automated)') },
              { value: 'open_chat', label: t('Open the chat window') },
              { value: 'select_department', label: t('Pre-select a department') },
              { value: 'greeting_variant', label: t('Use a different greeting') },
            ]}
          />
          {(a.type === 'show_teaser' || a.type === 'automated_message') && <TextInput multiline rows={2} label={t('Text')} value={a.text} onChange={(v) => setAction(i, { ...a, text: v })} />}
          {a.type === 'select_department' && <Select label={t('Department')} value={a.team_id} onChange={(v) => setAction(i, { ...a, team_id: Number(v) })} options={(lookup?.teams || []).map((tm) => ({ value: tm.id, label: tm.name }))} />}
          {a.type === 'greeting_variant' && (
            <>
              <TextInput label={t('Title')} value={a.title} onChange={(v) => setAction(i, { ...a, title: v })} />
              <TextInput label={t('Subtitle')} value={a.subtitle} onChange={(v) => setAction(i, { ...a, subtitle: v })} />
            </>
          )}
          {r.actions.length > 1 && <IconButton icon="trash" label={t('Remove action')} onClick={() => setR({ ...r, actions: r.actions.filter((_, j) => j !== i) })} />}
        </div>
      ))}
      <Button small kind="ghost" icon="plus" onClick={() => setR({ ...r, actions: [...r.actions, { type: 'show_launcher' }] })}>
        {t('Add action')}
      </Button>
      <h3 className="gb-subtitle">{t('Frequency')}</h3>
      <div className="gb-grid-2 gb-compact">
        <TextInput type="number" label={t('Max per page view')} value={r.frequency.per_page} onChange={(v) => setR({ ...r, frequency: { ...r.frequency, per_page: Number(v) } })} help={t('0 = no limit')} />
        <TextInput type="number" label={t('Max per visit (session)')} value={r.frequency.per_session} onChange={(v) => setR({ ...r, frequency: { ...r.frequency, per_session: Number(v) } })} />
        <TextInput type="number" label={t('Max per visitor (ever)')} value={r.frequency.per_visitor} onChange={(v) => setR({ ...r, frequency: { ...r.frequency, per_visitor: Number(v) } })} />
        <TextInput type="number" label={t('Cooldown (hours)')} value={r.frequency.cooldown_hours} onChange={(v) => setR({ ...r, frequency: { ...r.frequency, cooldown_hours: Number(v) } })} help={t('Counts across tabs, refreshes and pages.')} />
        <TextInput type="datetime-local" label={t('Stop showing after')} value={r.frequency.expires_at ? r.frequency.expires_at.slice(0, 16) : ''} onChange={(v) => setR({ ...r, frequency: { ...r.frequency, expires_at: v ? new Date(v).toISOString() : '' } })} />
        <Select label={t('On phones')} value={r.mobile} onChange={(v) => setR({ ...r, mobile: v as Rule['mobile'] })} options={[{ value: 'allow', label: t('Allowed') }, { value: 'skip', label: t('Do not show proactive messages') }]} />
      </div>
      <Toggle label={t('Never show again after the visitor dismisses it')} checked={r.frequency.stop_after_dismiss} onChange={(v) => setR({ ...r, frequency: { ...r.frequency, stop_after_dismiss: v } })} />
      <div className="gb-summary-box" aria-live="polite">
        <strong>{t('Summary')}</strong>
        <p>{summary}</p>
      </div>
    </Modal>
  );
}

function GroupEditor({ group, onChange, lookup, depth }: { group: Group; onChange: (g: Group) => void; lookup: Lookup | null; depth: number }) {
  const setItem = (i: number, item: Condition | Group) => onChange({ ...group, items: group.items.map((x, j) => (j === i ? item : x)) });
  const remove = (i: number) => onChange({ ...group, items: group.items.filter((_, j) => j !== i) });
  return (
    <fieldset className={'gb-group gb-depth-' + depth}>
      <legend className="gb-sr">{t('Condition group')}</legend>
      <div className="gb-group-op" role="group" aria-label={t('Combine conditions with')}>
        <button type="button" className="gb-tab" aria-pressed={group.op === 'and'} onClick={() => onChange({ ...group, op: 'and' })}>
          {t('All of (AND)')}
        </button>
        <button type="button" className="gb-tab" aria-pressed={group.op === 'or'} onClick={() => onChange({ ...group, op: 'or' })}>
          {t('Any of (OR)')}
        </button>
      </div>
      {group.items.length === 0 && <p className="gb-hint">{t('No conditions.')}</p>}
      {group.items.map((item, i) =>
        'items' in item ? (
          <div key={i} className="gb-group-wrap">
            <GroupEditor group={item} onChange={(g) => setItem(i, g)} lookup={lookup} depth={depth + 1} />
            <IconButton icon="trash" label={t('Remove group')} onClick={() => remove(i)} />
          </div>
        ) : (
          <ConditionEditor key={i} c={item} lookup={lookup} onChange={(c) => setItem(i, c)} onRemove={() => remove(i)} />
        ),
      )}
      <div className="gb-actions">
        <Button small kind="ghost" icon="plus" onClick={() => onChange({ ...group, items: [...group.items, { type: 'page_id', value: [] }] })}>
          {t('Add condition')}
        </Button>
        {depth < 4 && (
          <Button small kind="ghost" icon="list" onClick={() => onChange({ ...group, items: [...group.items, { op: group.op === 'and' ? 'or' : 'and', items: [] }] })}>
            {t('Add nested group')}
          </Button>
        )}
      </div>
    </fieldset>
  );
}

function ConditionEditor({ c, onChange, onRemove, lookup }: { c: Condition; onChange: (c: Condition) => void; onRemove: () => void; lookup: Lookup | null }) {
  const types = CONDITION_TYPES().filter((x) => !x.woo || lookup?.woocommerce);
  const list = Array.isArray(c.value) ? (c.value as unknown[]).map(String) : [];
  const toggleIn = (v: string) => onChange({ ...c, value: list.includes(v) ? list.filter((x) => x !== v) : [...list, v] });
  return (
    <div className="gb-cond">
      <Select
        label={t('Condition')}
        value={c.type}
        onChange={(v) => onChange({ type: v, not: c.not, value: BOOL_TYPES.includes(v) ? true : COMPARE_TYPES.includes(v) ? 0 : v === 'url' ? '' : [], match: v === 'url' ? 'path_wildcard' : v === 'query_param' || v === 'utm' ? 'equals' : v === 'referrer' ? 'contains' : undefined, compare: COMPARE_TYPES.includes(v) ? 'gte' : undefined })}
        options={types.map((x) => ({ value: x.value, label: `${x.group} — ${x.label}` }))}
      />
      <Toggle label={t('Negate (NOT)')} checked={!!c.not} onChange={(v) => onChange({ ...c, not: v })} />
      {c.type === 'page_id' && (
        <div className="gb-pick">
          {(lookup?.posts || []).map((p) => (
            <button key={p.id} type="button" className="gb-chip" aria-pressed={list.includes(String(p.id))} onClick={() => toggleIn(String(p.id))}>
              {p.title} <span className="gb-hint">#{num(p.id)}</span>
            </button>
          ))}
          <TextInput label={t('Or enter IDs (comma separated)')} dir="ltr" value={list.join(',')} onChange={(v) => onChange({ ...c, value: v.split(/[,\s]+/).filter(Boolean) })} />
        </div>
      )}
      {c.type === 'post_type' && (
        <div className="gb-pick">
          {(lookup?.post_types || []).map((p) => (
            <button key={p.name} type="button" className="gb-chip" aria-pressed={list.includes(p.name)} onClick={() => toggleIn(p.name)}>
              {p.label}
            </button>
          ))}
        </div>
      )}
      {c.type === 'template' && (
        <div className="gb-pick">
          {(lookup?.templates || []).map((p) => (
            <button key={p} type="button" className="gb-chip" aria-pressed={list.includes(p)} onClick={() => toggleIn(p)}>
              <bdi>{p}</bdi>
            </button>
          ))}
        </div>
      )}
      {c.type === 'role' && (
        <div className="gb-pick">
          {(lookup?.roles || []).map((p) => (
            <button key={p.name} type="button" className="gb-chip" aria-pressed={list.includes(p.name)} onClick={() => toggleIn(p.name)}>
              {p.label}
            </button>
          ))}
        </div>
      )}
      {(c.type === 'taxonomy_term' || c.type === 'wc_product_cat') && (
        <div className="gb-pick">
          {c.type === 'taxonomy_term' && <Select label={t('Taxonomy')} value={c.taxonomy || ''} onChange={(v) => onChange({ ...c, taxonomy: v, value: [] })} options={[{ value: '', label: t('Choose…') }, ...Object.entries(lookup?.taxonomies || {}).map(([k, v]) => ({ value: k, label: v.label }))]} />}
          {(lookup?.taxonomies[c.type === 'wc_product_cat' ? 'product_cat' : c.taxonomy || '']?.terms || []).map((term) => (
            <button key={term.id} type="button" className="gb-chip" aria-pressed={list.includes(String(term.id))} onClick={() => toggleIn(String(term.id))}>
              {term.name}
            </button>
          ))}
        </div>
      )}
      {ENUMS()[c.type] && (
        <div className="gb-pick">
          {ENUMS()[c.type].map(([v, label]) => (
            <button key={v} type="button" className="gb-chip" aria-pressed={list.includes(v)} onClick={() => toggleIn(v)}>
              {label}
            </button>
          ))}
        </div>
      )}
      {BOOL_TYPES.includes(c.type) && <Select label={t('Value')} value={c.value ? '1' : '0'} onChange={(v) => onChange({ ...c, value: v === '1' })} options={[{ value: '1', label: t('Yes') }, { value: '0', label: t('No') }]} />}
      {c.type === 'language' && <TextInput label={t('Language codes (comma separated)')} dir="ltr" value={list.join(',')} onChange={(v) => onChange({ ...c, value: v.split(/[,\s]+/).filter(Boolean) })} placeholder="fa, en" />}
      {c.type === 'url' && (
        <>
          <Select label={t('Match')} value={c.match || 'path_wildcard'} onChange={(v) => onChange({ ...c, match: v })} options={[{ value: 'path_wildcard', label: t('Path with * wildcard') }, { value: 'exact', label: t('Exact address or path') }, { value: 'contains', label: t('Address contains') }]} />
          <TextInput label={t('Pattern')} dir="ltr" value={String(c.value || '')} onChange={(v) => onChange({ ...c, value: v })} placeholder="/pricing*" />
        </>
      )}
      {(c.type === 'query_param' || c.type === 'utm') && (
        <>
          <TextInput label={c.type === 'utm' ? t('UTM key (e.g. utm_source)') : t('Parameter name')} dir="ltr" value={c.key || ''} onChange={(v) => onChange({ ...c, key: v })} />
          <Select label={t('Match')} value={c.match || 'equals'} onChange={(v) => onChange({ ...c, match: v })} options={[{ value: 'equals', label: t('Equals') }, { value: 'contains', label: t('Contains') }, { value: 'exists', label: t('Exists') }]} />
          {c.match !== 'exists' && <TextInput label={t('Value')} dir="ltr" value={String(c.value || '')} onChange={(v) => onChange({ ...c, value: v })} />}
          <p className="gb-hint">{t('Parameters with sensitive names (token, email, phone…) are removed before rules see them.')}</p>
        </>
      )}
      {c.type === 'referrer' && (
        <>
          <Select label={t('Match')} value={c.match || 'contains'} onChange={(v) => onChange({ ...c, match: v })} options={[{ value: 'contains', label: t('Address contains') }, { value: 'host', label: t('Domain is') }, { value: 'none', label: t('Direct visit (no referrer)') }]} />
          {c.match !== 'none' && <TextInput label={t('Value')} dir="ltr" value={String(c.value || '')} onChange={(v) => onChange({ ...c, value: v })} />}
        </>
      )}
      {COMPARE_TYPES.includes(c.type) && (
        <>
          <Select label={t('Comparison')} value={c.compare || 'gte'} onChange={(v) => onChange({ ...c, compare: v as Condition['compare'] })} options={[{ value: 'gte', label: '≥' }, { value: 'gt', label: '>' }, { value: 'lte', label: '≤' }, { value: 'lt', label: '<' }, { value: 'eq', label: '=' }]} />
          <TextInput label={t('Number')} dir="ltr" inputMode="numeric" value={String(c.value ?? '')} onChange={(v) => onChange({ ...c, value: v })} help={c.type === 'wc_cart_value' ? t('In the store’s own currency and units, exactly as WooCommerce stores it (no Rial/Toman conversion).') : undefined} />
        </>
      )}
      {c.type === 'campaign_seen' && (
        <>
          <TextInput type="number" label={t('Rule ID')} value={String(c.rule_id || '')} onChange={(v) => onChange({ ...c, rule_id: Number(v) })} />
          <TextInput type="number" label={t('Within hours')} value={String(c.value ?? 24)} onChange={(v) => onChange({ ...c, value: Number(v), within_hours: Number(v) })} />
        </>
      )}
      <IconButton icon="trash" label={t('Remove condition')} onClick={onRemove} />
    </div>
  );
}

function PreviewTester({ rules, lookup, onClose }: { rules: Rule[]; lookup: Lookup | null; onClose: () => void }) {
  const [url, setUrl] = useState(cfg().siteUrl + '/');
  const [pageId, setPageId] = useState('');
  const [device, setDevice] = useState<'desktop' | 'mobile' | 'tablet'>('desktop');
  const [loggedIn, setLoggedIn] = useState(false);
  const [opened, setOpened] = useState(false);
  const [availability, setAvailability] = useState<'online' | 'away' | 'offline'>('online');
  const [shownAgo, setShownAgo] = useState('');
  let parsed: URL | null = null;
  try {
    parsed = new URL(url);
  } catch {
    parsed = null;
  }
  const ctx: RuleContext = {
    page: { id: Number(pageId) || 0, post_type: lookup?.posts.find((p) => String(p.id) === pageId)?.type || '', template: 'default', kinds: [], terms: {}, wc: { pages: [], product_cats: [] } },
    url: { href: url, path: parsed?.pathname || '/', query: Object.fromEntries(parsed?.searchParams.entries() || []) },
    visitor: { logged_in: loggedIn, roles: loggedIn ? ['subscriber'] : [], returning: false, language: cfg().lang, device, viewport: device === 'mobile' ? 390 : device === 'tablet' ? 820 : 1440 },
    referrer: '',
    utm: Object.fromEntries(Array.from(parsed?.searchParams.entries() || []).filter(([k]) => k.startsWith('utm_'))),
    availability: { state: availability, business_hours: availability !== 'offline' },
    conversation: 'none',
    chat_opened: opened,
    cart: null,
    now: Date.now(),
    campaignLastShown: () => (shownAgo ? Date.now() - Number(shownAgo) * 3600_000 : null),
  };
  const decisions = decide(rules, ctx, () => ({ visitorShows: shownAgo ? [Date.now() - Number(shownAgo) * 3600_000] : [], sessionShows: 0, pageShows: 0, dismissedAt: null }), (c) => describeCondition(c, lookup));
  const blockLabel: Record<string, string> = { expired: t('expired'), mobile_skipped: t('skipped on phones'), dismissed: t('dismissed before'), per_page: t('page limit reached'), per_session: t('visit limit reached'), per_visitor: t('visitor limit reached'), cooldown: t('in cooldown') };
  return (
    <Modal title={t('Test rules')} onClose={onClose}>
      <p className="gb-hint">{t('Simulate a visit and see which rules match and why. Nothing is shown to real visitors.')}</p>
      <TextInput label={t('Page address')} dir="ltr" value={url} onChange={setUrl} />
      <div className="gb-grid-2 gb-compact">
        <Select label={t('Page')} value={pageId} onChange={setPageId} options={[{ value: '', label: t('(none)') }, ...(lookup?.posts || []).map((p) => ({ value: p.id, label: p.title }))]} />
        <Select label={t('Device')} value={device} onChange={(v) => setDevice(v as 'desktop')} options={[{ value: 'desktop', label: t('Desktop') }, { value: 'tablet', label: t('Tablet') }, { value: 'mobile', label: t('Mobile') }]} />
        <Select label={t('Team availability')} value={availability} onChange={(v) => setAvailability(v as 'online')} options={[{ value: 'online', label: t('Online') }, { value: 'away', label: t('Away') }, { value: 'offline', label: t('Offline / closed') }]} />
        <TextInput type="number" label={t('Campaign last shown (hours ago, empty = never)')} value={shownAgo} onChange={setShownAgo} />
      </div>
      <Toggle label={t('Visitor is logged in')} checked={loggedIn} onChange={setLoggedIn} />
      <Toggle label={t('Chat already opened in this visit')} checked={opened} onChange={setOpened} />
      <ul className="gb-explain">
        {decisions.map((d) => (
          <li key={d.rule.id}>
            <strong>{d.rule.name}</strong>{' '}
            {d.excluded ? <Badge tone="dark">{t('Excluded')}</Badge> : d.matched ? d.blockedBy ? <Badge tone="amber">{t('Matches, but %s', blockLabel[d.blockedBy] || d.blockedBy)}</Badge> : <Badge tone="green">{t('Matches')}</Badge> : <Badge>{t('Does not match')}</Badge>}
            <ExplainTree items={d.explain.conditions} />
            {d.explain.exclusions.length > 0 && (
              <>
                <p className="gb-hint">{t('Exclusions:')}</p>
                <ExplainTree items={d.explain.exclusions} />
              </>
            )}
          </li>
        ))}
      </ul>
    </Modal>
  );
}

function ExplainTree({ items }: { items: Explanation[] }) {
  return (
    <ul className="gb-explain-tree">
      {items.map((e, i) => (
        <li key={i}>
          <span aria-hidden="true">{e.ok ? '✔' : '✘'}</span> <span className="gb-sr">{e.ok ? t('true') : t('false')}</span> {e.children ? (e.label === 'and' ? t('All of') : t('Any of')) : e.label}
          {e.children && <ExplainTree items={e.children} />}
        </li>
      ))}
    </ul>
  );
}
