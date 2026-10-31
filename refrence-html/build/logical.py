# -*- coding: utf-8 -*-
"""
Rewrites physical Tailwind direction utilities as logical ones.

The template was drawn right-to-left with physical utilities (pl-, pr-, left-,
right-), which renders correctly in RTL and mirrors wrongly in LTR. Logical
utilities (ps-, pe-, start-, end-) follow the document direction, so one
stylesheet serves both.

Only class lists are touched:
  - class="..." attributes
  - the string literals inside :class="..." bindings
  - @apply directives in the stylesheet

Raw CSS properties are left alone: `float: left` inside .alignleft means the
physical left, because that is what the WordPress core class means.

Transforms are left alone too. translateX has no logical form -- the drawer
slide is handled by direction-aware rules in the stylesheet instead.

Usage: python build/logical.py [--dry-run]
"""
import glob
import io
import os
import re
import sys

# The template was authored right-to-left, which decides the whole mapping.
#
# In an RTL document the inline axis runs right-to-left, so:
#     inline-start == physical right
#     inline-end   == physical left
#
# A `pr-10` written against an RTL design is therefore padding at the START of
# the line, and its logical form is `ps-10` -- not `pe-10`. Getting this
# backwards mirrors the entire layout, so it is worth stating plainly.
#
# Longest first: text-right has to win over right.
MAP = [
    ('text-right', 'text-start'),
    ('text-left', 'text-end'),
    ('pr-', 'ps-'),
    ('pl-', 'pe-'),
    ('mr-', 'ms-'),
    ('ml-', 'me-'),
    ('right-', 'start-'),
    ('left-', 'end-'),
]

# Families that look directional but must not be rewritten.
KEEP = ('inset-x', 'translate-x', 'space-x', 'divide-x')


def convert_token(token):
    """Rewrites one class token, preserving its variants and negative sign."""
    if any(part in token for part in KEEP):
        return token, False

    head, _, base = token.rpartition(':')
    prefix = head + ':' if head else ''

    sign = ''
    if base.startswith('-'):
        sign = '-'
        base = base[1:]

    for physical, logical in MAP:
        if base == physical or (physical.endswith('-') and base.startswith(physical)):
            base = logical + base[len(physical):] if physical.endswith('-') else logical
            return prefix + sign + base, True

    return token, False


def convert_list(value):
    """Rewrites a whitespace-separated class list."""
    changed = 0
    out = []
    for token in value.split(' '):
        if not token.strip():
            out.append(token)
            continue
        new, hit = convert_token(token)
        changed += 1 if hit else 0
        out.append(new)
    return ' '.join(out), changed


def convert_source(src):
    """Rewrites every class list in one file's text."""
    total = [0]

    def in_class_attr(match):
        value, n = convert_list(match.group(2))
        total[0] += n
        return '%s"%s"' % (match.group(1), value)

    # class="..." and :class="..." -- for the bound form only the literals inside.
    src = re.sub(r'(\sclass=)"([^"]*)"', in_class_attr, src)

    def in_binding(match):
        body = match.group(2)

        def literal(lit):
            value, n = convert_list(lit.group(1))
            total[0] += n
            return "'%s'" % value

        body = re.sub(r"'([^']*)'", literal, body)
        return '%s"%s"' % (match.group(1), body)

    src = re.sub(r'((?::|x-bind:)class=)"([^"]*)"', in_binding, src)

    def in_apply(match):
        value, n = convert_list(match.group(1))
        total[0] += n
        return '@apply %s;' % value

    src = re.sub(r'@apply ([^;]+);', in_apply, src)

    return src, total[0]


def main():
    dry = '--dry-run' in sys.argv

    targets = sorted(glob.glob('*.html'))
    targets += sorted(glob.glob('wordpress/sevi/**/*.php', recursive=True))
    targets += sorted(glob.glob('wordpress/sevi-child/*.php'))
    targets += ['src/_shared.css']

    grand = 0
    touched = 0
    for path in targets:
        if not os.path.exists(path):
            continue
        src = io.open(path, encoding='utf-8').read()
        out, n = convert_source(src)
        if n:
            grand += n
            touched += 1
            if not dry:
                io.open(path, 'w', encoding='utf-8', newline='').write(out)

    print('  %s%d utilities rewritten across %d of %d files'
          % ('(dry run) ' if dry else '', grand, touched, len(targets)))

    # Anything left outside a class list is a manual decision, so say so.
    leftovers = []
    pattern = re.compile(r'(?<![\w-])(-?)(pl|pr|ml|mr|left|right)-[\w./\[\]%-]+')
    for path in targets:
        if not os.path.exists(path):
            continue
        src = io.open(path, encoding='utf-8').read()
        src = re.sub(r'\sclass="[^"]*"', ' ', src)
        src = re.sub(r'(?::|x-bind:)class="[^"]*"', ' ', src)
        src = re.sub(r'@apply [^;]+;', ' ', src)
        src = re.sub(r'x-transition[^=]*="[^"]*"', ' ', src)
        for match in pattern.finditer(src):
            token = match.group(0)
            if any(part in token for part in KEEP):
                continue
            leftovers.append((path, token))

    if leftovers:
        print('  %d physical token(s) left outside class lists -- review each:' % len(leftovers))
        seen = set()
        for path, token in leftovers:
            key = (path, token)
            if key in seen:
                continue
            seen.add(key)
            print('     %-46s %s' % (path, token))
    else:
        print('  no physical direction tokens remain outside class lists')


if __name__ == '__main__':
    main()
