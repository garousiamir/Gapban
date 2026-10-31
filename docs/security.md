# Security and privacy design

| Threat | Control | Test |
|---|---|---|
| Guest impersonation / IDOR | Visitor endpoints take **no conversation id**; everything is scoped to the session's visitor. The 256-bit secret is stored only as an HMAC. Sessions linked to a WP account are valid only while that account's auth cookie is present. | `SecurityTest::*session*`, `ReliabilityTest::test_twenty_simultaneous_visitors…` |
| Admin IDOR | Every conversation route re-checks `can_view_conversation()`. Unauthorized requests get 404 (no existence leak). | `test_operator_sees_only_assigned_or_own_team_conversations` |
| CSRF | Admin: WordPress cookie auth + `X-WP-Nonce`. Visitor: a custom header bearer (no ambient credentials). | — |
| XSS | Widget and inbox render message text through text nodes only; links are `http(s)` only with `rel="noopener noreferrer nofollow ugc"`. Settings are sanitized; the custom SVG goes through an allowlist sanitizer. | e2e XSS test, `test_hostile_svg…` |
| SQL injection | All queries use `$wpdb->prepare` placeholders; identifiers come from code constants. | — |
| Forged webhook | Unguessable revocable secret path (checked before parsing), 1 MB body cap, per-IP rate limit, then sender/chat allowlists and exact mappings. Bale request signing is **not** assumed. | `test_forged_webhook…`, `test_unauthorized_sender…` |
| Replay / duplicates | `UNIQUE (provider, bot, update_id)`; idempotent `client_key`. | `test_duplicate_and_out_of_order_updates` |
| SSRF | Bale base URL fixed to `https://tapi.bale.ai` (override only via a wp-config constant, localhost only with `GAPBAN_DEV_MODE`). Redirects are never followed, responses are size-limited, downloads are bounded by the upload limit. | `test_api_base_override…`, `test_download_size_is_bounded` |
| Malicious uploads | `finfo` MIME sniffing against an allowlist, no SVG/HTML/executables, double-extension check, polyglot scan, `getimagesize` for images, random names without extensions, per-conversation quota. Downloads send `nosniff`, a sandbox CSP and `Content-Disposition`. | `test_upload_validator…` |
| Public file access | Private directory with deny rules for Apache, LiteSpeed and IIS, plus an index guard; optional `GAPBAN_STORAGE_DIR` outside the web root; diagnostics probe public reachability. **nginx:** `location ~* /wp-content/uploads/gapban-private-[a-z0-9]+/ { deny all; }` | `test_attachments_are_not_downloadable_across_sessions` |
| Resource exhaustion | Rate limits on session creation (IP), requests/min (session), messages/min, uploads, typing, rule events, webhook; length and size caps. | `test_message_size_and_rate_limits`, `test_session_creation…` |
| Forwarded IP spoofing | `X-Forwarded-For` is trusted only when `REMOTE_ADDR` is a configured proxy (right-to-left hop walk). | `test_forwarded_ip…` |
| Credential leakage | Bot token and secrets encrypted with libsodium secretbox (authenticated). Token, webhook and cron secrets redacted from errors and diagnostics, excluded from export. | `test_secrets_are_encrypted_and_redacted` |
| Cache leakage | Page HTML contains only site-wide config and per-URL context; visitor data comes from `no-store` REST. | e2e cache test, `test_pages_never_receive_session_secret_in_html` |

## Key management

* **Default:** the key is derived with HKDF from `wp_salt('auth')`. Rotating WordPress salts makes stored secrets unreadable; re-enter the bot token afterwards.
* **Recommended:** define `GAPBAN_ENCRYPTION_KEY` (32+ random characters) in `wp-config.php`.
* **Rotation:** move the old value to `GAPBAN_PREVIOUS_ENCRYPTION_KEY`, set a new `GAPBAN_ENCRYPTION_KEY`, run `wp gapban rotate-key`, then remove the previous key.
* `GAPBAN_BALE_TOKEN` in `wp-config.php` overrides the stored token. The token then never touches the database.
* Session secrets and enrollment codes are HMACs keyed from `wp_salt('nonce')` and are never recoverable.

## Privacy

* No telemetry, no third-party services, no fingerprinting. First-party storage keys: `gapban:token`, `gapban:first_seen`, `gapban:rules`, `gapban:draft` (24 h), `gapban:sound`, `gapban:expanded`, `gapban:last_seen_msg` (local); `gapban:opened`, `gapban:utm`, `gapban:ref` (origin + path only), `gapban:rules_session`, `gapban:panel_open` (session).
* The text added to the privacy policy explains transmission to Bale and the limits of deletion there.
* WordPress personal-data exporter and eraser are integrated; cascade deletion is described in `architecture.md`.
* Sensitive query parameters (configurable list) are removed before page URLs reach operators, rules or storage.
