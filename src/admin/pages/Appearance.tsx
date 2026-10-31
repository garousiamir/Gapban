import { useEffect, useMemo, useRef, useState } from 'react';
import { cfg, t } from '../lib';
import { Card, Field, Notice, PageHeader, Select, Spinner, TextInput, Toggle } from '../ui';
import { SaveBar, useSettings } from '../settings';

/**
 * Launcher & widget appearance with a live preview. The preview runs the real
 * widget bundle inside a sandboxed same-origin iframe with the draft settings.
 */
export default function Appearance() {
  const { data, draft, set, save, saving, dirty, error } = useSettings();
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [dir, setDir] = useState<'rtl' | 'ltr'>(cfg().lang === 'fa' ? 'rtl' : 'ltr');
  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Spinner />;
  const s = draft;
  return (
    <div>
      <PageHeader title={t('Widget appearance & preview')} intro={t('Design the launcher and chat window. The preview uses the real widget with your unsaved settings.')} />
      <div className="gb-split">
        <div>
          <Card title={t('Launcher')}>
            <Select
              label={t('Style')}
              value={s.launcher_variant}
              onChange={(v) => set('launcher_variant', v)}
              options={[
                { value: 'pill', label: t('Pill with label') },
                { value: 'circle', label: t('Circle icon') },
                { value: 'avatar', label: t('Avatar image') },
                { value: 'text', label: t('Text button') },
              ]}
            />
            <TextInput label={t('Label')} value={s.launcher_label} onChange={(v) => set('launcher_label', v)} />
            <Select
              label={t('Icon')}
              value={s.launcher_icon}
              onChange={(v) => set('launcher_icon', v)}
              options={[
                { value: 'comments', label: t('Conversation bubbles') },
                { value: 'comment-dots', label: t('Speech bubble') },
                { value: 'headset', label: t('Headset') },
                { value: 'custom', label: t('Custom SVG') },
              ]}
            />
            {s.launcher_icon === 'custom' && (
              <TextInput multiline rows={4} dir="ltr" label={t('Custom SVG icon')} value={s.launcher_custom_svg} onChange={(v) => set('launcher_custom_svg', v)} help={t('Only simple shapes are kept. Scripts, styles, links and external references are rejected.')} />
            )}
            {s.launcher_variant === 'avatar' && <TextInput label={t('Avatar image URL')} dir="ltr" value={s.avatar_url} onChange={(v) => set('avatar_url', v)} />}
            <Select
              label={t('Position')}
              value={s.launcher_position}
              onChange={(v) => set('launcher_position', v)}
              options={[
                { value: 'end', label: t('End side (left in Persian, right in English)') },
                { value: 'start', label: t('Start side (right in Persian, left in English)') },
                { value: 'left', label: t('Always left') },
                { value: 'right', label: t('Always right') },
              ]}
            />
            <div className="gb-grid-2 gb-compact">
              <TextInput type="number" label={t('Desktop side offset (px)')} value={s.launcher_offset_x} onChange={(v) => set('launcher_offset_x', Number(v))} />
              <TextInput type="number" label={t('Desktop bottom offset (px)')} value={s.launcher_offset_y} onChange={(v) => set('launcher_offset_y', Number(v))} />
              <TextInput type="number" label={t('Mobile side offset (px)')} value={s.launcher_offset_x_mobile} onChange={(v) => set('launcher_offset_x_mobile', Number(v))} />
              <TextInput type="number" label={t('Mobile bottom offset (px)')} value={s.launcher_offset_y_mobile} onChange={(v) => set('launcher_offset_y_mobile', Number(v))} />
              <TextInput type="number" label={t('Desktop size (px)')} value={s.launcher_size} onChange={(v) => set('launcher_size', Number(v))} />
              <TextInput type="number" label={t('Mobile size (px)')} value={s.launcher_size_mobile} onChange={(v) => set('launcher_size_mobile', Number(v))} help={t('At least 44px for comfortable tapping.')} />
            </div>
            <TextInput label={t('Elements to avoid (CSS selectors)')} dir="ltr" value={s.launcher_avoid_selectors} onChange={(v) => set('launcher_avoid_selectors', v)} help={t('E.g. #cookie-notice, .sticky-add-to-cart. The launcher also detects fixed bars at the bottom automatically and moves up.')} />
            <Toggle label={t('Hide on the checkout page')} checked={!!s.launcher_hide_on_checkout} onChange={(v) => set('launcher_hide_on_checkout', v)} help={t('Keeps purchase buttons uncovered. Display rules can still show it.')} />
            <TextInput type="number" label={t('Stacking order (z-index)')} value={s.z_index} onChange={(v) => set('z_index', Number(v))} />
          </Card>
          <Card title={t('Motion & attention')}>
            <Select label={t('Animation')} value={s.launcher_animation} onChange={(v) => set('launcher_animation', v)} options={[{ value: 'subtle', label: t('Subtle (as in the design reference)') }, { value: 'none', label: t('None') }]} help={t('Visitors who ask their system for reduced motion never see animation.')} />
            <Toggle label={t('Gentle floating effect on the launcher')} checked={!!s.launcher_attention} onChange={(v) => set('launcher_attention', v)} />
            <TextInput label={t('Greeting bubble text (optional)')} value={s.launcher_teaser} onChange={(v) => set('launcher_teaser', v)} help={t('Shown once next to the launcher until the visitor dismisses it.')} />
          </Card>
          <Card title={t('Colors & texts')}>
            <div className="gb-grid-2 gb-compact">
              <ColorInput label={t('Brand color')} value={s.brand_color} onChange={(v) => set('brand_color', v)} />
              <ColorInput label={t('Brand color (hover)')} value={s.brand_color_dark} onChange={(v) => set('brand_color_dark', v)} />
            </div>
            <TextInput label={t('Brand name')} value={s.brand_name} onChange={(v) => set('brand_name', v)} />
            <TextInput label={t('Welcome title')} value={s.welcome_title} onChange={(v) => set('welcome_title', v)} />
            <TextInput label={t('Welcome subtitle')} value={s.welcome_subtitle} onChange={(v) => set('welcome_subtitle', v)} />
            <TextInput multiline label={t('Automated greeting (optional)')} value={s.automated_greeting} onChange={(v) => set('automated_greeting', v)} help={t('Posted at the start of each conversation and clearly labelled as automated. It does not count as a human reply.')} />
            <TextInput multiline label={t('Queue message')} value={s.queue_message} onChange={(v) => set('queue_message', v)} />
            <TextInput multiline label={t('Away message')} value={s.away_message} onChange={(v) => set('away_message', v)} />
            <TextInput multiline label={t('Offline message')} value={s.offline_message} onChange={(v) => set('offline_message', v)} />
          </Card>
          <Card title={t('Language & formats')}>
            <Select
              label={t('Widget language')}
              value={s.widget_language}
              onChange={(v) => set('widget_language', v)}
              options={[
                { value: 'fa', label: t('Persian (right-to-left)') },
                { value: 'en', label: t('English (left-to-right)') },
                { value: 'site', label: t('Same as the site language') },
              ]}
            />
            <Toggle label={t('Show Persian digits')} checked={!!s.persian_digits} onChange={(v) => set('persian_digits', v)} help={t('Display only; stored values always use standard digits.')} />
            <Toggle label={t('Show dates in the Jalali (Solar Hijri) calendar')} checked={!!s.jalali_dates} onChange={(v) => set('jalali_dates', v)} help={t('Uses the browser’s built-in Persian calendar. Exports keep UTC timestamps.')} />
          </Card>
          <Card title={t('Chat features')}>
            <Toggle label={t('Allow file attachments')} checked={!!s.allow_attachments} onChange={(v) => set('allow_attachments', v)} />
            <TextInput type="number" label={t('Maximum file size (MB)')} value={s.max_upload_mb} onChange={(v) => set('max_upload_mb', Number(v))} />
            <fieldset className="gb-fieldset">
              <legend>{t('Allowed file types')}</legend>
              {data.upload_types
                .filter((m) => !m.startsWith('audio/') && m !== 'video/webm')
                .map((m) => (
                  <Toggle key={m} label={<bdi dir="ltr">{m}</bdi>} checked={(s.allowed_upload_types || []).includes(m)} onChange={(on) => set('allowed_upload_types', on ? [...s.allowed_upload_types, m] : s.allowed_upload_types.filter((x: string) => x !== m))} />
                ))}
              <p className="gb-hint">{t('SVG, HTML and executable files are never accepted.')}</p>
            </fieldset>
            <Toggle label={t('Voice messages')} checked={!!s.allow_voice} onChange={(v) => set('allow_voice', v)} help={t('Recorded only after the visitor allows the microphone. Delivered to Bale as an audio file, not as a native Bale voice note.')} />
            <Toggle label={t('Satisfaction rating after a conversation')} checked={!!s.csat_enabled} onChange={(v) => set('csat_enabled', v)} />
            <Toggle label={t('Let visitors download a transcript')} checked={!!s.transcript_enabled} onChange={(v) => set('transcript_enabled', v)} />
            <Toggle label={t('Visitors can reopen a closed conversation by writing again')} checked={!!s.allow_reopen} onChange={(v) => set('allow_reopen', v)} />
            <Toggle label={t('Sound on new replies (default)')} checked={!!s.sound_default} onChange={(v) => set('sound_default', v)} />
            <Toggle label={t('Widget enabled')} checked={!!s.widget_enabled} onChange={(v) => set('widget_enabled', v)} />
          </Card>
        </div>
        <div className="gb-sticky">
          <Card
            title={t('Live preview')}
            actions={
              <div className="gb-tabs" role="group" aria-label={t('Preview options')}>
                <button type="button" className="gb-tab" aria-pressed={device === 'desktop'} onClick={() => setDevice('desktop')}>
                  {t('Desktop')}
                </button>
                <button type="button" className="gb-tab" aria-pressed={device === 'mobile'} onClick={() => setDevice('mobile')}>
                  {t('Mobile')}
                </button>
                <button type="button" className="gb-tab" aria-pressed={dir === 'rtl'} onClick={() => setDir('rtl')}>
                  {t('Persian')}
                </button>
                <button type="button" className="gb-tab" aria-pressed={dir === 'ltr'} onClick={() => setDir('ltr')}>
                  {t('English')}
                </button>
              </div>
            }
          >
            <Preview settings={s} device={device} dir={dir} />
            <p className="gb-hint">{t('The preview does not create conversations. Click the launcher to see the chat window.')}</p>
          </Card>
        </div>
      </div>
      <SaveBar dirty={dirty} saving={saving} onSave={save} />
    </div>
  );
}

function ColorInput({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <Field label={label}>
      {(id) => (
        <div className="gb-color">
          <input id={id} type="color" value={value} onChange={(e) => onChange(e.target.value)} />
          <input className="gb-input" dir="ltr" value={value} onChange={(e) => onChange(e.target.value)} aria-label={label} />
        </div>
      )}
    </Field>
  );
}

function Preview({ settings: s, device, dir }: { settings: Record<string, any>; device: 'desktop' | 'mobile'; dir: 'rtl' | 'ltr' }) {
  const ref = useRef<HTMLIFrameElement>(null);
  const c = cfg();
  const lang = dir === 'rtl' ? 'fa' : 'en';
  const config = useMemo(
    () => ({
      v: 'preview',
      rest: c.rest + 'preview-disabled/',
      assets: { chat: c.chatUrl, font: c.fontUrl },
      lang,
      dir,
      tz: c.tz,
      persianDigits: !!s.persian_digits,
      jalali: !!s.jalali_dates,
      brand: { name: s.brand_name, color: s.brand_color, colorDark: s.brand_color_dark, ink: s.ink_color, avatar: s.avatar_url },
      launcher: {
        variant: s.launcher_variant,
        label: s.launcher_label,
        position: s.launcher_position,
        offsetX: s.launcher_offset_x,
        offsetY: s.launcher_offset_y,
        offsetXMobile: s.launcher_offset_x_mobile,
        offsetYMobile: s.launcher_offset_y_mobile,
        size: s.launcher_size,
        sizeMobile: s.launcher_size_mobile,
        icon: s.launcher_icon === 'custom' ? 'comments' : s.launcher_icon,
        customSvg: '',
        animation: s.launcher_animation,
        attention: !!s.launcher_attention,
        teaser: s.launcher_teaser,
        avoid: '',
        hidden: false,
        zIndex: 70,
      },
      texts: { welcome_title: s.welcome_title, welcome_subtitle: s.welcome_subtitle, offline_message: s.offline_message, away_message: s.away_message, queue_message: s.queue_message, consent_text: s.consent_text },
      prechat: { enabled: !!s.prechat_enabled, fields: s.prechat_fields, custom: s.custom_fields || [], consentRequired: !!s.consent_required },
      departments: [],
      features: { attachments: !!s.allow_attachments, voice: !!s.allow_voice, csat: !!s.csat_enabled, transcript: !!s.transcript_enabled, maxUploadMb: s.max_upload_mb, allowedTypes: s.allowed_upload_types, honeypot: false, sound: false, reopen: true, maxLength: s.max_message_length },
      faq: [],
      suggestions: s.suggested_questions || [],
      rules: [],
      page: { id: 0, post_type: '', template: '', kinds: ['front'], terms: {}, wc: { pages: [], product_cats: [] } },
      sensitive: [],
      hours: { enabled: false, schedule: {}, holidays: [] },
      strings: c.widgetStrings[lang],
    }),
    [s, dir, lang, c],
  );
  useEffect(() => {
    const doc = `<!doctype html><html lang="${lang}" dir="${dir}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font-family:Tahoma,sans-serif;background:#f7f7f8;color:#6b7280;min-height:100vh}main{padding:24px;max-width:640px;margin:auto;line-height:2}.bar{height:14px;border-radius:8px;background:#e5e7eb;margin:12px 0}</style></head><body><main><div class="bar" style="width:60%"></div><div class="bar"></div><div class="bar" style="width:80%"></div></main><script>window.gapbanConfig=${JSON.stringify(config).replace(/</g, '\\u003c')};</script><script src="${c.loaderUrl}"></script></body></html>`;
    if (ref.current) ref.current.srcdoc = doc;
  }, [config, dir, lang, c.loaderUrl]);
  return (
    <div className={'gb-preview gb-preview-' + device}>
      <iframe ref={ref} title={t('Widget preview')} sandbox="allow-scripts allow-same-origin" />
    </div>
  );
}
