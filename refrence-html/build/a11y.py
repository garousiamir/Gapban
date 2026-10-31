# -*- coding: utf-8 -*-
"""
Gives every icon-only control an accessible name.

A button or link holding nothing but an icon is announced as "button" or
"link" and nothing else. This finds them and derives a label from the icon.

Labels are Persian in the HTML preview, which is a Persian document, and
translatable in the PHP, which is not.

Usage: python build/a11y.py [--dry-run]
"""
import glob
import io
import re
import sys

# What each icon means, once. A label missing here is reported rather than
# guessed, because a wrong label is worse than the generic one.
LABELS = {
    'fa-instagram': 'اینستاگرام',
    'fa-telegram': 'تلگرام',
    'fa-x-twitter': 'ایکس',
    'fa-twitter': 'توییتر',
    'fa-linkedin-in': 'لینکدین',
    'fa-linkedin': 'لینکدین',
    'fa-whatsapp': 'واتساپ',
    'fa-facebook-f': 'فیسبوک',
    'fa-facebook': 'فیسبوک',
    'fa-youtube': 'یوتیوب',
    'fa-github': 'گیت‌هاب',
    'fa-spotify': 'اسپاتیفای',
    'fa-apple': 'اپل پادکست',
    'fa-google': 'گوگل پادکست',
    'fa-soundcloud': 'ساندکلاد',
    'fa-dribbble': 'دریبل',
    'fa-behance': 'بیهنس',
    'fa-pinterest': 'پینترست',
    'fa-rss': 'فید آر‌اس‌اس',

    'fa-xmark': 'بستن',
    'fa-bars': 'باز کردن منو',
    'fa-magnifying-glass': 'جستجو',
    'fa-play': 'پخش',
    'fa-pause': 'توقف',
    'fa-arrow-up': 'بازگشت به بالا',
    'fa-arrow-left': 'بعدی',
    'fa-arrow-right': 'قبلی',
    'fa-arrow-up-left': 'باز کردن',
    'fa-angle-left': 'بعدی',
    'fa-angle-right': 'قبلی',
    'fa-chevron-left': 'بعدی',
    'fa-chevron-right': 'قبلی',
    'fa-chevron-down': 'باز کردن',
    'fa-chevron-up': 'بستن',
    'fa-plus': 'افزودن',
    'fa-minus': 'کاهش',
    'fa-trash-can': 'حذف',
    'fa-bookmark': 'ذخیره',
    'fa-heart': 'افزودن به علاقه‌مندی‌ها',
    'fa-share-nodes': 'اشتراک‌گذاری',
    'fa-bag-shopping': 'سبد خرید',
    'fa-cart-shopping': 'سبد خرید',
    'fa-envelope': 'ایمیل',
    'fa-paper-plane': 'ارسال',
    'fa-phone': 'تماس',
    'fa-link': 'کپی نشانی',
    'fa-grip': 'نمای شبکه‌ای',
    'fa-list': 'نمای فهرستی',
    'fa-download': 'دانلود',
    'fa-expand': 'بزرگ‌نمایی',
    'fa-volume-high': 'قطع صدا',
    'fa-volume-xmark': 'وصل صدا',
    'fa-rotate-left': 'عقب',
    'fa-rotate-right': 'جلو',
    'fa-sliders': 'فیلترها',
    'fa-layer-group': 'دموها',
    'fa-circle-check': 'انتخاب‌شده',
    'fa-copy': 'رونوشت',
    'fa-ellipsis': 'گزینه‌های بیشتر',
    'fa-backward-step': 'قسمت قبلی',
    'fa-forward-step': 'قسمت بعدی',
    'fa-globe': 'وب‌سایت',
}

CONTROL = re.compile(
    r'<(button|a)\b([^>]*)>(\s*(?:<i\b[^>]*>\s*</i>\s*)+)</\1>',
    re.I | re.S
)

ICON = re.compile(r'\b(fa-[a-z0-9-]+)')

# Icon classes that are modifiers, not glyphs.
MODIFIERS = {
    'fa-solid', 'fa-regular', 'fa-brands', 'fa-light', 'fa-thin', 'fa-duotone',
    'fa-fw', 'fa-spin', 'fa-pulse', 'fa-beat', 'fa-bounce', 'fa-shake',
    'fa-border', 'fa-inverse', 'fa-stack', 'fa-flip', 'fa-rotate',
}


def label_for(inner):
    for name in ICON.findall(inner):
        if name in MODIFIERS or re.match(r'^fa-(xs|sm|lg|xl|2xl|\d+x)$', name):
            continue
        if name in LABELS:
            return LABELS[name], name
        return None, name
    return None, ''


def main():
    dry = '--dry-run' in sys.argv

    targets = sorted(glob.glob('*.html'))
    fixed = 0
    unknown = {}

    for path in targets:
        src = io.open(path, encoding='utf-8').read()
        changes = [0]

        def apply(match):
            attrs = match.group(2)

            if 'aria-label' in attrs or 'aria-labelledby' in attrs:
                return match.group(0)

            label, icon = label_for(match.group(3))

            if label is None:
                unknown[icon] = unknown.get(icon, 0) + 1
                return match.group(0)

            changes[0] += 1

            return '<%s%s aria-label="%s">%s</%s>' % (
                match.group(1), attrs.rstrip(), label, match.group(3), match.group(1)
            )

        out = CONTROL.sub(apply, src)

        if changes[0] and not dry:
            io.open(path, 'w', encoding='utf-8', newline='').write(out)

        fixed += changes[0]

    print('  %s%d icon-only controls given a label' % ('(dry run) ' if dry else '', fixed))

    if unknown:
        print('  %d icons have no label defined -- add them to LABELS:' % len(unknown))
        for icon, count in sorted(unknown.items(), key=lambda kv: -kv[1]):
            print('     %-28s %d use(s)' % (icon, count))


if __name__ == '__main__':
    main()
