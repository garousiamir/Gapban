/**
 * Visitor REST client. The session token is kept in first-party localStorage and
 * sent only in the X-Gapban-Session header — never in URLs or cookies, so cached
 * pages and logs cannot capture it.
 */
import * as storage from './storage';

export type ApiError = { status: number; code: string; message: string; data?: any };

export class Api {
  private base: string;
  private creating: Promise<string> | null = null;
  onSessionReset: (() => void) | null = null;

  constructor(base: string) {
    this.base = base.replace(/\/+$/, '') + '/';
  }

  get token(): string | null {
    return storage.get<string | null>('token', null);
  }

  hasSession(): boolean {
    return !!this.token;
  }

  async ensureSession(honeypot = ''): Promise<string> {
    const existing = this.token;
    if (existing) return existing;
    if (!this.creating) {
      this.creating = this.raw('POST', 'session', { website: honeypot }, false)
        .then((d: any) => {
          storage.set('token', d.token);
          return d.token as string;
        })
        .finally(() => {
          this.creating = null;
        });
    }
    return this.creating;
  }

  resetSession(): void {
    storage.remove('token');
    this.onSessionReset?.();
  }

  async request<T = any>(method: string, path: string, body?: unknown, opts: { auth?: boolean; signal?: AbortSignal } = {}): Promise<T> {
    const auth = opts.auth !== false;
    try {
      return (await this.raw(method, path, body, auth, opts.signal)) as T;
    } catch (e: any) {
      if (auth && e && e.status === 401 && (e.code === 'gapban_session_invalid' || e.code === 'gapban_session_owner')) {
        // Expired/revoked/foreign session: start a fresh one once and retry.
        this.resetSession();
        await this.ensureSession();
        return (await this.raw(method, path, body, true, opts.signal)) as T;
      }
      throw e;
    }
  }

  url(path: string, query?: Record<string, string | number>): string {
    let u = this.base + path.replace(/^\//, '');
    if (query) {
      const qs = Object.entries(query)
        .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(String(v)))
        .join('&');
      u += (u.includes('?') ? '&' : '?') + qs;
    }
    return u;
  }

  private async raw(method: string, path: string, body: unknown, auth: boolean, signal?: AbortSignal): Promise<unknown> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const token = this.token;
    if (auth && token) headers['X-Gapban-Session'] = token;
    let url = this.base + path.replace(/^\//, '');
    let payload: BodyInit | undefined;
    if (method === 'GET' && body && typeof body === 'object') {
      url = this.url(path, body as Record<string, string | number>);
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const res = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store', signal });
    const text = await res.text();
    let data: any = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      data = null;
    }
    if (!res.ok) {
      throw { status: res.status, code: data?.code || 'http_' + res.status, message: data?.message || res.statusText, data: data?.data } as ApiError;
    }
    return data;
  }

  /** Authenticated binary GET (attachments, transcript) → Blob. */
  async blob(path: string): Promise<Blob> {
    const token = this.token;
    const res = await fetch(this.base + path.replace(/^\//, ''), {
      headers: token ? { 'X-Gapban-Session': token } : {},
      credentials: 'same-origin',
      cache: 'no-store',
    });
    if (!res.ok) throw { status: res.status, code: 'http_' + res.status, message: res.statusText } as ApiError;
    return res.blob();
  }

  /** Multipart upload with progress and cancellation. */
  upload(path: string, file: Blob, filename: string, extra: Record<string, string>, onProgress: (p: number) => void): { promise: Promise<any>; abort: () => void } {
    const xhr = new XMLHttpRequest();
    const promise = new Promise<any>((resolve, reject) => {
      xhr.open('POST', this.base + path.replace(/^\//, ''));
      const token = this.token;
      if (token) xhr.setRequestHeader('X-Gapban-Session', token);
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) onProgress(e.loaded / e.total);
      };
      xhr.onload = () => {
        let data: any = null;
        try {
          data = JSON.parse(xhr.responseText);
        } catch {
          data = null;
        }
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else reject({ status: xhr.status, code: data?.code || 'http_' + xhr.status, message: data?.message || '' } as ApiError);
      };
      xhr.onerror = () => reject({ status: 0, code: 'network', message: '' } as ApiError);
      xhr.onabort = () => reject({ status: 0, code: 'aborted', message: '' } as ApiError);
      const form = new FormData();
      form.append('file', file, filename);
      for (const [k, v] of Object.entries(extra)) form.append(k, v);
      xhr.send(form);
    });
    return { promise, abort: () => xhr.abort() };
  }
}
