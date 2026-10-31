/**
 * First-party browser storage, always wrapped: private windows, blocked storage
 * and quota errors degrade to in-memory behaviour instead of breaking the widget.
 * Keys are prefixed "gapban:" and disclosed in the privacy text.
 */
const memory = new Map<string, string>();

function store(kind: 'local' | 'session'): Storage | null {
  try {
    const s = kind === 'local' ? window.localStorage : window.sessionStorage;
    const probe = '__gapban_probe__';
    s.setItem(probe, '1');
    s.removeItem(probe);
    return s;
  } catch {
    return null;
  }
}

export function get<T>(key: string, fallback: T, kind: 'local' | 'session' = 'local'): T {
  const full = 'gapban:' + key;
  try {
    const s = store(kind);
    const raw = s ? s.getItem(full) : memory.get(kind + full) ?? null;
    return raw === null || raw === undefined ? fallback : (JSON.parse(raw) as T);
  } catch {
    return fallback;
  }
}

export function set(key: string, value: unknown, kind: 'local' | 'session' = 'local'): void {
  const full = 'gapban:' + key;
  const raw = JSON.stringify(value);
  try {
    const s = store(kind);
    if (s) s.setItem(full, raw);
    else memory.set(kind + full, raw);
  } catch {
    memory.set(kind + full, raw);
  }
}

export function remove(key: string, kind: 'local' | 'session' = 'local'): void {
  const full = 'gapban:' + key;
  try {
    store(kind)?.removeItem(full);
  } catch {
    /* ignore */
  }
  memory.delete(kind + full);
}
