#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 ZEDD
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Checks a release archive (or the folder it is made from) before it is signed.

    python3 tools/check-archive.py build/folderwatch-1.0.3.tar.gz
    python3 tools/check-archive.py build/appstore/folderwatch --app folderwatch

Nextcloud extracts the archive of an app and refuses it when the extraction folder holds more than one
folder ("Extracted app folderwatch has more than 1 folder"). The tar of macOS adds AppleDouble entries
("._folderwatch", "folderwatch/._README.md") for the extended attributes of files, and hides them when it
lists an archive: only Python's tarfile or a GNU tar on Linux shows what an archive really holds. So this
check reads the archive with tarfile, and fails when:

  - the root of the archive holds anything but the folder of the app;
  - a "._*" entry, a ".DS_Store" or a "__MACOSX" folder appears anywhere;
  - a path is absolute or climbs out of the archive;
  - appinfo/info.xml is missing from the app folder.
"""
import argparse
import os
import sys
import tarfile

JUNK_NAMES = {'.DS_Store', '__MACOSX', '.AppleDouble', '.Spotlight-V100', '.Trashes'}


def is_junk(path):
    parts = [p for p in path.split('/') if p not in ('', '.')]
    return any(p.startswith('._') or p in JUNK_NAMES for p in parts)


def archive_names(path):
    with tarfile.open(path, 'r:*') as archive:
        return archive.getnames()


def folder_names(path, app):
    names = [app]
    for current, directories, files in os.walk(path):
        for name in directories + files:
            relative = os.path.relpath(os.path.join(current, name), path)
            names.append(app + '/' + relative.replace(os.sep, '/'))
    return names


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('path', help='a .tar.gz archive, or the folder of the app before it is packed')
    parser.add_argument('--app', default='folderwatch', help='id of the app (the one folder the archive may hold)')
    args = parser.parse_args()

    if os.path.isdir(args.path):
        names = folder_names(args.path, args.app)
        kind = 'folder'
    else:
        names = archive_names(args.path)
        kind = 'archive'

    problems = []
    normalized = [n[2:] if n.startswith('./') else n for n in names]
    roots = sorted({n.split('/')[0] for n in normalized if n})
    if roots != [args.app]:
        problems.append('the root holds %r, expected only [%r]' % (roots, args.app))
    junk = [n for n in names if is_junk(n)]
    if junk:
        problems.append('%d AppleDouble / system entries, for example: %s' % (len(junk), ', '.join(junk[:5])))
    unsafe = [n for n in names if n.startswith('/') or '..' in n.split('/')]
    if unsafe:
        problems.append('unsafe paths: %s' % ', '.join(unsafe[:5]))
    if args.app + '/appinfo/info.xml' not in names:
        problems.append('%s/appinfo/info.xml is missing' % args.app)

    print('%s: %s' % (kind, args.path))
    print('root entries: %s' % roots)
    print('entries: %d, AppleDouble / .DS_Store: %d' % (len(names), len(junk)))
    if problems:
        for problem in problems:
            print('FAIL: %s' % problem)
        return 1
    print('OK')
    return 0


if __name__ == '__main__':
    sys.exit(main())
