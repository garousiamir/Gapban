/**
 * Admin runtime helpers: REST client (cookie auth + X-WP-Nonce), i18n, prefs.
 */
import { sprintf, type DisplayPrefs } from '../shared/format';

export type AdminConfig = {
  rest: string;
  restRoot: string;
  nonce: string;
  page: string;
  pages: Array<{ slug: string; title: string; url: string }>;
  lang: 'fa' | 'en';
  dir: 'rtl' | 'ltr';
  tz: string;
  persianDigits: boolean;
  jalali: boolean;
  caps: Record<string, boolean>;
  user: { id: number; name: string; operator_id: number };
  strings: Record<string, string>;
  widgetStrings: { fa: Record<string, string>; en: Record<string, string> };
  pluginUrl: string;
  siteUrl: string;
  loaderUrl: string;
  chatUrl: string;
  fontUrl: string;
  version: string;
};

declare global {
  interface Window {
    gapbanAdmin: AdminConfig;
  }
}

export const cfg = (): AdminConfig => window.gapbanAdmin;

/** Translate an English source string (keys are the English text; see tools/i18n.mjs). */
export function t(text: string, ...args: Array<string | number>): string {
  const tr = cfg().strings[text] || text;
  return args.length ? sprintf(tr, ...args.map((a) => (typeof a === 'number' ? num(a) : a))) : tr;
}

export function num(n: number | string): string {
  const s = String(n);
  const c = cfg();
  return c.lang === 'fa' && c.persianDigits ? s.replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]) : s;
}

export function prefs(): DisplayPrefs {
  const c = cfg();
  return { locale: c.lang, persianDigits: c.persianDigits, jalali: c.jalali, timeZone: c.tz };
}

export type ApiError = { status: number; code: string; message: string; data?: any };

export async function api<T = any>(method: string, path: string, body?: unknown, query?: Record<string, string | number | boolean | undefined | null>): Promise<T> {
  const c = cfg();
  const root = path.startsWith('wp/v2/') ? c.restRoot : c.rest;
  let url = root.replace(/\/+$/, '') + '/' + path.replace(/^\//, '');
  // Like wp.apiFetch: answer in the admin user's language, not the site language.
  query = { ...(query || {}), _locale: 'user' };
  if (query) {
    const qs = Object.entries(query)
      .filter(([, v]) => v !== undefined && v !== null && v !== '')
      .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(String(v)))
      .join('&');
    if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  }
  const headers: Record<string, string> = { 'X-WP-Nonce': c.nonce, Accept: 'application/json' };
  let payload: BodyInit | undefined;
  if (body instanceof FormData) payload = body;
  else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  const res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store' });
  const text = await res.text();
  let data: any = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }
  if (!res.ok) throw { status: res.status, code: data?.code || 'http_' + res.status, message: data?.message || res.statusText, data: data?.data } as ApiError;
  return data as T;
}

export async function apiBlob(path: string): Promise<Blob> {
  const c = cfg();
  const res = await fetch(c.rest.replace(/\/+$/, '') + '/' + path.replace(/^\//, ''), { headers: { 'X-WP-Nonce': c.nonce }, credentials: 'same-origin' });
  if (!res.ok) throw { status: res.status, code: 'http_' + res.status, message: res.statusText } as ApiError;
  return res.blob();
}

export function errorText(e: unknown): string {
  const err = e as ApiError;
  if (err && err.message) return err.message;
  return t('Something went wrong. Please try again.');
}

export function can(cap: string): boolean {
  return !!cfg().caps[cap];
}

export function downloadText(filename: string, text: string, type = 'application/json'): void {
  const url = URL.createObjectURL(new Blob([text], { type: type + ';charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 3000);
}

export function randomKey(len = 20): string {
  const bytes = new Uint8Array(len);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'[b % 62]).join('');
}
