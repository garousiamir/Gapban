import { useEffect, useState } from 'react';
import { api, errorText, t } from './lib';
import { Button, useToast } from './ui';

export type SettingsData = { settings: Record<string, any>; routing: { modes: Array<{ mode: string; available: boolean; reason: string }> }; timezones: string[]; site_timezone: string; upload_types: string[]; woocommerce: boolean };

/** Load settings once, edit a local draft, save only changed keys. */
export function useSettings() {
  const toast = useToast();
  const [data, setData] = useState<SettingsData | null>(null);
  const [draft, setDraft] = useState<Record<string, any>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<SettingsData>('GET', 'admin/settings')
      .then((d) => {
        setData(d);
        setDraft(d.settings);
      })
      .catch((e) => setError(errorText(e)));
  }, []);
  const set = (key: string, value: unknown) => setDraft((d) => ({ ...d, [key]: value }));
  const dirtyKeys = data ? Object.keys(draft).filter((k) => JSON.stringify(draft[k]) !== JSON.stringify(data.settings[k])) : [];
  const save = async () => {
    if (!data || !dirtyKeys.length) return;
    setSaving(true);
    try {
      const patch: Record<string, unknown> = {};
      dirtyKeys.forEach((k) => (patch[k] = draft[k]));
      const res = await api<{ settings: Record<string, any> }>('POST', 'admin/settings', patch);
      setData({ ...data, settings: res.settings });
      setDraft(res.settings);
      toast(t('Settings saved.'));
    } catch (e) {
      toast(errorText(e), 'error');
    } finally {
      setSaving(false);
    }
  };
  return { data, draft, set, save, saving, dirty: dirtyKeys.length > 0, error };
}

export function SaveBar({ dirty, saving, onSave }: { dirty: boolean; saving: boolean; onSave: () => void }) {
  return (
    <div className={'gb-savebar' + (dirty ? ' gb-savebar-dirty' : '')}>
      <span className="gb-hint" role="status">
        {dirty ? t('You have unsaved changes.') : t('All changes saved.')}
      </span>
      <Button onClick={onSave} busy={saving} disabled={!dirty} icon="check">
        {t('Save changes')}
      </Button>
    </div>
  );
}
