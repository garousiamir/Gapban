import { useEffect, useState } from 'react';
import { api, errorText, t } from '../lib';
import { Badge, Bdi, Button, Card, Icon, Modal, Notice, PageHeader, Spinner, TextInput, useAsync, useToast } from '../ui';

type Connection = {
  configured: boolean;
  token_masked: string;
  token_from_config: boolean;
  token_invalid_at: string;
  identity: { id: string; username: string; first_name: string; verified_at: string } | null;
  transport: 'webhook' | 'polling';
  webhook: { set: boolean; owned: boolean; foreign_host: string; pending_update_count: number; last_error_date: string; last_error_message: string; url: string } | null;
  webhook_error: string | null;
  capabilities: Record<string, { status: string; label: string; note: string; observed_at: string }>;
  destination: { chat_id: string; title: string };
  https: boolean;
  cron_url: string;
  queue: Record<string, number>;
  api_host: string;
};

export default function Setup() {
  const toast = useToast();
  const conn = useAsync<Connection>(() => api('GET', 'admin/connection'), []);
  const diag = useAsync<any>(() => api('GET', 'admin/diagnostics'), []);
  const [token, setToken] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [bind, setBind] = useState<{ id: number; code: string; command: string; expires_at: string } | null>(null);
  const [foreign, setForeign] = useState<string | null>(null);
  const [cronUrl, setCronUrl] = useState<string | null>(null);

  const run = async (key: string, fn: () => Promise<unknown>, ok?: string) => {
    setBusy(key);
    try {
      await fn();
      if (ok) toast(ok);
      conn.reload();
      diag.reload();
    } catch (e) {
      toast(errorText(e), 'error');
    } finally {
      setBusy(null);
    }
  };

  // While a binding code is pending, check whether the bot received it.
  useEffect(() => {
    if (!bind) return;
    const timer = window.setInterval(async () => {
      const st = await api('GET', `admin/enrollments/${bind.id}`).catch(() => null);
      if (st?.used_at) {
        window.clearInterval(timer);
        setBind(null);
        toast(st.result === 'bound' ? t('Support group connected.') : t('The code was used but binding failed.'), st.result === 'bound' ? 'success' : 'error');
        conn.reload();
      }
    }, 4000);
    return () => window.clearInterval(timer);
  }, [bind, conn, toast]);

  if (conn.loading && !conn.data) return <Spinner />;
  const c = conn.data;
  if (!c) return <Notice tone="error">{errorText(conn.error)}</Notice>;
  const steps = [
    { done: c.configured && !c.token_invalid_at, label: t('Bot token') },
    { done: !!c.destination.chat_id, label: t('Support group') },
    { done: !!diag.data?.test_message, label: t('Test sending') },
    { done: !!diag.data?.test_reply_at, label: t('Test receiving') },
    { done: c.transport === 'webhook' ? !!c.webhook?.owned : !!c.cron_url, label: t('Receiving updates') },
  ];

  return (
    <div className="gb-setup">
      <PageHeader title={t('Setup & connection status')} intro={t('Connect a Bale bot, choose the support group, verify sending and receiving, and pick how updates reach this site.')} />
      <ol className="gb-steps" aria-label={t('Setup progress')}>
        {steps.map((s, i) => (
          <li key={i} className={s.done ? 'gb-step-done' : ''}>
            <span className="gb-step-dot" aria-hidden="true">
              {s.done ? <Icon name="check" /> : i + 1}
            </span>
            {s.label}
            <span className="gb-sr">{s.done ? t('completed') : t('not completed')}</span>
          </li>
        ))}
      </ol>

      <div className="gb-grid-2">
        <Card eyebrow={t('Step 1')} title={t('Bale bot')}>
          <ol className="gb-howto">
            <li>{t('In Bale, open @BotFather and create a bot (/newbot).')}</li>
            <li>{t('Copy the token it gives you and paste it below. The token is encrypted and never shown again.')}</li>
          </ol>
          {c.configured ? (
            <div className="gb-kv">
              <p>
                {t('Token')}: <Bdi>{c.token_masked}</Bdi> {c.token_from_config && <Badge tone="blue">{t('from wp-config.php')}</Badge>}
              </p>
              {c.identity && (
                <p>
                  {t('Bot')}: <Bdi>@{c.identity.username}</Bdi> ({c.identity.first_name})
                </p>
              )}
              {c.token_invalid_at && <Notice tone="error">{t('Bale rejected the token recently. Save a new token.')}</Notice>}
            </div>
          ) : (
            <Notice tone="warn">{t('No bot is connected yet. Conversations still work in the WordPress inbox.')}</Notice>
          )}
          {!c.token_from_config && (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                run('token', () => api('POST', 'admin/connection/token', { token }).then(() => setToken('')), t('Bot connected.'));
              }}
            >
              <TextInput label={c.configured ? t('Replace token') : t('Bot token')} value={token} onChange={setToken} type="password" dir="ltr" placeholder="123456789:AA…" help={t('Gapban checks the token with getMe before saving it.')} />
              <div className="gb-actions">
                <Button type="submit" busy={busy === 'token'} disabled={!token.trim()} icon="plug">
                  {t('Verify and save')}
                </Button>
                {c.configured && (
                  <Button kind="ghost" busy={busy === 'rm'} onClick={() => window.confirm(t('Disconnect the bot? Its webhook is removed only if it belongs to this site.')) && run('rm', () => api('DELETE', 'admin/connection/token'), t('Bot disconnected.'))}>
                    {t('Disconnect')}
                  </Button>
                )}
              </div>
            </form>
          )}
        </Card>

        <Card eyebrow={t('Step 2')} title={t('Support group')}>
          <ol className="gb-howto">
            <li>{t('Create a Bale group for your support team and add the bot to it.')}</li>
            <li>{t('Allow the bot to send messages in the group.')}</li>
            <li>{t('Generate a one-time code below and send the command in that group. The group is detected automatically.')}</li>
          </ol>
          <Notice tone="warn">{t('Everyone in a Bale group can read every conversation sent there. If some operators must not see some conversations, use separate groups per department or answer from the WordPress inbox.')}</Notice>
          {c.destination.chat_id ? (
            <p>
              {t('Connected group')}: <strong>{c.destination.title || t('(untitled)')}</strong> <Bdi>{c.destination.chat_id}</Bdi>
            </p>
          ) : null}
          <div className="gb-actions">
            <Button kind={c.destination.chat_id ? 'ghost' : 'primary'} disabled={!c.configured} busy={busy === 'bind'} onClick={() => run('bind', async () => setBind(await api('POST', 'admin/teams/0/bind')))} icon="users">
              {c.destination.chat_id ? t('Connect a different group') : t('Get a binding code')}
            </Button>
          </div>
          {bind && (
            <Notice>
              <p>{t('Send this command in the support group within 15 minutes:')}</p>
              <p className="gb-code">
                <Bdi>{bind.command}</Bdi>
              </p>
              <Button small kind="ghost" icon="copy" onClick={() => navigator.clipboard?.writeText(bind.command).then(() => toast(t('Copied.')))}>
                {t('Copy')}
              </Button>
              <p className="gb-hint">{t('Waiting for the bot to receive it… (receiving must already work: webhook or polling)')}</p>
            </Notice>
          )}
          <details>
            <summary>{t('Enter the chat ID manually')}</summary>
            <ManualDestination current={c.destination.chat_id} onSaved={conn.reload} />
          </details>
        </Card>

        <Card eyebrow={t('Step 3')} title={t('Receiving updates from Bale')}>
          <p>{t('Choose how Bale delivers operator replies to this site.')}</p>
          <div className="gb-choice">
            <div className={'gb-choice-item' + (c.transport === 'webhook' ? ' gb-choice-on' : '')}>
              <h3>{t('Webhook (recommended)')}</h3>
              <p className="gb-hint">{t('Instant delivery. Requires HTTPS and a site Bale can reach. The webhook URL contains a long secret that you can rotate.')}</p>
              {!c.https && <Notice tone="warn">{t('This site does not use HTTPS, so a webhook cannot be registered.')}</Notice>}
              {c.webhook && (
                <ul className="gb-status-list">
                  <li>
                    {t('Status')}: {c.webhook.owned ? <Badge tone="green">{t('Active for this site')}</Badge> : c.webhook.set ? <Badge tone="amber">{t('Points to another address: %s', c.webhook.foreign_host)}</Badge> : <Badge>{t('Not set')}</Badge>}
                  </li>
                  <li>
                    {t('Pending updates at Bale')}: {c.webhook.pending_update_count}
                  </li>
                  {c.webhook.last_error_message && (
                    <li>
                      {t('Last error')}: <Bdi>{c.webhook.last_error_message}</Bdi>
                    </li>
                  )}
                </ul>
              )}
              {c.webhook_error && <Notice tone="error">{c.webhook_error}</Notice>}
              <div className="gb-actions">
                <Button
                  disabled={!c.configured || !c.https}
                  busy={busy === 'hook'}
                  onClick={() =>
                    run('hook', async () => {
                      const r = await api('POST', 'admin/connection/webhook', { action: 'install' });
                      if (r.reason === 'foreign_webhook') setForeign(r.foreign_host);
                      else toast(t('Webhook registered.'));
                    })
                  }
                >
                  {t('Register webhook')}
                </Button>
                {c.webhook?.owned && (
                  <>
                    <Button kind="ghost" busy={busy === 'rot'} onClick={() => run('rot', () => api('POST', 'admin/connection/webhook', { action: 'rotate' }), t('Webhook secret rotated.'))}>
                      {t('Rotate secret')}
                    </Button>
                    <Button kind="ghost" busy={busy === 'unhook'} onClick={() => run('unhook', () => api('POST', 'admin/connection/webhook', { action: 'remove' }), t('Webhook removed.'))}>
                      {t('Remove webhook')}
                    </Button>
                  </>
                )}
              </div>
            </div>
            <div className={'gb-choice-item' + (c.transport === 'polling' ? ' gb-choice-on' : '')}>
              <h3>{t('Polling (getUpdates)')}</h3>
              <p className="gb-hint">{t('For sites Bale cannot reach. Needs a real server cron job; replies arrive within the cron interval (about 1 minute). Cannot be used while a webhook is set.')}</p>
              <div className="gb-actions">
                <Button kind="ghost" disabled={!c.configured} busy={busy === 'poll'} onClick={() => run('poll', () => api('POST', 'admin/connection/webhook', { action: 'use_polling' }), t('Polling mode enabled. Set up the cron job below.'))}>
                  {t('Use polling')}
                </Button>
              </div>
            </div>
          </div>
        </Card>

        <Card eyebrow={t('Step 4')} title={t('Test sending and receiving')}>
          <ol className="gb-howto">
            <li>{t('Send a test message to the support group.')}</li>
            <li>{t('In Bale, reply to that message with any text. This page then shows that receiving works.')}</li>
          </ol>
          <div className="gb-actions">
            <Button disabled={!c.configured || !c.destination.chat_id} busy={busy === 'test'} onClick={() => run('test', () => api('POST', 'admin/connection/test'), t('Test message sent. Now reply to it in Bale.'))} icon="paper-plane">
              {t('Send test message')}
            </Button>
            <Button kind="ghost" onClick={() => diag.reload()} icon="retry">
              {t('Check again')}
            </Button>
          </div>
          <ul className="gb-status-list">
            <li>
              {t('Sending')}: {diag.data?.test_message ? <Badge tone="green">{t('Works')}</Badge> : <Badge>{t('Not tested')}</Badge>}
            </li>
            <li>
              {t('Receiving')}: {diag.data?.test_reply_at ? <Badge tone="green">{t('Works')}</Badge> : <Badge>{t('Waiting for your reply in Bale')}</Badge>}
            </li>
          </ul>
        </Card>
      </div>

      <Card title={t('Background processing (cron)')} eyebrow={t('Reliability')}>
        <p>{t('Messages are saved first and then delivered by a queue. WordPress’s built-in cron only runs when someone visits the site, so production sites should run the worker from the server’s cron every minute.')}</p>
        <p>
          <strong>{t('With WP-CLI (preferred):')}</strong>
        </p>
        <pre className="gb-pre" dir="ltr">
          {'* * * * * cd /path/to/wordpress && wp gapban worker --seconds=50 --quiet\n'}
          {c.transport === 'polling' ? '* * * * * cd /path/to/wordpress && wp gapban poll --seconds=50 --quiet\n' : ''}
        </pre>
        <p>
          <strong>{t('Without WP-CLI (shared hosting): call the secret cron URL every minute:')}</strong>
        </p>
        {cronUrl ? (
          <>
            <pre className="gb-pre" dir="ltr">{`* * * * * curl -fsS -m 55 -X POST '${cronUrl}' >/dev/null`}</pre>
            <Notice tone="warn">{t('This URL is shown only once. Keep it private; anyone with it can trigger the worker (but cannot read data).')}</Notice>
          </>
        ) : (
          <Button kind="ghost" icon="lock" onClick={() => run('cron', async () => setCronUrl((await api('POST', 'admin/connection/cron-secret')).cron_url))}>
            {c.cron_url ? t('Generate a new cron URL') : t('Generate cron URL')}
          </Button>
        )}
        {diag.data && (
          <p className="gb-hint">
            {t('Last worker run')}: {diag.data.last_worker_run || t('never')} · {t('Queue')}: {t('%d pending, %d failed', c.queue.pending || 0, c.queue.dead || 0)}
          </p>
        )}
      </Card>

      <Card title={t('Bale capabilities')} eyebrow={t('Verified on this site')}>
        <p className="gb-hint">{t('“Observed” means this site actually performed the operation successfully. Features Bale’s bot API does not offer are disabled instead of imitated.')}</p>
        <table className="gb-table">
          <thead>
            <tr>
              <th scope="col">{t('Capability')}</th>
              <th scope="col">{t('Status')}</th>
              <th scope="col">{t('Notes')}</th>
            </tr>
          </thead>
          <tbody>
            {Object.entries(c.capabilities).map(([k, v]) => (
              <tr key={k}>
                <td>{v.label}</td>
                <td>{v.status === 'observed' ? <Badge tone="green">{t('Observed')}</Badge> : v.status === 'unsupported' ? <Badge tone="dark">{t('Not available')}</Badge> : <Badge tone="amber">{t('Not yet observed')}</Badge>}</td>
                <td className="gb-hint">{v.note || v.observed_at}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      <Card title={t('Troubleshooting')}>
        <details>
          <summary>{t('“Could not reach Bale”')}</summary>
          <p>{t('The server needs outbound HTTPS access to %s. Ask your host to allow it; some hosts block outgoing requests by default.', c.api_host)}</p>
        </details>
        <details>
          <summary>{t('Operators reply in Bale but the visitor gets nothing')}</summary>
          <p>{t('Operators must use Bale’s Reply on a message the bot sent for that conversation. Replies to other messages are rejected and the bot explains why. Check that receiving works (webhook or polling) and that the operator has linked their Bale account on the Operators page.')}</p>
        </details>
        <details>
          <summary>{t('Messages stay in “waiting to send”')}</summary>
          <p>{t('The background worker is not running. Set up the cron job above, or open “Delivery queue & diagnostics” and run the worker manually.')}</p>
        </details>
        <details>
          <summary>{t('Another app already uses this bot’s webhook')}</summary>
          <p>{t('A bot has only one webhook. Use a separate bot for Gapban, or replace the other webhook only if you are sure it is no longer needed.')}</p>
        </details>
      </Card>

      {foreign !== null && (
        <Modal
          title={t('Replace another application’s webhook?')}
          onClose={() => setForeign(null)}
          footer={
            <>
              <Button kind="ghost" onClick={() => setForeign(null)}>
                {t('Cancel')}
              </Button>
              <Button
                kind="danger"
                onClick={() =>
                  run('hook', async () => {
                    await api('POST', 'admin/connection/webhook', { action: 'install', force: true });
                    setForeign(null);
                  }, t('Webhook registered.'))
                }
              >
                {t('Replace webhook')}
              </Button>
            </>
          }
        >
          <p>{t('This bot currently sends its updates to %s. Replacing it will stop that application from receiving messages from this bot.', foreign || t('another address'))}</p>
        </Modal>
      )}
    </div>
  );
}

function ManualDestination({ current, onSaved }: { current: string; onSaved: () => void }) {
  const toast = useToast();
  const [chat, setChat] = useState(current);
  return (
    <form
      className="gb-inline-form"
      onSubmit={async (e) => {
        e.preventDefault();
        try {
          await api('POST', 'admin/settings', { default_destination_chat: chat });
          toast(t('Saved.'));
          onSaved();
        } catch (err) {
          toast(errorText(err), 'error');
        }
      }}
    >
      <TextInput label={t('Chat ID')} value={chat} onChange={setChat} dir="ltr" inputMode="numeric" help={t('Group IDs are usually negative numbers. Persian digits are accepted.')} />
      <Button type="submit" kind="ghost">
        {t('Save')}
      </Button>
    </form>
  );
}
