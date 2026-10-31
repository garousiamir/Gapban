# -*- coding: utf-8 -*-
"""
Generates the theme screenshot WordPress shows on the Appearance screen.

This is a PLACEHOLDER. WordPress requires screenshot.png to exist at 1200x900
and Theme Check fails without it, but the real one has to be a shot of the
running demo -- which cannot be taken until the demo imagery question is
settled. It is generated rather than committed as a binary so it is obvious it
is not the final artwork.

Usage: python build/screenshot.py
"""
import os
import struct
import zlib

WIDTH = 1200
HEIGHT = 900
OUT = 'wordpress/sevi/screenshot.png'

BRAND = (0xEF, 0x4B, 0x4B)
INK = (0x11, 0x18, 0x27)


def lerp(a, b, t):
    return int(round(a + (b - a) * t))


def build_rows():
    """A diagonal brand-to-ink wash, so the placeholder still reads as SEVI."""
    rows = []
    for y in range(HEIGHT):
        row = bytearray()
        row.append(0)  # PNG filter type: none
        for x in range(WIDTH):
            t = (x / (WIDTH - 1) * 0.65) + (y / (HEIGHT - 1) * 0.35)
            row.append(lerp(BRAND[0], INK[0], t))
            row.append(lerp(BRAND[1], INK[1], t))
            row.append(lerp(BRAND[2], INK[2], t))
        rows.append(bytes(row))
    return b''.join(rows)


def chunk(tag, payload):
    body = tag + payload
    return struct.pack('>I', len(payload)) + body + struct.pack('>I', zlib.crc32(body) & 0xFFFFFFFF)


def main():
    header = struct.pack('>IIBBBBB', WIDTH, HEIGHT, 8, 2, 0, 0, 0)  # 8-bit truecolour RGB
    png = (
        b'\x89PNG\r\n\x1a\n'
        + chunk(b'IHDR', header)
        + chunk(b'IDAT', zlib.compress(build_rows(), 9))
        + chunk(b'IEND', b'')
    )

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, 'wb') as handle:
        handle.write(png)

    print('  %s  %dx%d  %d bytes  (PLACEHOLDER)' % (OUT, WIDTH, HEIGHT, os.path.getsize(OUT)))


if __name__ == '__main__':
    main()
