import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';
import { cfg, t } from './lib';
import { ToastProvider } from './ui';
import Inbox from './pages/Inbox';
import Setup from './pages/Setup';
import Operators from './pages/Operators';
import Appearance from './pages/Appearance';
import Rules from './pages/Rules';
import Hours from './pages/Hours';
import Knowledge from './pages/Knowledge';
import Reports from './pages/Reports';
import Privacy from './pages/Privacy';
import Queue from './pages/Queue';
import Tools from './pages/Tools';

const PAGES: Record<string, ComponentType> = {
  gapban: Inbox,
  'gapban-setup': Setup,
  'gapban-operators': Operators,
  'gapban-appearance': Appearance,
  'gapban-rules': Rules,
  'gapban-hours': Hours,
  'gapban-knowledge': Knowledge,
  'gapban-reports': Reports,
  'gapban-privacy': Privacy,
  'gapban-queue': Queue,
  'gapban-tools': Tools,
};

function App() {
  const page = cfg().page;
  const Page = PAGES[page] || Inbox;
  return (
    <ToastProvider>
      <div className="gb-app" dir={cfg().dir} lang={cfg().lang}>
        <nav className="gb-nav" aria-label={t('Gapban sections')}>
          {cfg().pages.map((p) => (
            <a key={p.slug} href={p.url} className="gb-nav-link" aria-current={p.slug === page ? 'page' : undefined}>
              {p.title}
            </a>
          ))}
        </nav>
        <Page />
      </div>
    </ToastProvider>
  );
}

const mount = document.getElementById('gapban-admin');
if (mount) createRoot(mount).render(<App />);
