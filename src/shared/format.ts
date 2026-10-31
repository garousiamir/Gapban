/**
 * Locale-aware display helpers. Stored/API values stay canonical (ASCII digits,
 * ISO-8601 UTC); conversion happens only at render time.
 */

const FA = '۰۱۲۳۴۵۶۷۸۹';
const AR = '٠١٢٣٤٥٦٧٨٩';

export function toPersianDigits(s: string): string {
  return s.replace(/[0-9]/g, (d) => FA[Number(d)]);
}

export function normalizeDigits(s: string): string {
  return s.replace(/[۰-۹]/g, (d) => String(FA.indexOf(d))).replace(/[٠-٩]/g, (d) => String(AR.indexOf(d)));
}

export type DisplayPrefs = { locale: 'fa' | 'en'; persianDigits: boolean; jalali: boolean; timeZone: string };

function intlLocale(p: DisplayPrefs): string {
  if (p.locale === 'fa') {
    const cal = p.jalali ? 'persian' : 'gregory';
    const nu = p.persianDigits ? 'arabext' : 'latn';
    return `fa-IR-u-ca-${cal}-nu-${nu}`;
  }
  return 'en-US';
}

function safeTz(tz: string): string | undefined {
  try {
    new Intl.DateTimeFormat('en', { timeZone: tz });
    return tz;
  } catch {
    return undefined;
  }
}

export function formatTime(iso: string, p: DisplayPrefs): string {
  const d = new Date(iso);
  return new Intl.DateTimeFormat(intlLocale(p), { hour: '2-digit', minute: '2-digit', timeZone: safeTz(p.timeZone) }).format(d);
}

export function formatDay(iso: string, p: DisplayPrefs): string {
  const d = new Date(iso);
  return new Intl.DateTimeFormat(intlLocale(p), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: safeTz(p.timeZone) }).format(d);
}

export function formatDateTime(iso: string, p: DisplayPrefs): string {
  const d = new Date(iso);
  return new Intl.DateTimeFormat(intlLocale(p), { dateStyle: 'medium', timeStyle: 'short', timeZone: safeTz(p.timeZone) }).format(d);
}

/** Calendar-day key in the display time zone (for day separators). */
export function dayKey(iso: string, p: DisplayPrefs): string {
  return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: safeTz(p.timeZone) }).format(new Date(iso));
}

export function formatNumber(n: number, p: DisplayPrefs): string {
  const s = String(n);
  return p.locale === 'fa' && p.persianDigits ? toPersianDigits(s) : s;
}

export function formatBytes(n: number, p: DisplayPrefs): string {
  const units = p.locale === 'fa' ? ['بایت', 'کیلوبایت', 'مگابایت'] : ['B', 'KB', 'MB'];
  let i = 0;
  let v = n;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  const s = (i === 0 ? String(Math.round(v)) : v.toFixed(1)) + ' ' + units[i];
  return p.locale === 'fa' && p.persianDigits ? toPersianDigits(s) : s;
}

export function formatDuration(seconds: number | null, p: DisplayPrefs): string {
  if (seconds === null || seconds === undefined) return '—';
  const fa = p.locale === 'fa';
  let s: string;
  if (seconds < 60) s = `${seconds}${fa ? ' ثانیه' : 's'}`;
  else if (seconds < 3600) s = `${Math.round(seconds / 60)}${fa ? ' دقیقه' : ' min'}`;
  else if (seconds < 86400) s = `${(seconds / 3600).toFixed(1)}${fa ? ' ساعت' : ' h'}`;
  else s = `${(seconds / 86400).toFixed(1)}${fa ? ' روز' : ' d'}`;
  return fa && p.persianDigits ? toPersianDigits(s) : s;
}

const URL_RE = /\b((?:https?:\/\/|www\.)[^\s<>"'`]+[^\s<>"'`.,;:!?)\]}»،؛])/gi;
const EMAIL_RE = /\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/g;

/**
 * Render message text into DOM nodes: text nodes only, plus anchors for http(s)
 * URLs. URLs and e-mail addresses are wrapped in <bdi> so they keep LTR order
 * inside Persian text. Never uses innerHTML.
 */
export function renderRichText(doc: Document, text: string, persianDigits: boolean): DocumentFragment {
  const frag = doc.createDocumentFragment();
  const lines = text.split('\n');
  lines.forEach((line, li) => {
    if (li > 0) frag.appendChild(doc.createElement('br'));
    let last = 0;
    const tokens: Array<{ start: number; end: number; kind: 'url' | 'email' }> = [];
    line.replace(URL_RE, (m, _g, offset: number) => {
      tokens.push({ start: offset, end: offset + m.length, kind: 'url' });
      return m;
    });
    line.replace(EMAIL_RE, (m, offset: number) => {
      if (!tokens.some((t) => offset >= t.start && offset < t.end)) tokens.push({ start: offset, end: offset + m.length, kind: 'email' });
      return m;
    });
    tokens.sort((a, b) => a.start - b.start);
    for (const tok of tokens) {
      if (tok.start > last) frag.appendChild(doc.createTextNode(digits(line.slice(last, tok.start), persianDigits)));
      const raw = line.slice(tok.start, tok.end);
      const bdi = doc.createElement('bdi');
      if (tok.kind === 'url') {
        const href = /^https?:\/\//i.test(raw) ? raw : 'https://' + raw;
        let safe = false;
        try {
          const u = new URL(href);
          safe = u.protocol === 'http:' || u.protocol === 'https:';
        } catch {
          safe = false;
        }
        if (safe) {
          const a = doc.createElement('a');
          a.href = href;
          a.target = '_blank';
          a.rel = 'noopener noreferrer nofollow ugc';
          a.textContent = raw;
          bdi.appendChild(a);
        } else {
          bdi.textContent = raw;
        }
      } else {
        bdi.textContent = raw;
      }
      frag.appendChild(bdi);
      last = tok.end;
    }
    if (last < line.length) frag.appendChild(doc.createTextNode(digits(line.slice(last), persianDigits)));
  });
  return frag;
}

function digits(s: string, persian: boolean): string {
  // Only convert digits in running text; URLs/emails/ids are left canonical above.
  return persian ? toPersianDigits(s) : s;
}

export function randomKey(len = 24): string {
  const bytes = new Uint8Array(len);
  crypto.getRandomValues(bytes);
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';
  return Array.from(bytes, (b) => alphabet[b & 63]).join('');
}

/** Interpolate %s / %d / %1$s placeholders. */
export function sprintf(template: string, ...args: Array<string | number>): string {
  let i = 0;
  return template.replace(/%(\d+\$)?[sd]/g, (_m, pos: string | undefined) => {
    const idx = pos ? parseInt(pos, 10) - 1 : i++;
    return String(args[idx] ?? '');
  });
}
