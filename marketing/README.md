# Gapban promo video

`out/gapban-promo-1080p.mp4` — 40 s, 1920×1080, 30 fps, H.264 + AAC, loudness −14 LUFS (web/social standard).
`out/gapban-promo-poster.jpg`, `out/gapban-promo-thumbnail.jpg` — poster and thumbnail frames.

Everything is generated from code and is original:
* Visuals: `promo/scene.html` (deterministic `render(t)` animation in the plugin's design language), rendered frame by frame by `promo/render.mjs` (Playwright/Chromium).
* Music and sound effects: `promo/audio.py` synthesizes a 128 BPM track (A minor, Am–F–C–G) and UI sound effects from `promo/timeline.json` — no samples or third-party music.
* The messenger shown is a generic phone mock; no Bale logo or app UI is reproduced.

Rebuild:
```
node marketing/promo/render.mjs /tmp/frames          # needs Playwright (NODE_PATH to a global install is fine)
python3 marketing/promo/audio.py /tmp/promo.wav      # needs numpy + scipy
ffmpeg -framerate 30 -i /tmp/frames/f%05d.jpg -i /tmp/promo.wav -c:v libx264 -preset slow -crf 18 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 192k -af loudnorm=I=-14:TP=-1.0:LRA=7 -shortest marketing/out/gapban-promo-1080p.mp4
```
Edit texts and timing in `scene.html` / `timeline.json`; sound effects follow the timeline automatically.
