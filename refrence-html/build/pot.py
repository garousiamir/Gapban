# -*- coding: utf-8 -*-
"""
Builds the .pot files for the theme and the plugin.

WP-CLI's `wp i18n make-pot` is the usual tool. It is not available here, so this
does the same job: it scans the PHP for translation calls, keeps the translator
comments attached to them, and writes a POT per text domain.

Usage: python build/pot.py
"""
import glob
import io
import os
import re

PACKAGES = [
    {
        'domain': 'sevi',
        'roots': ['wordpress/sevi'],
        'out': 'wordpress/sevi/languages/sevi.pot',
        'name': 'SEVI',
    },
    {
        'domain': 'sevi-core',
        'roots': ['wordpress/sevi-core'],
        'out': 'wordpress/sevi-core/languages/sevi-core.pot',
        'name': 'SEVI Core',
    },
]

# Which argument holds what, per function. singular, plural, context.
FUNCTIONS = {
    '__': (0, None, None),
    '_e': (0, None, None),
    'esc_html__': (0, None, None),
    'esc_html_e': (0, None, None),
    'esc_attr__': (0, None, None),
    'esc_attr_e': (0, None, None),
    '_x': (0, None, 1),
    'esc_html_x': (0, None, 1),
    'esc_attr_x': (0, None, 1),
    '_n': (0, 1, None),
    '_nx': (0, 1, 3),
    '_n_noop': (0, 1, None),
    '_nx_noop': (0, 1, 2),
}

CALL = re.compile(r'(?<![\w$>])(' + '|'.join(sorted(FUNCTIONS, key=len, reverse=True)) + r')\s*\(')
TRANSLATORS = re.compile(r'/\*\s*(translators:.*?)\*/', re.S | re.I)


def read_string(text, start):
    """Reads one PHP single- or double-quoted string, returning it and where it ended."""
    quote = text[start]

    if quote not in ("'", '"'):
        return None, start

    out = []
    i = start + 1

    while i < len(text):
        ch = text[i]

        if ch == '\\' and i + 1 < len(text):
            nxt = text[i + 1]
            # In single quotes PHP only escapes \' and \\; everything else is literal.
            if quote == "'":
                out.append(nxt if nxt in ("'", '\\') else ch + nxt)
            else:
                out.append({'n': '\n', 't': '\t', 'r': '\r'}.get(nxt, nxt))
            i += 2
            continue

        if ch == quote:
            return ''.join(out), i + 1

        out.append(ch)
        i += 1

    return None, start


def read_args(text, open_paren):
    """Reads the literal arguments of a call, stopping at its closing bracket."""
    args = []
    i = open_paren + 1
    depth = 1
    current = None

    while i < len(text) and depth > 0:
        ch = text[i]

        if ch in ("'", '"'):
            value, end = read_string(text, i)

            if value is None:
                return args, i

            if current is None:
                current = value
            else:
                # PHP concatenation inside an argument.
                current += value

            i = end
            continue

        if ch == '(':
            depth += 1
        elif ch == ')':
            depth -= 1

            if 0 == depth:
                args.append(current)
                break
        elif ch == ',' and 1 == depth:
            args.append(current)
            current = None
        elif ch not in ' \t\r\n.':
            # A variable or function call: the argument is not a literal.
            if not (ch == '_' or ch.isalnum()):
                pass
            current = current if current is not None else None

        i += 1

    return args, i


def scan(path, domain):
    """Returns the translatable entries in one file."""
    text = io.open(path, encoding='utf-8').read()
    entries = []

    # Map each translators comment to the character it ends at.
    comments = [(m.end(), ' '.join(m.group(1).split())) for m in TRANSLATORS.finditer(text)]

    for match in CALL.finditer(text):
        name = match.group(1)
        args, _ = read_args(text, match.end() - 1)

        singular_at, plural_at, context_at = FUNCTIONS[name]

        if len(args) <= singular_at or args[singular_at] is None:
            continue

        # The text domain is the last literal argument for every function here.
        if domain not in [a for a in args if a is not None]:
            continue

        line = text.count('\n', 0, match.start()) + 1

        comment = ''
        for end, body in comments:
            # A translators comment applies to the next call after it.
            if end < match.start() and match.start() - end < 400:
                comment = body

        entries.append({
            'singular': args[singular_at],
            'plural': args[plural_at] if plural_at is not None and len(args) > plural_at else None,
            'context': args[context_at] if context_at is not None and len(args) > context_at else None,
            'comment': comment,
            'reference': '%s:%d' % (path.replace(os.sep, '/'), line),
        })

    return entries


def escape(value):
    return (value.replace('\\', '\\\\')
                 .replace('"', '\\"')
                 .replace('\n', '\\n')
                 .replace('\t', '\\t'))


def build(package):
    entries = {}

    for root in package['roots']:
        for path in sorted(glob.glob(root + '/**/*.php', recursive=True)):
            if '/vendor/' in path.replace(os.sep, '/'):
                continue

            # References are written relative to the package, which is what a
            # translator sees when they open the file in Poedit.
            relative = path.replace(os.sep, '/')
            if relative.startswith(root + '/'):
                relative = relative[len(root) + 1:]

            for entry in scan(path, package['domain']):
                entry['reference'] = '%s:%s' % (relative, entry['reference'].rsplit(':', 1)[1])
                key = (entry['context'], entry['singular'], entry['plural'])

                if key not in entries:
                    entries[key] = {
                        'context': entry['context'],
                        'singular': entry['singular'],
                        'plural': entry['plural'],
                        'comment': entry['comment'],
                        'references': [],
                    }

                entries[key]['references'].append(entry['reference'])

                if entry['comment'] and not entries[key]['comment']:
                    entries[key]['comment'] = entry['comment']

    lines = [
        '# Copyright (C) %s' % package['name'],
        '# This file is distributed under the GPL-2.0-or-later licence.',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: %s\\n"' % package['name'],
        '"Report-Msgid-Bugs-To: https://sevitheme.com/\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Language-Team: SEVI\\n"',
        '"X-Domain: %s\\n"' % package['domain'],
        '',
    ]

    for key in sorted(entries, key=lambda k: (k[1] or '', k[0] or '')):
        entry = entries[key]

        if entry['comment']:
            lines.append('#. %s' % entry['comment'])

        for reference in sorted(set(entry['references'])):
            lines.append('#: %s' % reference)

        if entry['context']:
            lines.append('msgctxt "%s"' % escape(entry['context']))

        lines.append('msgid "%s"' % escape(entry['singular']))

        if entry['plural']:
            lines.append('msgid_plural "%s"' % escape(entry['plural']))
            lines.append('msgstr[0] ""')
            lines.append('msgstr[1] ""')
        else:
            lines.append('msgstr ""')

        lines.append('')

    os.makedirs(os.path.dirname(package['out']), exist_ok=True)
    io.open(package['out'], 'w', encoding='utf-8', newline='\n').write('\n'.join(lines))

    return len(entries)


def main():
    total = 0

    for package in PACKAGES:
        count = build(package)
        total += count
        print('  %-44s %4d strings' % (package['out'], count))

    print('  %d translatable strings in total' % total)


if __name__ == '__main__':
    main()
