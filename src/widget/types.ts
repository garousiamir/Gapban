import type { PageContext, Rule } from '../shared/rules';

export type Availability = { state: 'online' | 'away' | 'offline'; reason: string; next_open: string };

export type WidgetConfig = {
  v: string;
  rest: string;
  assets: { chat: string; chatCss?: string; font: string };
  lang: 'fa' | 'en';
  dir: 'rtl' | 'ltr';
  tz: string;
  persianDigits: boolean;
  jalali: boolean;
  brand: { name: string; color: string; colorDark: string; ink: string; avatar: string };
  launcher: {
    variant: 'pill' | 'circle' | 'avatar' | 'text';
    label: string;
    position: 'start' | 'end' | 'left' | 'right';
    offsetX: number;
    offsetY: number;
    offsetXMobile: number;
    offsetYMobile: number;
    size: number;
    sizeMobile: number;
    icon: string;
    customSvg: string;
    animation: 'none' | 'subtle';
    attention: boolean;
    teaser: string;
    avoid: string;
    hidden: boolean;
    zIndex: number;
  };
  texts: Record<string, string>;
  prechat: {
    enabled: boolean;
    fields: Record<'name' | 'email' | 'phone' | 'department', 'hidden' | 'optional' | 'required'>;
    custom: Array<{ key: string; label: string; type: string; required: boolean }>;
    consentRequired: boolean;
  };
  departments: Array<{ id: number; name: string }>;
  features: {
    attachments: boolean;
    voice: boolean;
    csat: boolean;
    transcript: boolean;
    maxUploadMb: number;
    allowedTypes: string[];
    honeypot: boolean;
    sound: boolean;
    reopen: boolean;
    maxLength: number;
  };
  faq: Array<{ title: string; body: string; url: string }>;
  suggestions: string[];
  rules: Rule[];
  page: PageContext;
  sensitive: string[];
  hours: { enabled: boolean; schedule: Record<string, Array<[string, string]>>; holidays: Array<{ date: string }> };
  strings: Record<string, string>;
};

export type VisitorMessage = {
  id: number;
  public_id: string;
  author: 'visitor' | 'operator' | 'automation' | 'system';
  author_name: string;
  kind: 'text' | 'attachment' | 'event' | 'note';
  body: string;
  created_at: string;
  client_key: string | null;
  status?: 'accepted' | 'delivered' | 'seen';
  attachments: Array<{ id: string; name: string; mime: string; size: number; width: number; height: number; image: boolean; audio: boolean }>;
  // Client-side only:
  local?: 'sending' | 'failed';
};

export type ConversationSummary = {
  id: string;
  reference: string;
  status: 'queued' | 'open' | 'waiting' | 'closed';
  last_message_id: number;
  operator_typing: boolean;
  rated: boolean;
  has_human: boolean;
  created_at: string;
};

export type Bootstrap = {
  availability: Availability;
  visitor: { logged_in: boolean; roles: string[] };
  conversation: ConversationSummary | null;
  unread: number;
  session: boolean;
  session_upgrade?: boolean;
  profile?: { name: string; email: string; phone: string };
  cart?: { count: number; value: number; currency: string } | null;
};

/** Shared state between the always-loaded loader and the lazily loaded chat chunk. */
export type Host = {
  config: WidgetConfig;
  root: ShadowRoot;
  stack: HTMLElement;
  launcher: HTMLButtonElement;
  api: import('./api').Api;
  t: (key: string, ...args: Array<string | number>) => string;
  state: {
    bootstrap: Bootstrap | null;
    open: boolean;
    unread: number;
    department: number | null;
    greeting: { title: string; subtitle: string } | null;
    campaignRuleId: number | null;
    pendingAutomated: { text: string; ruleId: number } | null;
  };
  setUnread(n: number): void;
  setOpen(open: boolean, opts?: { focus?: boolean }): void;
  setLauncherState(state: 'idle' | 'loading' | 'error'): void;
  onVisitorActivity(kind: 'typed' | 'sent' | 'opened'): void;
  channel: { post(msg: unknown): void; on(fn: (msg: any) => void): void };
};

declare global {
  interface Window {
    gapbanConfig?: WidgetConfig;
    Gapban?: { open(): void; close(): void; toggle(): void; isOpen(): boolean };
    __gapbanChat?: { mount(host: Host): { show(): void; hide(): void; focus(): void } };
    __gapbanHost?: Host;
  }
}
