import { useState } from 'react';
import { api, errorText, t } from '../lib';
import { Button, Card, Empty, Modal, PageHeader, Spinner, Tabs, TextInput, Toggle, useAsync, useToast } from '../ui';
import { SaveBar, useSettings } from '../settings';

type Item = { id?: number; type: 'canned' | 'faq'; title: string; shortcut: string; body: string; url: string; active: boolean; sort_order: number };

export default function Knowledge() {
  const [type, setType] = useState<'canned' | 'faq'>('canned');
  const list = useAsync<Item[]>(() => api('GET', 'admin/knowledge', undefined, { type }), [type]);
  const [edit, setEdit] = useState<Item | null>(null);
  const toast = useToast();
  const settings = useSettings();
  return (
    <div>
      <PageHeader
        title={t('Canned responses & help')}
        intro={t('Canned responses speed up replies in the inbox. Help articles and suggested questions appear in the widget before the visitor starts a chat.')}
        actions={
          <Button icon="plus" onClick={() => setEdit({ type, title: '', shortcut: '', body: '', url: '', active: true, sort_order: 0 })}>
            {type === 'canned' ? t('Add canned response') : t('Add help article')}
          </Button>
        }
      />
      <Tabs label={t('Type')} value={type} onChange={setType} items={[{ value: 'canned', label: t('Canned responses') }, { value: 'faq', label: t('Help articles') }]} />
      <Card>
        {list.loading ? (
          <Spinner />
        ) : !list.data?.length ? (
          <Empty icon="book" title={type === 'canned' ? t('No canned responses yet') : t('No help articles yet')} />
        ) : (
          <ul className="gb-k-list">
            {list.data.map((k) => (
              <li key={k.id}>
                <div>
                  <strong>{k.title}</strong> {k.shortcut && <code dir="ltr">/{k.shortcut}</code>} {!k.active && <span className="gb-hint">({t('inactive')})</span>}
                  <p className="gb-hint" dir="auto">
                    {k.body}
                  </p>
                </div>
                <div className="gb-row-actions">
                  <Button small kind="ghost" onClick={() => setEdit(k)}>
                    {t('Edit')}
                  </Button>
                  <Button
                    small
                    kind="ghost"
                    icon="trash"
                    ariaLabel={t('Delete %s', k.title)}
                    onClick={async () => {
                      if (!window.confirm(t('Delete this item?'))) return;
                      await api('DELETE', `admin/knowledge/${k.id}`);
                      list.reload();
                    }}
                  />
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
      {type === 'faq' && settings.data && (
        <Card title={t('Suggested questions')}>
          <p className="gb-hint">{t('Up to six one-tap questions shown on the widget’s home screen. Tapping one starts a chat with that text.')}</p>
          <TextInput multiline rows={6} label={t('One question per line')} value={(settings.draft.suggested_questions || []).join('\n')} onChange={(v) => settings.set('suggested_questions', v.split('\n').map((x) => x.trim()).filter(Boolean))} />
          <SaveBar dirty={settings.dirty} saving={settings.saving} onSave={settings.save} />
        </Card>
      )}
      {edit && (
        <Modal
          title={edit.id ? t('Edit') : t('Add')}
          onClose={() => setEdit(null)}
          footer={
            <>
              <Button kind="ghost" onClick={() => setEdit(null)}>
                {t('Cancel')}
              </Button>
              <Button
                onClick={async () => {
                  try {
                    await api('POST', edit.id ? `admin/knowledge/${edit.id}` : 'admin/knowledge', edit);
                    setEdit(null);
                    list.reload();
                    toast(t('Saved.'));
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
          <TextInput label={t('Title')} required value={edit.title} onChange={(v) => setEdit({ ...edit, title: v })} />
          {edit.type === 'canned' && <TextInput label={t('Shortcut')} dir="ltr" value={edit.shortcut} onChange={(v) => setEdit({ ...edit, shortcut: v })} help={t('Type / and the shortcut in the reply box to insert it.')} />}
          <TextInput multiline rows={5} label={t('Text')} required value={edit.body} onChange={(v) => setEdit({ ...edit, body: v })} />
          {edit.type === 'faq' && <TextInput label={t('Read-more link (optional)')} dir="ltr" value={edit.url} onChange={(v) => setEdit({ ...edit, url: v })} />}
          <TextInput type="number" label={t('Order')} value={edit.sort_order} onChange={(v) => setEdit({ ...edit, sort_order: Number(v) })} />
          <Toggle label={t('Active')} checked={edit.active} onChange={(v) => setEdit({ ...edit, active: v })} />
        </Modal>
      )}
    </div>
  );
}
