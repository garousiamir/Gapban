# Test report — Gapban 1.0.0 (2026-10-31)

Raw outputs: `docs/test-output/`.

| Suite | Environment | Result |
|---|---|---|
| PHP integration (`tests/php`, custom runner) | WordPress 6.5.5, PHP 8.3.6, MariaDB 10.11.14, real REST stack, **FakeBale** (in-process fake via `pre_http_request`) | **78 passed, 0 failed** |
| JS unit (`tests/js`, node:test) | Node 22 | **9 passed, 0 failed** |
| Browser E2E (`tests/e2e`, Playwright Chromium) | Same WordPress served by PHP's built-in server; Twenty Twenty-Four theme | **14 passed, 0 failed** |
| Accessibility (axe-core 4.10, WCAG 2.0/2.1/2.2 A/AA rules, run inside E2E) | Open widget; admin inbox | 0 violations inside Gapban UI (after fixing contrast and list semantics) |
| Static checks | `tsc --noEmit` (strict), `php -l` on every file | clean |
| Clean install from `release/gapban.zip` | Fresh WordPress 6.5.5 + empty MariaDB database | activated; 22 tables; schema v3; `POST /session` 200; `POST /conversation` 200; widget config enqueued; fa_IR .mo present; deactivate keeps tables; no PHP notices |
| Bundle budget | loader ≤ 14 KiB gzip (enforced by build) | loader 11.7 KiB, chat 19.7 KiB, admin 93.8 KiB + 5.0 KiB CSS (gzip) |

## Required cases → evidence

| Required case | Evidence | Kind |
|---|---|---|
| 20 simultaneous visitors, no leakage | `ReliabilityTest::test_twenty_simultaneous_visitors_have_isolated_histories` | internal (FakeBale) |
| Replies to older, chunked and media messages | `ReplyModeTest::test_reply_to_older…`, `test_long_message_is_chunked…`, `test_reply_to_media_message_resolves`, `test_media_reply_is_downloaded_and_attached` | internal |
| Duplicate and out-of-order updates | `test_duplicate_and_out_of_order_updates` | internal |
| Concurrent sends, claims, worker crashes | `test_parallel_workers_never_double_send` (3 processes), `test_parallel_claims_from_separate_processes`, `test_concurrent_claims…`, `test_worker_crash_recovery…`, `test_idempotent_visitor_send…`, `test_per_conversation_order…` | internal, real DB concurrency |
| Outage/recovery, invalid token, rate limits, uncertain sends | `test_provider_outage_then_recovery`, `test_invalid_token…`, `test_rate_limit_honours_retry_after…`, `test_uncertain_send_with_hold_policy…`, `test_malformed_provider_response…`, `test_deleted_group…`, `test_partial_chunk_failure…` | internal |
| Routing default changes preserve conversations | `test_routing_destination_change_preserves_existing_conversations` | internal |
| Topics Mode cannot be enabled | `test_topics_mode_cannot_be_selected` | internal |
| Forged webhook, unauthorized sender, expired session, cross-session downloads | `test_forged_webhook…`, `test_unauthorized_sender…`, `test_expired_and_revoked_sessions…`, `test_attachments_are_not_downloadable_across_sessions`, `test_visitor_cannot_attach_someone_elses_upload` | internal |
| Stored XSS, hostile SVG, executable upload, SSRF, oversized payloads | e2e XSS (widget and admin), `test_hostile_svg…`, `test_upload_validator…`, `test_api_base_override…`, `test_download_size_is_bounded`, `test_oversized_webhook_body…`, `test_message_size_and_rate_limits` | internal + browser |
| Refresh, multi-tab, background tabs, reconnect, cached pages | e2e acceptance (refresh/navigation/second tab), hidden-tab test, multi-tab test, cache test | browser |
| Rule precedence, exclusions, cooldown, dismissal, consent | `tests/js/rules.test.mjs`, e2e dismissal test, prechat validation tests | unit + browser |
| Persian/English, mobile keyboard, digits, timezone, Jalali | e2e LTR test, mobile full-screen test, `DomainTest` digits/phone/timezone/holiday tests, JS Jalali test | unit + browser |
| Fresh install, migrations, deactivate/reactivate, uninstall | `LifecycleTest` (6 tests) | internal |
| **Real Bale sandbox round trip** | **NOT RUN.** No credentials were supplied, and `tapi.bale.ai` was blocked by the build environment's network policy. | **unverified** |

## Not verified in this build

* Any behaviour of the real Bale API (see `bale-capabilities.md`).
* Visual fidelity beyond a side-by-side comparison of the design language: the reference has no chat surface, so there is no pixel comparison.
* WordPress releases newer than 6.5.5, PHP 8.1/8.2/8.4, MySQL 8, real WooCommerce, page builders, persistent object cache, CDN/full-page cache products, Safari/Firefox/real mobile devices, and multisite.
* The WordPress admin chrome in tests had no core CSS (the bundled test build ships without static core assets); Gapban's own UI was unaffected.
* Load beyond the concurrency tests above (no sustained load test was run).
