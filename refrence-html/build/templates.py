# -*- coding: utf-8 -*-
"""
Generates the SEVI Elementor block library.

Each block is a section holding one or more SEVI widgets with sensible
settings. The JSON is written to wordpress/sevi-core/templates/blocks/.

WHAT THIS DOES NOT DO
Full-page templates are authored in Elementor and exported, because a page is a
composition decision rather than a mechanical one. Drop an exported .json into
templates/pages/ and it appears in the library alongside these -- the source
reads whatever is in the folder.

Usage: python build/templates.py
"""
import hashlib
import io
import json
import os

OUT = 'wordpress/sevi-core/templates/blocks'

# Elementor requires a unique 7-character id on every element. Deriving them
# from the block slug and a counter keeps regenerated files byte-identical,
# so a rebuild produces no spurious diff.
_counter = {}


def eid(slug):
    _counter[slug] = _counter.get(slug, 0) + 1
    seed = '%s-%d' % (slug, _counter[slug])
    return hashlib.sha1(seed.encode('utf-8')).hexdigest()[:7]


def widget(slug, widget_type, settings=None):
    return {
        'id': eid(slug),
        'elType': 'widget',
        'widgetType': widget_type,
        'settings': settings or {},
        'elements': [],
        'isInner': False,
    }


def column(slug, children, width=100):
    return {
        'id': eid(slug),
        'elType': 'column',
        'settings': {'_column_size': width, '_inline_size': None},
        'elements': children,
        'isInner': False,
    }


def section(slug, columns, settings=None):
    base = {
        'content_width': 'boxed',
        'padding': {'unit': 'px', 'top': '60', 'right': '0', 'bottom': '60', 'left': '0', 'isLinked': False},
    }
    base.update(settings or {})

    return {
        'id': eid(slug),
        'elType': 'section',
        'settings': base,
        'elements': columns,
        'isInner': False,
    }


def heading(slug, eyebrow, title, subtitle='', icon=''):
    return widget(slug, 'sevi-heading', {
        'eyebrow': eyebrow,
        'eyebrow_icon': icon,
        'title': title,
        'subtitle': subtitle,
        'style': 'section',
        'sevi_align': 'center' if not eyebrow else 'start',
    })


BLOCKS = []


def block(slug, title, category, widgets_fn, keywords=None):
    BLOCKS.append({
        'slug': slug,
        'title': title,
        'category': category,
        'widgets': widgets_fn,
        'keywords': keywords or [],
    })


# --------------------------------------------------------------- the blocks

block('hero-intro', 'Hero — intro and buttons', 'Hero', lambda s: [
    heading(s, 'SEVI', 'A place to write things worth reading',
            'Eight layouts, a podcast system and a shop, all in one theme.', 'fa-bolt'),
    widget(s, 'sevi-button', {'text': 'Read the blog', 'variant': 'primary', 'icon': 'fa-arrow-left'}),
], ['hero', 'intro', 'header'])

block('stats-row', 'Stats — four numbers', 'Stats', lambda s: [
    widget(s, 'sevi-stats', {
        'columns': '4',
        'stats': [
            {'_id': eid(s), 'icon': 'fa-rocket', 'tile': 'bg-brand/10 text-brand', 'value': '۲۴۰+', 'label': 'پروژه تحویل‌شده'},
            {'_id': eid(s), 'icon': 'fa-handshake', 'tile': 'bg-blue-50 text-blue-500', 'value': '۶۴', 'label': 'مشتری فعال'},
            {'_id': eid(s), 'icon': 'fa-heart', 'tile': 'bg-amber-50 text-amber-500', 'value': '۹۸٪', 'label': 'رضایت مشتری'},
            {'_id': eid(s), 'icon': 'fa-calendar', 'tile': 'bg-emerald-50 text-emerald-500', 'value': '۸', 'label': 'سال تجربه'},
        ],
    }),
], ['stats', 'numbers', 'counters'])

block('services-grid', 'Services — three cards', 'Services', lambda s: [
    heading(s, 'خدمات', 'کاری که انجام می‌دهیم', '', 'fa-pen-ruler'),
    widget(s, 'sevi-services', {
        'columns': '3',
        'services': [
            {'_id': eid(s), 'icon': 'fa-pen-ruler', 'tile': 'bg-brand/10 text-brand', 'title': 'طراحی رابط کاربری', 'text': 'از وایرفریم تا فایل آماده تحویل.'},
            {'_id': eid(s), 'icon': 'fa-code', 'tile': 'bg-blue-50 text-blue-500', 'title': 'پیاده‌سازی فرانت‌اند', 'text': 'کدنویسی تمیز، ریسپانسیو و راست‌چین.'},
            {'_id': eid(s), 'icon': 'fa-microphone-lines', 'tile': 'bg-amber-50 text-amber-500', 'title': 'راه‌اندازی پادکست', 'text': 'از هویت صوتی تا توزیع روی همه پلتفرم‌ها.'},
        ],
    }),
], ['services', 'features'])

block('process-steps', 'Process — numbered steps', 'Services', lambda s: [
    heading(s, 'فرایند', 'چطور کار می‌کنیم', '', 'fa-list-check'),
    widget(s, 'sevi-steps', {
        'steps': [
            {'_id': eid(s), 'title': 'می‌شنویم', 'text': 'پیش از آنکه چیزی کشیده شود، می‌فهمیم مسئله واقعاً چیست.'},
            {'_id': eid(s), 'title': 'طراحی می‌کنیم', 'text': 'از ساختار شروع می‌کنیم، نه از رنگ.'},
            {'_id': eid(s), 'title': 'می‌سازیم', 'text': 'کدی که خواندنش سخت نباشد و شش ماه بعد هم قابل نگهداری باشد.'},
        ],
    }),
], ['process', 'steps', 'how it works'])

block('testimonials', 'Testimonials — three quotes', 'Social proof', lambda s: [
    heading(s, 'نظرها', 'مشتری‌ها چه گفتند', '', 'fa-quote-right'),
    widget(s, 'sevi-testimonials', {
        'columns': '3',
        'quotes': [
            {'_id': eid(s), 'text': 'دقیقاً همان چیزی را تحویل دادند که خواسته بودیم، همان روزی که گفته بودند.', 'title': 'مریم فراهانی', 'label': 'مدیر محصول'},
            {'_id': eid(s), 'text': 'انتظار یک بازطراحی زیبا داشتیم و یک درس تایپوگرافی گرفتیم.', 'title': 'نیما شریفی', 'label': 'دیجی‌رید'},
            {'_id': eid(s), 'text': 'سرعت سایت بعد از تحویل واقعاً فرق کرده بود.', 'title': 'سارا کاظمی', 'label': 'آوند'},
        ],
    }),
], ['testimonials', 'reviews', 'quotes'])

block('team-grid', 'Team — four people', 'Social proof', lambda s: [
    heading(s, 'تیم', 'آدم‌هایی که پشت کار هستند', '', 'fa-users'),
    widget(s, 'sevi-team', {
        'columns': '4',
        'members': [
            {'_id': eid(s), 'title': 'مریم فراهانی', 'label': 'طراح'},
            {'_id': eid(s), 'title': 'نیما شریفی', 'label': 'توسعه‌دهنده'},
            {'_id': eid(s), 'title': 'سارا کاظمی', 'label': 'سردبیر'},
            {'_id': eid(s), 'title': 'رضا موسوی', 'label': 'تدوینگر'},
        ],
    }),
], ['team', 'people', 'about'])

block('logo-strip', 'Logos — client strip', 'Social proof', lambda s: [
    widget(s, 'sevi-logos', {
        'columns': '4',
        'logos': [
            {'_id': eid(s), 'title': 'دیجی‌رید'},
            {'_id': eid(s), 'title': 'آوند'},
            {'_id': eid(s), 'title': 'رهنما'},
            {'_id': eid(s), 'title': 'نگین'},
        ],
    }),
], ['logos', 'clients', 'brands'])

block('faq-list', 'FAQ — questions', 'Content', lambda s: [
    heading(s, 'سوالات', 'پرسش‌های پرتکرار', '', 'fa-circle-question'),
    widget(s, 'sevi-faq', {
        'first_open': 'yes',
        'faqs': [
            {'_id': eid(s), 'title': 'یک پروژه چقدر طول می‌کشد؟', 'text': 'بسته به دامنه کار، معمولاً بین چهار تا هشت هفته.'},
            {'_id': eid(s), 'title': 'آیا پشتیبانی هم دارید؟', 'text': 'بله، بسته به نسخه بین شش ماه تا یک سال.'},
            {'_id': eid(s), 'title': 'می‌توانم بعداً تغییرش دهم؟', 'text': 'همه‌چیز از پنل تنظیمات قابل تغییر است، بدون نیاز به کد.'},
        ],
    }),
], ['faq', 'questions', 'accordion'])

block('timeline', 'Timeline — milestones', 'Content', lambda s: [
    heading(s, 'مسیر', 'از کجا شروع شد', '', 'fa-timeline'),
    widget(s, 'sevi-timeline', {
        'events': [
            {'_id': eid(s), 'label': '۱۴۰۲', 'title': 'شروع کردیم', 'text': 'دو نفر و یک ایده.'},
            {'_id': eid(s), 'label': '۱۴۰۳', 'title': 'اولین صد پروژه', 'text': 'و اولین باری که مجبور شدیم نه بگوییم.'},
            {'_id': eid(s), 'label': '۱۴۰۵', 'title': 'امروز', 'text': 'تیمی که هنوز خودش کد می‌نویسد.'},
        ],
    }),
], ['timeline', 'history', 'milestones'])

block('pricing-three', 'Pricing — three plans', 'Commerce', lambda s: [
    heading(s, 'عضویت', 'یک پلن انتخاب کنید', '', 'fa-crown'),
    widget(s, 'sevi-pricing', {
        'columns': '3',
        'plans': [
            {'_id': eid(s), 'name': 'رایگان', 'price': '۰', 'period': 'همیشه', 'features': 'دسترسی به مقاله‌ها\nخبرنامه هفتگی', 'cta': 'شروع کنید', 'featured': ''},
            {'_id': eid(s), 'name': 'حرفه‌ای', 'price': '۸۹', 'period': 'ماهانه', 'features': 'همه‌چیز در پلن رایگان\nآرشیو کامل\nبدون تبلیغات', 'cta': 'انتخاب پلن', 'featured': 'yes'},
            {'_id': eid(s), 'name': 'سازمانی', 'price': '۲۴۹', 'period': 'ماهانه', 'features': 'همه‌چیز در حرفه‌ای\nپنج کاربر\nپشتیبانی اولویت‌دار', 'cta': 'تماس بگیرید', 'featured': ''},
        ],
    }),
], ['pricing', 'plans', 'membership'])

block('estimator', 'Estimator — project cost', 'Commerce', lambda s: [
    heading(s, 'برآورد', 'هزینه تقریبی پروژه', '', 'fa-calculator'),
    widget(s, 'sevi-estimator', {
        'unit': 'میلیون تومان',
        'spread': 1.45,
        'services': [
            {'_id': eid(s), 'name': 'طراحی رابط کاربری', 'from': 25, 'preselected': 'yes'},
            {'_id': eid(s), 'name': 'دیزاین‌سیستم', 'from': 40, 'preselected': ''},
            {'_id': eid(s), 'name': 'پیاده‌سازی فرانت‌اند', 'from': 35, 'preselected': ''},
            {'_id': eid(s), 'name': 'راه‌اندازی پادکست', 'from': 22, 'preselected': ''},
        ],
    }),
], ['estimate', 'calculator', 'quote'])

block('cta-banner', 'CTA — closing banner', 'Calls to action', lambda s: [
    widget(s, 'sevi-cta', {
        'title': 'پروژه بعدی می‌تواند مال شما باشد',
        'text': 'یک جلسه مشاوره رایگان رزرو کنید، یا اول یک عدد تقریبی بگیرید.',
        'gradient': 'gradient-a',
        'button_text': 'شروع گفتگو',
    }),
], ['cta', 'banner', 'contact'])

block('posts-grid', 'Posts — three column grid', 'Blog', lambda s: [
    heading(s, 'وبلاگ', 'تازه‌ترین نوشته‌ها', '', 'fa-feather'),
    widget(s, 'sevi-posts', {
        'variant': 'grid', 'columns': '3', 'count': 6, 'post_type': 'post',
        'show_image': 'yes', 'show_meta': 'yes', 'show_excerpt': 'yes', 'show_terms': 'yes',
    }),
], ['posts', 'blog', 'grid'])

block('posts-featured', 'Posts — lead story and list', 'Blog', lambda s: [
    widget(s, 'sevi-featured', {'post_type': 'post', 'count': 1, 'orderby': 'date'}),
    widget(s, 'sevi-posts', {
        'variant': 'compact', 'count': 4, 'post_type': 'post', 'offset': 1,
        'show_image': 'yes', 'show_meta': 'yes', 'show_excerpt': '', 'show_terms': '',
    }),
], ['posts', 'featured', 'magazine'])

block('episodes-grid', 'Episodes — grid with toggle', 'Podcast', lambda s: [
    heading(s, 'پادکست', 'تازه‌ترین قسمت‌ها', '', 'fa-microphone-lines'),
    widget(s, 'sevi-episodes', {'count': 6, 'allow_toggle': 'yes'}),
], ['podcast', 'episodes', 'audio'])

block('episodes-popup', 'Episodes — popup grid', 'Podcast', lambda s: [
    heading(s, 'پادکست', 'یک قسمت را باز کنید', '', 'fa-window-restore'),
    widget(s, 'sevi-episode-popup', {'count': 6, 'post_type': 'sevi_episode'}),
], ['podcast', 'popup', 'modal'])

block('subscribe-row', 'Podcast — subscribe row', 'Podcast', lambda s: [
    widget(s, 'sevi-subscribe', {'label': 'شنیدن در'}),
], ['podcast', 'subscribe', 'rss'])

block('products-grid', 'Products — three column grid', 'Commerce', lambda s: [
    heading(s, 'فروشگاه', 'تازه‌ترین محصولات', '', 'fa-store'),
    widget(s, 'sevi-products', {'variant': 'grid', 'columns': '3', 'count': 6, 'source': 'recent'}),
], ['products', 'shop', 'woocommerce'])

block('plan-cards', 'Plans — buyable membership cards', 'Commerce', lambda s: [
    heading(s, 'عضویت', 'پلن خود را انتخاب کنید', '', 'fa-crown'),
    widget(s, 'sevi-plans', {'cta': 'انتخاب این پلن'}),
], ['plans', 'membership', 'subscribe'])

block('portfolio-grid', 'Portfolio — project grid', 'Content', lambda s: [
    heading(s, 'نمونه‌کارها', 'کارهایی که ساخته‌ایم', '', 'fa-briefcase'),
    widget(s, 'sevi-portfolio', {'count': 6, 'post_type': 'sevi_project'}),
], ['portfolio', 'projects', 'work'])

block('gallery-lightbox', 'Gallery — lightbox grid', 'Content', lambda s: [
    heading(s, 'گالری', 'تصاویر', '', 'fa-images'),
    widget(s, 'sevi-gallery', {'columns': '3', 'images': []}),
], ['gallery', 'images', 'lightbox'])


def main():
    os.makedirs(OUT, exist_ok=True)

    for spec in BLOCKS:
        slug = spec['slug']
        _counter.clear()

        content = [section(slug, [column(slug, spec['widgets'](slug))])]

        document = {
            'version': '0.4',
            'title': spec['title'],
            'type': 'section',
            'subtype': spec['category'],
            'categories': [spec['category']],
            'keywords': spec['keywords'],
            'content': content,
        }

        path = os.path.join(OUT, slug + '.json')
        io.open(path, 'w', encoding='utf-8', newline='').write(
            json.dumps(document, ensure_ascii=False, indent=1)
        )

    print('  %d blocks written to %s' % (len(BLOCKS), OUT))

    # Every element must carry a unique id, or Elementor silently drops one.
    seen = {}
    clashes = 0

    for spec in BLOCKS:
        path = os.path.join(OUT, spec['slug'] + '.json')
        data = json.loads(io.open(path, encoding='utf-8').read())

        def walk(nodes):
            global clashes
            for node in nodes:
                key = node['id']
                if key in seen and seen[key] != spec['slug']:
                    clashes += 1
                seen[key] = spec['slug']
                walk(node.get('elements', []))

        walk(data['content'])

    print('  %d element ids, %d cross-block clashes' % (len(seen), clashes))


if __name__ == '__main__':
    main()
