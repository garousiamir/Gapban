import { useState } from 'react';
import { api, downloadText, errorText, t } from '../lib';
import { Button, Card, Notice, PageHeader, Toggle, useToast } from '../ui';

export default function Tools() {
  const toast = useToast();
  const [withDest, setWithDest] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  const importFile = async (file: File) => {
    if (file.size > 2 * 1024 * 1024) {
      toast(t('The file is too large.'), 'error');
      return;
    }
    try {
      const data = JSON.parse(await file.text());
      const r = await api('POST', data.format === 'gapban-rules' ? 'admin/rules/import' : 'admin/settings/import', data);
      setResult(data.format === 'gapban-rules' ? t('%d rules imported (disabled for review).', r.imported) : t('Settings imported: %d rules, %d help items.', r.rules, r.knowledge));
    } catch (e) {
      toast(e instanceof SyntaxError ? t('This is not a valid JSON file.') : errorText(e), 'error');
    }
  };
  return (
    <div>
      <PageHeader title={t('Import & export settings')} intro={t('Move your configuration between sites. Bot tokens and secrets are never included.')} />
      <Card title={t('Export')}>
        <Toggle label={t('Include Bale destination chat IDs and trusted proxies')} checked={withDest} onChange={setWithDest} help={t('Off by default because these identify your private groups.')} />
        <Button
          icon="download"
          onClick={async () => {
            const data = await api('GET', 'admin/settings/export', undefined, { include_destinations: withDest ? 1 : 0 });
            downloadText(`gapban-settings-${new Date().toISOString().slice(0, 10)}.json`, JSON.stringify(data, null, 2));
          }}
        >
          {t('Download settings')}
        </Button>{' '}
        <Button
          kind="ghost"
          icon="download"
          onClick={async () => {
            const data = await api('GET', 'admin/rules/export');
            downloadText(`gapban-rules-${new Date().toISOString().slice(0, 10)}.json`, JSON.stringify(data, null, 2));
          }}
        >
          {t('Download display rules only')}
        </Button>
      </Card>
      <Card title={t('Import')}>
        <p className="gb-hint">{t('Every value is validated again on import; unknown or unsafe values are dropped. Topics Mode is never enabled by an import.')}</p>
        <label className="gb-upload-tile">
          <input type="file" accept="application/json,.json" onChange={(e) => e.target.files?.[0] && importFile(e.target.files[0])} />
          <span>{t('Choose a Gapban JSON file')}</span>
        </label>
        {result && <Notice tone="success">{result}</Notice>}
      </Card>
    </div>
  );
}
