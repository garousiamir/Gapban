import { useState } from 'react';
import { api, cfg, errorText, num, prefs, t } from '../lib';
import { Badge, Button, Card, Empty, Notice, PageHeader, Spinner, Tabs, useAsync, useToast } from '../ui';
import { formatDateTime } from '../../shared/format';

type Job = { id: number; type: string; status: string; attempts: number; max_attempts: number; uncertain: boolean; last_error: string; conversation_id: number | null; message_id: number | null; available_at: string; created_at: string; updated_at: string };

export default function Queue() {
  const toast = useToast();
  const [status, setStatus] = useState<'dead' | 'pending' | 'running' | 'done'>('dead');
  const q = useAsync<{ stats: Record<string, number>; jobs: Job[] }>(() => api('GET', 'admin/queue', undefined, { status }), [status]);
  const diag = useAsync<any>(() => api('GET', 'admin/diagnostics'), []);
  const inbound = useAsync<Array<{ id: number; update_id: string; status: string; error: string; received_at: string }>>(() => api('GET', 'admin/inbound'), []);
  const [running, setRunning] = useState(false);
  const inboxUrl = cfg().pages.find((p) => p.slug === 'gapban')?.url || '';

  const act = async (id: number, action: 'retry' | 'discard') => {
    try {
      await api('POST', `admin/queue/${id}/${action}`);
      toast(action === 'retry' ? t('Queued for another attempt.') : t('Discarded.'));
      q.reload();
    } catch (e) {
      toast(errorText(e), 'error');
    }
  };

  return (
    <div>
      <PageHeader
        title={t('Delivery queue & diagnostics')}
        intro={t('Every message is saved before delivery. Failed deliveries wait here until you retry or discard them.')}
        actions={
          <Button
            icon="play"
            busy={running}
            onClick={async () => {
              setRunning(true);
              try {
                const r = await api('POST', 'admin/queue/run');
                toast(t('Worker ran: %d done, %d failed.', r.processed, r.failed));
                q.reload();
              } catch (e) {
                toast(errorText(e), 'error');
              } finally {
                setRunning(false);
              }
            }}
          >
            {t('Run worker now')}
          </Button>
        }
      />
      {q.data && (
        <div className="gb-stats">
          {(['pending', 'running', 'dead', 'done'] as const).map((k) => (
            <div key={k} className="gb-stat">
              <p className="gb-stat-label">{{ pending: t('Waiting'), running: t('In progress'), dead: t('Failed (dead letters)'), done: t('Delivered (last 7 days)') }[k]}</p>
              <p className="gb-stat-value">{num(q.data!.stats[k] || 0)}</p>
            </div>
          ))}
          <div className="gb-stat">
            <p className="gb-stat-label">{t('Oldest waiting job')}</p>
            <p className="gb-stat-value">{q.data.stats.oldest_age ? t('%d s', q.data.stats.oldest_age) : '—'}</p>
          </div>
        </div>
      )}
      <Card title={t('Jobs')}>
        <Tabs label={t('Job status')} value={status} onChange={setStatus} items={[{ value: 'dead', label: t('Failed') }, { value: 'pending', label: t('Waiting') }, { value: 'running', label: t('In progress') }, { value: 'done', label: t('Done') }]} />
        {q.loading ? (
          <Spinner />
        ) : !q.data?.jobs.length ? (
          <Empty icon="list" title={t('Nothing here')} />
        ) : (
          <table className="gb-table">
            <thead>
              <tr>
                <th scope="col">#</th>
                <th scope="col">{t('Type')}</th>
                <th scope="col">{t('Attempts')}</th>
                <th scope="col">{t('Last error')}</th>
                <th scope="col">{t('Updated')}</th>
                <th scope="col">
                  <span className="gb-sr">{t('Actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {q.data.jobs.map((j) => (
                <tr key={j.id}>
                  <td>{num(j.id)}</td>
                  <td>
                    {{ deliver_message: t('Deliver message to Bale'), process_inbound: t('Process Bale update'), notify: t('Bot notice to operator') }[j.type] || j.type}
                    {j.uncertain && (
                      <>
                        {' '}
                        <Badge tone="amber">{t('Uncertain')}</Badge>
                      </>
                    )}
                    {j.conversation_id && inboxUrl && (
                      <>
                        {' '}
                        <a href={`${inboxUrl}&conversation=${j.conversation_id}`}>{t('Open conversation')}</a>
                      </>
                    )}
                  </td>
                  <td>
                    {num(j.attempts)}/{num(j.max_attempts)}
                  </td>
                  <td className="gb-hint">
                    <bdi>{j.last_error}</bdi>
                  </td>
                  <td>{formatDateTime(j.updated_at, prefs())}</td>
                  <td className="gb-row-actions">
                    {j.status === 'dead' && (
                      <>
                        <Button small onClick={() => act(j.id, 'retry')}>
                          {t('Retry')}
                        </Button>
                        <Button small kind="ghost" onClick={() => act(j.id, 'discard')}>
                          {t('Discard')}
                        </Button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {status === 'dead' && <p className="gb-hint">{t('“Uncertain” means Bale may already have the message (for example after a timeout). Check the Bale group before retrying to avoid a duplicate.')}</p>}
      </Card>
      <Card title={t('Recent Bale updates')}>
        {inbound.loading ? (
          <Spinner />
        ) : !inbound.data?.length ? (
          <Empty icon="inbox" title={t('No updates received yet')}>
            {t('If operators have replied in Bale but nothing appears here, receiving is not working (webhook or polling).')}
          </Empty>
        ) : (
          <table className="gb-table">
            <thead>
              <tr>
                <th scope="col">{t('Update')}</th>
                <th scope="col">{t('Result')}</th>
                <th scope="col">{t('Received')}</th>
              </tr>
            </thead>
            <tbody>
              {inbound.data.map((u) => (
                <tr key={u.id}>
                  <td>
                    <bdi>{u.update_id}</bdi>
                  </td>
                  <td>
                    {u.status === 'done' ? <Badge tone="green">{t('Processed')}</Badge> : u.status === 'ignored' ? <Badge>{t('Ignored')}</Badge> : u.status === 'failed' ? <Badge tone="brand">{t('Failed')}</Badge> : <Badge tone="amber">{t('Waiting')}</Badge>} <code dir="ltr">{u.error}</code>
                  </td>
                  <td>{formatDateTime(u.received_at, prefs())}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
      <Card title={t('System check')}>
        {diag.loading ? (
          <Spinner />
        ) : diag.data ? (
          <ul className="gb-status-list">
            <li>
              {t('Versions')}: Gapban {diag.data.versions.plugin} · {t('schema')} {diag.data.versions.db_schema} · WordPress {diag.data.versions.wordpress} · PHP {diag.data.versions.php} · {t('database')} <bdi>{diag.data.versions.database}</bdi>
            </li>
            <li>
              {t('Database tables')}: {Object.values(diag.data.tables).every(Boolean) ? <Badge tone="green">{t('OK')}</Badge> : <Badge tone="brand">{t('Missing tables — deactivate and reactivate the plugin')}</Badge>}
            </li>
            <li>
              {t('Transactions (InnoDB)')}: {diag.data.innodb ? <Badge tone="green">{t('OK')}</Badge> : <Badge tone="brand">{t('Not available')}</Badge>}
            </li>
            <li>
              {t('Encryption (libsodium)')}: {diag.data.sodium ? <Badge tone="green">{t('OK')}</Badge> : <Badge tone="brand">{t('Missing')}</Badge>} · {t('key source')}: {diag.data.encryption_key === 'wp-config' ? t('GAPBAN_ENCRYPTION_KEY in wp-config.php') : t('derived from WordPress salts')}
            </li>
            <li>
              {t('Persian calendar on the server (intl)')}: {diag.data.intl ? <Badge tone="green">{t('OK')}</Badge> : <Badge tone="amber">{t('Missing — Gregorian dates in exports')}</Badge>}
            </li>
            <li>
              {t('Private file storage')}:{' '}
              {diag.data.storage_custom ? (
                <Badge tone="green">{t('Outside the web root')}</Badge>
              ) : diag.data.storage_public === true ? (
                <Badge tone="brand">{t('Publicly reachable! Add the nginx rule from the documentation or set GAPBAN_STORAGE_DIR.')}</Badge>
              ) : diag.data.storage_public === false ? (
                <Badge tone="green">{t('Not publicly reachable')}</Badge>
              ) : (
                <Badge>{t('Unknown')}</Badge>
              )}
            </li>
            <li>
              {t('WP-Cron')}: {diag.data.wp_cron_disabled ? t('disabled (server cron expected)') : t('enabled (runs only on visits)')} · {t('last worker run')}: {diag.data.last_worker_run ? formatDateTime(diag.data.last_worker_run, prefs()) : t('never')}
            </li>
            <li>
              {t('Persistent object cache')}: {diag.data.object_cache ? t('yes') : t('no')}
            </li>
          </ul>
        ) : (
          <Notice tone="error">{errorText(diag.error)}</Notice>
        )}
      </Card>
    </div>
  );
}
