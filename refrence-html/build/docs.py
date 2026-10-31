# -*- coding: utf-8 -*-
"""
Builds the offline documentation.

One self-contained HTML file. It has to open from a file:// URL on a machine
with no internet, so there are no external stylesheets, fonts or scripts.

Numbers that could go stale -- widget counts, block counts, translatable
strings -- are read from the source rather than typed, so the documentation
cannot quietly drift away from what shipped.

Usage: python build/docs.py [output-path]
"""
import glob
import io
import json
import os
import re
import sys

OUT = 'dist/documentation/index.html'


def count_widgets():
    names = set()

    for path in glob.glob('wordpress/sevi-core/includes/elementor/widgets/*.php'):
        src = io.open(path, encoding='utf-8').read()
        found = re.search(r"function get_name\(\)\s*\{\s*return\s*'([^']+)'", src)

        if found:
            names.add(found.group(1))

    return len(names)


def widget_list():
    rows = []

    for path in sorted(glob.glob('wordpress/sevi-core/includes/elementor/widgets/*.php')):
        src = io.open(path, encoding='utf-8').read()
        title = re.search(r"function get_title\(\)\s*\{\s*return __\(\s*'([^']+)'", src)
        summary = re.search(r'^/\*\*\n \* ([^\n]+)', src, re.M)

        if title:
            rows.append((title.group(1), summary.group(1) if summary else ''))

    return rows


def count_strings():
    total = 0

    for path in glob.glob('wordpress/*/languages/*.pot'):
        src = io.open(path, encoding='utf-8').read()
        total += len(re.findall(r'^msgid "', src, re.M)) - 1

    return total


def count_overrides():
    return len(glob.glob('wordpress/sevi/woocommerce/**/*.php', recursive=True))


def count_woo_widgets():
    found = 0

    for path in glob.glob('wordpress/sevi-core/includes/elementor/widgets/*.php'):
        if 'Sevi_Woo_Widget' in io.open(path, encoding='utf-8').read():
            found += 1

    return found


def block_list():
    rows = []

    for path in sorted(glob.glob('wordpress/sevi-core/templates/blocks/*.json')):
        data = json.loads(io.open(path, encoding='utf-8').read())
        rows.append((data.get('title', ''), data.get('subtype', '')))

    return rows


SECTIONS = [
    ('start', 'Getting started', '''
<h3>What is in the package</h3>
<table>
<tr><th>sevi.zip</th><td>The theme.</td></tr>
<tr><th>sevi-child.zip</th><td>A child theme. Customise here so an update never overwrites your work.</td></tr>
<tr><th>plugins/sevi-core.zip</th><td>Episodes, projects, the podcast feed, the Elementor widgets, membership and the demo importer.</td></tr>
<tr><th>html-template/</th><td>The original {pages}-page HTML template, for reference or for a static build.</td></tr>
<tr><th>documentation/</th><td>This file.</td></tr>
<tr><th>licensing/</th><td>Licences for the bundled font, icons and scripts.</td></tr>
</table>

<h3>Installing</h3>
<ol>
<li>Appearance &rarr; Themes &rarr; Add New &rarr; Upload, choose <code>sevi.zip</code>, activate it.</li>
<li>Plugins &rarr; Add New &rarr; Upload, choose <code>sevi-core.zip</code>, activate it.</li>
<li><strong>Settings &rarr; Permalinks &rarr; Save.</strong> Episodes and the podcast feed have no URLs until you do this. It is the single most common thing to forget.</li>
<li>Optionally install Elementor and WooCommerce. Neither is required.</li>
</ol>

<div class="note">
<strong>Plain permalinks will not work.</strong> Episodes, projects and the podcast feed all need pretty permalinks. SEVI &rarr; Dashboard warns you if they are off.
</div>
'''),

    ('direction', 'Language and direction', '''
<p>SEVI is built right-to-left first, but it is not RTL-only. The stylesheet uses logical CSS properties throughout, so the same file serves both directions. Switching the site language to English mirrors the layout, flips the off-canvas panels, and turns the navigation arrows around.</p>

<p>There are <strong>{strings}</strong> translatable strings across the theme and plugin. The <code>.pot</code> files are in each package under <code>languages/</code>.</p>

<h3>WPML and Polylang</h3>
<p>Both packages ship a <code>wpml-config.xml</code>. It tells the translation plugin which fields hold text and which hold a file or a number:</p>
<ul>
<li><strong>Translated:</strong> transcripts, chapter titles, client names, roles.</li>
<li><strong>Copied:</strong> audio URLs, durations, episode numbers, prices, flags.</li>
</ul>
<p>Without this a translator is handed an MP3 filename and asked to translate it.</p>
'''),

    ('colours', 'Colours and typography', '''
<p>Every colour in the theme resolves through a CSS custom property. Changing the brand colour in <strong>Appearance &rarr; Customize &rarr; SEVI theme &rarr; Colours</strong> retints buttons, links, chips, badges, glows and the progress bar <em>instantly</em>, in the live preview, with no rebuild.</p>

<p>Five colours are exposed: brand, brand dark, body text, muted text and soft background. Two typography settings: base size and line height. Persian needs more room between lines than Latin does, which is why the default is higher than you might expect.</p>

<div class="note">
Only values you change are written to the page. Reset a colour to its default and the override disappears from the source entirely.
</div>
'''),

    ('podcast', 'Podcast', '''
<h3>Publishing an episode</h3>
<ol>
<li><strong>Episodes &rarr; Add new.</strong></li>
<li>Upload the audio to the media library, or paste a URL from your host.</li>
<li>Type the duration the way you read it: <code>42:10</code> or <code>1:04:32</code>. Persian digits work too. It is stored as seconds and the label is always derived, so the two can never disagree.</li>
<li>Add chapters one per line, timestamp then title: <code>04:12 The first guest</code></li>
<li>Paste a transcript if you have one. It becomes searchable on the episode page and is the single biggest accessibility improvement a podcast can make.</li>
</ol>

<h3>The feed</h3>
<p>Your feed is at <code>/feed/podcast/</code>. Fill in <strong>SEVI &rarr; Podcast</strong> before submitting it anywhere &mdash; Apple contacts the <em>owner</em>, not the author, and rejects cover art smaller than 1400&times;1400.</p>

<div class="note">
<strong>Validate before you submit.</strong> Paste your feed into <code>podba.se/validate</code>. Apple and Spotify both cache a rejection, so it is much easier to be right the first time.
</div>

<p>An episode with no audio file is left out of the feed &mdash; a directory does not consider it an episode.</p>
'''),

    ('elementor', 'Elementor', '''
<p>SEVI adds <strong>{widgets}</strong> widgets under a <strong>SEVI</strong> category in the widget panel, and a <strong>SEVI Library</strong> tab in the Add Template window holding <strong>{blocks}</strong> ready-made blocks.</p>

<p>Nothing here requires Elementor. Every template works without it; Elementor is for building pages that are not simply a list of posts.</p>

<h3>The shop widgets</h3>
<p>{woo_widgets} widgets only appear when WooCommerce is active. If you deactivate WooCommerce after building a page with them, they show an explanation in the editor rather than disappearing.</p>

<h3>Alignment</h3>
<p>Alignment controls read <strong>Start</strong>, <strong>Center</strong> and <strong>End</strong> rather than left and right. Start is the right edge in Persian and the left edge in English, so a layout you build in one language is correct in the other.</p>
'''),

    ('shop', 'Shop and membership', '''
<p>WooCommerce is optional. When it is active, SEVI restyles the shop, product, cart, checkout and account pages, and dequeues WooCommerce&rsquo;s own three stylesheets &mdash; they would fight the theme rather than add to it.</p>

<h3>Selling a membership</h3>
<ol>
<li>Create a product.</li>
<li>On the General tab set <strong>Grants plan</strong> and <strong>Days granted</strong>. Use 0 for lifetime.</li>
<li>Add the <strong>SEVI Plan Cards</strong> widget to a page. Every product with a plan appears automatically.</li>
</ol>

<p>When an order completes, the buyer gets the plan. Renewing before it expires <em>adds</em> to the remaining time rather than restarting it.</p>

<h3>Gating content</h3>
<p>Every post and episode has a <strong>Required plan</strong> dropdown. Leave it empty to keep the content open. A gated episode does not put its audio URL in the page source &mdash; hiding the player while leaving the file linked is not a paywall.</p>

<div class="note">
If you delete a plan while posts still require it, those posts stay locked rather than becoming public. Locking is recoverable; publishing paid content by accident is not.
</div>
'''),

    ('demos', 'Importing a demo', '''
<p><strong>SEVI &rarr; Demo import.</strong> Pick a demo and leave the tab open.</p>

<p>The import runs in small batches rather than one long request, which is why it works on shared hosting where other importers stop halfway. The progress bar reflects real work; the log tells you what happened.</p>

<ul>
<li>Install the <strong>WordPress Importer</strong> plugin first, or posts and pages will not come in.</li>
<li>Running the same import twice does not duplicate images.</li>
<li>An image that cannot be fetched is logged and skipped, not fatal.</li>
</ul>

<div class="note">
<strong>Take a backup first on a site that is already in use.</strong> An import adds to what is there. There is no undo.
</div>
'''),

    ('child', 'Customising safely', '''
<p>Use the child theme. Activate <code>sevi-child</code> and put your changes there; a theme update will never overwrite them.</p>

<p>The theme&rsquo;s real stylesheet is <code>assets/css/sevi.css</code>, compiled by Tailwind. The child theme&rsquo;s <code>style.css</code> is already set to load after it, so your rules win without needing <code>!important</code>.</p>

<h3>Filters worth knowing</h3>
<table>
<tr><th><code>sevi_settings</code></th><td>Add or change Customizer settings.</td></tr>
<tr><th><code>sevi_core_meta_fields</code></th><td>Add fields to any SEVI post type.</td></tr>
<tr><th><code>sevi_core_plans</code></th><td>Add a membership tier.</td></tr>
<tr><th><code>sevi_core_podcast_settings</code></th><td>Change what goes in the feed.</td></tr>
<tr><th><code>sevi_share_targets</code></th><td>Change where posts can be shared.</td></tr>
<tr><th><code>sevi_core_templates_path</code></th><td>Ship your own Elementor block library.</td></tr>
</table>
'''),

    ('trouble', 'If something is wrong', '''
<table>
<tr><th>Episodes give a 404</th><td>Settings &rarr; Permalinks &rarr; Save.</td></tr>
<tr><th>The podcast feed is a 404</th><td>Same. The feed is a rewrite rule.</td></tr>
<tr><th>The site looks unstyled</th><td><code>assets/css/sevi.css</code> is missing. Re-upload the theme; the file is built, not authored.</td></tr>
<tr><th>Icons are empty boxes</th><td>The Font Awesome webfonts did not upload. Check <code>assets/vendor/fontawesome/webfonts/</code>.</td></tr>
<tr><th>A SEVI widget does nothing in the editor</th><td>Clear the Elementor cache: Elementor &rarr; Tools &rarr; Regenerate CSS.</td></tr>
<tr><th>The demo import stops</th><td>Reload and start it again. It resumes where it left off.</td></tr>
<tr><th>WooCommerce warns about outdated templates</th><td>SEVI overrides {overrides} of them. Harmless, but tell us so we can update them.</td></tr>
<tr><th>Persian text falls back to another font</th><td>The Estedad WOFF2 did not upload. Check <code>assets/fonts/</code>.</td></tr>
</table>
'''),
]


CSS = '''
:root {
  --brand: #ef4b4b;
  --ink: #111827;
  --muted: #6b7280;
  --line: #e5e7eb;
  --soft: #f7f7f8;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font: 15px/1.75 -apple-system, "Segoe UI", system-ui, sans-serif;
  color: var(--ink);
  background: #fff;
}
.wrap { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
nav {
  border-inline-end: 1px solid var(--line);
  padding: 32px 20px;
  position: sticky;
  top: 0;
  align-self: start;
  max-height: 100vh;
  overflow: auto;
  background: var(--soft);
}
nav .brand {
  font-weight: 800;
  letter-spacing: .22em;
  font-size: 15px;
  margin-bottom: 4px;
}
nav .version { color: var(--muted); font-size: 12px; margin-bottom: 26px; }
nav a {
  display: block;
  padding: 7px 10px;
  border-radius: 8px;
  color: var(--ink);
  text-decoration: none;
  font-size: 13px;
}
nav a:hover { background: #fff; color: var(--brand); }
main { padding: 44px 48px 96px; max-width: 860px; }
h1 { font-size: 30px; margin: 0 0 8px; }
h2 {
  font-size: 21px;
  margin: 52px 0 14px;
  padding-top: 22px;
  border-top: 1px solid var(--line);
}
h2:first-of-type { border-top: 0; padding-top: 0; margin-top: 34px; }
h3 { font-size: 15px; margin: 26px 0 8px; }
p, li { color: #374151; }
code {
  background: var(--soft);
  border: 1px solid var(--line);
  border-radius: 5px;
  padding: 1px 5px;
  font-size: 13px;
  font-family: ui-monospace, Menlo, Consolas, monospace;
}
table { border-collapse: collapse; width: 100%; margin: 14px 0; font-size: 14px; }
th, td {
  border: 1px solid var(--line);
  padding: 9px 12px;
  text-align: start;
  vertical-align: top;
}
th { background: var(--soft); font-weight: 600; width: 34%; }
.note {
  border-inline-start: 3px solid var(--brand);
  background: #fef3f3;
  padding: 12px 16px;
  margin: 18px 0;
  border-radius: 0 8px 8px 0;
  font-size: 14px;
}
.lede { font-size: 16px; color: var(--muted); margin-bottom: 4px; }
@media (max-width: 860px) {
  .wrap { grid-template-columns: 1fr; }
  nav { position: static; max-height: none; border-inline-end: 0; border-bottom: 1px solid var(--line); }
  main { padding: 28px 20px 64px; }
}
'''


# Paths the documentation tells a buyer to look at. If one of these moves, the
# instruction becomes wrong, so the build says so rather than shipping it.
CLAIMED_PATHS = [
    'wordpress/sevi/assets/css/sevi.css',
    'wordpress/sevi/assets/fonts',
    'wordpress/sevi/assets/vendor/fontawesome/webfonts',
    'wordpress/sevi/languages/sevi.pot',
    'wordpress/sevi/wpml-config.xml',
    'wordpress/sevi-core/languages/sevi-core.pot',
    'wordpress/sevi-core/wpml-config.xml',
    'wordpress/sevi-child/style.css',
]


def verify(page):
    """Checks that everything the documentation names actually exists."""
    problems = []

    source = []

    for path in (glob.glob('wordpress/sevi/**/*.php', recursive=True)
                 + glob.glob('wordpress/sevi-core/**/*.php', recursive=True)):
        source.append(io.open(path, encoding='utf-8').read())

    source = '\n'.join(source)

    # Every sevi_ hook the docs promise has to be applied somewhere.
    for hook in sorted(set(re.findall(r'<code>(sevi_[a-z0-9_]+)</code>', page))):
        if ("'%s'" % hook) not in source:
            problems.append('documented hook %s is not in the source' % hook)

    for path in CLAIMED_PATHS:
        if not os.path.exists(path):
            problems.append('documented path %s does not exist' % path)

    return problems


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else OUT

    facts = {
        'widgets': count_widgets(),
        'blocks': len(block_list()),
        'strings': count_strings(),
        'pages': len(glob.glob('*.html')),
        'overrides': count_overrides(),
        'woo_widgets': count_woo_widgets(),
    }

    nav = '\n'.join(
        '<a href="#%s">%s</a>' % (slug, title) for slug, title, _ in SECTIONS
    )

    body = []

    for slug, title, html in SECTIONS:
        body.append('<h2 id="%s">%s</h2>' % (slug, title))
        body.append(html.format(**facts).strip())

    # The widget and block tables are generated, so they cannot go stale.
    body.append('<h2 id="widgets">Widget reference</h2>')
    body.append('<table><tr><th>Widget</th><td>What it does</td></tr>')
    for name, summary in widget_list():
        body.append('<tr><th>%s</th><td>%s</td></tr>' % (name, summary))
    body.append('</table>')

    body.append('<h2 id="blocks">Block reference</h2>')
    body.append('<table><tr><th>Block</th><td>Category</td></tr>')
    for name, category in block_list():
        body.append('<tr><th>%s</th><td>%s</td></tr>' % (name, category))
    body.append('</table>')

    page = '''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>SEVI documentation</title>
<style>%(css)s</style>
</head>
<body>
<div class="wrap">
<nav>
<p class="brand">SEVI</p>
<p class="version">Documentation &middot; 1.0.0</p>
%(nav)s
<a href="#widgets">Widget reference</a>
<a href="#blocks">Block reference</a>
</nav>
<main>
<h1>SEVI</h1>
<p class="lede">A right-to-left first blog, podcast and shop theme for WordPress.</p>
%(body)s
</main>
</div>
</body>
</html>
''' % {
        'css': CSS.strip(),
        'nav': nav,
        'body': '\n'.join(body),
    }

    problems = verify(page)

    if problems:
        print('  documentation does not match the source:')
        for problem in problems:
            print('     %s' % problem)
        sys.exit(1)

    os.makedirs(os.path.dirname(out), exist_ok=True)
    io.open(out, 'w', encoding='utf-8', newline='').write(page)

    print('  %s  (%d widgets, %d blocks, %d strings documented)'
          % (out, facts['widgets'], facts['blocks'], facts['strings']))


if __name__ == '__main__':
    main()
