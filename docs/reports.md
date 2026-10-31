# Report formulas

Population: conversations whose `created_at` falls in the selected local-date range (converted to UTC using the configured time zone), excluding `spam`.

| Metric | Formula |
|---|---|
| Volume | count of conversations |
| Unanswered rate | conversations with `first_human_response_at IS NULL` ÷ volume |
| First human response | `first_human_response_at − created_at`; median, average, p90. `first_human_response_at` is set only by operator messages (Bale or WordPress). Automated greetings, campaign messages and system events never set it. |
| Resolution time | `closed_at − created_at` for conversations in status `closed` |
| Queue wait | `assigned_at − created_at` (claim, first reply, or automatic assignment) |
| CSAT | ratings with score ≥ 4 ÷ all ratings (also average score) |
| Operator performance | per operator: assigned conversations, non-internal replies, resolved (closed in range), average first response on assigned conversations, average rating |
| Team performance | per department: volume, unanswered, average first response |
| Campaign conversion | `converted` events (conversation started with that rule as campaign) ÷ `shown` events. Shows are counted from visitors' browsers and can be undercounted by blockers. |

Covered by `DomainTest::test_reports_exclude_automated_messages_from_human_response`.
