# Release readiness and limitations — 1.0.0

**Status: release candidate for internal/pilot use. Not production-certified.**

Blocking before a commercial production release:
1. **Real Bale verification.** Run the checklist in `bale-capabilities.md` with a sandbox bot and group, and correct any assumption that differs (endpoint shape, message length limit, media fields, webhook/getUpdates behaviour, error shapes).
2. **Current-platform testing.** Test on the current WordPress release and PHP 8.2–8.4, plus at least one block theme, one classic theme, WooCommerce and one page builder.
3. **Real browsers and devices.** iOS Safari (keyboard and safe areas), Android Chrome, Firefox.

Known limitations by design:
* Topics Mode is disabled (unverifiable).
* No inline buttons; text commands only.
* Voice notes are delivered to Bale as audio documents.
* No CAPTCHA integration (to avoid third parties); honeypot and rate limits only.
* Exactly-once delivery to Bale is impossible to guarantee; uncertain sends are flagged.
* Bale group members can read every conversation sent to that group.
* Guest history is not merged into a WordPress account after login (safety on shared computers).
* Campaign "shown" counts depend on visitors' browsers.
* Multisite: per-site activation only, untested.
* No marketplace licensing, update server or purchase verification is included, and none is claimed.
