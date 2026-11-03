#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ZEDD
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Installs the release archive in a clean Nextcloud the way the App Store does (OC\Installer, extraction included,
# see tools/install-from-archive.php), then runs integrity:check-app on what was installed.
#
#   tools/test-install.sh build/folderwatch-1.0.3.tar.gz ~/.nextcloud/certificates/folderwatch.key \
#       ~/.nextcloud/certificates/folderwatch.crt [35]
#
# The private key stays on this machine: it only signs the archive here, as the App Store would have it signed
# (the signature of release), and the container receives the public certificate.
# Exit code 0 only when the archive installs and its code signature is valid.

set -u
ARCHIVE=${1:?usage: test-install.sh <archive> <private key> <certificate> [nextcloud version]}
KEY=${2:?}
CRT=${3:?}
NC_VERSION=${4:-35}
APP_ID=folderwatch
HERE=$(cd "$(dirname "$0")" && pwd)
NAME=fw-install-$$
TMP=$(mktemp -d)

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1; rm -rf "$TMP"; }
trap cleanup EXIT

step() { printf '\n== %s\n' "$1"; }
run() { docker exec -u www-data -w /var/www/html "$NAME" "$@"; }

step "signature of release (made here, with the private key)"
openssl dgst -sha512 -sign "$KEY" "$ARCHIVE" | openssl base64 > "$TMP/release.sig" || { echo "cannot sign the archive"; exit 2; }
echo "ok"

step "clean Nextcloud $NC_VERSION"
docker run -d --name "$NAME" "nextcloud:$NC_VERSION-apache" >/dev/null || exit 2
# the image copies Nextcloud into place on its first start: wait for it, then install with SQLite
for _ in $(seq 1 90); do
	run php occ status 2>/dev/null | grep -q 'versionstring' && break
	sleep 2
done
run php occ maintenance:install --database=sqlite --admin-user=admin --admin-pass=admin >/dev/null 2>&1
run php occ status 2>/dev/null | grep -q 'installed: true' || { echo "Nextcloud did not install"; exit 2; }
run php occ status | grep -E 'versionstring'
run php occ config:system:set allow_local_remote_servers --value=true --type=boolean >/dev/null

step "the archive is served over HTTP, as a download from the App Store would be"
docker exec "$NAME" mkdir -p /tmp/serve /tmp/harness
docker cp "$ARCHIVE" "$NAME:/tmp/serve/app.tar.gz"
docker cp "$CRT" "$NAME:/tmp/harness/app.crt"
docker cp "$TMP/release.sig" "$NAME:/tmp/harness/release.sig"
docker cp "$HERE/install-from-archive.php" "$NAME:/tmp/harness/install-from-archive.php"
docker exec -u 0 "$NAME" chmod -R a+rX /tmp/serve /tmp/harness
docker exec -d -u www-data "$NAME" php -S 127.0.0.1:8099 -t /tmp/serve
sleep 2

step "install through OC\\Installer"
run php /tmp/harness/install-from-archive.php "$APP_ID" /tmp/harness/app.crt /tmp/harness/release.sig http://127.0.0.1:8099/app.tar.gz
INSTALL=$?
if [ "$INSTALL" -ne 0 ]; then
	printf '\nRESULT: the archive does NOT install\n'
	exit 1
fi

step "files that were installed"
run sh -c "find custom_apps/$APP_ID apps/$APP_ID -maxdepth 0 2>/dev/null; find custom_apps apps -name '._*' -o -name '.DS_Store' 2>/dev/null | head -3"

step "integrity:check-app"
OUT=$(run php occ integrity:check-app "$APP_ID" 2>&1)
CHECK=$?
echo "${OUT:-(no error reported)}"
echo "exit code: $CHECK"
run php occ app:list 2>/dev/null | grep -A0 "$APP_ID"
if [ "$CHECK" -ne 0 ] || [ -n "$OUT" ]; then
	printf '\nRESULT: installed, but the code signature is NOT valid\n'
	exit 1
fi
printf '\nRESULT: installs through OC\\Installer and passes integrity:check-app (Nextcloud %s)\n' "$NC_VERSION"
