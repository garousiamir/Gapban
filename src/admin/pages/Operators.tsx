import { useEffect, useState } from 'react';
import { api, errorText, t } from '../lib';
import { Badge, Bdi, Button, Card, Empty, Modal, Notice, PageHeader, Select, Spinner, TextInput, Toggle, useAsync, useToast } from '../ui';

type Operator = {
  id: number;
  display_name: string;
  wp_user_id: number | null;
  wp_user_login: string;
  bale_linked: boolean;
  bale_user_id: string;
  bale_username: string;
  status: 'active' | 'disabled';
  availability: string;
  available_now: boolean;
  max_concurrent: number;
  schedule: Record<string, Array<[string, string]>> | null;
  teams: number[];
};
type Team = { id: number; name: string; description: string; destination_chat_id: string; destination_title: string; assignment_strategy: string; public: boolean; active: boolean; sort_order: number };

export default function Operators() {
  const ops = useAsync<Operator[]>(() => api('GET', 'admin/operators'), []);
  const teams = useAsync<Team[]>(() => api('GET', 'admin/teams'), []);
  const [editOp, setEditOp] = useState<Partial<Operator> | null>(null);
  const [editTeam, setEditTeam] = useState<Partial<Team> | null>(null);
  const [enroll, setEnroll] = useState<{ op: Operator; data: any } | null>(null);
  const [bind, setBind] = useState<{ team: Team; data: any } | null>(null);
  const toast = useToast();

  return (
    <div>
      <PageHeader title={t('Operators & departments')} intro={t('Operators answer from Bale or the WordPress inbox. Departments decide where conversations go and how they are assigned.')} actions={<Button icon="plus" onClick={() => setEditOp({ status: 'active', availability: 'auto', max_concurrent: 5, teams: [] })}>{t('Add operator')}</Button>} />
      <Card title={t('Operators')}>
        {ops.loading ? (
          <Spinner />
        ) : !ops.data?.length ? (
          <Empty icon="users" title={t('No operators yet')}>
            {t('Add an operator, then send them the one-time link code so their Bale account is recognised.')}
          </Empty>
        ) : (
          <table className="gb-table">
            <thead>
              <tr>
                <th scope="col">{t('Name')}</th>
                <th scope="col">{t('Bale account')}</th>
                <th scope="col">{t('WordPress user')}</th>
                <th scope="col">{t('Status')}</th>
                <th scope="col">{t('Departments')}</th>
                <th scope="col">
                  <span className="gb-sr">{t('Actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {ops.data.map((o) => (
                <tr key={o.id}>
                  <td>
                    <strong>{o.display_name}</strong>
                  </td>
                  <td>{o.bale_linked ? <Badge tone="green">{o.bale_username ? <Bdi>@{o.bale_username}</Bdi> : <Bdi>{o.bale_user_id}</Bdi>}</Badge> : <Badge tone="amber">{t('Not linked')}</Badge>}</td>
                  <td>{o.wp_user_login ? <Bdi>{o.wp_user_login}</Bdi> : '—'}</td>
                  <td>
                    {o.status === 'disabled' ? <Badge tone="dark">{t('Disabled')}</Badge> : o.available_now ? <Badge tone="green">{t('Available')}</Badge> : <Badge>{availabilityLabel(o.availability)}</Badge>}
                  </td>
                  <td>{o.teams.map((id) => teams.data?.find((x) => x.id === id)?.name).filter(Boolean).join('، ') || t('All departments')}</td>
                  <td className="gb-row-actions">
                    <Button small kind="ghost" onClick={() => setEditOp(o)}>
                      {t('Edit')}
                    </Button>
                    {o.status === 'active' && (
                      <Button
                        small
                        kind="ghost"
                        icon="plug"
                        onClick={async () => {
                          try {
                            setEnroll({ op: o, data: await api('POST', `admin/operators/${o.id}/enroll`) });
                          } catch (e) {
                            toast(errorText(e), 'error');
                          }
                        }}
                      >
                        {o.bale_linked ? t('Re-link Bale') : t('Link Bale')}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <p className="gb-hint">{t('Availability is set by the operator (in WordPress, or with /online, /away, /offline sent to the bot) or comes from an open inbox tab. Gapban never shows an operator as online otherwise.')}</p>
      </Card>

      <Card title={t('Departments')} actions={<Button small icon="plus" kind="ghost" onClick={() => setEditTeam({ assignment_strategy: 'manual', public: true, active: true, sort_order: 0 })}>{t('Add department')}</Button>}>
        {teams.loading ? (
          <Spinner />
        ) : (
          <table className="gb-table">
            <thead>
              <tr>
                <th scope="col">{t('Department')}</th>
                <th scope="col">{t('Bale destination')}</th>
                <th scope="col">{t('Assignment')}</th>
                <th scope="col">{t('Visible to visitors')}</th>
                <th scope="col">
                  <span className="gb-sr">{t('Actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {teams.data?.map((tm) => (
                <tr key={tm.id}>
                  <td>
                    <strong>{tm.name}</strong> {!tm.active && <Badge tone="dark">{t('Inactive')}</Badge>}
                  </td>
                  <td>{tm.destination_chat_id ? <>{tm.destination_title || t('(untitled)')} <Bdi>{tm.destination_chat_id}</Bdi></> : <span className="gb-hint">{t('Default support group')}</span>}</td>
                  <td>{strategyLabel(tm.assignment_strategy)}</td>
                  <td>{tm.public ? t('Yes') : t('No')}</td>
                  <td className="gb-row-actions">
                    <Button small kind="ghost" onClick={() => setEditTeam(tm)}>
                      {t('Edit')}
                    </Button>
                    <Button
                      small
                      kind="ghost"
                      icon="users"
                      onClick={async () => {
                        try {
                          setBind({ team: tm, data: await api('POST', `admin/teams/${tm.id}/bind`) });
                        } catch (e) {
                          toast(errorText(e), 'error');
                        }
                      }}
                    >
                      {t('Connect group')}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        <Notice tone="warn">{t('Members of a Bale group see every conversation delivered to that group. For confidentiality between departments, give each department its own group.')}</Notice>
      </Card>

      {editOp && <OperatorModal op={editOp} teams={teams.data || []} onClose={() => setEditOp(null)} onSaved={() => (setEditOp(null), ops.reload())} />}
      {editTeam && <TeamModal team={editTeam} onClose={() => setEditTeam(null)} onSaved={() => (setEditTeam(null), teams.reload())} />}
      {enroll && <CodeModal title={t('Link %s to Bale', enroll.op.display_name)} data={enroll.data} instructions={[t('The operator opens a private chat with the bot in Bale (search for its username).'), t('They send this exact command within 15 minutes. The code works once.')]} onClose={() => (setEnroll(null), ops.reload())} />}
      {bind && <CodeModal title={t('Connect a group to “%s”', bind.team.name)} data={bind.data} instructions={[t('Add the bot to the department’s Bale group and allow it to send messages.'), t('Send this command in that group within 15 minutes.')]} onClose={() => (setBind(null), teams.reload())} />}
    </div>
  );
}

function availabilityLabel(a: string): string {
  return ({ online: t('Online'), away: t('Away'), offline: t('Offline'), auto: t('Automatic') } as Record<string, string>)[a] || a;
}
function strategyLabel(s: string): string {
  return ({ manual: t('Manual (claim)'), round_robin: t('Round-robin'), least_loaded: t('Least busy') } as Record<string, string>)[s] || s;
}

function CodeModal({ title, data, instructions, onClose }: { title: string; data: { id: number; command: string; bot?: string; expires_at: string }; instructions: string[]; onClose: () => void }) {
  const toast = useToast();
  const [status, setStatus] = useState<string | null>(null);
  useEffect(() => {
    const timer = window.setInterval(async () => {
      const st = await api('GET', `admin/enrollments/${data.id}`).catch(() => null);
      if (st?.used_at) {
        setStatus(st.result);
        window.clearInterval(timer);
      }
    }, 4000);
    return () => window.clearInterval(timer);
  }, [data.id]);
  return (
    <Modal title={title} onClose={onClose} footer={<Button onClick={onClose}>{t('Done')}</Button>}>
      <ol className="gb-howto">
        {instructions.map((i) => (
          <li key={i}>{i}</li>
        ))}
      </ol>
      {data.bot && (
        <p>
          {t('Bot')}: <Bdi>@{data.bot}</Bdi>
        </p>
      )}
      <p className="gb-code">
        <Bdi>{data.command}</Bdi>
      </p>
      <Button small kind="ghost" icon="copy" onClick={() => navigator.clipboard?.writeText(data.command).then(() => toast(t('Copied.')))}>
        {t('Copy')}
      </Button>
      {status === null ? (
        <p className="gb-hint" role="status">
          {t('Waiting for the bot to receive the code…')}
        </p>
      ) : (
        <Notice tone={['linked', 'bound'].includes(status) ? 'success' : 'error'}>{['linked', 'bound'].includes(status) ? t('Connected successfully.') : status === 'already_linked' ? t('That Bale account is already linked to another operator.') : t('The code could not be used.')}</Notice>
      )}
    </Modal>
  );
}

function OperatorModal({ op, teams, onClose, onSaved }: { op: Partial<Operator>; teams: Team[]; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [f, setF] = useState<Partial<Operator>>(op);
  const [busy, setBusy] = useState(false);
  const users = useAsync<Array<{ id: number; name: string; slug: string }>>(() => api('GET', 'wp/v2/users', undefined, { per_page: 100, context: 'edit', _fields: 'id,name,slug' }).catch(() => []), []);
  const save = async () => {
    setBusy(true);
    try {
      await api('POST', op.id ? `admin/operators/${op.id}` : 'admin/operators', f);
      toast(t('Saved.'));
      onSaved();
    } catch (e) {
      toast(errorText(e), 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal
      title={op.id ? t('Edit operator') : t('Add operator')}
      onClose={onClose}
      footer={
        <>
          {op.id && op.status === 'active' && (
            <Button
              kind="danger"
              onClick={async () => {
                if (!window.confirm(t('Disable this operator? Their conversations return to the queue and their Bale link is removed.'))) return;
                await api('DELETE', `admin/operators/${op.id}`);
                onSaved();
              }}
            >
              {t('Disable')}
            </Button>
          )}
          <span className="gb-grow" />
          <Button kind="ghost" onClick={onClose}>
            {t('Cancel')}
          </Button>
          <Button onClick={save} busy={busy}>
            {t('Save')}
          </Button>
        </>
      }
    >
      <TextInput label={t('Display name')} required value={f.display_name || ''} onChange={(v) => setF({ ...f, display_name: v })} help={t('Visitors see this name next to the operator’s replies.')} />
      <Select label={t('WordPress user (optional)')} value={f.wp_user_id || ''} onChange={(v) => setF({ ...f, wp_user_id: v ? Number(v) : null })} options={[{ value: '', label: t('None — answers only from Bale') }, ...(users.data || []).map((u) => ({ value: u.id, label: `${u.name} (${u.slug})` }))]} help={t('Linking a user lets them use the WordPress inbox for their assigned conversations.')} />
      <Select label={t('Availability')} value={f.availability || 'auto'} onChange={(v) => setF({ ...f, availability: v })} options={[{ value: 'auto', label: t('Automatic (online while the inbox is open)') }, { value: 'online', label: t('Online') }, { value: 'away', label: t('Away') }, { value: 'offline', label: t('Offline') }]} />
      <TextInput label={t('Maximum simultaneous conversations')} type="number" value={f.max_concurrent ?? 5} onChange={(v) => setF({ ...f, max_concurrent: Number(v) })} inputMode="numeric" />
      <fieldset className="gb-fieldset">
        <legend>{t('Departments')}</legend>
        <p className="gb-hint">{t('No selection means the operator may answer every department.')}</p>
        {teams.map((tm) => (
          <Toggle key={tm.id} label={tm.name} checked={(f.teams || []).includes(tm.id)} onChange={(on) => setF({ ...f, teams: on ? [...(f.teams || []), tm.id] : (f.teams || []).filter((x) => x !== tm.id) })} />
        ))}
      </fieldset>
      {op.id && f.bale_linked && (
        <Button small kind="ghost" onClick={() => setF({ ...f, bale_user_id: '', bale_linked: false })}>
          {t('Unlink Bale account')}
        </Button>
      )}
    </Modal>
  );
}

function TeamModal({ team, onClose, onSaved }: { team: Partial<Team>; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const [f, setF] = useState<Partial<Team>>(team);
  return (
    <Modal
      title={team.id ? t('Edit department') : t('Add department')}
      onClose={onClose}
      footer={
        <>
          {team.id && (
            <Button
              kind="danger"
              onClick={async () => {
                if (!window.confirm(t('Delete this department? Existing conversations keep their history and routing.'))) return;
                try {
                  await api('DELETE', `admin/teams/${team.id}`);
                  onSaved();
                } catch (e) {
                  toast(errorText(e), 'error');
                }
              }}
            >
              {t('Delete')}
            </Button>
          )}
          <span className="gb-grow" />
          <Button kind="ghost" onClick={onClose}>
            {t('Cancel')}
          </Button>
          <Button
            onClick={async () => {
              try {
                await api('POST', team.id ? `admin/teams/${team.id}` : 'admin/teams', f);
                toast(t('Saved.'));
                onSaved();
              } catch (e) {
                toast(errorText(e), 'error');
              }
            }}
          >
            {t('Save')}
          </Button>
        </>
      }
    >
      <TextInput label={t('Name')} required value={f.name || ''} onChange={(v) => setF({ ...f, name: v })} />
      <TextInput label={t('Description')} value={f.description || ''} onChange={(v) => setF({ ...f, description: v })} />
      <Select
        label={t('Assignment')}
        value={f.assignment_strategy || 'manual'}
        onChange={(v) => setF({ ...f, assignment_strategy: v })}
        options={[
          { value: 'manual', label: t('Manual — operators claim conversations') },
          { value: 'round_robin', label: t('Round-robin among available operators') },
          { value: 'least_loaded', label: t('Least busy available operator') },
        ]}
      />
      <TextInput label={t('Bale destination chat ID (optional)')} dir="ltr" value={f.destination_chat_id || ''} onChange={(v) => setF({ ...f, destination_chat_id: v })} help={t('Leave empty to use the default support group. Easier: use “Connect group” to bind with a code.')} />
      <Toggle label={t('Visitors can choose this department')} checked={f.public !== false} onChange={(v) => setF({ ...f, public: v })} />
      <Toggle label={t('Active')} checked={f.active !== false} onChange={(v) => setF({ ...f, active: v })} />
      <p className="gb-hint">{t('Changing the destination affects new conversations only. Existing conversations stay in the group they started in.')}</p>
    </Modal>
  );
}
