# Gapban promo videos

## v2 (current)

`out/gapban-promo-v2-1080p.mp4`: 45 s, 1920×1080, 30 fps, H.264 + AAC, −14 LUFS. The thumbnail is `out/gapban-promo-v2-thumbnail.jpg`.

Storyboard:
1. hook
2. logo
3. a visitor sends a message on the site (real widget screenshots)
4. the message arrives in a Bale group and the operator replies with "Reply"
5. the answer appears on the site (desktop + mobile)
6. the WordPress inbox and admin panel
7. features
8. price comparison
9. CTA

Sources:
* `assets/shots/*.png` are **real screenshots** of the plugin. `promo/capture.cjs` captures them from a local WordPress install with Gapban active. That includes the widget on a demo page talking to the real REST API, an operator reply sent from the real inbox, the admin screens and the mobile view.
* `assets/bale-logo.png` is the official Bale logo, supplied by the client. It is shown with the note that Bale is a trademark of its owner and that Gapban is an independent, compatible plugin.
* The Bale chat in scene 4 is recreated in HTML/CSS. It shows only the plugin's own message format and the sample conversation. No real Bale user data appears in it.
* The price table uses public competitor prices found by web search, as of Aban 1405 (they cannot be fetched from this environment). Verify them before publishing; the on-screen footnote says they may have changed.
* Music and sound effects are synthesized by `promo/audio.py`, which uses no samples. Icons come from `src/shared/icons.ts` plus Font Awesome Free (CC BY 4.0).

Rebuild:
```
NODE_PATH=<global node_modules> node marketing/promo/capture.cjs marketing/assets/shots   # optional; needs WP at $BASE (default http://localhost:8899)
NODE_PATH=<global node_modules> node marketing/promo/render.mjs /tmp/frames --scene=marketing/promo/v2/scene.html --timeline=marketing/promo/v2/timeline.json
python3 marketing/promo/audio.py /tmp/promo.wav marketing/promo/v2/timeline.json
ffmpeg -framerate 30 -i /tmp/frames/f%05d.jpg -i /tmp/promo.wav -c:v libx264 -preset slow -crf 17 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 192k -af loudnorm=I=-14:TP=-1.0:LRA=7 -shortest marketing/out/gapban-promo-v2-1080p.mp4
```
`render.mjs` also accepts `--from=a --to=b` to render a time range in parallel, and `--times=a,b` to render spot-check stills.

## v1 (superseded)

`out/gapban-promo-1080p.mp4` was built from `promo/scene.html` and `promo/timeline.json`, using the same commands without `--scene` / `--timeline`.
