# -*- coding: utf-8 -*-
"""
Converts the Estedad variable font to WOFF2 for shipping.

The TTF stays in the repo as the conversion source; only the WOFF2 is distributed.
Needs: pip install fonttools brotli

Usage: python build/font.py
"""
import os
import sys

try:
    from fontTools.ttLib import TTFont
except ImportError:
    sys.exit('fonttools is not installed -- run: pip install fonttools brotli')

SRC = 'assets/fonts/Estedad-VariableFont_wght.ttf'
DST = 'assets/fonts/Estedad-VariableFont_wght.woff2'

font = TTFont(SRC)
axes = [(a.axisTag, a.minValue, a.maxValue) for a in font['fvar'].axes] if 'fvar' in font else []
if not axes:
    sys.exit('FAIL  the source font has no variable axis -- the theme relies on wght 100-900')

font.flavor = 'woff2'
font.save(DST)

# Reopen the written file: a WOFF2 that drops its axis would silently flatten every weight.
check = TTFont(DST)
written = [a.axisTag for a in check['fvar'].axes] if 'fvar' in check else []
if 'wght' not in written:
    sys.exit('FAIL  the weight axis did not survive the conversion')

before, after = os.path.getsize(SRC), os.path.getsize(DST)
print('  %s' % DST)
print('  %d -> %d bytes (%.0f%% smaller), axes %s, %d glyphs'
      % (before, after, (1 - after / before) * 100, axes, len(check.getGlyphOrder())))
