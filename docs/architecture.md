# Gapban — technical documentation

## Supported platform matrix

| Component | Minimum | Tested in this build | Notes |
|---|---|---|---|
| WordPress | 6.5 | 6.5.5 | Uses REST API, `wp_register_script` strategies (6.3+), translation controller (6.5). The newest WordPress release could not be downloaded (wordpress.org blocked in the build environment); test on the current release before shipping. |
| PHP | 8.1 | 8.3.6 | Requires `sodium`, `json`, `mbstring`, `fileinfo`. `intl` is optional (server-side Jalali dates in transcripts). 8.1 is past upstream security support; 8.2+ is recommended. |
| Database | MySQL 5.7 / MariaDB 10.4, InnoDB | MariaDB 10.11.14 | Transactions are required for atomic message/outbox writes. |
| Browsers | Last two versions of Chrome, Edge, Firefox, Safari; iOS Safari 15+; Android Chrome | Chromium (Playwright) | Uses Shadow DOM, `Intl` Persian calendar, `BroadcastChannel` (falls back to `storage` events). |
| WooCommerce | optional | not installed | Cart/page conditions return "no match" when absent. |
| Multisite | per-site activation only | not tested | Network activation is refused; each site has its own tables/options. No network-wide claims are made. |

## Modules (`gapban/includes`, namespace `Gapban\`)

| Module | Responsibility |
|---|---|
| `Core` | Bootstrap (`Plugin`), settings & encrypted secrets, locale helpers (digits, phones, Jalali display), cron, installer, maintenance, WP-CLI, translatable strings |
| `Persistence` | `Schema` (22 tables, versioned migrations), `Db` helpers |
| `Domain` | `ConversationState` state machine, random ids |
| `Security` | Crypto, visitor sessions, rate limiting, client IP / trusted proxies, capabilities, SVG sanitizer, upload validation, private storage, redaction, DB locks |
| `Provider` | Provider-neutral interface, normalized `InboundMessage`, classified `ProviderException` |
| `Bale` | HTTP client, provider adapter, update normalizer, webhook manager, poller, capability matrix |
| `Routing` | `RoutingInterface`, `ReplyRouter`, `TopicRouter` (gated), registry/snapshots, inbound processor, delivery handler, enrollment codes |
| `Queue` | Durable job queue, worker |
| `Chat` | Conversation service, assignment, availability, audit, transcripts |
| `Rules` | Rule validator and storage/events (evaluation is in `src/shared/rules.ts`) |
| `Reporting` | Metrics |
| `Privacy` | Retention/cascade deletion, WordPress exporter/eraser |
| `Rest` | Visitor, webhook/cron, inbox and management controllers, serializers |
| `Admin` | Menu, React app bootstrap, generated string table |
| `Widget` | Front-end enqueue with cache-safe config |

Front end: `src/widget` (loader ≈11.7 KB gzip, lazy chat chunk ≈19.7 KB gzip, Shadow DOM), `src/admin` (React 18 bundled into an IIFE, so other plugins' globals cannot collide), `src/shared` (rule engine, formatting, icons).

## Database schema (prefix `{$wpdb->prefix}gapban_`)

All datetimes are UTC `DATETIME`. Provider ids are `VARCHAR(32)` strings (they can exceed 32-bit and JavaScript-safe integers).

| Table | Purpose | Key indexes / constraints |
|---|---|---|
| `visitors` | Visitor profile (name/email/phone/custom), block flag, consent time | `UNIQUE public_id`, `wp_user_id`, `last_seen_at` |
| `sessions` | Bearer sessions: public id + **HMAC of the secret**, expiry, revocation | `UNIQUE public_id`, `visitor_id`, `expires_at` |
| `conversations` | State, team, assignee, **immutable `routing_snapshot`**, read markers, typing-until, first human response, version (optimistic concurrency) | `UNIQUE public_id`, `UNIQUE reference`, `(visitor_id,status)`, `(status,last_message_at)`, `(assigned_operator_id,status)` |
| `participants` | Who joined a conversation | `UNIQUE (conversation_id,kind,ref_id)` |
| `messages` | Messages and internal notes, delivery state | `UNIQUE public_id`, **`UNIQUE (conversation_id, client_key)`** (idempotency), `(conversation_id,id)` cursor |
| `attachments` | Protected files (random 40-char storage names, sha256, mime) | `UNIQUE public_id`, `conversation_id`, `message_id` |
| `operators` | Name, WP user, **`UNIQUE bale_user_id`**, availability, heartbeat, capacity, schedule | |
| `teams`, `team_members` | Departments, destination chat, strategy, round-robin cursor | `UNIQUE slug`; PK `(team_id,operator_id)` |
| `provider_mappings` | Provider message → conversation/message/part | **`UNIQUE (provider, bot_id, chat_id, provider_message_id)`** |
| `inbound_updates` | Durable raw updates and processing status | **`UNIQUE (provider, bot_id, update_id)`** |
| `jobs` | Outbox/queue | `(status,available_at)`, `(order_key,id)` |
| `rules`, `rule_events` | Targeting rules; shown/clicked/dismissed/converted | `(enabled,priority)`, `(rule_id,event,created_at)` |
| `tags`, `conversation_tags`, `ratings` | Organisation and CSAT | `UNIQUE ratings.conversation_id` |
| `audit_events` | Append-only audit trail (no message bodies, no secrets) | `(object_type,object_id)` |
| `enrollments` | One-time operator/destination codes (HMAC only, 15 min, single use) | `UNIQUE code_hash` |
| `rate_limits`, `locks` | Atomic fixed-window counters; lease locks | PK |
| `knowledge` | Canned responses and FAQ | `(type,active,sort_order)` |

Migrations: `Schema::migrate()` runs `dbDelta` for structure, then data migrations keyed by schema version (`gapban_db_version`). It runs on activation and on the first request after an update.

## Conversation state machine

```
queued ──operator_message──▶ waiting ──visitor_message──▶ open
  │  └─assign──▶ open ──operator_message──▶ waiting
  ├─close──▶ closed ──reopen / visitor_message (if allowed)──▶ open|queued
  └─spam──▶ spam ──unspam──▶ closed
```

`transition()` is a compare-and-set on `version`; concurrent changes return HTTP 409.

## Delivery states (never conflated)

| `messages.delivery_status` | Meaning |
|---|---|
| `none` | Not routed to a provider (inbox-only conversation, internal note) |
| `queued` | Accepted and stored by WordPress; job pending |
| `sent` | Bale API accepted **every** part. Not a read receipt. |
| `retrying` | Transient failure; will retry |
| `uncertain` | Timeout or crash after the request may have reached Bale |
| `failed` | Permanent error or attempts exhausted (dead letter) |

The visitor sees: ✓ *received by the website* → ✓✓ *delivered to the support team* (`sent`) → ✓✓ in brand colour *seen* (only from the WordPress inbox read marker).

## REST API (`/wp-json/gapban/v1`)

Visitor endpoints authenticate with `X-Gapban-Session: <session_public_id>.<secret>`. All responses are `Cache-Control: no-store, private`.

| Method & path | Purpose |
|---|---|
| `POST /session` | Create a visitor session (rate-limited per IP; honeypot `website`) |
| `GET /bootstrap` | Availability, logged-in state/roles (from the auth cookie, read-only), current conversation, unread count, WooCommerce cart |
| `GET/POST /conversation` | Current conversation + latest messages / start with pre-chat data |
| `POST /conversation/close` | Visitor ends the chat |
| `GET /messages?after=&before=` | Cursor pagination |
| `POST /messages` | `{body, client_key, attachments[]}`; idempotent per `client_key` |
| `POST /read`, `POST /typing` | Read marker; real typing signal (6 s TTL) |
| `POST /uploads` | Multipart `file` (+`voice=1`) |
| `GET /attachments/{id}` | Ownership-checked download with safe headers |
| `POST /rating`, `GET /transcript` | CSAT; plain-text transcript |
| `POST /rule-events` | shown/clicked/dismissed |
| `POST /bale/webhook/{secret}` | Bale webhook |
| `POST /cron/{secret}` | Host-cron worker trigger |

Admin endpoints (`/admin/...`) use cookie auth + `X-WP-Nonce`, plus capability checks per route and per conversation. They cover conversations (list/get/delete/messages/reply/notes/actions/read/typing/uploads/transcript), attachments, visitor block, heartbeat, tags, canned, settings (+export/import), connection (token, webhook, test, cron secret), operators (+enroll), enrollments, teams (+bind), rules (+validate/import/export), targeting data, knowledge, reports, queue (+retry/discard/run), inbound log and diagnostics.

Capabilities: `gapban_manage_settings`, `gapban_view_assigned`, `gapban_view_all`, `gapban_reply`, `gapban_assign`, `gapban_view_reports`, `gapban_export`, `gapban_delete`, `gapban_diagnostics`. Administrators get all of them; the `gapban_operator` role gets view-assigned and reply.

## Routing

* **Snapshot.** At creation each conversation stores `{mode, provider, bot_id, destination_chat_id, destination_title, team_id}`. Changing the global mode or destination, or deleting a team, never rewrites existing snapshots. If the bot itself is replaced, older conversations fail with `bot_changed` (visible in the queue) instead of misrouting.
* **Reply Mode outbound.** The first relayed message carries a header with the reference (`GB-XXXXXX`) and approved context. Later messages reply to that first message, so the group shows a reply chain. Each chunk and attachment gets a mapping `(provider, bot, chat, message_id) → (conversation, message, part)`. On retry, parts that already have a mapping are skipped.
* **Reply Mode inbound.** Checks run in this order: bot/service/anonymous senders are ignored; enrollment codes; test-message reply; approved chat (current or former destination, or operator private chat); linked active operator; **exact mapping of `reply_to_message_id` in the same chat**; team/assignee authorization; commands; content. Unmapped replies are rejected with a rate-limited bot notice. There is no "last active conversation" fallback anywhere. The operator's own message is mapped too, so replies to it resolve.
* **Topics Mode.** Gated as described in `bale-capabilities.md`.

## Queue and workers

* `jobs` holds `deliver_message`, `process_inbound` and `notify` jobs. The visitor message and its delivery job are written in **one InnoDB transaction**. `Delivery::requeue_orphans()` (hourly) is defence in depth, covered by a test.
* **Claim:** select runnable jobs with no earlier `pending|running` job on the same `order_key` (per conversation / per inbound chat), then `UPDATE … WHERE status='pending'`. Exactly one worker wins (tested with three parallel processes).
* **Lease:** 120 s. Expired leases (crashed workers) are requeued with `uncertain=1`.
* **Failure classes:** retryable (backoff 5 s·2ⁿ with full jitter, capped at 15 min, 8 attempts) · rate-limited (`retry_after`, attempt not consumed) · permanent/auth (dead letter at once) · uncertain (`retry` policy re-sends and marks *possible duplicate*; `hold` policy dead-letters for review).
* **Runners:** a bounded post-response attempt (`fastcgi_finish_request`, ≤8 s, same conversation only); `wp gapban worker --seconds=50`; `POST /cron/{secret}`; WP-Cron every minute (fallback only).
* **Webhook:** secret check → size check (1 MB) → `INSERT IGNORE` into `inbound_updates` → enqueue → `200`. Processing continues after the response and in the queue.
* **Polling:** one poller via a DB lease lock; refuses while a webhook is set; records each update before advancing the stored offset.

Exactly-once remote delivery is **not** claimed: Bale offers no idempotency key, so a timeout after send is reported as uncertain.

## Website polling

Panel open and visible: every 3 s during activity, 8 s after 2 minutes idle. Panel open but tab hidden: 30 s. Panel closed with an active conversation: the loader checks `/bootstrap` every 30 s (90 s when hidden). Errors back off exponentially to 60 s. The `online` and `visibilitychange` events trigger an immediate poll. Messages are keyed by public id and client key, so duplicates never render twice. Other tabs receive new messages through `BroadcastChannel`.
