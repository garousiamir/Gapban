# -*- coding: utf-8 -*-
import io, re, os, sys, subprocess, glob

PAGES = sorted(p for p in glob.glob('*.html'))
css = io.open('assets/css/output.css', encoding='utf-8').read()

SPECIAL = set(r""":[]()/.,#%!<>&$*+?=\@^~|{}"'""")
BS = chr(92)
def esc(c):
    return '.' + ''.join((BS + ch) if ch in SPECIAL else ch for ch in c)

CLASSLIKE = re.compile(r'^[!A-Za-z0-9:_\[\]/.#%()' + BS + r',-]+$')
SKIP_PREFIX = ('fa-',)
SKIP_EXACT = {'sr-only', 'group', 'hidden', 'peer', 'fa', 'fas', 'far', 'fab'}

TAG = re.compile(r'<(/?)([a-zA-Z][\w-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)(/?)>')
VOID = {'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}

BAD_DIRECTIVES = ('x-collapse', 'x-intersect', 'x-trap')

report = []
fail = 0

def note(page, kind, msg):
    global fail
    fail += 1
    report.append('  %-22s %-12s %s' % (page, kind, msg))

tmp = os.environ.get('TEMP', '.')

for page in PAGES:
    src = io.open(page, encoding='utf-8').read()

    # ---- 1. class tokens exist in compiled CSS ----
    tokens = set()
    for m in re.finditer(r'\sclass="([^"]*)"', src):
        tokens.update(m.group(1).split())
    for m in re.finditer(r':class="([^"]*)"', src):
        expr = m.group(1)
        expr = re.sub(r"(===|!==|==|!=)\s*'[^']*'", ' ', expr)
        expr = re.sub(r"'[^']*'\s*(===|!==|==|!=)", ' ', expr)
        expr = re.sub(r"\.\w+\('[^']*'\)", ' ', expr)
        # only take literals that look like class lists
        for lit in re.findall(r"'([^']*)'", expr):
            for t in lit.split():
                if CLASSLIKE.match(t):
                    tokens.add(t)
    for t in sorted(tokens):
        if t.startswith(SKIP_PREFIX) or t in SKIP_EXACT or t.endswith('-') or not CLASSLIKE.match(t):
            continue
        bare = t[1:] if t.startswith('!') else t
        if esc(t) not in css and esc(bare) not in css:
            note(page, 'css-miss', t)

    # ---- 2. inline JS parses ----
    js = chr(10).join(re.findall(r'<script>(.*?)</script>', src, re.S))
    if js.strip():
        f = os.path.join(tmp, 'chk_%s.mjs' % page.replace('.html', ''))
        io.open(f, 'w', encoding='utf-8').write(js)
        r = subprocess.run(['node', '--check', f], capture_output=True, text=True)
        if r.returncode:
            note(page, 'js-parse', (r.stderr or '').strip().splitlines()[0] if r.stderr else 'error')

    # ---- 3. tag balance (skip script/pre content) ----
    body = re.sub(r'<script.*?</script>', '', src, flags=re.S)
    body = re.sub(r'<pre.*?</pre>', '', body, flags=re.S)
    body = re.sub(r'<!--.*?-->', '', body, flags=re.S)
    stack = []
    for m in TAG.finditer(body):
        closing, name, attrs, selfclose = m.group(1), m.group(2).lower(), m.group(3), m.group(4)
        if name in VOID or selfclose == '/' or name == '!doctype':
            continue
        if closing:
            if not stack:
                note(page, 'tag', 'stray </%s>' % name); break
            if stack[-1] != name:
                note(page, 'tag', 'expected </%s> got </%s>' % (stack[-1], name)); break
            stack.pop()
        else:
            stack.append(name)
    else:
        if stack:
            note(page, 'tag', 'unclosed: ' + ', '.join(stack[-4:]))

    # ---- 4. every x-for has :key ----
    for m in re.finditer(r'<template([^>]*)>', src):
        a = m.group(1)
        if 'x-for' in a and ':key' not in a:
            note(page, 'x-for', 'missing :key -> ' + a.strip()[:60])

    # ---- 5. unavailable Alpine plugins ----
    for d in BAD_DIRECTIVES:
        if d in src:
            note(page, 'plugin', d + ' not in bundle')

    # ---- 6. local links resolve ----
    prose = re.sub(r'&lt;.*?&gt;', ' ', src, flags=re.S)
    for m in re.finditer(r'(?<![:\w-])(?:href|src)="([^"#:?]+)"', prose):
        target = m.group(1)
        if target.startswith(('http', '//', 'mailto', 'tel', 'data:')):
            continue
        if target.startswith('assets/images/'):
            continue  # intentionally empty, onerror fallback
        if not os.path.exists(target):
            note(page, 'link', target)

    # ---- 7. switcher integrity ----
    blk = re.search(r'demoLinks: \[(.*?)^        \],', src, re.M | re.S)
    if not blk:
        note(page, 'switcher', 'no demoLinks block')
    else:
        b = blk.group(1)
        n = b.count("href: '")
        if n != len(PAGES):
            note(page, 'switcher', '%d entries, expected %d' % (n, len(PAGES)))
        cur = b.count('current: true')
        if cur != 1:
            note(page, 'switcher', '%d current:true' % cur)
        elif ("href: '%s'" % page) not in b.split('current: true')[0].rsplit('{', 1)[-1] + '':
            pass
        for m in re.finditer(r"href: '([^']+)'", b):
            if not os.path.exists(m.group(1)):
                note(page, 'switcher', 'dead link ' + m.group(1))

    # ---- 8. onerror fallback on every image ----
    for m in re.finditer(r'<img([^>]*)>', src):
        if 'onerror' not in m.group(1):
            note(page, 'img', 'no onerror fallback')

    # ---- 9. alt attribute present ----
    for m in re.finditer(r'<img([^>]*)>', src):
        if 'alt=' not in m.group(1):
            note(page, 'img', 'missing alt')

# ---- 10. compiled CSS is tokenized ---------------------------------------
# `npm run watch` skips build/tokenize.mjs, so a watch-built stylesheet can ship
# with literal brand colours that theme options would not be able to retint.
for hexcode, token in (('ef4b4b', '--color-brand'), ('d33c3c', '--color-brand-dark')):
    stray = [l.strip() for l in css.splitlines()
             if hexcode in l.lower() and (token + ':') not in l]
    if stray:
        note('output.css', 'untokenized',
             '%d literal #%s outside a declaration -- run: npm run css' % (len(stray), hexcode))

# ---- 11. the WordPress theme -------------------------------------------
# Same idea as the page check above, against the theme's own stylesheet:
# a class a template emits that Tailwind never compiled renders unstyled.
import glob as _glob
glob = _glob

theme_css_path = 'wordpress/sevi/assets/css/sevi.css'
if os.path.exists(theme_css_path):
    theme_css = io.open(theme_css_path, encoding='utf-8').read()
    php_files = sorted(_glob.glob('wordpress/sevi/**/*.php', recursive=True))

    # Classes that are meant to carry meaning rather than style: WordPress admin
    # classes rendered by wp-admin's own stylesheet, hentry microformat hooks
    # that themes and readers key off, and sprintf placeholders.
    WP_SEMANTIC = {
        'notice', 'notice-error', 'notice-warning', 'notice-success', 'notice-info',
        'entry-date', 'published', 'updated', 'entry-title', 'entry-content',
        'screen-reader-text', 'comment-reply-link', 'says', '%s', '%1$s', '%2$s',
    }

    # WooCommerce keys its own JavaScript off these. They are a contract with
    # Woo, not part of this design system, so they are carried unstyled.
    WC_SEMANTIC = {
        'quantity', 'product', 'woocommerce', 'woocommerce-page', 'cart',
        'cart_item', 'shop_table', 'checkout', 'wc-block-components-notice-banner',
        'add_to_cart_button', 'ajax_add_to_cart', 'single_add_to_cart_button',
        'login', 'register', 'lost_reset_password',
    }

    for php in php_files:
        src = io.open(php, encoding='utf-8').read()

        # PHP can be interpolated into a class list; drop those spans before
        # tokenising so `<?php echo esc_attr( $x ); ?>` is not read as a class.
        for m in re.finditer(r'\sclass="([^"]*)"', src):
            value = re.sub(r'<\?php.*?\?>', ' ', m.group(1), flags=re.S)
            for t in value.split():
                # `sevi-` prefixed classes are behaviour hooks, never styled:
                # JS targets for the Customizer preview and body-state classes
                # that templates branch on. The design system defines no
                # `.sevi-` class selector -- the prefix appears there only on
                # keyframe names, which are a separate namespace -- so skipping
                # them here cannot mask a typo in a styled class.
                # A class attribute can be assembled in PHP with concatenation:
                #   class="' . esc_attr( sevi_wc_content_class() ) . '"
                # The fragments of that expression are not class names. Function
                # calls and bare punctuation are dropped; parentheses inside
                # square brackets are kept, because Tailwind arbitrary values
                # legitimately contain them, as in bg-[url(x)].
                if t and all( ch in '.()\'"' for ch in t ):
                    continue
                if ('(' in t or ')' in t) and '[' not in t:
                    continue
                # Anything Woo namespaces to itself is part of its contract, not
                # of this design system, and is carried unstyled.
                if t.startswith('woocommerce-') or t.startswith('wc-'):
                    continue
                if t.startswith('sevi-'):
                    continue
                if t.startswith(SKIP_PREFIX) or t in SKIP_EXACT or t in WP_SEMANTIC or t in WC_SEMANTIC or t.endswith('-'):
                    continue
                if not CLASSLIKE.match(t):
                    continue
                bare = t[1:] if t.startswith('!') else t
                if esc(t) not in theme_css and esc(bare) not in theme_css:
                    note(php.replace(chr(92), '/'), 'css-miss', t)

    # Templates the hierarchy relies on must actually exist.
    for required in ('index.php', 'style.css', 'functions.php', 'header.php',
                     'footer.php', 'screenshot.png'):
        if not os.path.exists('wordpress/sevi/' + required):
            note('wordpress/sevi', 'missing', required)

    print('php templates checked: %d' % len(php_files))

# ---- 12. the Elementor template library ---------------------------------
# A block referencing a widget that is not registered imports as an empty box
# with no explanation, so the two lists are compared rather than trusted.
import json as _json

template_dir = 'wordpress/sevi-core/templates/blocks'

if os.path.isdir(template_dir):
    registered = set()

    for widget_file in _glob.glob('wordpress/sevi-core/includes/elementor/widgets/*.php'):
        src = io.open(widget_file, encoding='utf-8').read()
        found = re.search(r"function get_name\(\)\s*\{\s*return\s*'([^']+)'", src)
        if found:
            registered.add(found.group(1))

    used = set()
    blocks = sorted(_glob.glob(template_dir + '/*.json'))

    for path in blocks:
        try:
            data = _json.loads(io.open(path, encoding='utf-8').read())
        except ValueError as error:
            note(path, 'json', str(error))
            continue

        def collect(nodes):
            for node in nodes:
                if node.get('elType') == 'widget' and 'widgetType' in node:
                    used.add(node['widgetType'])
                collect(node.get('elements', []))

        collect(data.get('content', []))

    for widget_name in sorted(used - registered):
        note('templates/blocks', 'unknown-widget', widget_name)

    print('template blocks checked: %d' % len(blocks))



# ---- 13. direction regression -------------------------------------------
# Phase 2 converted 660 physical utilities to logical ones so the theme works
# in both directions. Nothing stops a later edit reintroducing one, and the
# symptom -- a single element on the wrong side in English -- is easy to miss.
PHYSICAL = ('text-right', 'text-left', 'pl-', 'pr-', 'ml-', 'mr-', 'left-', 'right-')

direction_files = sorted(glob.glob('*.html'))
direction_files += sorted(glob.glob('wordpress/sevi/**/*.php', recursive=True))
direction_files += sorted(glob.glob('wordpress/sevi-core/**/*.php', recursive=True))

for path in direction_files:
    src = io.open(path, encoding='utf-8').read()

    lists = [m.group(1) for m in re.finditer(r'\sclass="([^"]*)"', src)]
    for m in re.finditer(r':class="([^"]*)"', src):
        lists += re.findall(r"'([^']*)'", m.group(1))

    for value in lists:
        # Horizontal centring is direction-neutral: both the inset and the
        # transform are physical, so they cancel out. Those pairs are allowed.
        if 'translate-x' in value:
            continue

        for token in value.split():
            base = token.rpartition(':')[2].lstrip('-')

            for physical in PHYSICAL:
                if base == physical or (physical.endswith('-') and base.startswith(physical)):
                    note(path.replace(chr(92), '/'), 'physical-dir', token)


# ---- 14. icon-only controls carry a label -------------------------------
# A button holding nothing but an icon is announced as "button" by a screen
# reader. These are the ones a keyboard user cannot identify.
ICON_ONLY = re.compile(
    r'<(button|a)\b([^>]*)>\s*(?:<i\b[^>]*>\s*</i>\s*)+</\1>',
    re.I | re.S
)

for path in direction_files:
    src = io.open(path, encoding='utf-8').read()

    for match in ICON_ONLY.finditer(src):
        attrs = match.group(2)

        if 'aria-label' in attrs or 'aria-labelledby' in attrs or 'screen-reader-text' in match.group(0):
            continue

        line = src.count(chr(10), 0, match.start()) + 1
        note(path.replace(chr(92), '/'), 'icon-no-label', 'line %d' % line)

print('pages checked: %d' % len(PAGES))
print('issues: %d' % fail)
if report:
    print()
    print(chr(10).join(report[:80]))
sys.exit(1 if fail else 0)
