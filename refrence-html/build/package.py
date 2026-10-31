# -*- coding: utf-8 -*-
"""
Assembles the package that gets uploaded to the marketplace.

Produces dist/sevi-theme-package/ and zips it. Everything in there is built
from the repository, so the package can never contain a file that the
repository does not have, and nothing is dragged in by hand at release time.

Three things this refuses to do quietly:

  * ship a zip whose contents do not match what a WordPress installer expects
  * ship a bundled third-party asset with no licence text beside it
  * ship while a version number disagrees with another version number

Anything still missing is listed at the end as a blocker and the exit code is
non-zero, but the package is still written -- a package you cannot submit is
still a package you can install and test.

Usage: python build/package.py
"""
import glob
import io
import os
import re
import shutil
import sys
import zipfile

DIST = 'dist'
STAGE = os.path.join(DIST, 'sevi-theme-package')

# Names and suffixes that must never reach a buyer, wherever they appear.
EXCLUDE_NAMES = {
    '.git', '.svn', '.idea', '.vscode', 'node_modules',
    '__pycache__', '.DS_Store', 'Thumbs.db', 'desktop.ini', '.gitkeep',
}

# Not by name: the theme's own assets/vendor/fontawesome has to ship, so
# "vendor" cannot be a blanket exclusion. Composer's tree is caught by these
# instead, wherever it turns up.
FORBIDDEN_PATHS = ('vendor/autoload.php', 'composer.json', 'composer.lock')
EXCLUDE_SUFFIXES = ('.map', '.zip', '.log', '.bak', '.orig', '.rej', '.pyc', '.swp')

# Every third-party file that ships, and where its licence comes from.
#
# "bundled" means the licence text travels inside the theme or plugin as well
# as in licensing/, which is what a reviewer opening the zip looks for.
THIRD_PARTY = [
    {
        'name': 'Font Awesome Free 6.5.2',
        'licence': 'CC BY 4.0 (icons), SIL OFL 1.1 (fonts), MIT (code)',
        'source': 'https://fontawesome.com/',
        'text': 'wordpress/sevi/assets/vendor/fontawesome/LICENSE.txt',
        'ships': 'wordpress/sevi/assets/vendor/fontawesome/',
    },
    {
        'name': 'Alpine.js',
        'licence': 'MIT',
        'source': 'https://alpinejs.dev/',
        'text': 'wordpress/licensing/alpinejs-LICENSE.txt',
        'ships': 'wordpress/sevi/assets/js/alpine.min.js',
    },
    {
        'name': 'Estedad (variable)',
        'licence': 'SIL Open Font License 1.1',
        'source': 'https://github.com/aminabedi68/Estedad',
        'text': 'wordpress/licensing/estedad-OFL.txt',
        'ships': 'wordpress/sevi/assets/fonts/Estedad-VariableFont_wght.woff2',
    },
    {
        'name': 'Demo images',
        'licence': 'see the manifest',
        'source': 'per image',
        'text': 'wordpress/licensing/images-MANIFEST.txt',
        'ships': 'demo-content/',
    },
]

# What a WordPress installer, and a reviewer, expect to find in each zip.
REQUIRED = {
    'sevi': ['style.css', 'index.php', 'functions.php', 'screenshot.png', 'readme.txt'],
    'sevi-child': ['style.css', 'screenshot.png'],
    'sevi-core': ['sevi-core.php', 'readme.txt'],
}


def read_version(path, pattern):
    """Reads one version header, or stops.

    An unreadable version used to come back as an empty string, and four empty
    strings agree with each other -- the comparison below would pass while the
    package was named sevi-.zip.
    """
    src = io.open(path, encoding='utf-8').read()
    found = re.search(pattern, src, re.M)

    if not found or not found.group(1).strip():
        print('  cannot read a version from %s' % path)
        sys.exit(1)

    return found.group(1).strip()


def keep(name, allow_zip=False):
    if name in EXCLUDE_NAMES:
        return False

    suffixes = tuple(s for s in EXCLUDE_SUFFIXES if not (allow_zip and '.zip' == s))

    return not name.lower().endswith(suffixes)


def walk(root, allow_zip=False):
    """Yields (absolute path, path relative to root) for everything shippable."""
    for base, dirs, files in os.walk(root):
        dirs[:] = sorted(d for d in dirs if keep(d))

        for name in sorted(files):
            if not keep(name, allow_zip):
                continue

            full = os.path.join(base, name)
            yield full, os.path.relpath(full, root).replace(os.sep, '/')


def make_zip(source, out, prefix, allow_zip=False):
    """Zips a directory under a single top-level folder, as WordPress requires."""
    os.makedirs(os.path.dirname(out), exist_ok=True)

    count = 0

    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for full, relative in walk(source, allow_zip):
            archive.write(full, '%s/%s' % (prefix, relative))
            count += 1

    return count, os.path.getsize(out)


def verify_zip(path, prefix, required):
    """Reads the finished zip back and checks it is installable."""
    problems = []

    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()

    if not names:
        return ['%s is empty' % path]

    for name in names:
        if not name.startswith(prefix + '/'):
            problems.append('%s: %s is outside the %s/ folder' % (path, name, prefix))
            break

    for name in names:
        parts = name.split('/')

        if any(part in EXCLUDE_NAMES for part in parts):
            problems.append('%s: %s should not be in the package' % (path, name))

        if '..' in parts or name.startswith('/'):
            problems.append('%s: %s has an unsafe path' % (path, name))

        if name.endswith(FORBIDDEN_PATHS):
            problems.append('%s: %s is a development file' % (path, name))

    have = set(names)

    for name in required:
        if '%s/%s' % (prefix, name) not in have:
            problems.append('%s: %s is missing' % (path, name))

    return problems


def copy_tree(source, target, only=None):
    for full, relative in walk(source):
        if only and not only(relative):
            continue

        out = os.path.join(target, relative.replace('/', os.sep))
        os.makedirs(os.path.dirname(out), exist_ok=True)
        shutil.copyfile(full, out)


def html_template(target):
    """The static template, with Font Awesome trimmed the way the theme trims it."""
    os.makedirs(target, exist_ok=True)

    pages = sorted(glob.glob('*.html'))

    for page in pages:
        shutil.copyfile(page, os.path.join(target, page))

    for relative in ['assets/css/output.css', 'assets/js/alpine.min.js']:
        out = os.path.join(target, relative.replace('/', os.sep))
        os.makedirs(os.path.dirname(out), exist_ok=True)
        shutil.copyfile(relative, out)

    copy_tree('assets/fonts', os.path.join(target, 'assets', 'fonts'),
              only=lambda r: r.endswith('.woff2'))

    copy_tree('assets/images', os.path.join(target, 'assets', 'images'))

    # The repository keeps the full 24 MB Font Awesome source. Only the part
    # the theme ships goes in here, at the path the pages already reference.
    fa = os.path.join(target, 'assets', 'vendor', 'fontawesome-free')
    copy_tree('wordpress/sevi/assets/vendor/fontawesome', fa)

    return len(pages)


def licensing(target):
    """Collects every licence text, and reports the ones that do not exist."""
    os.makedirs(target, exist_ok=True)

    missing = []
    rows = []

    for item in THIRD_PARTY:
        source = item['text']
        name = os.path.basename(source)

        if os.path.exists(source):
            shutil.copyfile(source, os.path.join(target, name))
            rows.append((item, name))
        else:
            missing.append(item)
            rows.append((item, 'MISSING'))

    lines = [
        'SEVI - third-party licences',
        '===========================',
        '',
        'Every file in the theme and the plugin is either written for SEVI and',
        'covered by its GPL-2.0-or-later licence, or listed below.',
        '',
    ]

    for item, name in rows:
        lines.extend([
            item['name'],
            '  Licence:  %s' % item['licence'],
            '  Source:   %s' % item['source'],
            '  Ships as: %s' % item['ships'],
            '  Text:     %s' % ('licensing/' + name if name != 'MISSING'
                                else 'NOT YET SUPPLIED - see the note below'),
            '',
        ])

    lines.extend([
        'Alpine.js publishes no LICENSE file in its npm package, only',
        '"license": "MIT" in the manifest. The text in licensing/ was taken from',
        'the project repository and the attribution banner is written onto the',
        'copy of alpine.min.js that ships.',
        '',
        'The images used in the demo content are listed one by one in',
        'licensing/images-MANIFEST.txt with their source and licence. No image is',
        'bundled inside the theme itself.',
        '',
    ])

    io.open(os.path.join(target, 'README.txt'), 'w', encoding='utf-8',
            newline='').write('\n'.join(lines))

    return missing


def main():
    problems = []
    blockers = []

    theme_version = read_version('wordpress/sevi/style.css', r'^\s*Version:\s*(.+)$')
    child_version = read_version('wordpress/sevi-child/style.css', r'^\s*Version:\s*(.+)$')
    core_version = read_version('wordpress/sevi-core/sevi-core.php', r'^\s*\*\s*Version:\s*(.+)$')
    logged = read_version('wordpress/changelog.txt', r'^=\s*([\d.]+)\s')

    for label, value in [('child theme', child_version), ('sevi-core', core_version),
                         ('changelog', logged)]:
        if value != theme_version:
            problems.append('version mismatch: theme is %s, %s is %s'
                            % (theme_version, label, value))

    if os.path.isdir(STAGE):
        shutil.rmtree(STAGE)

    os.makedirs(STAGE)

    print('  SEVI %s' % theme_version)
    print('')

    packages = [
        ('wordpress/sevi', os.path.join(STAGE, 'sevi.zip'), 'sevi'),
        ('wordpress/sevi-child', os.path.join(STAGE, 'sevi-child.zip'), 'sevi-child'),
        ('wordpress/sevi-core', os.path.join(STAGE, 'plugins', 'sevi-core.zip'), 'sevi-core'),
    ]

    for source, out, prefix in packages:
        count, size = make_zip(source, out, prefix)
        problems.extend(verify_zip(out, prefix, REQUIRED[prefix]))
        print('  %-34s %4d files  %8.1f KB'
              % (os.path.relpath(out, DIST).replace(os.sep, '/'), count, size / 1024))

    # Documentation is generated, not copied, so it cannot lag behind a release.
    sys.stdout.flush()

    if os.system('%s build/docs.py %s'
                 % (sys.executable, os.path.join(STAGE, 'documentation', 'index.html'))):
        problems.append('the documentation build failed')

    pages = html_template(os.path.join(STAGE, 'html-template'))
    print('  %-34s %4d pages' % ('html-template/', pages))

    shutil.copyfile('wordpress/changelog.txt', os.path.join(STAGE, 'changelog.txt'))

    demo = os.path.join(STAGE, 'demo-content')
    os.makedirs(demo, exist_ok=True)
    shutil.copyfile('wordpress/sevi-core/demos/README.md', os.path.join(demo, 'README.md'))

    payloads = [d for d in sorted(glob.glob('wordpress/sevi-core/demos/*')) if os.path.isdir(d)]

    for folder in payloads:
        copy_tree(folder, os.path.join(demo, os.path.basename(folder)))

    print('  %-34s %4d demos' % ('demo-content/', len(payloads)))

    missing_licences = licensing(os.path.join(STAGE, 'licensing'))

    for item in missing_licences:
        blockers.append('no licence text for %s (%s)' % (item['name'], item['text']))

    if not payloads:
        blockers.append('no demo payloads: demo-content/ has documentation but nothing to import')

    previews = sorted(glob.glob('wordpress/previews/*.png') + glob.glob('wordpress/previews/*.jpg'))

    if not previews:
        blockers.append('no preview images: wordpress/previews/ is empty '
                        '-- see the README there for the sizes')

    # The wrapper is what actually gets uploaded.
    upload = os.path.join(DIST, 'sevi-%s.zip' % theme_version)
    count, size = make_zip(STAGE, upload, 'sevi-theme-package', allow_zip=True)

    print('')
    print('  %-34s %4d files  %8.1f KB'
          % (os.path.relpath(upload, DIST).replace(os.sep, '/'), count, size / 1024))

    if problems:
        print('')
        print('  the package is not correct:')

        for problem in problems:
            print('     %s' % problem)

    if blockers:
        print('')
        print('  built, but not submittable yet:')

        for blocker in blockers:
            print('     %s' % blocker)

    if problems or blockers:
        sys.exit(1)

    print('')
    print('  ready to submit')


if __name__ == '__main__':
    main()
