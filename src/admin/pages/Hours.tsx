import { useState } from 'react';
import { cfg, t } from '../lib';
import { Button, Card, Field, IconButton, Notice, PageHeader, Select, Spinner, TextInput, Toggle } from '../ui';
import { SaveBar, useSettings } from '../settings';
import { formatDay, normalizeDigits } from '../../shared/format';
import { prefs } from '../lib';

const DAYS: Array<[string, string]> = [
  ['sat', 'Saturday'],
  ['sun', 'Sunday'],
  ['mon', 'Monday'],
  ['tue', 'Tuesday'],
  ['wed', 'Wednesday'],
  ['thu', 'Thursday'],
  ['fri', 'Friday'],
];

export default function Hours() {
  const { data, draft, set, save, saving, dirty, error } = useSettings();
  const [holiday, setHoliday] = useState({ date: '', label: '' });
  if (error) return <Notice tone="error">{error}</Notice>;
  if (!data) return <Spinner />;
  const hours: Record<string, Array<[string, string]>> = draft.business_hours || {};
  const setDay = (day: string, ranges: Array<[string, string]>) => set('business_hours', { ...hours, [day]: ranges });
  const away = draft.temporary_away_until;
  return (
    <div>
      <PageHeader title={t('Business hours & holidays')} intro={t('Outside these hours the widget invites visitors to leave a message, and nobody is shown as available.')} />
      <Card title={t('Time zone')}>
        <Select label={t('Time zone')} value={draft.timezone || ''} onChange={(v) => set('timezone', v)} options={[{ value: '', label: t('Site time zone (%s)', data.site_timezone) }, ...data.timezones.map((z) => ({ value: z, label: z }))]} help={t('Asia/Tehran follows Iran’s official time; no fixed offset is assumed.')} />
      </Card>
      <Card title={t('Weekly schedule')}>
        <Toggle label={t('Use business hours')} checked={!!draft.business_hours_enabled} onChange={(v) => set('business_hours_enabled', v)} />
        <div className="gb-hours" aria-disabled={!draft.business_hours_enabled}>
          {DAYS.map(([key, name]) => (
            <div key={key} className="gb-hours-row">
              <strong className="gb-hours-day">{t(name)}</strong>
              <div className="gb-hours-ranges">
                {(hours[key] || []).length === 0 && <span className="gb-hint">{t('Closed')}</span>}
                {(hours[key] || []).map((r, i) => (
                  <span key={i} className="gb-range">
                    <label className="gb-sr" htmlFor={`gb-h-${key}-${i}-a`}>
                      {t('%s opens at', t(name))}
                    </label>
                    <input id={`gb-h-${key}-${i}-a`} className="gb-input gb-time" type="time" value={r[0]} onChange={(e) => setDay(key, hours[key].map((x, j) => (j === i ? [normalizeDigits(e.target.value), x[1]] : x)))} />
                    <span aria-hidden="true">–</span>
                    <label className="gb-sr" htmlFor={`gb-h-${key}-${i}-b`}>
                      {t('%s closes at', t(name))}
                    </label>
                    <input id={`gb-h-${key}-${i}-b`} className="gb-input gb-time" type="time" value={r[1]} onChange={(e) => setDay(key, hours[key].map((x, j) => (j === i ? [x[0], normalizeDigits(e.target.value)] : x)))} />
                    <IconButton icon="xmark" label={t('Remove time range')} onClick={() => setDay(key, hours[key].filter((_, j) => j !== i))} />
                  </span>
                ))}
                {(hours[key] || []).length < 4 && (
                  <Button small kind="ghost" icon="plus" onClick={() => setDay(key, [...(hours[key] || []), ['09:00', '17:00']])}>
                    {t('Add hours')}
                  </Button>
                )}
              </div>
            </div>
          ))}
        </div>
      </Card>
      <Card title={t('Holidays')}>
        <p className="gb-hint">{t('On these dates the widget is in offline mode all day. Dates are in the selected time zone.')}</p>
        <ul className="gb-list">
          {(draft.holidays || []).map((h: { date: string; label: string }, i: number) => (
            <li key={h.date + i}>
              <span>{formatDay(h.date + 'T12:00:00Z', prefs())}</span> <bdi className="gb-hint">{h.date}</bdi> {h.label && <span>— {h.label}</span>}
              <IconButton icon="trash" label={t('Remove holiday')} onClick={() => set('holidays', draft.holidays.filter((_: unknown, j: number) => j !== i))} />
            </li>
          ))}
        </ul>
        <form
          className="gb-inline-form"
          onSubmit={(e) => {
            e.preventDefault();
            if (!holiday.date) return;
            set('holidays', [...(draft.holidays || []), { date: normalizeDigits(holiday.date), label: holiday.label }]);
            setHoliday({ date: '', label: '' });
          }}
        >
          <Field label={t('Date')}>{(id) => <input id={id} className="gb-input" type="date" value={holiday.date} onChange={(e) => setHoliday({ ...holiday, date: e.target.value })} />}</Field>
          <TextInput label={t('Label (optional)')} value={holiday.label} onChange={(v) => setHoliday({ ...holiday, label: v })} placeholder={t('e.g. Nowruz')} />
          <Button type="submit" kind="ghost" icon="plus">
            {t('Add holiday')}
          </Button>
        </form>
        {cfg().jalali && <p className="gb-hint">{t('The date picker uses your browser’s calendar; the list above shows Jalali dates.')}</p>}
      </Card>
      <Card title={t('Temporary away')}>
        <p className="gb-hint">{t('Use this for short breaks; the widget shows the away message until the time below.')}</p>
        <Field label={t('Away until')}>
          {(id) => <input id={id} className="gb-input" type="datetime-local" value={away ? away.replace(' ', 'T').slice(0, 16) : ''} onChange={(e) => set('temporary_away_until', e.target.value ? new Date(e.target.value).toISOString() : '')} />}
        </Field>
        {away && (
          <Button small kind="ghost" onClick={() => set('temporary_away_until', '')}>
            {t('Clear')}
          </Button>
        )}
      </Card>
      <SaveBar dirty={dirty} saving={saving} onSave={save} />
    </div>
  );
}
