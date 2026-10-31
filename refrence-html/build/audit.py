# -*- coding: utf-8 -*-
"""
Pre-submission audit.

Covers the mechanical part of what Theme Check and Plugin Check look for, plus
a few things neither of them checks but a reviewer will notice. It does not
replace either -- both need a running WordPress -- but it catches the findings
that would otherwise be discovered at submission.

Usage: python build/audit.py
"""
import glob
import io
import os
import re
import subprocess
import sys

THEME = 'wordpress/sevi'
PLUGIN = 'wordpress/sevi-core'

findings = []


def note(kind, where, message):
    findings.append((kind, where, message))


def php_files():
    paths = sorted(glob.glob(THEME + '/**/*.php', recursive=True))
    paths += sorted(glob.glob(PLUGIN + '/**/*.php', recursive=True))
    paths += sorted(glob.glob('wordpress/sevi-child/*.php'))

    return [p.replace(os.sep, '/') for p in paths]


# --------------------------------------------------------------- forbidden

# Things a reviewer rejects on sight, and why.
FORBIDDEN = {
    r'\beval\s*\(': 'eval() is not permitted',
    r'\bbase64_decode\s*\(': 'base64_decode() reads as obfuscation',
    r'\bcreate_function\s*\(': 'create_function() was removed in PHP 8',
    r'\bquery_posts\s*\(': 'query_posts() breaks the main query',
    r'\bini_set\s*\(': 'ini_set() changes the host configuration',
    r'\bset_time_limit\s*\(': 'set_time_limit() is not ours to call',
    r'\berror_reporting\s*\(': 'error_reporting() hides problems from the site owner',
    r'\bextract\s*\(': 'extract() makes the origin of a variable unknowable',
    r'\bshow_admin_bar\s*\(\s*false': 'a theme must not remove the admin bar',
    r'<\?(?!php|xml|=)': 'PHP short open tags are not portable',
    r'\bwp_deregister_script\s*\(\s*[\'"]jquery': 'deregistering jQuery breaks other plugins',
}

DEPRECATED = (
    'screen_icon', 'get_bloginfo_rss', 'wp_get_http', 'get_the_author_email',
    'get_settings', 'attribute_escape', 'clean_url', 'js_escape', 'wp_specialchars',
    'get_currentuserinfo', 'add_object_page', 'create_function',
)


def scan_forbidden():
    for path in php_files():
        text = io.open(path, encoding='utf-8').read()

        for pattern, why in FORBIDDEN.items():
            for match in re.finditer(pattern, text):
                line = text.count('\n', 0, match.start()) + 1
                note('forbidden', '%s:%d' % (path, line), why)

        for name in DEPRECATED:
            for match in re.finditer(r'(?<![\w$>])' + name + r'\s*\(', text):
                line = text.count('\n', 0, match.start()) + 1
                note('deprecated', '%s:%d' % (path, line), '%s() is deprecated' % name)


# ------------------------------------------------- suppressions must explain

def scan_suppressions():
    """Every phpcs:ignore has to say why, or it is just silence."""
    total = 0

    for path in php_files():
        text = io.open(path, encoding='utf-8').read()

        for match in re.finditer(r'phpcs:ignore\s+([^\n]*)', text):
            total += 1
            body = match.group(1)
            line = text.count('\n', 0, match.start()) + 1

            if '--' not in body:
                note('unexplained', '%s:%d' % (path, line), 'phpcs:ignore with no reason given')

    return total


# ---------------------------------------------------- superglobals and nonces

def scan_superglobals():
    """Raw request data has to be unslashed and sanitised on the same line or near it."""
    for path in php_files():
        text = io.open(path, encoding='utf-8').read()
        lines = text.split('\n')

        for number, line in enumerate(lines, start=1):
            if not re.search(r'\$_(GET|POST|REQUEST|COOKIE)\b', line):
                continue

            if 'phpcs:ignore' in line:
                continue

            # isset() and empty() only test presence, so they need nothing.
            stripped = re.sub(r'(isset|empty)\s*\([^)]*\)', '', line)

            if not re.search(r'\$_(GET|POST|REQUEST|COOKIE)\b', stripped):
                continue

            window = '\n'.join(lines[max(0, number - 2):number + 1])

            if not re.search(r'(sanitize_|wp_unslash|absint|intval|esc_)', window):
                note('raw-input', '%s:%d' % (path, number), 'request data used without unslashing or sanitising')


def scan_nonces():
    """A handler that writes on POST should be verifying something."""
    for path in php_files():
        text = io.open(path, encoding='utf-8').read()

        writes = re.search(r'\b(update_option|update_post_meta|update_user_meta|wp_insert_post|wp_delete_post)\s*\(', text)
        reads_post = re.search(r'\$_POST\b', text)

        if not writes or not reads_post:
            continue

        verifies = re.search(r'(wp_verify_nonce|check_admin_referer|check_ajax_referer|woocommerce_process_product_meta)', text)

        if not verifies:
            note('nonce', path, 'writes from $_POST with no nonce check in the file')


# ------------------------------------------------------------- theme basics

def scan_theme_requirements():
    header = io.open(THEME + '/style.css', encoding='utf-8').read()

    for field in ('Theme Name', 'Version', 'License', 'License URI', 'Text Domain', 'Requires at least', 'Requires PHP'):
        if field + ':' not in header:
            note('header', 'sevi/style.css', 'missing %s' % field)

    for required in ('index.php', 'functions.php', 'screenshot.png', 'readme.txt'):
        if not os.path.exists(THEME + '/' + required):
            note('missing', THEME, required)

    head = io.open(THEME + '/header.php', encoding='utf-8').read()
    footer = io.open(THEME + '/footer.php', encoding='utf-8').read()

    if 'wp_head()' not in head:
        note('missing', 'sevi/header.php', 'wp_head()')

    if 'wp_body_open()' not in head:
        note('missing', 'sevi/header.php', 'wp_body_open()')

    if 'wp_footer()' not in footer:
        note('missing', 'sevi/footer.php', 'wp_footer()')

    if re.search(r'<title\b', head, re.I):
        note('header', 'sevi/header.php', 'hardcoded <title>; the title-tag support handles this')


def scan_plugin_requirements():
    main = io.open(PLUGIN + '/sevi-core.php', encoding='utf-8').read()

    for field in ('Plugin Name', 'Version', 'License', 'Text Domain', 'Requires at least', 'Requires PHP'):
        if field + ':' not in main:
            note('header', 'sevi-core/sevi-core.php', 'missing %s' % field)

    for required in ('readme.txt',):
        if not os.path.exists(PLUGIN + '/' + required):
            note('missing', PLUGIN, required)


# -------------------------------------------------------------- php syntax

def scan_php_compatibility():
    """Confirms the whole tree parses under the PHP that is installed."""
    broken = 0

    for path in php_files():
        result = subprocess.run(
            ['php', '-l', path],
            capture_output=True,
            text=True,
        )

        if result.returncode != 0:
            broken += 1
            note('syntax', path, result.stdout.strip().split('\n')[0])

    return len(php_files()) - broken


# ------------------------------------------------------------ asset weight

def weigh():
    rows = []

    for label, path in (
        ('theme stylesheet', THEME + '/assets/css/sevi.css'),
        ('preview stylesheet', 'assets/css/output.css'),
        ('Alpine', THEME + '/assets/js/alpine.min.js'),
        ('Estedad woff2', THEME + '/assets/fonts/Estedad-VariableFont_wght.woff2'),
        ('Font Awesome css', THEME + '/assets/vendor/fontawesome/css/all.min.css'),
    ):
        if not os.path.exists(path):
            continue

        raw = os.path.getsize(path)

        import gzip
        packed = len(gzip.compress(io.open(path, 'rb').read(), 9))

        rows.append((label, raw, packed))

    return rows


def main():
    scan_forbidden()
    suppressions = scan_suppressions()
    scan_superglobals()
    scan_nonces()
    scan_theme_requirements()
    scan_plugin_requirements()
    parsed = scan_php_compatibility()

    print('  php files parsed:        %d' % parsed)
    print('  phpcs suppressions:      %d (each must state a reason)' % suppressions)
    print()
    print('  asset weight')

    for label, raw, packed in weigh():
        print('    %-22s %8.1f KB   %7.1f KB gzipped' % (label, raw / 1024, packed / 1024))

    print()

    if not findings:
        print('  audit clean')
        return 0

    print('  %d finding(s):' % len(findings))

    for kind, where, message in findings:
        print('    %-12s %-46s %s' % (kind, where, message))

    return 1


if __name__ == '__main__':
    sys.exit(main())
