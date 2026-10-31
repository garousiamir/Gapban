/**
 * Gapban chat panel (lazy chunk). Plain DOM, no framework: everything visitor-
 * provided is rendered with textContent / text nodes, never innerHTML.
 */
import chatCss from './chat.css';
import { iconSvg } from '../shared/icons-chat';
import { dayKey, formatBytes, formatDay, formatTime, normalizeDigits, randomKey, renderRichText, type DisplayPrefs } from '../shared/format';
import * as storage from './storage';
import type { ApiError } from './api';
import type { Availability, ConversationSummary, Host, VisitorMessage } from './types';

const EMOJI = ['😀', '😊', '😂', '🙂', '😉', '😍', '🤔', '😅', '😢', '😮', '😡', '👍', '👎', '🙏', '👏', '💪', '❤️', '🌹', '🎉', '✅', '❌', '⭐', '🔥', '💡', '📦', '🛒', '💳', '📞', '📧', '⏰', '📍', '🇮🇷'];
const DRAFT_TTL = 24 * 3600_000;

type Pending = { id: string; file: File; progress: number; status: 'uploading' | 'done' | 'failed'; attachmentId?: string; abort?: () => void; preview?: string; voice: boolean };

function h<K extends keyof HTMLElementTagNameMap>(tag: K, attrs: Record<string, string | boolean | number | null | undefined> = {}, ...children: Array<Node | string | null | undefined | false>): HTMLElementTagNameMap[K] {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = String(v);
    else if (k === 'text') el.textContent = String(v);
    else if (k === 'html') el.innerHTML = String(v); // Only used with trusted icon SVG strings.
    else el.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of children) if (c !== null && c !== undefined && c !== false) el.append(c);
  return el;
}

function iconButton(icon: string, label: string, extra: Record<string, string> = {}): HTMLButtonElement {
  return h('button', { type: 'button', class: 'gb-icon-btn ' + (extra.class || ''), 'aria-label': label, title: label, html: iconSvg(icon), ...Object.fromEntries(Object.entries(extra).filter(([k]) => k !== 'class')) });
}

export function mount(host: Host) {
  const { config, api, t } = host;
  const prefs: DisplayPrefs = { locale: config.lang, persianDigits: config.persianDigits, jalali: config.jalali, timeZone: config.tz };
  const fa = (s: string) => (config.lang === 'fa' && config.persianDigits ? s.replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]) : s);

  const style = document.createElement('style');
  style.textContent = chatCss;
  host.root.appendChild(style);

  /* --------------------------------- state ---------------------------------- */
  let conversation: ConversationSummary | null = host.state.bootstrap?.conversation ?? null;
  let availability: Availability = host.state.bootstrap?.availability ?? { state: 'away', reason: '', next_open: '' };
  const messages = new Map<string, VisitorMessage>(); // key: public_id or client_key for local
  let lastId = 0;
  let oldestId = 0;
  let hasMore = false;
  let view: 'home' | 'prechat' | 'chat' = conversation ? 'chat' : 'home';
  let visible = false;
  let expanded = storage.get<boolean>('expanded', false);
  let sound = storage.get<boolean>('sound', config.features.sound);
  let pollTimer: number | null = null;
  let pollFailures = 0;
  let lastActivity = Date.now();
  let polling = false;
  let pending: Pending[] = [];
  let typingSentAt = 0;
  let typingActive = false;
  let readSent = 0;
  let operatorTyping = false;
  let ratingDone = conversation?.rated ?? false;
  let localAutomated: { text: string; ruleId: number } | null = host.state.pendingAutomated;
  let recorder: MediaRecorder | null = null;
  let recordChunks: Blob[] = [];
  let lastFocused: Element | null = null;

  /* --------------------------------- shell ---------------------------------- */
  const titleId = 'gb-title-' + randomKey(6);
  const panel = h('section', { class: 'gb-panel', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': titleId, 'data-expanded': String(expanded), hidden: true });
  const avatar = h('div', { class: 'gb-head-avatar' });
  if (config.brand.avatar) avatar.append(h('img', { src: config.brand.avatar, alt: '' }));
  else avatar.innerHTML = iconSvg('headset');
  const headPresence = h('span', { class: 'gb-presence', 'aria-hidden': 'true' });
  avatar.append(headPresence);
  const title = h('h2', { class: 'gb-title', id: titleId, text: config.brand.name });
  const status = h('p', { class: 'gb-status' });
  const expandBtn = iconButton(expanded ? 'compress' : 'expand', expanded ? t('collapse') : t('expand'), { class: 'gb-expand' });
  const menuBtn = iconButton('menu', t('more_options'), { 'aria-haspopup': 'menu', 'aria-expanded': 'false' });
  const closeBtn = iconButton('minus', t('minimize'));
  const head = h('header', { class: 'gb-head' }, avatar, h('div', { class: 'gb-head-text' }, title, status), h('div', { class: 'gb-head-actions' }, expandBtn, menuBtn, closeBtn));
  const banner = h('div', { class: 'gb-banner', hidden: true, role: 'status' });
  const body = h('div', { class: 'gb-body', tabindex: '-1' });
  const live = h('div', { class: 'gb-sr', 'aria-live': 'polite', 'aria-atomic': 'false' });
  const composer = h('div', { class: 'gb-composer', hidden: true });
  const footer = h('div', { class: 'gb-footer-note' });
  panel.append(head, banner, body, composer, footer, live);
  host.stack.insertBefore(panel, host.launcher);

  /* ------------------------------ header logic ------------------------------ */
  function renderStatus(): void {
    headPresence.dataset.state = availability.state;
    const map: Record<string, string> = {
      online: t('status_online'),
      away: availability.reason === 'temporary_away' ? t('status_temporary_away') : t('status_away'),
      offline: availability.reason === 'holiday' ? t('status_holiday') : t('status_offline'),
    };
    status.textContent = operatorTyping ? t('operator_typing') : map[availability.state] || '';
    const greeting = host.state.greeting;
    title.textContent = conversation ? config.brand.name : greeting?.title || config.texts.welcome_title || config.brand.name;
    if (availability.state !== 'online' && view === 'chat') {
      banner.hidden = false;
      banner.dataset.tone = 'warn';
      banner.textContent = availability.state === 'offline' ? config.texts.offline_message : config.texts.away_message;
    } else {
      banner.hidden = true;
    }
    footer.textContent = conversation ? `${t('reference')}: ${conversation.reference}` : t('powered_by_brand');
  }

  expandBtn.addEventListener('click', () => {
    expanded = !expanded;
    storage.set('expanded', expanded);
    panel.dataset.expanded = String(expanded);
    expandBtn.setAttribute('aria-label', expanded ? t('collapse') : t('expand'));
    expandBtn.innerHTML = iconSvg(expanded ? 'compress' : 'expand');
  });
  closeBtn.addEventListener('click', () => host.setOpen(false));
  panel.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (menuEl) return closeMenu();
      if (emojiEl) return closeEmoji();
      host.setOpen(false);
    }
    if (e.key === 'Tab' && isFullscreen()) trapFocus(e);
  });

  function isFullscreen(): boolean {
    return window.innerWidth <= 640;
  }
  function trapFocus(e: KeyboardEvent): void {
    const items = Array.from(panel.querySelectorAll<HTMLElement>('button, [href], input, textarea, select, [tabindex]:not([tabindex="-1"])')).filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    const active = host.root.activeElement;
    if (e.shiftKey && active === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && active === last) {
      e.preventDefault();
      first.focus();
    }
  }

  /* ---------------------------------- menu ---------------------------------- */
  let menuEl: HTMLElement | null = null;
  function closeMenu(): void {
    menuEl?.remove();
    menuEl = null;
    menuBtn.setAttribute('aria-expanded', 'false');
    menuBtn.focus();
  }
  menuBtn.addEventListener('click', () => {
    if (menuEl) return closeMenu();
    const item = (icon: string, label: string, fn: () => void) => {
      const b = h('button', { type: 'button', class: 'gb-menu-item', role: 'menuitem', html: iconSvg(icon) }, label);
      b.addEventListener('click', () => {
        closeMenu();
        fn();
      });
      return b;
    };
    menuEl = h('div', { class: 'gb-popover gb-menu', role: 'menu' });
    menuEl.append(item(sound ? 'mute' : 'volume', sound ? t('sound_off') : t('sound_on'), () => {
      sound = !sound;
      storage.set('sound', sound);
    }));
    if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
      menuEl.append(item('bell', t('enable_notifications'), () => {
        Notification.requestPermission().catch(() => undefined);
      }));
    }
    if (conversation && config.features.transcript) menuEl.append(item('download', t('download_transcript'), downloadTranscript));
    if (conversation && conversation.status !== 'closed') menuEl.append(item('leave', t('end_chat'), endChat));
    panel.append(menuEl);
    menuBtn.setAttribute('aria-expanded', 'true');
    (menuEl.querySelector('button') as HTMLButtonElement | null)?.focus();
  });

  /* ---------------------------------- views --------------------------------- */
  function render(): void {
    body.replaceChildren();
    composer.hidden = view !== 'chat' || !conversation;
    renderStatus();
    if (view === 'home') renderHome();
    else if (view === 'prechat') renderPrechat();
    else renderChat();
  }

  function renderHome(): void {
    const greeting = host.state.greeting;
    body.append(
      h('div', { class: 'gb-hero' }, h('h2', { text: greeting?.title || config.texts.welcome_title }), h('p', { text: greeting?.subtitle || config.texts.welcome_subtitle })),
    );
    if (localAutomated) body.append(automatedBubble(localAutomated.text));
    if (availability.state !== 'online') body.append(h('div', { class: 'gb-banner', 'data-tone': 'warn', text: availability.state === 'offline' ? config.texts.offline_message : config.texts.away_message }));
    const start = h('button', { type: 'button', class: 'gb-btn gb-block', html: iconSvg('paper-plane') }, availability.state === 'online' ? t('start_chat') : t('leave_message'));
    start.addEventListener('click', () => {
      view = config.prechat.enabled || config.prechat.consentRequired || availability.state !== 'online' ? 'prechat' : 'chat';
      if (view === 'chat') startConversation({});
      else render();
    });
    body.append(h('div', { style: 'margin-block:12px 4px' }, start));
    if (config.suggestions.length) {
      body.append(h('p', { class: 'gb-section-title', text: t('suggested_questions') }));
      const chips = h('div', { class: 'gb-chips' });
      for (const q of config.suggestions) {
        const c = h('button', { type: 'button', class: 'gb-chip', text: q });
        c.addEventListener('click', () => {
          storage.set('draft', { text: q, at: Date.now() });
          start.click();
        });
        chips.append(c);
      }
      body.append(chips);
    }
    if (config.faq.length) {
      body.append(h('p', { class: 'gb-section-title', text: t('faq_title') }));
      const cards = h('div', { class: 'gb-cards' });
      for (const f of config.faq) {
        const card = h('button', { type: 'button', class: 'gb-card', 'aria-expanded': 'false' }, h('strong', { text: f.title }), h('span', { text: f.body }));
        card.addEventListener('click', () => card.setAttribute('aria-expanded', card.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'));
        cards.append(card);
        if (f.url) {
          const a = h('a', { class: 'gb-link', href: f.url, target: '_blank', rel: 'noopener', text: t('read_more') });
          cards.append(a);
        }
      }
      body.append(cards);
      const human = h('button', { type: 'button', class: 'gb-btn gb-ghost-btn gb-block', style: 'margin-block-start:12px' }, t('talk_to_human'));
      human.addEventListener('click', () => start.click());
      body.append(human);
    }
  }

  function automatedBubble(text: string): HTMLElement {
    return h(
      'div',
      { class: 'gb-msg', 'data-author': 'automation', 'data-group': 'start' },
      h('div', { class: 'gb-author' }, h('span', { class: 'gb-auto-badge', html: iconSvg('robot') }, t('automated_message'))),
      h('div', { class: 'gb-bubble', dir: 'auto' }, text),
    );
  }

  function renderPrechat(): void {
    const offline = availability.state !== 'online';
    const form = h('form', { class: 'gb-form', novalidate: true });
    const profile = host.state.bootstrap?.profile;
    const errors: Record<string, HTMLElement> = {};
    const field = (name: string, label: string, type: string, mode: string, value = '', autocomplete = '') => {
      if (mode === 'hidden') return null;
      const id = 'gb-f-' + name.replace(/\W/g, '') + randomKey(4);
      const required = mode === 'required';
      const input = h('input', { class: 'gb-input', id, name, type, value, autocomplete, required, 'aria-required': String(required), inputmode: type === 'tel' ? 'tel' : null, dir: type === 'email' || type === 'tel' || type === 'url' ? 'ltr' : 'auto' });
      const err = h('div', { class: 'gb-error', id: id + '-err', hidden: true });
      input.setAttribute('aria-describedby', id + '-err');
      errors[name] = err;
      return h('div', { class: 'gb-field' }, h('label', { for: id }, label, required ? h('span', { class: 'gb-req', 'aria-hidden': 'true', text: ' *' }) : ''), input, err);
    };
    const f = config.prechat.fields;
    form.append(
      h('div', { class: 'gb-hero' }, h('h2', { text: offline ? t('offline_form_title') : t('prechat_title') }), h('p', { text: offline ? config.texts.offline_message : t('prechat_intro') })),
    );
    const nodes = [
      field('name', t('field_name'), 'text', config.prechat.enabled ? f.name : 'hidden', profile?.name || '', 'name'),
      // When offline an e-mail is needed to reply later.
      field('email', t('field_email'), 'email', offline && f.email !== 'required' ? 'required' : config.prechat.enabled ? f.email : 'hidden', profile?.email || '', 'email'),
      field('phone', t('field_phone'), 'tel', config.prechat.enabled ? f.phone : 'hidden', profile?.phone || '', 'tel'),
    ];
    nodes.forEach((n) => n && form.append(n));
    if (config.prechat.enabled && f.department !== 'hidden' && config.departments.length > 1) {
      const id = 'gb-dept-' + randomKey(4);
      const sel = h('select', { class: 'gb-input', id, name: 'department', required: f.department === 'required' });
      sel.append(h('option', { value: '', text: t('choose_department') }));
      for (const d of config.departments) sel.append(h('option', { value: String(d.id), text: d.name, selected: host.state.department === d.id }));
      errors.department = h('div', { class: 'gb-error', hidden: true });
      form.append(h('div', { class: 'gb-field' }, h('label', { for: id }, t('field_department')), sel, errors.department));
    }
    if (config.prechat.enabled) {
      for (const c of config.prechat.custom) {
        const n = field('custom.' + c.key, c.label, c.type === 'number' ? 'text' : c.type, c.required ? 'required' : 'optional');
        if (n) form.append(n);
      }
    }
    if (config.prechat.consentRequired) {
      const id = 'gb-consent-' + randomKey(4);
      errors.consent = h('div', { class: 'gb-error', hidden: true });
      form.append(h('div', {}, h('label', { class: 'gb-check', for: id }, h('input', { type: 'checkbox', id, name: 'consent', required: true }), h('span', { text: config.texts.consent_text })), errors.consent));
    }
    if (config.features.honeypot) {
      form.append(h('div', { class: 'gb-hp', 'aria-hidden': 'true' }, h('label', {}, 'Website', h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' }))));
    }
    const submit = h('button', { type: 'submit', class: 'gb-btn gb-block' }, offline ? t('continue') : t('start_chat'));
    const back = h('button', { type: 'button', class: 'gb-btn gb-ghost-btn gb-block' }, t('back'));
    back.addEventListener('click', () => {
      view = 'home';
      render();
    });
    form.append(submit, back);
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      Object.values(errors).forEach((el) => (el.hidden = true));
      form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
      const fd = new FormData(form);
      const data: Record<string, unknown> = { custom: {} as Record<string, string> };
      let firstInvalid: HTMLElement | null = null;
      fd.forEach((v, k) => {
        const value = String(v).trim();
        if (k.startsWith('custom.')) (data.custom as Record<string, string>)[k.slice(7)] = normalizeDigits(value);
        else if (k === 'phone') data.phone = normalizeDigits(value);
        else data[k] = value;
      });
      // Client-side required check for fast feedback (server re-validates).
      form.querySelectorAll<HTMLInputElement>('[required]').forEach((input) => {
        const empty = input.type === 'checkbox' ? !input.checked : !input.value.trim();
        if (empty) {
          input.setAttribute('aria-invalid', 'true');
          const err = errors[input.name];
          if (err) {
            err.textContent = t('field_required');
            err.hidden = false;
          }
          firstInvalid = firstInvalid || input;
        }
      });
      if (firstInvalid) {
        (firstInvalid as HTMLElement).focus();
        return;
      }
      data.consent = fd.get('consent') === 'on';
      submit.disabled = true;
      try {
        await startConversation(data, String(fd.get('website') || ''));
      } catch (err) {
        const e2 = err as ApiError;
        const fields = (e2.data && e2.data.fields) || {};
        let focused = false;
        for (const [name, msg] of Object.entries(fields)) {
          const el = errors[name];
          const input = form.querySelector<HTMLElement>(`[name="${CSS.escape(name)}"]`);
          if (el) {
            el.textContent = String(msg);
            el.hidden = false;
          }
          if (input) {
            input.setAttribute('aria-invalid', 'true');
            if (!focused) {
              input.focus();
              focused = true;
            }
          }
        }
        if (!Object.keys(fields).length) showError(e2);
      } finally {
        submit.disabled = false;
      }
    });
    body.append(form);
    (form.querySelector('input:not([type=hidden]), select') as HTMLElement | null)?.focus();
  }

  async function startConversation(data: Record<string, unknown>, honeypot = ''): Promise<void> {
    await api.ensureSession(honeypot);
    const res = await api.request<{ conversation: ConversationSummary }>('POST', 'conversation', {
      ...data,
      department: data.department || host.state.department || '',
      page_url: location.href,
      page_title: document.title.slice(0, 190),
      locale: config.lang,
      campaign_rule_id: host.state.campaignRuleId || '',
    });
    conversation = res.conversation;
    localAutomated = null;
    host.state.pendingAutomated = null;
    view = 'chat';
    host.onVisitorActivity('opened');
    render();
    await poll(true);
    textarea.focus();
  }

  /* ---------------------------------- chat ---------------------------------- */
  const list = h('ol', { class: 'gb-list', 'aria-label': t('messages'), role: 'log', 'aria-live': 'off' });
  const typingEl = h('div', { class: 'gb-typing', 'aria-label': t('operator_typing'), hidden: true }, h('i'), h('i'), h('i'));
  const jumpBtn = h('button', { type: 'button', class: 'gb-jump', hidden: true }, t('new_messages'));
  jumpBtn.addEventListener('click', () => scrollToBottom(true));
  const loadMoreBtn = h('button', { type: 'button', class: 'gb-btn gb-ghost-btn gb-load-more', hidden: true }, t('load_older'));
  loadMoreBtn.addEventListener('click', loadOlder);

  function renderChat(): void {
    body.append(loadMoreBtn, list, typingEl, jumpBtn);
    renderMessages();
    renderRating();
    scrollToBottom(false);
  }

  function nearBottom(): boolean {
    return body.scrollHeight - body.scrollTop - body.clientHeight < 80;
  }
  function scrollToBottom(smooth: boolean): void {
    body.scrollTo({ top: body.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    jumpBtn.hidden = true;
  }
  body.addEventListener('scroll', () => {
    if (nearBottom()) {
      jumpBtn.hidden = true;
      markRead();
    }
  });

  function sorted(): VisitorMessage[] {
    return Array.from(messages.values()).sort((a, b) => (a.id || Number.MAX_SAFE_INTEGER) - (b.id || Number.MAX_SAFE_INTEGER) || a.created_at.localeCompare(b.created_at));
  }

  function renderMessages(): void {
    list.replaceChildren();
    let prevDay = '';
    let prevAuthor = '';
    let prevTime = 0;
    for (const m of sorted()) {
      const day = dayKey(m.created_at, prefs);
      if (day !== prevDay) {
        list.append(h('li', { class: 'gb-day', text: formatDay(m.created_at, prefs) }));
        prevDay = day;
        prevAuthor = '';
      }
      if (m.kind === 'event') {
        const map: Record<string, string> = { conversation_closed: t('event_closed'), conversation_reopened: t('event_reopened') };
        list.append(h('li', { class: 'gb-event', text: map[m.body] || m.body }));
        prevAuthor = '';
        continue;
      }
      const time = Date.parse(m.created_at);
      const groupStart = m.author !== prevAuthor || time - prevTime > 5 * 60_000;
      prevAuthor = m.author;
      prevTime = time;
      list.append(messageEl(m, groupStart));
    }
    loadMoreBtn.hidden = !hasMore;
  }

  function messageEl(m: VisitorMessage, groupStart: boolean): HTMLElement {
    const li = h('li', { class: 'gb-msg', 'data-author': m.author, 'data-group': groupStart ? 'start' : 'cont' });
    if (groupStart && m.author !== 'visitor') {
      const who = h('div', { class: 'gb-author' });
      if (m.author === 'automation') who.append(h('span', { class: 'gb-auto-badge', html: iconSvg('robot') }, t('automated_message')));
      else who.append(m.author_name || t('support_team'));
      li.append(who);
    }
    if (m.body) {
      const bubble = h('div', { class: 'gb-bubble', dir: 'auto' });
      bubble.append(renderRichText(document, m.body, false));
      li.append(bubble);
    }
    if (m.attachments.length) {
      const atts = h('div', { class: 'gb-atts' });
      for (const a of m.attachments) atts.append(attachmentEl(a));
      li.append(atts);
    }
    const meta = h('div', { class: 'gb-meta' }, fa(formatTime(m.created_at, prefs)));
    if (m.author === 'visitor') {
      if (m.local === 'sending') meta.append(' · ', t('sending'));
      else if (m.local === 'failed') {
        meta.dataset.status = 'failed';
        const retry = h('button', { type: 'button', class: 'gb-retry' }, t('retry'));
        retry.addEventListener('click', () => resend(m));
        meta.append(' · ', t('not_sent'), ' ', retry);
      } else if (m.status) {
        meta.dataset.status = m.status;
        const icon = h('span', { html: iconSvg(m.status === 'accepted' ? 'check' : 'check-double'), 'aria-hidden': 'true' });
        meta.append(icon, h('span', { class: 'gb-sr', text: t('status_' + m.status) }));
        meta.title = t('status_' + m.status);
      }
    }
    li.append(meta);
    return li;
  }

  const blobCache = new Map<string, string>();
  async function blobUrl(id: string): Promise<string> {
    if (blobCache.has(id)) return blobCache.get(id)!;
    const blob = await api.blob('attachments/' + id);
    const url = URL.createObjectURL(blob);
    blobCache.set(id, url);
    return url;
  }

  function attachmentEl(a: VisitorMessage['attachments'][number]): HTMLElement {
    if (a.image) {
      const img = h('img', { class: 'gb-img', alt: a.name, width: a.width || null, height: a.height || null, loading: 'lazy', tabindex: '0', role: 'button' });
      blobUrl(a.id).then((u) => (img.src = u)).catch(() => (img.alt = t('file_unavailable')));
      const open = () => lightbox(img.src, a.name);
      img.addEventListener('click', open);
      img.addEventListener('keydown', (e) => (e.key === 'Enter' || e.key === ' ') && open());
      return img;
    }
    if (a.audio) {
      const audio = h('audio', { class: 'gb-audio', controls: true, preload: 'none' });
      blobUrl(a.id).then((u) => (audio.src = u)).catch(() => undefined);
      return audio;
    }
    const btn = h('button', { type: 'button', class: 'gb-att', html: iconSvg('file') }, h('span', { class: 'gb-att-name', text: a.name }), h('span', { class: 'gb-att-size', text: formatBytes(a.size, prefs) }));
    btn.setAttribute('aria-label', `${t('download')} ${a.name}`);
    btn.addEventListener('click', async () => {
      try {
        const u = await blobUrl(a.id);
        const link = h('a', { href: u, download: a.name });
        host.root.append(link);
        link.click();
        link.remove();
      } catch {
        showBanner(t('file_unavailable'), 'error');
      }
    });
    return btn;
  }

  function lightbox(src: string, alt: string): void {
    if (!src) return;
    lastFocused = host.root.activeElement;
    const close = iconButton('xmark', t('close'));
    const box = h('div', { class: 'gb-lightbox', role: 'dialog', 'aria-modal': 'true', 'aria-label': alt }, h('img', { src, alt }), close);
    const done = () => {
      box.remove();
      (lastFocused as HTMLElement | null)?.focus?.();
    };
    close.addEventListener('click', done);
    box.addEventListener('click', (e) => e.target === box && done());
    box.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        done();
      }
    });
    panel.append(box);
    close.focus();
  }

  function renderRating(): void {
    body.querySelector('.gb-rating')?.remove();
    if (!conversation || conversation.status !== 'closed' || !config.features.csat || ratingDone || !conversation.has_human) return;
    let score = 0;
    const card = h('div', { class: 'gb-rating' }, h('h3', { text: t('rate_title') }));
    const stars = h('div', { class: 'gb-stars', role: 'radiogroup', 'aria-label': t('rate_title') });
    const buttons: HTMLButtonElement[] = [];
    for (let i = 1; i <= 5; i++) {
      const b = h('button', { type: 'button', class: 'gb-star', role: 'radio', 'aria-checked': 'false', 'aria-label': t('rate_n', i), text: '★' });
      b.addEventListener('click', () => {
        score = i;
        buttons.forEach((x, j) => {
          x.setAttribute('aria-checked', String(j + 1 === i));
          x.classList.toggle('gb-on', j < i);
        });
      });
      buttons.push(b);
      stars.append(b);
    }
    const comment = h('textarea', { class: 'gb-input', rows: '2', placeholder: t('rate_comment'), 'aria-label': t('rate_comment'), dir: 'auto' });
    const send = h('button', { type: 'button', class: 'gb-btn gb-block', style: 'margin-block-start:10px' }, t('submit_rating'));
    send.addEventListener('click', async () => {
      if (!score) {
        buttons[0].focus();
        return;
      }
      send.disabled = true;
      try {
        await api.request('POST', 'rating', { score, comment: comment.value });
        ratingDone = true;
        card.replaceChildren(h('h3', { text: t('rate_thanks') }));
        live.textContent = t('rate_thanks');
      } catch (e) {
        showError(e as ApiError);
        send.disabled = false;
      }
    });
    card.append(stars, comment, send);
    const newChat = h('button', { type: 'button', class: 'gb-link', style: 'margin-block-start:10px' }, t('new_conversation'));
    newChat.addEventListener('click', newConversation);
    card.append(h('div', {}, newChat));
    body.append(card);
  }

  function newConversation(): void {
    conversation = null;
    messages.clear();
    lastId = 0;
    view = 'home';
    ratingDone = false;
    render();
  }

  /* -------------------------------- composer -------------------------------- */
  const textarea = h('textarea', { class: 'gb-textarea', rows: '1', placeholder: t('type_message'), 'aria-label': t('type_message'), dir: 'auto', maxlength: String(config.features.maxLength), enterkeyhint: 'send' });
  const sendBtn = h('button', { type: 'button', class: 'gb-send', 'aria-label': t('send'), html: iconSvg('paper-plane') });
  const pendingEl = h('div', { class: 'gb-pending', hidden: true });
  const fileInput = h('input', { type: 'file', hidden: true, multiple: true, accept: config.features.allowedTypes.join(',') });
  const tools = h('div', { class: 'gb-tools' });
  const emojiBtn = iconButton('smile', t('emoji'), { class: 'gb-ghost', 'aria-haspopup': 'true', 'aria-expanded': 'false' });
  tools.append(emojiBtn);
  if (config.features.attachments) {
    const attachBtn = iconButton('paperclip', t('attach_file'), { class: 'gb-ghost' });
    attachBtn.addEventListener('click', () => fileInput.click());
    tools.append(attachBtn);
  }
  let micBtn: HTMLButtonElement | null = null;
  const recordingLabel = h('span', { class: 'gb-recording', hidden: true, role: 'status' }, t('recording'));
  if (config.features.voice && typeof MediaRecorder !== 'undefined' && typeof navigator.mediaDevices?.getUserMedia === 'function') {
    micBtn = iconButton('microphone', t('record_voice'), { class: 'gb-ghost', 'aria-pressed': 'false' });
    micBtn.addEventListener('click', toggleRecording);
    tools.append(micBtn, recordingLabel);
  }
  const counter = h('span', { class: 'gb-hint', 'aria-hidden': 'true' });
  tools.append(counter);
  composer.append(pendingEl, h('div', { class: 'gb-row' }, textarea, sendBtn), tools, fileInput);

  const draft = storage.get<{ text: string; at: number } | null>('draft', null);
  if (draft && Date.now() - draft.at < DRAFT_TTL) textarea.value = draft.text;
  else storage.remove('draft');

  function autosize(): void {
    textarea.style.blockSize = 'auto';
    textarea.style.blockSize = Math.min(140, textarea.scrollHeight + 2) + 'px';
    const len = textarea.value.length;
    counter.textContent = len > config.features.maxLength * 0.8 ? fa(`${len}/${config.features.maxLength}`) : '';
    sendBtn.disabled = !textarea.value.trim() && !pending.some((p) => p.status === 'done');
  }
  textarea.addEventListener('input', () => {
    autosize();
    storage.set('draft', { text: textarea.value, at: Date.now() });
    host.onVisitorActivity('typed');
    lastActivity = Date.now();
    sendTyping(true);
  });
  textarea.addEventListener('keydown', (e) => {
    // Enter sends on desktop; Shift+Enter (and Enter on touch keyboards) adds a line.
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !window.matchMedia('(pointer: coarse)').matches) {
      e.preventDefault();
      send();
    }
  });
  textarea.addEventListener('blur', () => sendTyping(false));
  sendBtn.addEventListener('click', send);
  fileInput.addEventListener('change', () => {
    Array.from(fileInput.files || []).forEach((f) => addFile(f, false));
    fileInput.value = '';
  });
  panel.addEventListener('dragover', (e) => {
    if (config.features.attachments && view === 'chat') e.preventDefault();
  });
  panel.addEventListener('drop', (e) => {
    if (!config.features.attachments || view !== 'chat') return;
    e.preventDefault();
    Array.from(e.dataTransfer?.files || []).forEach((f) => addFile(f, false));
  });
  textarea.addEventListener('paste', (e) => {
    const files = Array.from(e.clipboardData?.files || []);
    if (files.length && config.features.attachments) {
      e.preventDefault();
      files.forEach((f) => addFile(f, false));
    }
  });

  let emojiEl: HTMLElement | null = null;
  function closeEmoji(): void {
    emojiEl?.remove();
    emojiEl = null;
    emojiBtn.setAttribute('aria-expanded', 'false');
    emojiBtn.focus();
  }
  emojiBtn.addEventListener('click', () => {
    if (emojiEl) return closeEmoji();
    emojiEl = h('div', { class: 'gb-popover', role: 'dialog', 'aria-label': t('emoji') });
    const grid = h('div', { class: 'gb-emoji-grid' });
    for (const e of EMOJI) {
      const b = h('button', { type: 'button', class: 'gb-emoji', text: e, 'aria-label': e });
      b.addEventListener('click', () => {
        const start = textarea.selectionStart ?? textarea.value.length;
        textarea.setRangeText(e, start, textarea.selectionEnd ?? start, 'end');
        textarea.dispatchEvent(new Event('input'));
        closeEmoji();
        textarea.focus();
      });
      grid.append(b);
    }
    emojiEl.append(grid);
    composer.style.position = 'relative';
    composer.append(emojiEl);
    emojiBtn.setAttribute('aria-expanded', 'true');
    (grid.firstElementChild as HTMLElement).focus();
  });

  function addFile(file: File, voice: boolean): void {
    const maxBytes = config.features.maxUploadMb * 1024 * 1024;
    if (file.size > maxBytes) {
      showBanner(t('file_too_large', config.features.maxUploadMb), 'error');
      return;
    }
    if (!voice && config.features.allowedTypes.length && file.type && !config.features.allowedTypes.includes(file.type)) {
      showBanner(t('file_type_not_allowed'), 'error');
      return;
    }
    if (pending.length >= 5) return;
    const p: Pending = { id: randomKey(8), file, progress: 0, status: 'uploading', voice };
    if (file.type.startsWith('image/')) p.preview = URL.createObjectURL(file);
    pending.push(p);
    upload(p);
  }

  function upload(p: Pending): void {
    p.status = 'uploading';
    p.progress = 0;
    renderPending();
    api.ensureSession().then(() => {
      const job = api.upload('uploads', p.file, p.file.name || (p.voice ? 'voice.webm' : 'file'), p.voice ? { voice: '1' } : {}, (x) => {
        p.progress = x;
        const bar = pendingEl.querySelector(`[data-id="${p.id}"] .gb-progress > i`) as HTMLElement | null;
        if (bar) bar.style.inlineSize = Math.round(x * 100) + '%';
      });
      p.abort = job.abort;
      job.promise
        .then((res) => {
          p.status = 'done';
          p.attachmentId = res.attachment.id;
          renderPending();
          autosize();
        })
        .catch((e: ApiError) => {
          if (e.code === 'aborted') return;
          p.status = 'failed';
          renderPending();
          showError(e);
        });
    });
  }

  function renderPending(): void {
    pendingEl.replaceChildren();
    pendingEl.hidden = pending.length === 0;
    for (const p of pending) {
      const item = h('div', { class: 'gb-pending-item', 'data-id': p.id });
      if (p.preview) item.append(h('img', { src: p.preview, alt: '' }));
      item.append(h('span', { class: 'gb-att-name', text: p.voice ? t('voice_message') : p.file.name }));
      if (p.status === 'uploading') item.append(h('span', { class: 'gb-progress', role: 'progressbar', 'aria-label': t('uploading') }, h('i', { style: `inline-size:${Math.round(p.progress * 100)}%` })));
      if (p.status === 'failed') {
        const retry = h('button', { type: 'button', class: 'gb-retry' }, t('retry'));
        retry.addEventListener('click', () => upload(p));
        item.append(retry);
      }
      const remove = iconButton('xmark', p.status === 'uploading' ? t('cancel_upload') : t('remove_file'), { class: 'gb-ghost' });
      remove.style.inlineSize = '24px';
      remove.style.blockSize = '24px';
      remove.addEventListener('click', () => {
        p.abort?.();
        if (p.preview) URL.revokeObjectURL(p.preview);
        pending = pending.filter((x) => x !== p);
        renderPending();
        autosize();
      });
      item.append(remove);
      pendingEl.append(item);
    }
  }

  async function toggleRecording(): Promise<void> {
    if (recorder) {
      recorder.stop();
      return;
    }
    let stream: MediaStream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      showBanner(t('mic_denied'), 'warn');
      return;
    }
    const type = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/webm'].find((m) => MediaRecorder.isTypeSupported(m)) || '';
    recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
    recordChunks = [];
    recorder.ondataavailable = (e) => e.data.size && recordChunks.push(e.data);
    recorder.onstop = () => {
      stream.getTracks().forEach((tr) => tr.stop());
      const mime = (recorder?.mimeType || 'audio/webm').split(';')[0];
      const ext = mime.includes('ogg') ? 'ogg' : 'webm';
      const file = new File(recordChunks, `voice-${Date.now()}.${ext}`, { type: mime });
      recorder = null;
      micBtn?.setAttribute('aria-pressed', 'false');
      if (micBtn) micBtn.innerHTML = iconSvg('microphone');
      recordingLabel.hidden = true;
      if (file.size > 0) addFile(file, true);
    };
    recorder.start();
    micBtn?.setAttribute('aria-pressed', 'true');
    if (micBtn) micBtn.innerHTML = iconSvg('stop');
    recordingLabel.hidden = false;
    // Hard cap at 2 minutes.
    window.setTimeout(() => recorder?.state === 'recording' && recorder.stop(), 120_000);
  }

  function sendTyping(on: boolean): void {
    if (!conversation) return;
    const nowTs = Date.now();
    if (on && typingActive && nowTs - typingSentAt < 3000) return;
    if (!on && !typingActive) return;
    typingActive = on;
    typingSentAt = nowTs;
    api.request('POST', 'typing', { typing: on }).catch(() => undefined);
  }

  async function send(): Promise<void> {
    const text = textarea.value.trim();
    const ready = pending.filter((p) => p.status === 'done');
    if (!text && !ready.length) return;
    if (pending.some((p) => p.status === 'uploading')) {
      showBanner(t('wait_for_upload'), 'warn');
      return;
    }
    if (!conversation) await startConversation({});
    const local: VisitorMessage = {
      id: 0,
      public_id: '',
      author: 'visitor',
      author_name: '',
      kind: ready.length && !text ? 'attachment' : 'text',
      body: text,
      created_at: new Date().toISOString(),
      client_key: randomKey(24),
      attachments: ready.map((p) => ({ id: p.attachmentId!, name: p.voice ? t('voice_message') : p.file.name, mime: p.file.type, size: p.file.size, width: 0, height: 0, image: p.file.type.startsWith('image/'), audio: p.voice })),
      local: 'sending',
    };
    messages.set('local:' + local.client_key, local);
    textarea.value = '';
    storage.remove('draft');
    pending.forEach((p) => p.preview && URL.revokeObjectURL(p.preview));
    pending = [];
    renderPending();
    autosize();
    sendTyping(false);
    host.onVisitorActivity('sent');
    lastActivity = Date.now();
    renderMessages();
    scrollToBottom(true);
    await deliver(local);
  }

  async function deliver(local: VisitorMessage): Promise<void> {
    try {
      const res = await api.request<{ message: VisitorMessage; conversation: ConversationSummary }>('POST', 'messages', {
        body: local.body,
        client_key: local.client_key,
        attachments: local.attachments.map((a) => a.id),
      });
      messages.delete('local:' + local.client_key);
      upsert(res.message);
      conversation = res.conversation;
      host.channel.post({ type: 'message', message: res.message, conversation: res.conversation });
      renderMessages();
      renderStatus();
      if (!res.conversation.has_human && availability.state === 'online' && config.texts.queue_message && !storage.get('queue_notice_' + res.conversation.id, false, 'session')) {
        storage.set('queue_notice_' + res.conversation.id, true, 'session');
        showBanner(config.texts.queue_message, 'info');
      }
      schedulePoll(0);
    } catch (e) {
      local.local = 'failed';
      renderMessages();
      showError(e as ApiError);
    }
  }

  function resend(m: VisitorMessage): void {
    m.local = 'sending';
    renderMessages();
    deliver(m);
  }

  /* -------------------------------- polling --------------------------------- */
  function upsert(m: VisitorMessage): boolean {
    if (m.client_key && messages.has('local:' + m.client_key)) messages.delete('local:' + m.client_key);
    const existed = messages.has(m.public_id);
    messages.set(m.public_id, m);
    if (m.id > lastId) lastId = m.id;
    if (!oldestId || m.id < oldestId) oldestId = m.id;
    return !existed;
  }

  async function poll(initial = false): Promise<void> {
    if (polling || !api.hasSession()) return;
    polling = true;
    try {
      const res = await api.request<{ conversation: ConversationSummary; messages: VisitorMessage[]; has_more: boolean; availability: Availability }>('GET', 'messages', { after: lastId });
      pollFailures = 0;
      showBanner('', 'info');
      const wasBottom = nearBottom();
      let fresh: VisitorMessage[] = [];
      if (initial && lastId === 0) hasMore = res.has_more;
      for (const m of res.messages) if (upsert(m)) fresh.push(m);
      // Status changes (delivered/seen) on already-known messages are refreshed by re-fetching the tail.
      const changedConv = !conversation || conversation.status !== res.conversation.status;
      conversation = res.conversation;
      availability = res.availability;
      operatorTyping = res.conversation.operator_typing;
      typingEl.hidden = !operatorTyping;
      if (fresh.length || changedConv) {
        if (view === 'chat') {
          renderMessages();
          renderRating();
        }
        fresh = fresh.filter((m) => m.author === 'operator' || m.author === 'automation');
        if (fresh.length) {
          lastActivity = Date.now();
          announce(fresh);
          if (wasBottom || initial) scrollToBottom(!initial);
          else jumpBtn.hidden = false;
        }
      }
      renderStatus();
      if (visible && document.visibilityState === 'visible' && nearBottom()) markRead();
      else if (!visible) host.setUnread(host.state.unread + fresh.length);
      if (initial) await refreshStatuses();
    } catch (e) {
      pollFailures++;
      if ((e as ApiError).status === 0 || !navigator.onLine) showBanner(t('connection_lost'), 'warn');
    } finally {
      polling = false;
      schedulePoll();
    }
  }

  /** Re-read the latest page so delivery/seen markers on own messages stay current. */
  let statusTick = 0;
  async function refreshStatuses(): Promise<void> {
    const res = await api.request<{ messages: VisitorMessage[]; has_more: boolean }>('GET', 'messages', { before: lastId + 1 });
    for (const m of res.messages) upsert(m);
    if (!oldestId || res.messages.length) hasMore = res.has_more;
    if (view === 'chat') renderMessages();
  }

  async function loadOlder(): Promise<void> {
    if (!oldestId) return;
    loadMoreBtn.disabled = true;
    const before = body.scrollHeight;
    try {
      const res = await api.request<{ messages: VisitorMessage[]; has_more: boolean }>('GET', 'messages', { before: oldestId });
      res.messages.forEach(upsert);
      hasMore = res.has_more;
      renderMessages();
      body.scrollTop = body.scrollHeight - before;
    } finally {
      loadMoreBtn.disabled = false;
    }
  }

  function interval(): number {
    const hidden = document.visibilityState !== 'visible';
    let base: number;
    if (!conversation || conversation.status === 'closed') base = visible ? 15_000 : 60_000;
    else if (!visible) base = 30_000;
    else if (hidden) base = 30_000;
    else base = Date.now() - lastActivity < 120_000 ? 3_000 : 8_000;
    if (pollFailures) base = Math.min(60_000, base * 2 ** Math.min(pollFailures, 5));
    return base + Math.round(Math.random() * 500);
  }

  function schedulePoll(delay?: number): void {
    if (pollTimer !== null) window.clearTimeout(pollTimer);
    pollTimer = null;
    if (!conversation) return;
    if (!visible) return; // The loader polls unread state while the panel is closed.
    pollTimer = window.setTimeout(() => {
      statusTick++;
      poll().then(() => {
        if (statusTick % 5 === 0) refreshStatuses().catch(() => undefined);
      });
    }, delay ?? interval());
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && visible) schedulePoll(0);
  });
  window.addEventListener('online', () => visible && schedulePoll(0));
  window.addEventListener('offline', () => showBanner(t('connection_lost'), 'warn'));

  host.channel.on((msg) => {
    if (msg?.type === 'message' && msg.message && conversation && msg.conversation?.id === conversation.id) {
      if (upsert(msg.message)) renderMessages();
    }
  });

  function markRead(): void {
    if (!conversation || lastId <= readSent || document.visibilityState !== 'visible' || !visible) return;
    readSent = lastId;
    host.setUnread(0);
    host.channel.post({ type: 'unread', count: 0 });
    storage.set('last_seen_msg', lastId);
    api.request('POST', 'read', { message_id: lastId }).catch(() => undefined);
  }

  function announce(fresh: VisitorMessage[]): void {
    const last = fresh[fresh.length - 1];
    live.textContent = `${last.author === 'automation' ? t('automated_message') : last.author_name || t('support_team')}: ${last.body || t('sent_a_file')}`;
    if (sound) beep();
    if (document.visibilityState !== 'visible' && 'Notification' in window && Notification.permission === 'granted') {
      try {
        new Notification(config.brand.name, { body: (last.body || t('sent_a_file')).slice(0, 120), tag: 'gapban', silent: true });
      } catch {
        /* ignore */
      }
    }
  }

  function beep(): void {
    try {
      const Ctx = window.AudioContext || (window as any).webkitAudioContext;
      const ctx = new Ctx();
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.frequency.value = 880;
      g.gain.setValueAtTime(0.0001, ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25);
      o.connect(g).connect(ctx.destination);
      o.start();
      o.stop(ctx.currentTime + 0.3);
      o.onended = () => ctx.close();
    } catch {
      /* audio unavailable */
    }
  }

  /* --------------------------------- helpers -------------------------------- */
  function showBanner(text: string, tone: 'info' | 'warn' | 'error'): void {
    if (!text) {
      if (banner.dataset.transient === '1') {
        banner.hidden = true;
        banner.dataset.transient = '';
        renderStatus();
      }
      return;
    }
    banner.hidden = false;
    banner.dataset.tone = tone === 'info' ? '' : tone;
    banner.dataset.transient = '1';
    banner.textContent = text;
  }

  function showError(e: ApiError): void {
    const msg = e?.status === 429 ? t('rate_limited') : e?.status === 0 ? t('connection_lost') : e?.message || t('generic_error');
    showBanner(msg, 'error');
  }

  async function downloadTranscript(): Promise<void> {
    try {
      const blob = await api.blob('transcript');
      const url = URL.createObjectURL(blob);
      const a = h('a', { href: url, download: (conversation?.reference || 'chat') + '.txt' });
      host.root.append(a);
      a.click();
      a.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (e) {
      showError(e as ApiError);
    }
  }

  async function endChat(): Promise<void> {
    if (!window.confirm(t('end_chat_confirm'))) return;
    try {
      const res = await api.request<{ conversation: ConversationSummary }>('POST', 'conversation/close');
      conversation = res.conversation;
      await poll();
      render();
    } catch (e) {
      showError(e as ApiError);
    }
  }

  /* ------------------------------- lifecycle -------------------------------- */
  async function initialLoad(): Promise<void> {
    if (!api.hasSession() || !conversation) {
      render();
      return;
    }
    body.replaceChildren(h('div', { class: 'gb-spinner', role: 'status', 'aria-label': t('loading') }));
    try {
      const res = await api.request<{ conversation: ConversationSummary | null; messages: VisitorMessage[]; has_more: boolean }>('GET', 'conversation');
      conversation = res.conversation;
      res.messages.forEach(upsert);
      hasMore = res.has_more;
      view = conversation ? 'chat' : 'home';
      ratingDone = conversation?.rated ?? false;
    } catch (e) {
      showError(e as ApiError);
    }
    render();
  }
  initialLoad();
  autosize();

  return {
    show() {
      lastFocused = document.activeElement;
      panel.hidden = false;
      panel.dataset.closing = 'false';
      panel.setAttribute('aria-modal', String(isFullscreen()));
      visible = true;
      if (isFullscreen()) document.documentElement.style.overflow = 'hidden';
      if (host.state.pendingAutomated && !conversation) {
        localAutomated = host.state.pendingAutomated;
        view = 'home';
        render();
      }
      schedulePoll(0);
      markRead();
    },
    hide() {
      visible = false;
      sendTyping(false);
      document.documentElement.style.overflow = '';
      if (pollTimer !== null) window.clearTimeout(pollTimer);
      const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) panel.hidden = true;
      else {
        panel.dataset.closing = 'true';
        window.setTimeout(() => {
          if (!visible) panel.hidden = true;
        }, 150);
      }
      window.dispatchEvent(new Event('gapban:panel-closed'));
    },
    focus() {
      window.setTimeout(() => {
        if (view === 'chat' && !composer.hidden) textarea.focus();
        else (body.querySelector('button, input, select, textarea') as HTMLElement | null)?.focus();
      }, 30);
    },
  };
}

window.__gapbanChat = { mount };
