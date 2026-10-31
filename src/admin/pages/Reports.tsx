import { useState } from 'react';
import { api, errorText, num, prefs, t } from '../lib';
import { Card, Field, Notice, PageHeader, Spinner, StatTile, useAsync } from '../ui';
import { formatDuration } from '../../shared/format';

type Dur = { count: number; average: number | null; median: number | null; p90: number | null };
type Report = {
  volume: number;
  spam: number;
  unanswered: number;
  unanswered_rate: number | null;
  first_response: Dur;
  resolution: Dur;
  queue_wait: Dur;
  csat: { responses: number; average: number | null; satisfied_pct: number | null };
  daily: Array<{ day: string; conversations: number; unanswered: number }>;
  operators: Array<{ id: number; name: string; assigned: number; replies: number; resolved: number; avg_first_response: number | null; csat: number | null }>;
  teams: Array<{ id: number; name: string; volume: number; unanswered: number; avg_first_response: number | null }>;
  campaigns: Array<{ id: number; name: string; shown: number; clicked: number; dismissed: number; converted: number; conversion: number | null }>;
  local_range: { from: string; to: string; timezone: string };
};

const pct = (x: number | null) => (x === null ? '—' : num((x * 100).toFixed(1)) + '٪');

export default function Reports() {
  const today = new Date();
  const iso = (d: Date) => d.toISOString().slice(0, 10);
  const [from, setFrom] = useState(iso(new Date(today.getTime() - 29 * 86400_000)));
  const [to, setTo] = useState(iso(today));
  const r = useAsync<Report>(() => api('GET', 'admin/reports', undefined, { from, to }), [from, to]);
  const d = (s: number | null) => formatDuration(s, prefs());
  return (
    <div>
      <PageHeader title={t('Reports & customer satisfaction')} intro={t('Figures cover conversations started in the selected period (spam excluded). Automated greetings never count as human replies.')} />
      <div className="gb-inline-form">
        <Field label={t('From')}>{(id) => <input id={id} className="gb-input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />}</Field>
        <Field label={t('To')}>{(id) => <input id={id} className="gb-input" type="date" value={to} onChange={(e) => setTo(e.target.value)} />}</Field>
      </div>
      {r.loading && !r.data ? (
        <Spinner />
      ) : r.error ? (
        <Notice tone="error">{errorText(r.error)}</Notice>
      ) : r.data ? (
        <>
          <div className="gb-stats">
            <StatTile label={t('Conversations')} value={num(r.data.volume)} hint={t('%d spam', r.data.spam)} />
            <StatTile label={t('Unanswered')} value={pct(r.data.unanswered_rate)} hint={t('%d without a human reply', r.data.unanswered)} />
            <StatTile label={t('First human response (median)')} value={d(r.data.first_response.median)} hint={t('average %s', d(r.data.first_response.average))} />
            <StatTile label={t('Resolution time (median)')} value={d(r.data.resolution.median)} hint={t('90%% within %s', d(r.data.resolution.p90))} />
            <StatTile label={t('Queue wait (median)')} value={d(r.data.queue_wait.median)} hint={t('until an operator was assigned')} />
            <StatTile label={t('Satisfaction (CSAT)')} value={pct(r.data.csat.satisfied_pct)} hint={t('%d ratings, average %s / 5', r.data.csat.responses, r.data.csat.average === null ? '—' : num(r.data.csat.average))} />
          </div>
          <Card title={t('Daily volume')}>
            <BarChart data={r.data.daily} />
          </Card>
          <Card title={t('Operators')}>
            <table className="gb-table">
              <thead>
                <tr>
                  <th scope="col">{t('Operator')}</th>
                  <th scope="col">{t('Assigned')}</th>
                  <th scope="col">{t('Replies')}</th>
                  <th scope="col">{t('Resolved')}</th>
                  <th scope="col">{t('Avg. first response')}</th>
                  <th scope="col">{t('CSAT')}</th>
                </tr>
              </thead>
              <tbody>
                {r.data.operators.map((o) => (
                  <tr key={o.id}>
                    <td>{o.name}</td>
                    <td>{num(o.assigned)}</td>
                    <td>{num(o.replies)}</td>
                    <td>{num(o.resolved)}</td>
                    <td>{d(o.avg_first_response)}</td>
                    <td>{o.csat === null ? '—' : num(o.csat)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <Card title={t('Departments')}>
            <table className="gb-table">
              <thead>
                <tr>
                  <th scope="col">{t('Department')}</th>
                  <th scope="col">{t('Conversations')}</th>
                  <th scope="col">{t('Unanswered')}</th>
                  <th scope="col">{t('Avg. first response')}</th>
                </tr>
              </thead>
              <tbody>
                {r.data.teams.map((tm) => (
                  <tr key={tm.id}>
                    <td>{tm.name}</td>
                    <td>{num(tm.volume)}</td>
                    <td>{num(tm.unanswered)}</td>
                    <td>{d(tm.avg_first_response)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <Card title={t('Campaigns (display rules)')}>
            <table className="gb-table">
              <thead>
                <tr>
                  <th scope="col">{t('Rule')}</th>
                  <th scope="col">{t('Shown')}</th>
                  <th scope="col">{t('Clicked')}</th>
                  <th scope="col">{t('Dismissed')}</th>
                  <th scope="col">{t('Chats started')}</th>
                  <th scope="col">{t('Conversion')}</th>
                </tr>
              </thead>
              <tbody>
                {r.data.campaigns.map((c) => (
                  <tr key={c.id}>
                    <td>{c.name}</td>
                    <td>{num(c.shown)}</td>
                    <td>{num(c.clicked)}</td>
                    <td>{num(c.dismissed)}</td>
                    <td>{num(c.converted)}</td>
                    <td>{pct(c.conversion)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <details className="gb-card-box">
            <summary>{t('How these numbers are calculated')}</summary>
            <ul className="gb-formulas">
              <li>{t('Unanswered rate = conversations without any operator message ÷ all conversations.')}</li>
              <li>{t('First human response = time from conversation start to the first operator message (from Bale or WordPress). Automated and system messages are ignored.')}</li>
              <li>{t('Resolution time = time from start to closing, for closed conversations.')}</li>
              <li>{t('Queue wait = time from start until an operator was assigned (claim, reply or automatic assignment).')}</li>
              <li>{t('CSAT = ratings of 4 or 5 ÷ all ratings.')}</li>
              <li>{t('Campaign conversion = chats started from the campaign ÷ times it was shown. Shows are counted in visitors’ browsers and may be blocked by privacy tools.')}</li>
            </ul>
            <p className="gb-hint">{t('Period boundaries use the %s time zone.', r.data.local_range.timezone)}</p>
          </details>
        </>
      ) : null}
    </div>
  );
}

function BarChart({ data }: { data: Report['daily'] }) {
  if (!data.length) return <p className="gb-hint">{t('No data in this period.')}</p>;
  const max = Math.max(1, ...data.map((x) => x.conversations));
  const w = 100 / data.length;
  return (
    <figure className="gb-chart">
      <svg viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label={t('Conversations per day')}>
        {data.map((x, i) => {
          const h = (x.conversations / max) * 38;
          const hu = (x.unanswered / max) * 38;
          return (
            <g key={x.day}>
              <rect x={i * w + w * 0.15} y={40 - h} width={w * 0.7} height={h} rx="0.6" className="gb-bar" />
              <rect x={i * w + w * 0.15} y={40 - hu} width={w * 0.7} height={hu} rx="0.6" className="gb-bar-un" />
              <title>{`${x.day}: ${x.conversations} / ${x.unanswered}`}</title>
            </g>
          );
        })}
      </svg>
      <figcaption className="gb-legend">
        <span className="gb-dot gb-dot-brand" /> {t('Conversations')} <span className="gb-dot gb-dot-dark" /> {t('Unanswered')}
      </figcaption>
      <table className="gb-sr">
        <caption>{t('Conversations per day')}</caption>
        <tbody>
          {data.map((x) => (
            <tr key={x.day}>
              <th scope="row">{x.day}</th>
              <td>{x.conversations}</td>
              <td>{x.unanswered}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}
