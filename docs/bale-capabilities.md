# Bale Bot API — capability record

**Verification date:** 2026-10-31
**Initial official reference:** https://docs.bale.ai/

## What could and could not be verified

The build environment's network policy **blocked both `docs.bale.ai` and `tapi.bale.ai`**:

```
curl https://docs.bale.ai/        → CONNECT tunnel failed, response 403 (egress proxy: connect_rejected)
curl https://tapi.bale.ai/bot…/getMe → CONNECT tunnel failed, response 403 (egress proxy: connect_rejected)
WebFetch https://docs.bale.ai/   → getaddrinfo ENOTFOUND (host outside the session's allowed domains)
```

As a result:

* **No statement below was verified against the official Bale documentation during this build.** A web search returned only third-party SDKs and Telegram-derived references, which the specification forbids treating as proof.
* **No real sandbox observation exists yet.** No bot token or sandbox group was supplied, and the API host was unreachable anyway.
* Every Bale-dependent feature is therefore implemented **conservatively**. Gapban records *runtime observations*: the first time an installation actually performs an operation successfully, it stores a timestamp. The Setup screen shows each capability as **Observed**, **Not yet observed** or **Not available**. This is the "actual sandbox observations" column, and it fills in on the first real install.

To complete verification, allow `docs.bale.ai` and `tapi.bale.ai` in the environment's network settings, supply a sandbox bot token and a test group, then run *Setup → Test sending and receiving* (or the manual checklist at the end of this file).

## Assumptions the implementation relies on

These are the minimum assumptions a Bale bot integration needs. Each one is isolated in `gapban/includes/Bale/` so it can be corrected in one place.

| Assumption | Where used | How it fails safely if wrong |
|---|---|---|
| Endpoint `https://tapi.bale.ai/bot<token>/<method>`, JSON body, response `{ok, result}` / `{ok:false, error_code, description}` | `BaleClient::call()` | `getMe` fails in the setup wizard → token not saved; clear Persian error |
| File download `https://tapi.bale.ai/file/bot<token>/<file_path>` after `getFile` | `BaleClient::download()` | Download fails → operator gets a notice, nothing is attached |
| `getMe` returns `{id, username, first_name}` | `BaleProvider::verify()` | Shape check → explicit error |
| `sendMessage(chat_id, text, reply_to_message_id)` returns `{message_id}` | `BaleProvider::send_text()` | Missing `message_id` → **uncertain** (not "sent") |
| `sendPhoto` / `sendDocument` as multipart uploads | `BaleProvider::send_file()` | Error → retry/dead-letter, visible in the queue |
| Updates: `{update_id, message{message_id, from{id,is_bot,…}, chat{id,type,title}, text|caption|photo[]|document|voice|audio, reply_to_message{message_id}}}` and `edited_message` | `BaleProvider::normalize()` | Unknown shapes → ignored, logged as `unsupported_update` |
| `setWebhook(url)`, `deleteWebhook`, `getWebhookInfo{url, pending_update_count, last_error_*}` | `WebhookManager` | Errors shown in Setup; nothing destructive without confirmation |
| `getUpdates(offset, limit, timeout)` acknowledges updates below `offset`, and does not work while a webhook is set | `Poller` | Poller refuses to run while `getWebhookInfo.url` is non-empty |
| HTTP 429 may carry `parameters.retry_after` | `BaleClient`, `JobQueue::fail()` | If absent, exponential backoff with jitter |

## Supported operations (Reply Mode)

| Operation | Status in code | Notes |
|---|---|---|
| Token entry, masked display, `getMe` verification | Implemented | Token encrypted (libsodium secretbox); `GAPBAN_BALE_TOKEN` override |
| Send text, chunked | Implemented | Default chunk 3,500 characters (conservative, unverified limit; configurable 500–4,000) |
| Send photo / document | Implemented | JPEG/PNG/WebP as photo; everything else as document |
| Voice from website | Implemented as **audio document** | Not sent as a native Bale voice note (format support unverified) |
| Receive text replies | Implemented | Only via exact reply mapping |
| Receive photo / document / voice / audio from operators | Implemented | Size-bounded download, MIME re-validation |
| Reply metadata | Implemented | `reply_to_message.message_id` + chat id → mapping lookup |
| Webhook set / status / delete | Implemented | Ownership tracked; foreign webhook never replaced or removed without confirmation |
| `getUpdates` fallback | Implemented | Single poller (DB lease), offset persisted after durable recording |
| Rate-limit handling | Implemented | `retry_after` honoured when present; attempts not consumed |

## Disabled or not offered (with reasons)

| Capability | Decision | Persian UI text |
|---|---|---|
| Topics (topic per conversation) | **Disabled.** Topic creation, topic ids and per-topic routing could not be confirmed in the official API. `TopicRouter` exists behind `RoutingInterface`, but `Capabilities::topics_supported()` returns `false`. The REST API refuses `routing_mode=topics`, and new conversations fall back to Reply Mode even if the option is forced. | «موضوع‌بندی در API فعلی بله پشتیبانی نمی‌شود» + explanation |
| Inline keyboards / callback queries | Not offered | Text commands (`/claim`, `/close`, `/reopen`, `/spam`, `/note`) sent as replies are authorized against the operator allowlist |
| Signed / secret-header webhooks | Not assumed | 256-bit revocable secret URL path + sender/chat allowlists |
| Operator read receipts, presence, typing from Bale | Not available to bots | Never fabricated |
| Edit sync | Not offered | Edits are audited only |
| Bulk/business messaging, payments, OTP | Not used | Not needed for support chat |

## Group visibility

Every member of a Bale group can read every message the bot posts there. Gapban never promises confidentiality between members of one group. The UI warns about this in Setup and on the Departments page, and recommends separate destination groups per department or the WordPress inbox.

## Manual verification checklist (run once credentials and network access exist)

1. Setup → paste the token → expect the bot's username (`getMe` becomes **Observed**).
2. Bind the support group with `/gapban_bind <code>`.
3. *Send test message* → `sendMessage` **Observed**. Reply to it in Bale → *Receiving works*, `reply_metadata` **Observed**.
4. Start a website chat, attach a PNG and a PDF → `sendPhoto` / `sendDocument` **Observed**.
5. Reply in Bale with text, a photo and a file → the visitor receives all three (`getFile` **Observed**).
6. Send a 10,000-character message → chunks numbered; reply to the last chunk → routed correctly. Record the real per-message limit here.
7. Register the webhook; compare `getWebhookInfo`; remove it. Switch to polling and confirm `getUpdates` refuses while the webhook is set.
8. Record findings, including error payload shapes, in this file under a new "Sandbox observations" section with the date.
