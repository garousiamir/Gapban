import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, apiBlob, can, cfg, downloadText, errorText, num, prefs, randomKey, t } from '../lib';
import { Badge, Button, Empty, Icon, IconButton, Modal, Notice, PageHeader, Select, Spinner, Tabs, useAsync, useToast } from '../ui';
import { formatBytes, formatDateTime, formatTime, renderRichText } from '../../shared/format';

type Conv = {
  id: number;
  reference: string;
  status: string;
  priority: number;
  team_id: number | null;
  assigned_operator_id: number | null;
  routing: { mode: string; provider: string; destination: string; reason: string };
  page_url: string;
  page_title: string;
  offline: boolean;
  unread: number;
  visitor_typing: boolean;
  last_message_at: string | null;
  created_at: string;
  closed_at: string | null;
  first_human_response_at: string | null;
  version: number;
  visitor: { id: number; name: string; email: string; phone: string; blocked: boolean; custom?: Record<string, string>; previous?: number; first_seen_at?: string; wp_user_id?: number | null };
  preview: string | null;
  tags: number[];
  rating?: { score: number; comment: string; at: string } | null;
  audit?: Array<{ action: string; actor_type: string; actor_id: number; details: any; at: string }>;
  can_reply?: boolean;
  visitor_read_message_id: number;
};
type Msg = {
  id: number;
  author: string;
  author_id: number;
  author_name: string;
  kind: string;
  internal: boolean;
  body: string;
  source: string;
  delivery_status: string;
  delivery_error: string;
  possible_duplicate: boolean;
  created_at: string;
  attachments: Array<{ id: string; name: string; mime: string; size: number; image: boolean }>;
};
type Operator = { id: number; display_name: string; status: string; available_now: boolean };
type Team = { id: number; name: string };
type Tag = { id: number; name: string; color: string };

const STATUS_LABEL = (): Record<string, string> => ({
  queued: t('Waiting for an operator'),
  open: t('Open'),
  waiting: t('Waiting for visitor'),
  closed: t('Closed'),
  spam: t('Spam'),
});
const PRIORITY = (): string[] => [t('Normal'), t('Medium'), t('High'), t('Urgent')];

export default function Inbox() {
  const toast = useToast();
  const [status, setStatus] = useState<'active' | 'queued' | 'open' | 'waiting' | 'closed' | 'spam'>('active');
  const [assigned, setAssigned] = useState<'any' | 'me' | 'none'>('any');
  const [team, setTeam] = useState('');
  const [tag, setTag] = useState('');
  const [q, setQ] = useState('');
  const [query, setQuery] = useState('');
  const [items, setItems] = useState<Conv[]>([]);
  const [next, setNext] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [selected, setSelected] = useState<number | null>(() => Number(new URLSearchParams(location.search).get('conversation')) || null);
  const [availability, setAvailability] = useState<string>('auto');
  const operators = useAsync<Operator[]>(() => api('GET', 'admin/operators'), []);
  const teams = useAsync<Team[]>(() => api('GET', 'admin/teams'), []);
  const tags = useAsync<Tag[]>(() => api('GET', 'admin/tags'), []);

  const load = useCallback(
    async (append = false) => {
      try {
        const res = await api<{ items: Conv[]; next_before: number | null }>('GET', 'admin/conversations', undefined, {
          status,
          assigned: assigned === 'any' ? '' : assigned,
          team,
          tag,
          q: query,
          before: append && next ? next : undefined,
        });
        setItems((prev) => (append ? [...prev, ...res.items] : res.items));
        setNext(res.next_before);
      } catch (e) {
        toast(errorText(e), 'error');
      } finally {
        setLoading(false);
      }
    },
    [status, assigned, team, tag, query, next, toast],
  );

  useEffect(() => {
    setLoading(true);
    load(false);
    const timer = window.setInterval(() => document.visibilityState === 'visible' && load(false), 10_000);
    return () => window.clearInterval(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, assigned, team, tag, query]);

  // Presence heartbeat: only while the inbox is open; expires automatically (2 minutes).
  useEffect(() => {
    const beat = () => api('POST', 'admin/heartbeat', availability === 'auto' ? {} : { availability }).then((r) => setAvailability(r.availability)).catch(() => undefined);
    beat();
    const timer = window.setInterval(() => document.visibilityState === 'visible' && beat(), 30_000);
    return () => window.clearInterval(timer);
  }, [availability]);

  const opName = (id: number | null) => operators.data?.find((o) => o.id === id)?.display_name || '';

  return (
    <div className="gb-inbox">
      <PageHeader
        title={t('Conversations inbox')}
        intro={t('Every conversation is stored in WordPress. Replies sent here are also shown in the Bale group so the team stays in sync.')}
        actions={
          <div className="gb-avail">
            <Select
              label={t('My status')}
              value={availability}
              onChange={(v) => setAvailability(v)}
              options={[
                { value: 'auto', label: t('Automatic (online while this page is open)') },
                { value: 'online', label: t('Online') },
                { value: 'away', label: t('Away') },
                { value: 'offline', label: t('Offline') },
              ]}
            />
          </div>
        }
      />
      <div className="gb-inbox-grid">
        <aside className="gb-inbox-list gb-card-box" aria-label={t('Conversation list')}>
          <form
            className="gb-search"
            role="search"
            onSubmit={(e) => {
              e.preventDefault();
              setQuery(q.trim());
            }}
          >
            <label className="gb-sr" htmlFor="gb-inbox-q">
              {t('Search conversations')}
            </label>
            <input id="gb-inbox-q" className="gb-input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Search name, email, reference or text…')} />
            <IconButton icon="search" label={t('Search')} onClick={() => setQuery(q.trim())} />
          </form>
          <Tabs
            label={t('Status filter')}
            value={status}
            onChange={setStatus}
            items={[
              { value: 'active', label: t('Active') },
              { value: 'queued', label: t('Queue') },
              { value: 'waiting', label: t('Waiting') },
              { value: 'closed', label: t('Closed') },
              { value: 'spam', label: t('Spam') },
            ]}
          />
          <div className="gb-filters">
            <select className="gb-input gb-select gb-select-sm" aria-label={t('Assignment filter')} value={assigned} onChange={(e) => setAssigned(e.target.value as 'any')}>
              <option value="any">{t('All assignees')}</option>
              <option value="me">{t('Assigned to me')}</option>
              <option value="none">{t('Unassigned')}</option>
            </select>
            <select className="gb-input gb-select gb-select-sm" aria-label={t('Department filter')} value={team} onChange={(e) => setTeam(e.target.value)}>
              <option value="">{t('All departments')}</option>
              {teams.data?.map((tm) => (
                <option key={tm.id} value={tm.id}>
                  {tm.name}
                </option>
              ))}
            </select>
            {!!tags.data?.length && (
              <select className="gb-input gb-select gb-select-sm" aria-label={t('Tag filter')} value={tag} onChange={(e) => setTag(e.target.value)}>
                <option value="">{t('All tags')}</option>
                {tags.data.map((tg) => (
                  <option key={tg.id} value={tg.id}>
                    {tg.name}
                  </option>
                ))}
              </select>
            )}
          </div>
          {loading ? (
            <Spinner />
          ) : items.length === 0 ? (
            <Empty title={t('No conversations here yet')}>{t('New website conversations will appear here automatically.')}</Empty>
          ) : (
            <ul className="gb-conv-list">
              {items.map((c) => (
                <li key={c.id}>
                  <button type="button" className="gb-conv-item" aria-current={selected === c.id || undefined} onClick={() => setSelected(c.id)}>
                    <span className="gb-conv-top">
                      <strong className="gb-conv-name">{c.visitor.name || t('Visitor')}</strong>
                      <span className="gb-conv-time">{c.last_message_at ? formatTime(c.last_message_at, prefs()) : ''}</span>
                    </span>
                    <span className="gb-conv-preview" dir="auto">
                      {c.preview || '—'}
                    </span>
                    <span className="gb-conv-meta">
                      <Badge tone={c.status === 'queued' ? 'brand' : c.status === 'closed' ? 'gray' : c.status === 'spam' ? 'dark' : 'green'}>{STATUS_LABEL()[c.status]}</Badge>
                      {c.priority > 0 && <Badge tone="amber">{PRIORITY()[c.priority]}</Badge>}
                      {c.unread > 0 && <Badge tone="brand">{t('%d new', c.unread)}</Badge>}
                      {c.assigned_operator_id ? <span className="gb-hint">{opName(c.assigned_operator_id)}</span> : null}
                      <bdi className="gb-ref">{c.reference}</bdi>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          {next && (
            <Button kind="ghost" onClick={() => load(true)}>
              {t('Load more')}
            </Button>
          )}
        </aside>
        <main className="gb-inbox-detail">
          {selected ? (
            <Detail
              key={selected}
              id={selected}
              operators={operators.data || []}
              teams={teams.data || []}
              tags={tags.data || []}
              reloadTags={tags.reload}
              onChanged={() => load(false)}
              onDeleted={() => {
                setSelected(null);
                load(false);
              }}
            />
          ) : (
            <div className="gb-card-box">
              <Empty icon="comments" title={t('Select a conversation')}>
                {t('Pick a conversation from the list to read and reply.')}
              </Empty>
            </div>
          )}
        </main>
      </div>
    </div>
  );
}

function Detail({ id, operators, teams, tags, reloadTags, onChanged, onDeleted }: { id: number; operators: Operator[]; teams: Team[]; tags: Tag[]; reloadTags: () => void; onChanged: () => void; onDeleted: () => void }) {
  const toast = useToast();
  const [conv, setConv] = useState<Conv | null>(null);
  const [messages, setMessages] = useState<Msg[]>([]);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [visitorTyping, setVisitorTyping] = useState(false);
  const [modal, setModal] = useState<null | 'transfer' | 'tags' | 'delete' | 'block'>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const lastId = useRef(0);

  const loadConv = useCallback(async () => {
    try {
      setConv(await api<Conv>('GET', `admin/conversations/${id}`));
    } catch (e) {
      setError(errorText(e));
    }
  }, [id]);

  const merge = (list: Msg[]) => {
    setMessages((prev) => {
      const map = new Map(prev.map((m) => [m.id, m]));
      list.forEach((m) => map.set(m.id, m));
      return Array.from(map.values()).sort((a, b) => a.id - b.id);
    });
    list.forEach((m) => (lastId.current = Math.max(lastId.current, m.id)));
  };

  const poll = useCallback(async () => {
    try {
      const res = await api<{ messages: Msg[]; conversation: any; has_more: boolean }>('GET', `admin/conversations/${id}/messages`, undefined, lastId.current ? { after: lastId.current } : {});
      if (!lastId.current) setHasMore(res.has_more);
      const atBottom = listRef.current ? listRef.current.scrollHeight - listRef.current.scrollTop - listRef.current.clientHeight < 80 : true;
      if (res.messages.length) {
        merge(res.messages);
        if (atBottom) setTimeout(() => listRef.current?.scrollTo({ top: listRef.current.scrollHeight }), 30);
      }
      setVisitorTyping(res.conversation.visitor_typing);
      setConv((c) => (c ? { ...c, status: res.conversation.status, assigned_operator_id: res.conversation.assigned_operator_id, version: res.conversation.version, visitor_read_message_id: res.conversation.visitor_read_message_id } : c));
      if (document.visibilityState === 'visible' && lastId.current) api('POST', `admin/conversations/${id}/read`, { message_id: lastId.current }).catch(() => undefined);
    } catch (e) {
      setError(errorText(e));
    }
  }, [id]);

  // Delivery states of earlier messages change after send; refresh the tail periodically.
  const refreshTail = useCallback(async () => {
    const res = await api<{ messages: Msg[] }>('GET', `admin/conversations/${id}/messages`).catch(() => null);
    if (res) merge(res.messages);
  }, [id]);

  useEffect(() => {
    lastId.current = 0;
    setMessages([]);
    loadConv();
    poll();
    let n = 0;
    const timer = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      poll();
      if (++n % 4 === 0) refreshTail();
    }, 3000);
    return () => window.clearInterval(timer);
  }, [id, loadConv, poll, refreshTail]);

  const older = async () => {
    const first = messages[0]?.id;
    if (!first) return;
    const res = await api<{ messages: Msg[]; has_more: boolean }>('GET', `admin/conversations/${id}/messages`, undefined, { before: first });
    merge(res.messages);
    setHasMore(res.has_more);
  };

  const action = async (body: Record<string, unknown>, success?: string) => {
    try {
      setConv(await api<Conv>('POST', `admin/conversations/${id}/actions`, body));
      if (success) toast(success);
      onChanged();
    } catch (e) {
      toast(errorText(e), 'error');
      loadConv();
    }
  };

  if (error && !conv) return <Notice tone="error">{error}</Notice>;
  if (!conv) return <Spinner />;
  const myOp = cfg().user.operator_id;
  const assignedName = operators.find((o) => o.id === conv.assigned_operator_id)?.display_name;

  return (
    <div className="gb-detail">
      <div className="gb-card-box gb-thread">
        <header className="gb-thread-head">
          <div>
            <h2 className="gb-card-title">
              {conv.visitor.name || t('Visitor')} <bdi className="gb-ref">{conv.reference}</bdi>
            </h2>
            <p className="gb-hint">
              {STATUS_LABEL()[conv.status]} · {assignedName ? t('Assigned to %s', assignedName) : t('Unassigned')}
              {conv.routing.mode === 'reply' ? ` · ${t('Bale: %s', conv.routing.destination || t('support group'))}` : ` · ${t('WordPress inbox only')}`}
            </p>
          </div>
          <div className="gb-thread-actions">
            {!conv.assigned_operator_id && can('gapban_reply') && (
              <Button small onClick={() => action({ action: 'claim' }, t('Conversation assigned to you.'))} icon="user">
                {t('Claim')}
              </Button>
            )}
            {can('gapban_assign') && (
              <Button small kind="ghost" icon="arrows" onClick={() => setModal('transfer')}>
                {t('Transfer')}
              </Button>
            )}
            {['queued', 'open', 'waiting'].includes(conv.status) ? (
              <Button small kind="dark" icon="check" onClick={() => action({ action: 'close' }, t('Conversation closed.'))}>
                {t('Close')}
              </Button>
            ) : conv.status === 'closed' ? (
              <Button small kind="ghost" icon="retry" onClick={() => action({ action: 'reopen' }, t('Conversation reopened.'))}>
                {t('Reopen')}
              </Button>
            ) : null}
          </div>
        </header>
        <div className="gb-messages" ref={listRef} role="log" aria-label={t('Messages')} aria-live="polite">
          {hasMore && (
            <Button kind="ghost" small onClick={older}>
              {t('Load earlier messages')}
            </Button>
          )}
          {messages.map((m) => (
            <MessageRow key={m.id} m={m} seen={m.id <= conv.visitor_read_message_id} />
          ))}
          {visitorTyping && <p className="gb-typing-note">{t('Visitor is typing…')}</p>}
        </div>
        {conv.status !== 'spam' && conv.can_reply !== false && <Composer id={id} onSent={(m) => (merge([m]), setTimeout(() => listRef.current?.scrollTo({ top: listRef.current.scrollHeight }), 30), onChanged())} />}
        {conv.can_reply === false && <Notice>{t('You can read this conversation but not reply to it.')}</Notice>}
      </div>
      <aside className="gb-side">
        <div className="gb-card-box">
          <h3 className="gb-side-title">{t('Visitor')}</h3>
          <dl className="gb-dl">
            <dt>{t('Name')}</dt>
            <dd>{conv.visitor.name || '—'}</dd>
            <dt>{t('Email')}</dt>
            <dd>{conv.visitor.email ? <bdi>{conv.visitor.email}</bdi> : '—'}</dd>
            <dt>{t('Mobile')}</dt>
            <dd>{conv.visitor.phone ? <bdi dir="ltr">{conv.visitor.phone}</bdi> : '—'}</dd>
            {Object.entries(conv.visitor.custom || {}).map(([k, v]) => (
              <div key={k} className="gb-dl-row">
                <dt>{k}</dt>
                <dd dir="auto">{v}</dd>
              </div>
            ))}
            <dt>{t('Started on')}</dt>
            <dd>
              {conv.page_url ? (
                <a href={conv.page_url} target="_blank" rel="noopener noreferrer">
                  <bdi>{conv.page_title || conv.page_url}</bdi>
                </a>
              ) : (
                '—'
              )}
            </dd>
            <dt>{t('Started')}</dt>
            <dd>{formatDateTime(conv.created_at, prefs())}</dd>
            <dt>{t('Previous conversations')}</dt>
            <dd>{num(conv.visitor.previous || 0)}</dd>
          </dl>
          <p className="gb-hint">{t('Contact details are provided by the visitor and are not verified.')}</p>
        </div>
        <div className="gb-card-box">
          <h3 className="gb-side-title">{t('Organize')}</h3>
          <Select label={t('Priority')} value={conv.priority} onChange={(v) => action({ action: 'priority', priority: Number(v) })} options={PRIORITY().map((p, i) => ({ value: i, label: p }))} />
          <div className="gb-chip-row">
            {conv.tags.map((tid) => {
              const tg = tags.find((x) => x.id === tid);
              return tg ? (
                <span key={tid} className="gb-chip" style={{ borderColor: tg.color }}>
                  <Icon name="tag" /> {tg.name}
                </span>
              ) : null;
            })}
            <Button small kind="ghost" icon="tag" onClick={() => setModal('tags')}>
              {t('Edit tags')}
            </Button>
          </div>
          {conv.rating && (
            <p className="gb-rating-line">
              {t('Rating: %s of 5', num(conv.rating.score))} {conv.rating.comment && <q dir="auto">{conv.rating.comment}</q>}
            </p>
          )}
        </div>
        <div className="gb-card-box gb-danger-zone">
          <h3 className="gb-side-title">{t('More')}</h3>
          {can('gapban_export') && (
            <Button
              small
              kind="ghost"
              icon="download"
              onClick={async () => {
                const r = await api<{ filename: string; text: string }>('GET', `admin/conversations/${id}/transcript`, undefined, { internal: 1 });
                downloadText(r.filename, r.text, 'text/plain');
              }}
            >
              {t('Export transcript')}
            </Button>
          )}
          {can('gapban_assign') && conv.status !== 'spam' && (
            <Button small kind="ghost" icon="flag" onClick={() => action({ action: 'spam' }, t('Marked as spam.'))}>
              {t('Mark as spam')}
            </Button>
          )}
          {can('gapban_assign') && conv.status === 'spam' && (
            <Button small kind="ghost" icon="flag" onClick={() => action({ action: 'unspam' }, t('Spam mark removed.'))}>
              {t('Not spam')}
            </Button>
          )}
          {can('gapban_assign') && (
            <Button small kind="ghost" icon="ban" onClick={() => setModal('block')}>
              {conv.visitor.blocked ? t('Unblock visitor') : t('Block visitor')}
            </Button>
          )}
          {can('gapban_delete') && (
            <Button small kind="danger" icon="trash" onClick={() => setModal('delete')}>
              {t('Delete conversation')}
            </Button>
          )}
        </div>
        {!!conv.audit?.length && (
          <details className="gb-card-box gb-audit">
            <summary>{t('Activity log')}</summary>
            <ol>
              {conv.audit.map((a, i) => (
                <li key={i}>
                  <span className="gb-hint">{formatDateTime(a.at, prefs())}</span> <code>{a.action}</code>
                </li>
              ))}
            </ol>
          </details>
        )}
      </aside>
      {modal === 'transfer' && <TransferModal conv={conv} operators={operators} teams={teams} onClose={() => setModal(null)} onSave={(b) => (setModal(null), action(b, t('Conversation transferred.')))} />}
      {modal === 'tags' && <TagsModal conv={conv} tags={tags} reloadTags={reloadTags} onClose={() => setModal(null)} onSave={(ids) => (setModal(null), action({ action: 'tags', tags: ids }, t('Tags saved.')))} />}
      {modal === 'delete' && (
        <Modal
          title={t('Delete conversation?')}
          onClose={() => setModal(null)}
          footer={
            <>
              <Button kind="ghost" onClick={() => setModal(null)}>
                {t('Cancel')}
              </Button>
              <Button
                kind="danger"
                onClick={async () => {
                  try {
                    await api('DELETE', `admin/conversations/${id}`);
                    toast(t('Conversation deleted.'));
                    onDeleted();
                  } catch (e) {
                    toast(errorText(e), 'error');
                  }
                }}
              >
                {t('Delete permanently')}
              </Button>
            </>
          }
        >
          <p>{t('Messages, files and notes of this conversation are deleted from WordPress. Copies already delivered to Bale are not affected.')}</p>
        </Modal>
      )}
      {modal === 'block' && (
        <Modal
          title={conv.visitor.blocked ? t('Unblock visitor') : t('Block visitor')}
          onClose={() => setModal(null)}
          footer={
            <>
              <Button kind="ghost" onClick={() => setModal(null)}>
                {t('Cancel')}
              </Button>
              <Button
                kind={conv.visitor.blocked ? 'primary' : 'danger'}
                onClick={async () => {
                  try {
                    await api('POST', `admin/visitors/${conv.visitor.id}/block`, { blocked: !conv.visitor.blocked });
                    setModal(null);
                    loadConv();
                    toast(conv.visitor.blocked ? t('Visitor unblocked.') : t('Visitor blocked.'));
                  } catch (e) {
                    toast(errorText(e), 'error');
                  }
                }}
              >
                {conv.visitor.blocked ? t('Unblock') : t('Block')}
              </Button>
            </>
          }
        >
          <p>{conv.visitor.blocked ? t('The visitor will be able to chat again.') : t('All chat sessions of this visitor are revoked and new messages are refused. A determined visitor can start over from another browser.')}</p>
        </Modal>
      )}
    </div>
  );
}

function MessageRow({ m, seen }: { m: Msg; seen: boolean }) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (ref.current) ref.current.replaceChildren(renderRichText(document, m.body, false));
  }, [m.body]);
  if (m.kind === 'event') {
    return <p className="gb-event">{m.body === 'conversation_closed' ? t('The conversation was closed') : t('The conversation was reopened')}</p>;
  }
  const who = m.author === 'visitor' ? t('Visitor') : m.author === 'automation' ? t('Automated message') : m.author === 'system' ? t('System') : m.author_name || t('Operator');
  const delivery: Record<string, [string, 'gray' | 'green' | 'amber' | 'brand']> = {
    queued: [t('Waiting to send to Bale'), 'gray'],
    sent: [t('Accepted by Bale'), 'green'],
    retrying: [t('Retrying'), 'amber'],
    uncertain: [t('Uncertain — may or may not have reached Bale'), 'amber'],
    failed: [t('Not delivered to Bale'), 'brand'],
  };
  const d = delivery[m.delivery_status];
  return (
    <div className={`gb-row gb-row-${m.author}${m.internal ? ' gb-row-note' : ''}`}>
      <div className="gb-row-head">
        <strong>{who}</strong>
        {m.internal && <Badge tone="amber">{t('Internal note — visitors never see this')}</Badge>}
        {m.source === 'bale' && <Badge tone="blue">{t('from Bale')}</Badge>}
        <span className="gb-hint">{formatDateTime(m.created_at, prefs())}</span>
        {d && (
          <Badge tone={d[1]}>
            <span title={m.delivery_error || undefined}>{d[0]}</span>
          </Badge>
        )}
        {m.possible_duplicate && <Badge tone="amber">{t('Possible duplicate in Bale')}</Badge>}
        {m.author === 'operator' && !m.internal && seen && <Badge tone="green">{t('Seen by visitor')}</Badge>}
      </div>
      {m.body && <div className="gb-row-body" dir="auto" ref={ref} />}
      {m.attachments.map((a) => (
        <AttachmentLink key={a.id} a={a} />
      ))}
    </div>
  );
}

function AttachmentLink({ a }: { a: Msg['attachments'][number] }) {
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    if (!a.image) return;
    let url = '';
    apiBlob(`admin/attachments/${a.id}`)
      .then((b) => setSrc((url = URL.createObjectURL(b))))
      .catch(() => undefined);
    return () => {
      if (url) URL.revokeObjectURL(url);
    };
  }, [a.id, a.image]);
  const download = async () => {
    const b = await apiBlob(`admin/attachments/${a.id}`);
    const url = URL.createObjectURL(b);
    const link = document.createElement('a');
    link.href = url;
    link.download = a.name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 3000);
  };
  return (
    <div className="gb-att-row">
      {src && <img className="gb-att-img" src={src} alt={a.name} />}
      <button type="button" className="gb-att" onClick={download}>
        <Icon name="download" /> <bdi>{a.name}</bdi> <span className="gb-hint">{formatBytes(a.size, prefs())}</span>
      </button>
    </div>
  );
}

function Composer({ id, onSent }: { id: number; onSent: (m: Msg) => void }) {
  const toast = useToast();
  const [text, setText] = useState('');
  const [note, setNote] = useState(false);
  const [busy, setBusy] = useState(false);
  const [files, setFiles] = useState<Array<{ name: string; id?: string; error?: boolean }>>([]);
  const canned = useAsync<Array<{ id: number; title: string; shortcut: string; body: string }>>(() => api('GET', 'admin/canned'), []);
  const [showCanned, setShowCanned] = useState(false);
  const typingAt = useRef(0);
  const fileRef = useRef<HTMLInputElement>(null);

  const matches = useMemo(() => {
    const m = text.match(/(?:^|\s)\/([\p{L}\w-]*)$/u);
    if (!m || !canned.data) return [];
    return canned.data.filter((c) => c.shortcut.startsWith(m[1]) || c.title.includes(m[1])).slice(0, 6);
  }, [text, canned.data]);

  const typing = (on: boolean) => {
    const now = Date.now();
    if (on && now - typingAt.current < 3000) return;
    typingAt.current = on ? now : 0;
    api('POST', `admin/conversations/${id}/typing`, { typing: on }).catch(() => undefined);
  };

  const upload = async (file: File) => {
    const entry: { name: string; id?: string; error?: boolean } = { name: file.name };
    setFiles((f) => [...f, entry]);
    const form = new FormData();
    form.append('file', file, file.name);
    try {
      const res = await api<{ attachment: { id: string } }>('POST', `admin/conversations/${id}/uploads`, form);
      entry.id = res.attachment.id;
    } catch (e) {
      entry.error = true;
      toast(errorText(e), 'error');
    }
    setFiles((f) => [...f]);
  };

  const send = async () => {
    const ready = files.filter((f) => f.id).map((f) => f.id!);
    if (!text.trim() && !ready.length) return;
    setBusy(true);
    try {
      const res = note
        ? await api<{ message: Msg }>('POST', `admin/conversations/${id}/notes`, { body: text })
        : await api<{ message: Msg }>('POST', `admin/conversations/${id}/messages`, { body: text, attachments: ready, client_key: randomKey() });
      setText('');
      setFiles([]);
      typing(false);
      onSent(res.message);
    } catch (e) {
      toast(errorText(e), 'error');
    } finally {
      setBusy(false);
    }
  };

  const insert = (body: string) => {
    setText((v) => v.replace(/(?:^|\s)\/[\p{L}\w-]*$/u, (m) => (m.startsWith(' ') ? ' ' : '')) + body);
    setShowCanned(false);
  };

  return (
    <div className={'gb-composer' + (note ? ' gb-composer-note' : '')}>
      <div className="gb-composer-tabs" role="group" aria-label={t('Message type')}>
        <button type="button" className="gb-tab" aria-pressed={!note} onClick={() => setNote(false)}>
          {t('Reply to visitor')}
        </button>
        <button type="button" className="gb-tab" aria-pressed={note} onClick={() => setNote(true)}>
          <Icon name="note" /> {t('Internal note')}
        </button>
      </div>
      {matches.length > 0 && (
        <ul className="gb-canned-pop" role="listbox" aria-label={t('Canned responses')}>
          {matches.map((c) => (
            <li key={c.id}>
              <button type="button" onClick={() => insert(c.body)}>
                <strong>/{c.shortcut}</strong> {c.title}
              </button>
            </li>
          ))}
        </ul>
      )}
      <label className="gb-sr" htmlFor={`gb-reply-${id}`}>
        {note ? t('Internal note') : t('Reply')}
      </label>
      <textarea
        id={`gb-reply-${id}`}
        className="gb-input gb-reply"
        dir="auto"
        rows={3}
        value={text}
        placeholder={note ? t('Write a note for your team (never sent to the visitor)…') : t('Write a reply… Type / for canned responses. Ctrl+Enter sends.')}
        onChange={(e) => {
          setText(e.target.value);
          if (!note) typing(true);
        }}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            send();
          }
        }}
        onBlur={() => !note && typing(false)}
      />
      {files.length > 0 && (
        <ul className="gb-file-list">
          {files.map((f, i) => (
            <li key={i}>
              <bdi>{f.name}</bdi> {f.error ? <Badge tone="brand">{t('Upload failed')}</Badge> : f.id ? <Badge tone="green">{t('Ready')}</Badge> : <Badge>{t('Uploading')}</Badge>}
              <button type="button" className="gb-link" onClick={() => setFiles((x) => x.filter((_, j) => j !== i))}>
                {t('Remove')}
              </button>
            </li>
          ))}
        </ul>
      )}
      <div className="gb-composer-bar">
        {!note && (
          <>
            <input ref={fileRef} type="file" hidden onChange={(e) => (Array.from(e.target.files || []).forEach(upload), (e.target.value = ''))} />
            <IconButton icon="paperclip" label={t('Attach a file')} onClick={() => fileRef.current?.click()} />
            <Button kind="ghost" small icon="book" onClick={() => setShowCanned((v) => !v)}>
              {t('Canned responses')}
            </Button>
          </>
        )}
        <span className="gb-grow" />
        <Button onClick={send} busy={busy} icon={note ? 'note' : 'paper-plane'} disabled={!text.trim() && !files.some((f) => f.id)}>
          {note ? t('Save note') : t('Send')}
        </Button>
      </div>
      {showCanned && (
        <div className="gb-canned-panel">
          {canned.loading ? (
            <Spinner />
          ) : !canned.data?.length ? (
            <p className="gb-hint">{t('No canned responses yet. Add them under “Canned responses & help”.')}</p>
          ) : (
            canned.data.map((c) => (
              <button key={c.id} type="button" className="gb-canned-item" onClick={() => insert(c.body)}>
                <strong>{c.title}</strong>
                <span dir="auto">{c.body}</span>
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}

function TransferModal({ conv, operators, teams, onClose, onSave }: { conv: Conv; operators: Operator[]; teams: Team[]; onClose: () => void; onSave: (b: Record<string, unknown>) => void }) {
  const [op, setOp] = useState(String(conv.assigned_operator_id || ''));
  const [team, setTeam] = useState(String(conv.team_id || ''));
  return (
    <Modal
      title={t('Transfer conversation')}
      onClose={onClose}
      footer={
        <>
          <Button kind="ghost" onClick={onClose}>
            {t('Cancel')}
          </Button>
          <Button onClick={() => onSave({ action: 'transfer', operator_id: op ? Number(op) : 0, team_id: team ? Number(team) : null, expected_operator_id: conv.assigned_operator_id || 0 })}>{t('Transfer')}</Button>
        </>
      }
    >
      <Select
        label={t('Operator')}
        value={op}
        onChange={setOp}
        options={[{ value: '', label: t('Unassigned (back to the queue)') }, ...operators.filter((o) => o.status === 'active').map((o) => ({ value: o.id, label: o.display_name + (o.available_now ? ` (${t('available')})` : '') }))]}
      />
      <Select label={t('Department')} value={team} onChange={setTeam} options={teams.map((tm) => ({ value: tm.id, label: tm.name }))} />
      <p className="gb-hint">{t('The conversation keeps its original Bale destination; the transfer changes who is responsible in WordPress.')}</p>
    </Modal>
  );
}

function TagsModal({ conv, tags, reloadTags, onClose, onSave }: { conv: Conv; tags: Tag[]; reloadTags: () => void; onClose: () => void; onSave: (ids: number[]) => void }) {
  const [sel, setSel] = useState<number[]>(conv.tags);
  const [name, setName] = useState('');
  return (
    <Modal
      title={t('Tags')}
      onClose={onClose}
      footer={
        <>
          <Button kind="ghost" onClick={onClose}>
            {t('Cancel')}
          </Button>
          <Button onClick={() => onSave(sel)}>{t('Save')}</Button>
        </>
      }
    >
      <div className="gb-chip-row">
        {tags.map((tg) => (
          <button key={tg.id} type="button" className="gb-chip" aria-pressed={sel.includes(tg.id)} onClick={() => setSel((s) => (s.includes(tg.id) ? s.filter((x) => x !== tg.id) : [...s, tg.id]))}>
            {tg.name}
          </button>
        ))}
      </div>
      {can('gapban_manage_settings') || can('gapban_assign') ? (
        <form
          className="gb-inline-form"
          onSubmit={async (e) => {
            e.preventDefault();
            if (!name.trim()) return;
            await api('POST', 'admin/tags', { name });
            setName('');
            reloadTags();
          }}
        >
          <label className="gb-sr" htmlFor="gb-new-tag">
            {t('New tag')}
          </label>
          <input id="gb-new-tag" className="gb-input" value={name} onChange={(e) => setName(e.target.value)} placeholder={t('New tag')} />
          <Button type="submit" kind="ghost" icon="plus">
            {t('Add')}
          </Button>
        </form>
      ) : null}
    </Modal>
  );
}
