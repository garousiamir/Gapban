/**
 * Admin UI primitives styled after the reference design system (card-box,
 * btn-primary/ghost/dark, comment-input, tag-chip, tab-pill, stat-tile, empty-state).
 */
import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { iconSvg } from '../shared/icons';
import { t } from './lib';

export function Icon({ name, className = '' }: { name: string; className?: string }) {
  return <span className={'gb-ico ' + className} aria-hidden="true" dangerouslySetInnerHTML={{ __html: iconSvg(name) }} />;
}

export function Card({ title, eyebrow, actions, children, className = '' }: { title?: ReactNode; eyebrow?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <section className={'gb-card-box ' + className}>
      {(title || actions) && (
        <header className="gb-card-head">
          <div>
            {eyebrow && <p className="gb-eyebrow">{eyebrow}</p>}
            {title && <h2 className="gb-card-title">{title}</h2>}
          </div>
          {actions && <div className="gb-card-actions">{actions}</div>}
        </header>
      )}
      {children}
    </section>
  );
}

type BtnProps = { children?: ReactNode; onClick?: () => void; kind?: 'primary' | 'ghost' | 'dark' | 'danger' | 'link'; type?: 'button' | 'submit'; disabled?: boolean; icon?: string; busy?: boolean; small?: boolean; title?: string; ariaLabel?: string };
export function Button({ children, onClick, kind = 'primary', type = 'button', disabled, icon, busy, small, title, ariaLabel }: BtnProps) {
  return (
    <button type={type} className={`gb-btn gb-btn-${kind}${small ? ' gb-btn-sm' : ''}`} onClick={onClick} disabled={disabled || busy} title={title} aria-label={ariaLabel} aria-busy={busy || undefined}>
      {busy ? <span className="gb-spin" aria-hidden="true" /> : icon ? <Icon name={icon} /> : null}
      {children}
    </button>
  );
}

export function IconButton({ icon, label, onClick, pressed, disabled }: { icon: string; label: string; onClick?: () => void; pressed?: boolean; disabled?: boolean }) {
  return (
    <button type="button" className="gb-icon-btn" aria-label={label} title={label} onClick={onClick} aria-pressed={pressed} disabled={disabled}>
      <Icon name={icon} />
    </button>
  );
}

export function Field({ label, help, error, children, required }: { label: ReactNode; help?: ReactNode; error?: string | null; children: (id: string, describedBy: string | undefined) => ReactNode; required?: boolean }) {
  const id = useId();
  const helpId = help ? id + '-help' : undefined;
  const errId = error ? id + '-err' : undefined;
  const described = [helpId, errId].filter(Boolean).join(' ') || undefined;
  return (
    <div className="gb-field">
      <label className="gb-label" htmlFor={id}>
        {label}
        {required && <span className="gb-req" aria-hidden="true"> *</span>}
      </label>
      {children(id, described)}
      {help && (
        <p className="gb-hint" id={helpId}>
          {help}
        </p>
      )}
      {error && (
        <p className="gb-field-error" id={errId} role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

export function TextInput({ label, value, onChange, help, type = 'text', placeholder, dir, required, error, multiline, rows = 3, maxLength, inputMode }: { label: ReactNode; value: string | number; onChange: (v: string) => void; help?: ReactNode; type?: string; placeholder?: string; dir?: 'ltr' | 'rtl' | 'auto'; required?: boolean; error?: string | null; multiline?: boolean; rows?: number; maxLength?: number; inputMode?: 'numeric' | 'text' | 'tel' | 'email' | 'url' }) {
  return (
    <Field label={label} help={help} error={error} required={required}>
      {(id, d) =>
        multiline ? (
          <textarea id={id} className="gb-input" rows={rows} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} dir={dir || 'auto'} aria-describedby={d} aria-invalid={!!error || undefined} maxLength={maxLength} />
        ) : (
          <input id={id} className="gb-input" type={type} value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} dir={dir} aria-describedby={d} aria-invalid={!!error || undefined} required={required} maxLength={maxLength} inputMode={inputMode} />
        )
      }
    </Field>
  );
}

export function Select({ label, value, onChange, options, help }: { label: ReactNode; value: string | number; onChange: (v: string) => void; options: Array<{ value: string | number; label: string; disabled?: boolean }>; help?: ReactNode }) {
  return (
    <Field label={label} help={help}>
      {(id, d) => (
        <select id={id} className="gb-input gb-select" value={value} onChange={(e) => onChange(e.target.value)} aria-describedby={d}>
          {options.map((o) => (
            <option key={String(o.value)} value={o.value} disabled={o.disabled}>
              {o.label}
            </option>
          ))}
        </select>
      )}
    </Field>
  );
}

export function Toggle({ label, checked, onChange, help, disabled }: { label: ReactNode; checked: boolean; onChange: (v: boolean) => void; help?: ReactNode; disabled?: boolean }) {
  const id = useId();
  return (
    <div className="gb-toggle-row">
      <button type="button" role="switch" id={id} aria-checked={checked} className="gb-switch" onClick={() => onChange(!checked)} disabled={disabled}>
        <span />
      </button>
      <div>
        <label htmlFor={id} className="gb-toggle-label">
          {label}
        </label>
        {help && <p className="gb-hint">{help}</p>}
      </div>
    </div>
  );
}

/** Segmented filter control (buttons with aria-pressed; not an ARIA tab widget). */
export function Tabs<T extends string>({ value, onChange, items, label }: { value: T; onChange: (v: T) => void; items: Array<{ value: T; label: ReactNode; count?: number }>; label: string }) {
  return (
    <div className="gb-tabs" role="group" aria-label={label}>
      {items.map((it) => (
        <button key={it.value} type="button" aria-pressed={value === it.value} className="gb-tab" onClick={() => onChange(it.value)}>
          {it.label}
          {it.count !== undefined && <span className="gb-tab-count">{it.count}</span>}
        </button>
      ))}
    </div>
  );
}

export function Badge({ tone = 'gray', children }: { tone?: 'gray' | 'brand' | 'green' | 'amber' | 'blue' | 'dark'; children: ReactNode }) {
  return <span className={'gb-badge gb-badge-' + tone}>{children}</span>;
}

export function Empty({ icon = 'inbox', title, children }: { icon?: string; title: ReactNode; children?: ReactNode }) {
  return (
    <div className="gb-empty">
      <div className="gb-empty-icon">
        <Icon name={icon} />
      </div>
      <p className="gb-empty-title">{title}</p>
      {children && <div className="gb-empty-body">{children}</div>}
    </div>
  );
}

export function Spinner({ label }: { label?: string }) {
  return (
    <div className="gb-loading" role="status">
      <span className="gb-spin" aria-hidden="true" />
      <span>{label || t('Loading…')}</span>
    </div>
  );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'warn' | 'error' | 'success'; children: ReactNode }) {
  return (
    <div className={'gb-notice gb-notice-' + tone} role={tone === 'error' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}

export function Modal({ title, onClose, children, footer }: { title: ReactNode; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  const ref = useRef<HTMLDivElement>(null);
  const prev = useRef<Element | null>(null);
  useEffect(() => {
    prev.current = document.activeElement;
    const first = ref.current?.querySelector<HTMLElement>('input, select, textarea, button:not(.gb-modal-close)');
    (first || ref.current)?.focus();
    return () => (prev.current as HTMLElement | null)?.focus?.();
  }, []);
  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') onClose();
    if (e.key === 'Tab' && ref.current) {
      const items = Array.from(ref.current.querySelectorAll<HTMLElement>('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter((el) => !el.hasAttribute('disabled'));
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  };
  const titleId = useId();
  return (
    <div className="gb-modal-backdrop" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="gb-modal" role="dialog" aria-modal="true" aria-labelledby={titleId} ref={ref} tabIndex={-1} onKeyDown={onKey}>
        <header className="gb-modal-head">
          <h2 id={titleId}>{title}</h2>
          <button type="button" className="gb-icon-btn gb-modal-close" aria-label={t('Close')} onClick={onClose}>
            <Icon name="xmark" />
          </button>
        </header>
        <div className="gb-modal-body">{children}</div>
        {footer && <footer className="gb-modal-foot">{footer}</footer>}
      </div>
    </div>
  );
}

type ToastItem = { id: number; text: string; tone: 'success' | 'error' | 'info' };
const ToastCtx = createContext<(text: string, tone?: ToastItem['tone']) => void>(() => undefined);
export const useToast = () => useContext(ToastCtx);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const push = useCallback((text: string, tone: ToastItem['tone'] = 'success') => {
    const id = Date.now() + Math.random();
    setItems((x) => [...x, { id, text, tone }]);
    setTimeout(() => setItems((x) => x.filter((i) => i.id !== id)), tone === 'error' ? 7000 : 3500);
  }, []);
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="gb-toasts" aria-live="polite">
        {items.map((i) => (
          <div key={i.id} className={'gb-toast gb-toast-' + i.tone} role={i.tone === 'error' ? 'alert' : 'status'}>
            <Icon name={i.tone === 'error' ? 'alert' : 'check'} />
            <span>{i.text}</span>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}

export function useAsync<T>(fn: () => Promise<T>, deps: unknown[]): { data: T | null; error: unknown; loading: boolean; reload: () => void; setData: (d: T | null) => void } {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [tick, setTick] = useState(0);
  useEffect(() => {
    let alive = true;
    setLoading(true);
    fn()
      .then((d) => alive && (setData(d), setError(null)))
      .catch((e) => alive && setError(e))
      .finally(() => alive && setLoading(false));
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);
  return { data, error, loading, reload: () => setTick((x) => x + 1), setData };
}

export function StatTile({ label, value, hint }: { label: ReactNode; value: ReactNode; hint?: ReactNode }) {
  return (
    <div className="gb-stat">
      <p className="gb-stat-label">{label}</p>
      <p className="gb-stat-value">{value}</p>
      {hint && <p className="gb-hint">{hint}</p>}
    </div>
  );
}

export function PageHeader({ title, intro, actions }: { title: ReactNode; intro?: ReactNode; actions?: ReactNode }) {
  return (
    <header className="gb-page-head">
      <div>
        <p className="gb-eyebrow">
          <Icon name="bolt" /> {t('Gapban')}
        </p>
        <h1 className="gb-page-title">{title}</h1>
        {intro && <p className="gb-page-intro">{intro}</p>}
      </div>
      {actions && <div className="gb-page-actions">{actions}</div>}
    </header>
  );
}

export function Bdi({ children }: { children: ReactNode }) {
  return <bdi dir="ltr">{children}</bdi>;
}
