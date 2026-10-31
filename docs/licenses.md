# Dependency and asset licence inventory

| Component | Version | Licence | Where | Notes |
|---|---|---|---|---|
| Gapban | 1.0.0 | GPL-2.0-or-later | everything in `gapban/` | `gapban/license.txt` |
| Estedad font | 8.5 (from reference) | SIL OFL 1.1 | `gapban/assets/fonts/Estedad-VariableFont_wght.woff2` | Licence declared in the font's name table (IDs 0/13/14); redistributed unmodified; `gapban/licenses/OFL-1.1-Estedad.txt` |
| Font Awesome Free icons | 6.x (from reference) | Icons CC BY 4.0 | inlined SVG paths in `src/shared/icons*.ts` | Attribution in source and `gapban/licenses/fontawesome-free-LICENSE.txt` |
| React, React DOM, Scheduler | 18.3.1 | MIT | bundled into `assets/dist/admin.js` | `gapban/licenses/react*-LICENSE.txt`, `admin.js.LEGAL.txt` |
| esbuild, TypeScript, axe-core | dev only | MIT / Apache-2.0 / MPL-2.0 | not shipped | |
| Reference template (Tailwind/Alpine build) | — | not redistributed | `refrence-html/` (repository only) | Tokens and patterns were reimplemented; no reference code ships in the plugin |

No runtime CDN, web font service or remote asset is used.
