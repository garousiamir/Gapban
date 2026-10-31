# Design reference adoption

## What the reference is

`refrence-html/` is a Persian RTL blog/magazine theme template ("SEVI", Tailwind v4 + Alpine). Its scripts and styles were inspected before rendering: it contains Alpine.js, Font Awesome Free and a Tailwind build, and makes no network calls. It contains **no chat widget and no admin dashboard**. The closest surfaces are:

| Reference surface | Used for |
|---|---|
| Floating "demo switcher" (`fixed bottom-6 end-6`): dark pill button `h-12 px-5 rounded-2xl bg-gray-950 text-xs font-bold`, shadow `0 18px 40px rgba(15,23,42,.25)`, `hover:bg-brand`, icon swaps to `fa-xmark` when open; panel `rounded-[24px] bg-white border-gray-100 shadow-[0_30px_70px_rgba(15,23,42,.18)] p-4`, enter `250ms ease-out` + `translate-y-3`, leave `150ms ease-in` | Launcher (pill variant), open state, chat panel shape and motion |
| Toast `rounded-2xl bg-gray-950 px-5 py-3.5 shadow-[0_24px_60px_rgba(15,23,42,.3)]`, emerald check | Admin toasts |
| `.card-box` (radius 28px, `0 15px 45px rgba(15,23,42,.05)`), `.stat-tile`, `.empty-state`, `.empty-icon` | Admin cards, stats, empty states, widget rating card |
| `.btn-primary` / `.btn-ghost` / `.btn-dark` (h-12, radius 16px, bold 14px) | All buttons |
| `.comment-input` / `.comment-textarea` (radius 16px/24px, gray-200 border, focus `brand/40` + 4px `brand/10` ring) | Inputs, composer |
| `.icon-btn`, `.tag-chip`, `.tab-pill` / `.seg-btn`, `.field-label`/`.field-hint`/`.field-error`, `.eyebrow`, `.section-title` | Header buttons, chips, segmented filters, form text |
| `sevi-pop`, `sevi-pulse-ring`, `sevi-floaty` keyframes + reduced-motion overrides | Teaser pop, online ring, optional floating attention effect |
| Estedad variable font (SIL OFL 1.1, verified from the font's name table), local woff2 | Widget and admin typeface |

## Extracted tokens

`--gb-brand #ef4b4b`, `--gb-brand-dark #d33c3c`, gray scale 50…950 copied verbatim from the reference's compiled CSS (Tailwind v4 oklch values), emerald-400 for online presence, `ease-out cubic-bezier(0,0,.2,1)`, `ease-in cubic-bezier(.4,0,1,1)`, radii 12/16/20/24/28 px, the shadows listed above, type sizes 10–13 px for UI chrome and 14 px for message text. No colours, fonts or animations were invented. Amber/blue/green status hues come from the same Tailwind palette the reference ships.

## Extensions (surfaces the reference does not have)

Chat bubbles (visitor = `gray-950` like the reference's dark button; operator = `gray-50` card; automated = dashed outline plus a "پیام خودکار" badge), day separators (chip style), composer, attachment rows, typing dots, rating stars, teaser bubble (panel style with the pop animation), inbox list and thread, rules editor, setup steps. All of them reuse the tokens above.

## Deliberate deviations

| Deviation | Reason |
|---|---|
| Filled buttons, badges and launcher hover use `#d33c3c` (the reference's own `brand-dark`) instead of `#ef4b4b` | White 12–14 px text on `#ef4b4b` is 3.7:1, below WCAG AA 4.5:1; `#d33c3c` is about 4.7:1. axe reported this; fixed. |
| Meta text uses gray-500 instead of gray-400 | gray-400 on white is 2.6:1 (axe). |
| Mobile (< 640 px): the panel is full screen and the launcher is hidden while it is open | The reference's 260 px panel does not fit a chat UI on phones; the panel's minimize button is the close control. |
| Shadow DOM isolation and a separate `GapbanEstedad` font-face name | Prevents theme and page-builder CSS conflicts. |
| Font Awesome icons are inlined as SVG paths (subset), not the webfont | Smaller payload; CC BY 4.0 attribution in the licence inventory. |

## Visual comparison

Rendered at matching sizes (desktop 1280×800, tablet 820×1180, mobile 390×844) with the reference's floating panel open next to Gapban's open panel: `docs/screenshots/compare-*-side-by-side.png`. The result is a close match of the visual language (shape, typography, colours, shadow, motion), **not pixel identity**: the reference has no chat surface to compare pixel for pixel. Persian RTL and English LTR, mobile and desktop, launcher-only and panel states are in `docs/screenshots/`.
