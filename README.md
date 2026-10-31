# Gapban | گپ‌بان

افزونه چت آنلاین وردپرس متصل به بله. Visitors chat on the website; operators answer in Bale (Reply Mode) or the WordPress inbox.

* Installable plugin: `release/gapban.zip`
* Distribution package (ZIP + Persian docs + licences + source): `release/gapban-distribution.zip`
* Persian guides: `docs/fa/`
* Technical docs: `docs/architecture.md`, `docs/security.md`, `docs/reports.md`, `docs/bale-capabilities.md`, `docs/design-reference.md`
* Status and evidence: `docs/implementation-status.md`, `docs/test-report.md`, `docs/release-limitations.md`

## Build

```
npm ci
node tools/package.mjs        # builds assets, translations, release/gapban.zip and the distribution ZIP
```

## Tests

```
WP_PATH=/path/to/wordpress WP_DB_NAME=wptest php tests/php/run.php   # needs Gapban active in that install; truncates Gapban tables
node --test tests/js/*.test.mjs
BASE=http://localhost:8899 WP_DB_NAME=wp node tests/e2e/run.mjs      # Playwright
```

Translations: edit `tools/l10n/fa_IR_*.py`, then `python3 tools/l10n/build_po.py && node tools/i18n.mjs`.

`refrence-html/` is the supplied visual design reference; it is not part of the plugin.
