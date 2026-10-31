# Implementation status

Legend: ✅ implemented and tested here · 🟡 implemented, only partly verifiable here · ⛔ blocked/unverified (reason given) · ➖ deliberately not offered

| # | Requirement | Status | Source | Tests / evidence | Blockers / notes |
|---|---|---|---|---|---|
| 1 | HTML design reference: inspect, tokens, reproduce visual language, extensions, deviations | 🟡 | `src/widget/*.css`, `src/admin/admin.css`, `docs/design-reference.md` | `docs/screenshots/compare-*` (desktop/tablet/mobile), axe | The reference has no chat/admin surface, so the comparison covers design language, not pixels |
| 2 | Architecture: modular PHP, custom tables, TS widget, React admin, REST, queue, no SaaS | ✅ | `gapban/includes/*`, `src/*` | all suites | React bundled (no global collisions) |
| 3 | DB model, migrations, ids/secrets, idempotency, cursors, snapshots, atomic claims, UTC, retention | ✅ | `Persistence/Schema.php`, `Chat/*`, `Queue/JobQueue.php`, `Privacy/Retention.php` | Lifecycle, Reliability, Security tests | — |
| 3a | State machine; distinct local/provider/failed/read states | ✅ | `Domain/ConversationState.php`, `Routing/Delivery.php`, `Rest/Serializer.php` | DomainTest, ReplyModeTest | — |
| 4 | Bale capability verification + `docs/bale-capabilities.md` | ⛔ | `Bale/*`, `docs/bale-capabilities.md` | runtime "observed" matrix | docs.bale.ai and tapi.bale.ai blocked by network policy; no credentials |
| 5A | Reply Mode | 🟡 | `Routing/ReplyRouter.php`, `Routing/InboundProcessor.php` | 20 ReplyModeTest cases vs FakeBale | Real Bale round trip not run |
| 5B | Topics Mode gated | ✅ (disabled) | `Routing/TopicRouter.php`, `Bale/Capabilities.php` | `test_topics_mode_cannot_be_selected` | Shows «موضوع‌بندی در API فعلی بله پشتیبانی نمی‌شود» |
| 5c | Per-conversation snapshots; bot echoes, service, edits, anonymous senders; callback safety | ✅ | `RoutingRegistry`, `InboundProcessor` | routing-change, unauthorized-sender tests | Callbacks ➖ (text commands only) |
| 6 | Persian onboarding wizard, enrollment codes, allowlists, webhook ownership warning | ✅ | `src/admin/pages/Setup.tsx`, `Operators.tsx`, `Routing/Enrollment.php`, `Bale/WebhookManager.php` | enrollment, binding, foreign-webhook, test-message tests | Live Bale flow unverified |
| 6b | Operators, teams, availability, schedules, manual/round-robin/least-loaded, claim/transfer | ✅ | `Chat/Assignment.php`, `Chat/Availability.php` | DomainTest, ReliabilityTest | Group-visibility warning in UI |
| 7 | Reliability: save-first, durable inbound, dedupe, retries/backoff, ordering, dead letters, uncertain handling, polling, cron/CLI | ✅ | `Queue/*`, `Bale/Poller.php`, `Rest/WebhookController.php`, `Core/Cli.php` | ReliabilityTest (17) | Real network timing unverified |
| 7b | Adaptive website polling, reconnect | ✅ | `src/widget/chat.ts`, `loader.ts` | e2e multi-tab and round trip | — |
| 8 | Full website chat experience | ✅ / 🟡 | `src/widget/chat.ts` | e2e | Voice and file upload not covered in browser e2e (validator and storage are tested in PHP); notifications/sound need manual checks |
| 9 | Premium launcher: variants, positions, offsets, safe areas, badge, presence, teaser, states, reduced motion, overlap avoidance, live preview | ✅ / 🟡 | `src/widget/loader.ts`, `launcher.css`, `Appearance.tsx` | e2e mobile and keyboard, screenshots | Overlap heuristics need real-theme testing |
| 10 | Targeting and automation builder, acceptance example | ✅ | `src/shared/rules.ts`, `Rules/RuleValidator.php`, `src/admin/pages/Rules.tsx` | rules.test.mjs, e2e acceptance (15 s, refresh, navigation, second tab, hidden tab, dismissal) | WooCommerce conditions untested with real WooCommerce |
| 11 | Persian admin screens (12) and inbox features; granular capabilities; reports | ✅ | `src/admin/pages/*`, `Rest/InboxController.php`, `Rest/ManageController.php`, `Reporting/Reports.php` | Security capability tests, e2e admin round trip, screenshots | — |
| 12 | Localisation: fa_IR/en, POT/PO/MO, logical CSS, bidi, digits, Jalali, time zones, phones, fonts | ✅ | `languages/`, `tools/i18n.mjs`, `tools/l10n/*`, `Core/Locale.php`, `src/shared/format.ts` | DomainTest, JS tests, e2e LTR | 1,000/1,000 strings translated; native-speaker review recommended |
| 12b | WooCommerce currency | ✅ | cart values are the store's canonical values; no Rial/Toman conversion is applied, and the rule editor says so | — | — |
| 13 | Security and privacy | ✅ | `Security/*`, `Privacy/*` | SecurityTest (22), e2e XSS/cache | nginx needs the documented rule |
| 14 | Performance, a11y, compatibility | 🟡 | build budget, Shadow DOM, logical CSS | axe in e2e, bundle manifest | Multisite, page builders, CDNs and object caches untested |
| 15 | Tests | ✅ / ⛔ | `tests/*` | `docs/test-report.md` | Real Bale sandbox ⛔ |
| 16 | Packaging and docs | ✅ | `tools/package.mjs`, `docs/` | `release/gapban.zip` clean-install check | — |
