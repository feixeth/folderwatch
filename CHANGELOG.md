<!--
  - SPDX-FileCopyrightText: 2026 ZEDD
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

All notable changes to Folder Watch are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## 1.0.4 - 2026-11-03

### Fixed

- A watched folder no longer shows two eye icons in the Files list. The icon-only indicator next to the sharing
  button is gone; the labelled "Watched folder" action stays, visible without hovering, and opens the settings.
  On a narrow screen, where inline actions are hidden, it stays reachable from the "…" menu under the same name.

### Changed

- Updated the Files screenshots of the README and the App Store listing.

## 1.0.3 - 2026-11-03

Fix release archive packaging. No change to the code of the app.

### Fixed

- The release archive of 1.0.2 held `._folderwatch` next to `folderwatch` (AppleDouble entries added by the `tar` of
  macOS, which hides them when it lists an archive), so Nextcloud refused to install it from the App Store with
  "Extracted app folderwatch has more than 1 folder". The archive is now packed by GNU tar in a Linux container, with
  no extended attributes, `._*` or `.DS_Store`, in a fixed order and with root as owner.

### Added

- `make appstore`, `appstore-sign` and `appstore-signature` check the folder and the archive with Python's `tarfile`
  before any signature (`tools/check-archive.py`): the root must hold only `folderwatch/`, with no `._*`,
  `.DS_Store` or `__MACOSX` anywhere.
- `make test-install` installs the archive in a clean Nextcloud through `OC\Installer`, as the App Store does
  (certificate chain, signature of release, download, extraction), then runs `integrity:check-app`.

## 1.0.2 - 2026-11-02

### Fixed

- French: the frequency selector cut its longest label ("Récapitulatif tout…les heures"). The label is now
  "Récapitulatif horaire", and a unit test keeps the frequency labels short enough for the selector, which is only as
  wide as a column of the settings page. Checked in English and French from 320 px to 1280 px wide.

### Changed

- App Store listing improvements: new screenshots (personal settings with four watched folders as the first one,
  the "Watch this folder" action, a grouped notification, the e-mail, the administration page), declared with public
  URLs; a longer description with features, how it works and requirements, in English and French; links to the
  README, the issues and this changelog; documentation links for users and administrators, and the project website.
- `README.md` is now the English version, with the English screenshots; the French version is `README.fr.md`, with
  the French screenshots. Each links to the other.
- Screenshots in `screenshots/en` and `screenshots/fr` are regenerated from a new demo scene
  (`tools/seed-demo.sh`, `tools/screenshots.mjs`) with neutral data and no local address visible.

## 1.0.1 - 2026-10-27

Initial release.

### Added

- Watch folders from the Files app ("Watch this folder" / "Stop watching", with an eye on watched folders) or
  from the personal settings, with a folder picker.
- Per folder: include sub-folders or not, events (added, modified, removed, moved in), channel (Nextcloud
  notification, e-mail or both), frequency (as soon as possible, hourly digest, daily digest at a chosen hour).
- "Ignore my own actions" option (on by default).
- One notification and one e-mail per folder and per batch, with a detail line for mixed batches. E-mails use the
  native Nextcloud template, are written in the language of the recipient, list at most 50 items, and never carry
  file contents.
- Matching by file ID, so watches follow renames and work for owners, share recipients, owners watching above a
  share point, and team folders. Ignored names (`*.part`, `.~lock.*`, `~$*`, `.DS_Store`, `Thumbs.db` by
  default) and internal paths (versions, trash, upload chunks, app data) never raise an alert.
- Access is checked again when an alert is sent: a watch whose folder is not reachable any more is suspended
  and resumes by itself when the folder comes back.
- Links to files and to the watched folder have the form `/index.php/f/<file id>`, which does not depend on the
  reader's paths: the link opens the file even when the recipient renamed the shared folder on their side. They are
  worked out when the event happens, in the web request that caused it, and stored with the queued event. The host of
  the request is used only when it can be trusted: the host of `overwrite.cli.url` when that is usable, otherwise the
  first of `trusted_domains`. A request that came in through any other name (internal address, former or secondary
  domain) gets its link rebuilt on `overwrite.cli.url`. For events without a web request (command line, cron, scan)
  the address is `overwrite.cli.url`. With no trustworthy address at all the e-mail goes out without a link and a
  warning is logged at most once a day. Notifications make their links when they are shown.
- Administration page: allow e-mail, folders per user (default 50), alerts per user and hour (default 20,
  postponed rather than dropped), ignored file names, and the same messages as the setup checks below.
- Three setup checks in *Administration → Overview*: background jobs not run by cron, `overwrite.cli.url` missing or
  localhost, e-mail server not configured.
- Personal settings: the e-mail channel is greyed out with the reason (switched off, server not configured, no
  address on the account), and a banner warns that alerts may be late when background jobs do not run by cron.
  Nextcloud without a configured e-mail server does not attempt e-mails: only notifications are sent.
- `occ folderwatch:list`, `folderwatch:add`, `folderwatch:remove` and `folderwatch:flush`.
- OCS API with an OpenAPI specification.
- Daily clean-up: trash, unshared and deleted folders, deleted accounts, orphaned alerts.
- French and English interface.
- README: installation from the App Store first, then manual installation as the PHP user of the instance
  (including hosting panels such as Virtualmin or ISPConfig).

### Known limitations

- Files added outside Nextcloud on an external storage raise no alert until a `files:scan` has seen them.
- A watch does not cross a mount point (a shared folder, team folder or external storage inside the watched
  folder).
- Background jobs must run in cron mode for reliable alerts.
