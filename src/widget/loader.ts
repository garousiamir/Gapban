/**
 * Gapban widget bootstrap (always loaded, kept small).
 *
 * - Renders the launcher inside a shadow root (theme CSS cannot interfere).
 * - Reads only cache-safe config from the page; visitor-specific state comes from
 *   the no-store /bootstrap endpoint after the page is idle.
 * - Evaluates targeting rules and schedules proactive triggers.
 * - Loads the chat UI chunk on demand.
 */
import launcherCss from './launcher.css';
import { iconSvg } from '../shared/icons-launcher';
import { sprintf } from '../shared/format';
import { decide, filterSensitive, parseQuery, staticOutcome, frequencyBlock, type FrequencyState, type Rule, type RuleContext } from '../shared/rules';
import { Api } from './api';
import * as storage from './storage';
import type { Bootstrap, Host, WidgetConfig } from './types';

const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

function boot(config: WidgetConfig): void {
  if (window.__gapbanHost) return;

  const t = (key: string, ...args: Array<string | number>): string => sprintf(config.strings[key] ?? key, ...args);

  /* ------------------------------ shadow host ------------------------------ */
  const hostEl = document.createElement('div');
  hostEl.id = 'gapban-widget';
  hostEl.setAttribute('dir', config.dir);
  hostEl.setAttribute('lang', config.lang);
  const side = config.launcher.position;
  hostEl.dataset.side = side;
  const alignStart = side === 'start' || (side === 'left' && config.dir === 'ltr') || (side === 'right' && config.dir === 'rtl');
  hostEl.dataset.align = alignStart ? 'start' : 'end';
  hostEl.dataset.animation = config.launcher.animation;
  const vars: Record<string, string> = {
    '--gb-brand': config.brand.color,
    '--gb-brand-dark': config.brand.colorDark,
    '--gb-ink': config.brand.ink,
    '--gb-size': config.launcher.size + 'px',
    '--gb-size-mobile': config.launcher.sizeMobile + 'px',
    '--gb-offset-x': config.launcher.offsetX + 'px',
    '--gb-offset-y': config.launcher.offsetY + 'px',
    '--gb-offset-x-mobile': config.launcher.offsetXMobile + 'px',
    '--gb-offset-y-mobile': config.launcher.offsetYMobile + 'px',
    '--gb-z': String(config.launcher.zIndex),
  };
  for (const [k, v] of Object.entries(vars)) hostEl.style.setProperty(k, v);
  const root = hostEl.attachShadow({ mode: 'open' });
  const style = document.createElement('style');
  style.textContent = launcherCss;
  root.appendChild(style);

  const stack = document.createElement('div');
  stack.className = 'gb-stack';
  root.appendChild(stack);

  const live = document.createElement('div');
  live.className = 'gb-sr';
  live.setAttribute('role', 'status');
  live.setAttribute('aria-live', 'polite');
  root.appendChild(live);

  /* -------------------------------- launcher -------------------------------- */
  const launcher = document.createElement('button');
  launcher.type = 'button';
  launcher.className = 'gb-launcher';
  launcher.dataset.variant = config.launcher.variant;
  launcher.dataset.state = 'idle';
  launcher.dataset.attention = String(config.launcher.attention);
  launcher.setAttribute('aria-expanded', 'false');
  launcher.setAttribute('aria-haspopup', 'dialog');
  const icon = document.createElement('span');
  icon.className = 'gb-icon';
  if (config.launcher.variant === 'avatar' && config.brand.avatar) {
    const img = document.createElement('img');
    img.className = 'gb-avatar';
    img.alt = '';
    img.src = config.brand.avatar;
    icon.style.inlineSize = '100%';
    icon.style.blockSize = '100%';
    icon.appendChild(img);
  } else if (config.launcher.icon === 'custom' && config.launcher.customSvg) {
    // Sanitized server-side by an allowlist SVG sanitizer.
    icon.innerHTML = config.launcher.customSvg;
  } else {
    icon.innerHTML = iconSvg(config.launcher.icon === 'custom' ? 'comments' : config.launcher.icon);
  }
  const label = document.createElement('span');
  label.className = 'gb-label';
  label.textContent = config.launcher.label || t('open_chat');
  const badge = document.createElement('span');
  badge.className = 'gb-badge';
  badge.hidden = true;
  badge.setAttribute('aria-hidden', 'true');
  const presence = document.createElement('span');
  presence.className = 'gb-presence';
  presence.hidden = true;
  presence.setAttribute('aria-hidden', 'true');
  launcher.append(icon, label, badge, presence);
  stack.appendChild(launcher);

  const api = new Api(config.rest);

  /* ------------------------------ cross-tab bus ----------------------------- */
  const listeners: Array<(msg: any) => void> = [];
  let bc: BroadcastChannel | null = null;
  try {
    bc = new BroadcastChannel('gapban');
    bc.onmessage = (e) => listeners.forEach((fn) => fn(e.data));
  } catch {
    window.addEventListener('storage', (e) => {
      if (e.key === 'gapban:bus' && e.newValue) {
        try {
          const msg = JSON.parse(e.newValue).msg;
          listeners.forEach((fn) => fn(msg));
        } catch {
          /* ignore */
        }
      }
    });
  }
  const channel = {
    post(msg: unknown) {
      if (bc) bc.postMessage(msg);
      else storage.set('bus', { msg, at: Date.now() + Math.random() });
    },
    on(fn: (msg: any) => void) {
      listeners.push(fn);
    },
  };

  /* --------------------------------- state ---------------------------------- */
  let chat: { show(): void; hide(): void; focus(): void } | null = null;
  let chatLoading: Promise<void> | null = null;
  let launcherVisible = !config.launcher.hidden;

  const host: Host = {
    config,
    root,
    stack,
    launcher,
    api,
    t,
    state: { bootstrap: null, open: false, unread: 0, department: null, greeting: null, campaignRuleId: null, pendingAutomated: null },
    channel,
    setUnread(n: number) {
      host.state.unread = Math.max(0, n);
      badge.hidden = host.state.unread === 0;
      badge.textContent = host.state.unread > 9 ? (config.persianDigits && config.lang === 'fa' ? '۹+' : '9+') : formatDigits(String(host.state.unread));
      launcher.setAttribute('aria-label', host.state.unread ? `${label.textContent} — ${t('unread_count', host.state.unread)}` : label.textContent || '');
    },
    setOpen(open: boolean, opts = {}) {
      if (open === host.state.open) {
        if (open && opts.focus) chat?.focus();
        return;
      }
      host.state.open = open;
      launcher.setAttribute('aria-expanded', String(open));
      if (!(config.launcher.variant === 'avatar' && config.brand.avatar) && !(config.launcher.icon === 'custom' && config.launcher.customSvg)) {
        icon.innerHTML = iconSvg(open ? 'xmark' : config.launcher.icon === 'custom' ? 'comments' : config.launcher.icon);
      }
      if (open) {
        storage.set('opened', true, 'session');
        hideTeaser(false);
        cancelTriggers();
        storage.set('panel_open', true, 'session');
        loadChat().then(() => {
          chat?.show();
          if (opts.focus !== false) chat?.focus();
        });
      } else {
        storage.set('panel_open', false, 'session');
        chat?.hide();
        if (openedByRule !== null) {
          markDismissed(openedByRule);
          openedByRule = null;
        }
        launcher.focus({ preventScroll: true });
      }
      applyVisibility();
    },
    setLauncherState(s) {
      launcher.dataset.state = s;
      launcher.setAttribute('aria-busy', String(s === 'loading'));
    },
    onVisitorActivity() {
      cancelTriggers();
      hideTeaser(false);
    },
  };
  window.__gapbanHost = host;

  function formatDigits(s: string): string {
    return config.lang === 'fa' && config.persianDigits ? s.replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]) : s;
  }

  function loadChat(): Promise<void> {
    if (window.__gapbanChat) {
      if (!chat) chat = window.__gapbanChat.mount(host);
      return Promise.resolve();
    }
    if (!chatLoading) {
      host.setLauncherState('loading');
      loadFont();
      chatLoading = new Promise<void>((resolve, reject) => {
        const s = document.createElement('script');
        s.src = config.assets.chat;
        s.async = true;
        s.onload = () => {
          if (window.__gapbanChat) {
            chat = window.__gapbanChat.mount(host);
            host.setLauncherState('idle');
            resolve();
          } else {
            reject(new Error('chat chunk missing'));
          }
        };
        s.onerror = () => {
          host.setLauncherState('error');
          live.textContent = t('error_loading');
          chatLoading = null;
          reject(new Error('chat chunk failed'));
        };
        document.head.appendChild(s);
      }).catch(() => {
        host.state.open = false;
        launcher.setAttribute('aria-expanded', 'false');
      });
    }
    return chatLoading;
  }

  let fontLoaded = false;
  function loadFont(): void {
    if (fontLoaded || !config.assets.font) return;
    fontLoaded = true;
    const st = document.createElement('style');
    st.id = 'gapban-font';
    st.textContent = `@font-face{font-family:'GapbanEstedad';src:url('${config.assets.font}') format('woff2');font-weight:100 900;font-style:normal;font-display:swap;}`;
    document.head.appendChild(st);
  }

  launcher.addEventListener('click', () => {
    host.setOpen(!host.state.open);
  });

  /* ------------------------------ public API -------------------------------- */
  window.Gapban = {
    open: () => host.setOpen(true),
    close: () => host.setOpen(false),
    toggle: () => host.setOpen(!host.state.open),
    isOpen: () => host.state.open,
  };
  document.addEventListener('click', (e) => {
    const target = e.target instanceof Element ? e.target.closest('[data-gapban-open], a[href="#gapban"]') : null;
    if (target) {
      e.preventDefault();
      host.setOpen(true);
    }
  });

  /* --------------------------------- teaser --------------------------------- */
  let teaserEl: HTMLElement | null = null;
  let teaserRule: number | null = null;
  function showTeaser(text: string, meta: string, ruleId: number | null): void {
    hideTeaser(false);
    const wrap = document.createElement('div');
    wrap.className = 'gb-teaser';
    wrap.setAttribute('role', 'button');
    wrap.tabIndex = 0;
    const m = document.createElement('span');
    m.className = 'gb-teaser-meta';
    m.textContent = meta;
    const body = document.createElement('span');
    body.textContent = text;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'gb-teaser-close';
    close.setAttribute('aria-label', t('dismiss'));
    close.innerHTML = iconSvg('xmark');
    close.addEventListener('click', (e) => {
      e.stopPropagation();
      hideTeaser(true);
    });
    wrap.append(m, body, close);
    const open = () => {
      if (ruleId !== null) {
        recordEvent(ruleId, 'clicked');
        host.state.campaignRuleId = ruleId;
      }
      host.setOpen(true);
    };
    wrap.addEventListener('click', open);
    wrap.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        open();
      }
      if (e.key === 'Escape') hideTeaser(true);
    });
    stack.insertBefore(wrap, launcher);
    teaserEl = wrap;
    teaserRule = ruleId;
    live.textContent = `${meta}: ${text}`;
  }
  function hideTeaser(dismissed: boolean): void {
    if (!teaserEl) return;
    teaserEl.remove();
    teaserEl = null;
    if (dismissed) {
      if (teaserRule !== null) markDismissed(teaserRule);
      else storage.set('teaser_dismissed', Date.now());
    }
    teaserRule = null;
  }

  /* --------------------------- rules & frequency ---------------------------- */
  type Stored = Record<string, { shows: number[]; dismissed: number | null }>;
  const pageShows: Record<number, number> = {};
  const readStored = (): Stored => storage.get<Stored>('rules', {});
  const freq = (id: number): FrequencyState => {
    const s = readStored()[id] || { shows: [], dismissed: null };
    const sess = storage.get<Record<string, number>>('rules_session', {}, 'session');
    return { visitorShows: s.shows, sessionShows: sess[id] || 0, pageShows: pageShows[id] || 0, dismissedAt: s.dismissed };
  };
  function recordShow(id: number): void {
    const all = readStored();
    const cur = all[id] || { shows: [], dismissed: null };
    cur.shows = cur.shows.concat(Date.now()).slice(-50);
    all[id] = cur;
    storage.set('rules', all);
    const sess = storage.get<Record<string, number>>('rules_session', {}, 'session');
    sess[id] = (sess[id] || 0) + 1;
    storage.set('rules_session', sess, 'session');
    pageShows[id] = (pageShows[id] || 0) + 1;
    channel.post({ type: 'rule_shown', id });
    recordEvent(id, 'shown');
  }
  function markDismissed(id: number): void {
    const all = readStored();
    const cur = all[id] || { shows: [], dismissed: null };
    cur.dismissed = Date.now();
    all[id] = cur;
    storage.set('rules', all);
    recordEvent(id, 'dismissed');
  }
  function recordEvent(id: number, event: 'shown' | 'clicked' | 'dismissed'): void {
    api.request('POST', 'rule-events', { rule_id: id, event }, { auth: api.hasSession() }).catch(() => undefined);
  }

  const now = Date.now();
  if (!storage.get<number | null>('first_seen', null)) storage.set('first_seen', now);
  const firstSeen = storage.get<number>('first_seen', now);
  const query = filterSensitive(parseQuery(location.search), config.sensitive);
  const utmIn: Record<string, string> = {};
  for (const [k, v] of Object.entries(query)) if (k.startsWith('utm_')) utmIn[k] = v;
  if (Object.keys(utmIn).length && !storage.get('utm', null, 'session')) storage.set('utm', utmIn, 'session');
  if (storage.get<string | null>('ref', null, 'session') === null) {
    let ref = '';
    try {
      const r = new URL(document.referrer);
      ref = r.origin + r.pathname; // never keep referrer query strings
    } catch {
      ref = '';
    }
    storage.set('ref', ref, 'session');
  }

  function device(): 'mobile' | 'tablet' | 'desktop' {
    const w = window.innerWidth;
    const coarse = window.matchMedia?.('(pointer: coarse)').matches;
    if (w <= 640 || (coarse && w < 768)) return 'mobile';
    if (coarse && w <= 1024) return 'tablet';
    return 'desktop';
  }

  function businessHoursNow(): boolean {
    if (!config.hours.enabled) return true;
    try {
      const parts = new Intl.DateTimeFormat('en-US', { timeZone: config.tz, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
      const get = (type: string) => parts.find((p) => p.type === type)?.value || '';
      const date = `${get('year')}-${get('month')}-${get('day')}`;
      if (config.hours.holidays.some((h) => h.date === date)) return false;
      const day = DAY_KEYS[['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(get('weekday'))];
      const time = `${get('hour')}:${get('minute')}`;
      return (config.hours.schedule[day] || []).some(([a, b]) => time >= a && time < b);
    } catch {
      return true;
    }
  }

  function context(): RuleContext {
    const b = host.state.bootstrap;
    const conv = b?.conversation;
    return {
      page: config.page,
      url: { href: location.href, path: location.pathname, query: filterSensitive(parseQuery(location.search), config.sensitive) },
      visitor: {
        logged_in: b?.visitor.logged_in ?? false,
        roles: b?.visitor.roles ?? [],
        returning: now - firstSeen > 30 * 60_000,
        language: document.documentElement.lang || navigator.language || '',
        device: device(),
        viewport: window.innerWidth,
      },
      referrer: storage.get<string>('ref', '', 'session'),
      utm: storage.get<Record<string, string>>('utm', {}, 'session'),
      availability: { state: b?.availability.state ?? 'away', business_hours: businessHoursNow() },
      conversation: !conv ? 'none' : conv.status === 'closed' ? 'closed' : 'active',
      chat_opened: storage.get<boolean>('opened', false, 'session'),
      cart: b?.cart ? { count: b.cart.count, value: b.cart.value } : null,
      now: Date.now(),
      campaignLastShown: (id: number) => {
        const s = readStored()[id];
        return s && s.shows.length ? Math.max(...s.shows) : null;
      },
    };
  }

  /* ------------------------------- triggers --------------------------------- */
  let visibleMs = 0;
  let lastActivity = Date.now();
  let maxScroll = 0;
  let proactiveShown = false;
  let openedByRule: number | null = null;
  const armed = new Map<number, Rule>();
  let tick: number | null = null;
  const desktopPointer = window.matchMedia?.('(pointer: fine)').matches && !('ontouchstart' in window);

  function cancelTriggers(): void {
    armed.clear();
    if (tick !== null) {
      window.clearInterval(tick);
      tick = null;
    }
  }

  function fire(rule: Rule): void {
    if (proactiveShown || host.state.open) return;
    // Re-check caps against fresh storage (another tab may have shown it).
    const block = frequencyBlock(rule, freq(rule.id), Date.now(), device() === 'mobile');
    if (block) return;
    const fresh = decide([rule], context(), freq);
    if (!fresh[0] || !fresh[0].matched) return;
    proactiveShown = true;
    armed.clear();
    recordShow(rule.id);
    host.state.campaignRuleId = rule.id;
    for (const a of rule.actions) {
      if (a.type === 'automated_message') {
        host.state.pendingAutomated = { text: a.text, ruleId: rule.id };
        showTeaser(a.text, t('automated_message'), rule.id);
      } else if (a.type === 'show_teaser') {
        showTeaser(a.text, config.brand.name, rule.id);
      } else if (a.type === 'open_chat') {
        openedByRule = rule.id;
        host.setOpen(true, { focus: false });
      }
    }
  }

  function startTicker(): void {
    if (tick !== null) return;
    tick = window.setInterval(() => {
      if (document.visibilityState === 'visible') visibleMs += 1000;
      for (const rule of Array.from(armed.values())) {
        const trig = rule.trigger;
        if (trig.type === 'time_on_page' && visibleMs >= trig.seconds * 1000) fire(rule);
        if (trig.type === 'inactivity' && document.visibilityState === 'visible' && Date.now() - lastActivity >= trig.seconds * 1000) fire(rule);
        if (trig.type === 'scroll_depth' && maxScroll >= trig.percent) fire(rule);
      }
      if (!armed.size && tick !== null) {
        window.clearInterval(tick);
        tick = null;
      }
    }, 1000);
  }

  const markActivity = () => {
    lastActivity = Date.now();
  };
  ['mousemove', 'keydown', 'touchstart', 'scroll'].forEach((ev) => window.addEventListener(ev, markActivity, { passive: true }));
  window.addEventListener(
    'scroll',
    () => {
      const doc = document.documentElement;
      const h = Math.max(1, doc.scrollHeight - window.innerHeight);
      maxScroll = Math.max(maxScroll, Math.round((window.scrollY / h) * 100));
    },
    { passive: true },
  );
  if (desktopPointer) {
    document.addEventListener('mouseout', (e) => {
      if (!e.relatedTarget && e.clientY <= 0) {
        for (const rule of Array.from(armed.values())) if (rule.trigger.type === 'exit_intent') fire(rule);
      }
    });
  }

  function evaluate(): void {
    const decisions = decide(config.rules, context(), freq);
    const outcome = staticOutcome(decisions);
    if (outcome.launcher === 'hide') launcherVisible = false;
    else if (outcome.launcher === 'show') launcherVisible = true;
    else launcherVisible = !config.launcher.hidden;
    host.state.department = outcome.department;
    host.state.greeting = outcome.greeting;
    applyVisibility();

    armed.clear();
    if (proactiveShown || host.state.open) return;
    for (const d of decisions) {
      if (!d.matched || d.blockedBy) continue;
      const proactive = d.rule.actions.some((a) => a.type === 'show_teaser' || a.type === 'automated_message' || a.type === 'open_chat');
      if (!proactive) continue;
      if (d.rule.trigger.type === 'exit_intent' && !desktopPointer) continue; // exit intent is desktop-only.
      if (d.rule.trigger.type === 'immediate') {
        fire(d.rule);
        if (proactiveShown) return;
        continue;
      }
      armed.set(d.rule.id, d.rule);
    }
    if (armed.size) startTicker();
  }

  channel.on((msg) => {
    if (msg && msg.type === 'rule_shown' && armed.has(msg.id)) armed.delete(msg.id);
    if (msg && msg.type === 'unread') host.setUnread(msg.count);
  });

  /* ------------------------- visibility & placement -------------------------- */
  function applyVisibility(): void {
    hostEl.hidden = !launcherVisible && !host.state.open;
  }

  function avoidOverlaps(): void {
    let lift = 0;
    const vh = window.innerHeight;
    const rect = launcher.getBoundingClientRect();
    if (!rect.width) return;
    const candidates: Element[] = [];
    if (config.launcher.avoid) {
      try {
        document.querySelectorAll(config.launcher.avoid).forEach((el) => candidates.push(el));
      } catch {
        /* invalid selector */
      }
    }
    // Probe a few points just above the launcher's resting position.
    const x = rect.left + rect.width / 2;
    for (const y of [vh - 4, vh - config.launcher.offsetY - 4, vh - config.launcher.offsetY - rect.height / 2]) {
      for (const el of document.elementsFromPoint(x, y)) {
        if (el === hostEl || hostEl.contains(el)) continue;
        candidates.push(el);
      }
    }
    for (const el of candidates) {
      let node: Element | null = el;
      while (node && node !== document.body) {
        const pos = getComputedStyle(node).position;
        if (pos === 'fixed' || pos === 'sticky') {
          const r = node.getBoundingClientRect();
          const overlapsX = r.right > rect.left - 8 && r.left < rect.right + 8;
          if (overlapsX && r.bottom >= vh - 2 && r.top > vh * 0.4 && r.height < vh * 0.5) lift = Math.max(lift, vh - r.top + 4 - config.launcher.offsetY + 8);
          break;
        }
        node = node.parentElement;
      }
    }
    hostEl.style.setProperty('--gb-lift', Math.max(0, Math.round(lift)) + 'px');
  }

  /* --------------------------- bootstrap & unread ---------------------------- */
  let unreadTimer: number | null = null;
  let lastSeenMessage = storage.get<number>('last_seen_msg', 0);

  async function loadBootstrap(): Promise<void> {
    try {
      const b = await api.request<Bootstrap>('GET', 'bootstrap', undefined, { auth: api.hasSession() });
      host.state.bootstrap = b;
      if (b.session_upgrade) api.resetSession();
      presence.hidden = false;
      presence.dataset.state = b.availability.state;
      host.setUnread(b.unread);
      if (b.unread > 0 && !host.state.open) notifyTeaser();
      if (b.conversation && b.conversation.status !== 'closed') scheduleUnread();
      if (storage.get<boolean>('panel_open', false, 'session') && window.innerWidth > 640) host.setOpen(true, { focus: false });
    } catch {
      presence.hidden = true;
    }
    evaluate();
  }

  function notifyTeaser(): void {
    if (teaserEl || proactiveShown) return;
    showTeaser(t('new_reply_teaser'), config.brand.name, null);
  }

  function scheduleUnread(): void {
    if (unreadTimer !== null) window.clearTimeout(unreadTimer);
    const delay = document.visibilityState === 'visible' ? 30_000 : 90_000;
    unreadTimer = window.setTimeout(pollUnread, delay);
  }

  async function pollUnread(): Promise<void> {
    unreadTimer = null;
    if (host.state.open || !api.hasSession()) return;
    try {
      const b = await api.request<Bootstrap>('GET', 'bootstrap');
      host.state.bootstrap = b;
      presence.dataset.state = b.availability.state;
      const lastId = b.conversation?.last_message_id ?? 0;
      if (b.unread > host.state.unread && lastId > lastSeenMessage) {
        lastSeenMessage = lastId;
        storage.set('last_seen_msg', lastId);
        notifyTeaser();
        live.textContent = t('new_reply_teaser');
      }
      host.setUnread(b.unread);
      if (b.conversation && b.conversation.status !== 'closed') scheduleUnread();
    } catch {
      scheduleUnread();
    }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && unreadTimer !== null && !host.state.open) {
      window.clearTimeout(unreadTimer);
      pollUnread();
    }
  });
  window.addEventListener('gapban:panel-closed', () => {
    if (host.state.bootstrap?.conversation) scheduleUnread();
  });

  /* ------------------------------ SPA navigation ----------------------------- */
  let lastHref = location.href;
  const onNavigate = () => {
    if (location.href === lastHref) return;
    lastHref = location.href;
    proactiveShown = false;
    for (const k of Object.keys(pageShows)) delete pageShows[Number(k)];
    visibleMs = 0;
    maxScroll = 0;
    evaluate();
  };
  window.addEventListener('popstate', onNavigate);
  window.addEventListener('hashchange', onNavigate);
  window.addEventListener('gapban:navigate', onNavigate);

  /* --------------------------------- mount ----------------------------------- */
  document.body.appendChild(hostEl);
  applyVisibility();
  if (config.launcher.teaser && !storage.get('teaser_dismissed', null)) {
    window.setTimeout(() => {
      if (!host.state.open && !teaserEl && !proactiveShown) showTeaser(config.launcher.teaser, config.brand.name, null);
    }, 1500);
  }
  const ric = (window as unknown as { requestIdleCallback?: (fn: () => void, o: { timeout: number }) => number }).requestIdleCallback;
  const idle = (fn: () => void) => (ric ? ric.call(window, fn, { timeout: 2500 }) : window.setTimeout(fn, 800));
  idle(() => {
    loadBootstrap();
    avoidOverlaps();
  });
  idle(loadFont);
  let resizeTimer: number | null = null;
  window.addEventListener('resize', () => {
    if (resizeTimer !== null) window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(avoidOverlaps, 200);
  });
  // Cookie notices and sticky bars often appear late.
  [2000, 5000, 10000].forEach((ms) => window.setTimeout(avoidOverlaps, ms));
}

function start(): void {
  const config = window.gapbanConfig;
  if (!config || !document.body) return;
  try {
    boot(config);
  } catch (e) {
    // Never break the host page.
    if (window.console) console.warn('[gapban]', e);
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
