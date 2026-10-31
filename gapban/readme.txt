=== Gapban | گپ‌بان ===
Contributors: gapban
Tags: live chat, bale, persian, support, chat widget
Requires at least: 6.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

گپ‌بان | افزونه چت آنلاین وردپرس متصل به بله — Persian-first live chat for WordPress; operators answer in Bale or the WordPress inbox.

== Description ==

Visitors chat on your website without a Bale account. Your team answers by replying in a Bale group (Reply Mode) or from the WordPress inbox. WordPress stores the conversation history and controls access, routing and delivery state.

* Persian (RTL) by default, complete English (LTR).
* Durable delivery queue with retries, dead letters and honest delivery states.
* Exact reply mapping: operator replies are routed only through the Bale message they replied to.
* Display rules and proactive automated messages with frequency caps.
* Reports, CSAT, privacy tools, retention.
* No external SaaS, CDN, telemetry or relay. Only the Bale Bot API is contacted.

Topics Mode (one Bale topic per conversation) is disabled: Gapban could not confirm the required topic operations in the official Bale Bot API.

Full Persian documentation ships in the distribution package (docs/fa).

== Installation ==

1. Upload gapban.zip in Plugins → Add New → Upload.
2. Activate the plugin (per site on multisite).
3. Open Gapban → Setup & connection and follow the steps.
4. Configure a server cron job: `* * * * * wp gapban worker --seconds=50 --quiet`.

== Changelog ==

= 1.0.0 =
* First release.
