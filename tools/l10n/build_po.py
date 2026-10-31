"""Generate gapban/languages/gapban-fa_IR.po from the POT and the fa_IR_*.py tables.

Usage: python3 tools/l10n/build_po.py   (then: node tools/i18n.mjs to compile .mo)
Fails if a placeholder (%s, %d, %1$s, %%) differs between source and translation.
"""
import importlib.util
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
table = {}
for part in sorted((ROOT / 'tools/l10n').glob('fa_IR_*.py')):
    spec = importlib.util.spec_from_file_location(part.stem, part)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    table.update(mod.T)

pot = (ROOT / 'gapban/languages/gapban.pot').read_text(encoding='utf-8')
msgids = [m for m in re.findall(r'^msgid "((?:[^"\\]|\\.)*)"$', pot, re.M) if m]


def unesc(s):
    return s.replace('\\"', '"').replace('\\n', '\n').replace('\\\\', '\\')


def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


PH = re.compile(r'%(?:\d+\$)?[sd]')
missing, bad = [], []
out = [
    '# Persian translation for Gapban.',
    '# This file is distributed under the GPL-2.0-or-later.',
    'msgid ""',
    'msgstr ""',
    '"Project-Id-Version: Gapban 1.0.0\\n"',
    '"Language: fa_IR\\n"',
    '"MIME-Version: 1.0\\n"',
    '"Content-Type: text/plain; charset=UTF-8\\n"',
    '"Content-Transfer-Encoding: 8bit\\n"',
    '"Plural-Forms: nplurals=1; plural=0;\\n"',
    '"X-Domain: gapban\\n"',
    '',
]
for raw in msgids:
    mid = unesc(raw)
    tr = table.get(mid)
    if tr is None:
        missing.append(mid)
        continue
    if sorted(PH.findall(mid)) != sorted(PH.findall(tr)):
        bad.append((mid, tr))
    out += [f'msgid "{esc(mid)}"', f'msgstr "{esc(tr)}"', '']
(ROOT / 'gapban/languages/gapban-fa_IR.po').write_text('\n'.join(out), encoding='utf-8')
print(f'{len(msgids) - len(missing)} translated, {len(missing)} missing, {len(bad)} placeholder mismatches')
for m in missing:
    print('MISSING:', m)
for m, t in bad:
    print('PLACEHOLDER:', m, '=>', t)
sys.exit(1 if bad else 0)
