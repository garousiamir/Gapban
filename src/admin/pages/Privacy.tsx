import { t } from '../lib';
import { Button, Card, Notice, PageHeader, Select, Spinner, TextInput, Toggle } from '../ui';
import { SaveBar, useSettings } from '../settings';

export default function Privacy() {
  const { data, draft, set, save, saving, dirty, error } = useSettings();
  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Spinner />;
  const fields = draft.prechat_fields || {};
  const ctx: string[] = draft.visitor_context_fields || [];
  const toggleCtx = (k: string, on: boolean) => set('visitor_context_fields', on ? [...ctx, k] : ctx.filter((x) => x !== k));
  return (
    <div>
      <PageHeader title={t('Privacy & data retention')} intro={t('Decide what visitors are asked, what is shared with Bale, and how long conversations are kept.')} />
      <Notice>{t('Conversation text and files that are routed to Bale are transmitted to Bale’s servers and processed under Bale’s terms. Deleting a conversation here does not delete copies already delivered to Bale. Gapban sends no telemetry and shares no visitor data with anyone else.')}</Notice>
      <Card title={t('Pre-chat form')}>
        <Toggle label={t('Ask for details before the chat starts')} checked={!!draft.prechat_enabled} onChange={(v) => set('prechat_enabled', v)} />
        <div className="gb-grid-2 gb-compact">
          {(['name', 'email', 'phone', 'department'] as const).map((k) => (
            <Select
              key={k}
              label={{ name: t('Name'), email: t('Email'), phone: t('Mobile number'), department: t('Department') }[k]}
              value={fields[k] || 'hidden'}
              onChange={(v) => set('prechat_fields', { ...fields, [k]: v })}
              options={[
                { value: 'hidden', label: t('Do not ask') },
                { value: 'optional', label: t('Optional') },
                { value: 'required', label: t('Required') },
              ]}
            />
          ))}
        </div>
        <p className="gb-hint">{t('Mobile numbers are normalised (09… / +98…) for display only; they are not verified.')}</p>
        <h3 className="gb-subtitle">{t('Custom fields')}</h3>
        {(draft.custom_fields || []).map((f: any, i: number) => (
          <div key={i} className="gb-inline-form">
            <TextInput label={t('Key')} dir="ltr" value={f.key} onChange={(v) => set('custom_fields', draft.custom_fields.map((x: any, j: number) => (j === i ? { ...x, key: v } : x)))} />
            <TextInput label={t('Label')} value={f.label} onChange={(v) => set('custom_fields', draft.custom_fields.map((x: any, j: number) => (j === i ? { ...x, label: v } : x)))} />
            <Select label={t('Type')} value={f.type} onChange={(v) => set('custom_fields', draft.custom_fields.map((x: any, j: number) => (j === i ? { ...x, type: v } : x)))} options={[{ value: 'text', label: t('Text') }, { value: 'number', label: t('Number') }, { value: 'url', label: t('Link') }]} />
            <Toggle label={t('Required')} checked={!!f.required} onChange={(v) => set('custom_fields', draft.custom_fields.map((x: any, j: number) => (j === i ? { ...x, required: v } : x)))} />
            <Button small kind="ghost" icon="trash" onClick={() => set('custom_fields', draft.custom_fields.filter((_: unknown, j: number) => j !== i))}>
              {t('Remove')}
            </Button>
          </div>
        ))}
        <Button small kind="ghost" icon="plus" onClick={() => set('custom_fields', [...(draft.custom_fields || []), { key: 'field' + ((draft.custom_fields || []).length + 1), label: '', type: 'text', required: false }])}>
          {t('Add field')}
        </Button>
        <Toggle label={t('Require consent before chatting')} checked={!!draft.consent_required} onChange={(v) => set('consent_required', v)} />
        <TextInput multiline label={t('Consent text')} value={draft.consent_text} onChange={(v) => set('consent_text', v)} />
      </Card>
      <Card title={t('What is sent to Bale')}>
        <p className="gb-hint">{t('Operators in Bale see the conversation reference and the message text. Choose what else is included with the first message.')}</p>
        {[
          ['name', t('Visitor name')],
          ['email', t('Email')],
          ['phone', t('Mobile number')],
          ['department', t('Department')],
          ['page', t('Page the chat started on (sensitive query values removed)')],
          ['custom', t('Custom fields')],
        ].map(([k, label]) => (
          <Toggle key={k} label={label} checked={ctx.includes(k)} onChange={(on) => toggleCtx(k, on)} />
        ))}
        <TextInput multiline label={t('Sensitive URL parameter names')} dir="ltr" value={(draft.sensitive_query_keys || []).join(', ')} onChange={(v) => set('sensitive_query_keys', v.split(/[\s,]+/).filter(Boolean))} help={t('Query parameters containing any of these words are removed from page addresses before operators or rules see them.')} />
        <Toggle label={t('Mirror replies written in WordPress into the Bale group')} checked={!!draft.mirror_admin_replies} onChange={(v) => set('mirror_admin_replies', v)} />
      </Card>
      <Card title={t('Retention')}>
        <TextInput type="number" label={t('Delete closed conversations after (days)')} value={draft.retention_days} onChange={(v) => set('retention_days', Number(v))} help={t('0 keeps conversations until you delete them.')} />
        <TextInput type="number" label={t('Delete attachments after (days)')} value={draft.attachment_retention_days} onChange={(v) => set('attachment_retention_days', Number(v))} help={t('0 keeps files as long as their conversation.')} />
        <TextInput type="number" label={t('Keep raw Bale update logs for (days)')} value={draft.inbound_retention_days} onChange={(v) => set('inbound_retention_days', Number(v))} />
        <TextInput type="number" label={t('Visitor session lifetime (days)')} value={draft.session_ttl_days} onChange={(v) => set('session_ttl_days', Number(v))} />
        <p className="gb-hint">{t('Cleanup runs hourly in the background. Visitors can ask for their data through WordPress’s Tools → Export/Erase Personal Data, which includes Gapban conversations.')}</p>
        <Toggle label={t('Delete all Gapban data when the plugin is uninstalled')} checked={!!draft.delete_data_on_uninstall} onChange={(v) => set('delete_data_on_uninstall', v)} help={t('Off by default. Deactivating the plugin never deletes data.')} />
      </Card>
      <Card title={t('Abuse protection & limits')}>
        <div className="gb-grid-2 gb-compact">
          <TextInput type="number" label={t('Maximum message length (characters)')} value={draft.max_message_length} onChange={(v) => set('max_message_length', Number(v))} />
          <TextInput type="number" label={t('Messages per minute per visitor')} value={draft.messages_per_minute} onChange={(v) => set('messages_per_minute', Number(v))} />
          <TextInput type="number" label={t('New chat sessions per hour per network address')} value={draft.sessions_per_ip_per_hour} onChange={(v) => set('sessions_per_ip_per_hour', Number(v))} />
        </div>
        <Toggle label={t('Hidden spam trap field (honeypot)')} checked={!!draft.honeypot} onChange={(v) => set('honeypot', v)} />
        <TextInput multiline label={t('Trusted reverse proxies (IP or CIDR)')} dir="ltr" value={(draft.trusted_proxies || []).join('\n')} onChange={(v) => set('trusted_proxies', v.split(/[\s,]+/).filter(Boolean))} help={t('Only requests from these addresses may set X-Forwarded-For. Leave empty unless the site is behind a CDN or proxy you control.')} />
        <p className="gb-hint">{t('A CAPTCHA service is not built in, to avoid sending visitor data to third parties.')}</p>
      </Card>
      <Card title={t('Delivery reliability')}>
        <Select
          label={t('When Bale’s answer is unclear (timeout)')}
          value={draft.uncertain_send_policy}
          onChange={(v) => set('uncertain_send_policy', v)}
          options={[
            { value: 'retry', label: t('Retry automatically (a message may appear twice in Bale)') },
            { value: 'hold', label: t('Hold for manual review (no duplicates, may need action)') },
          ]}
          help={t('Bale does not offer idempotent sending, so after a timeout Gapban cannot know whether a message arrived.')}
        />
        <TextInput type="number" label={t('Maximum characters per Bale message')} value={draft.max_text_chunk} onChange={(v) => set('max_text_chunk', Number(v))} help={t('Longer messages are split into numbered parts; every part can be replied to.')} />
      </Card>
      <SaveBar dirty={dirty} saving={saving} onSave={save} />
    </div>
  );
}
