#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ZEDD
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Fills the dev instance with a small, realistic scene for screenshots: Alice watches a few
# folders, Bob drops files in them, the alerts are sent. Usage: tools/seed-demo.sh fr|en
# Needs `make dev` and the users alice and bob (password below, test instance only).

set -eu
LANG_CODE=${1:-en}
NC_URL=${NC_URL:-http://localhost:8080}
MAILPIT_URL=${MAILPIT_URL:-http://localhost:8025}
PASSWORD=${DEMO_PASSWORD:-Passw0rd-test-42}
COMPOSE="docker compose -f $(dirname "$0")/../docker-compose.dev.yml"
occ() { $COMPOSE exec -T -u www-data nextcloud php occ "$@"; }
dav() { curl -s -o /dev/null -w '%{http_code}' -u "$1:$PASSWORD" "${@:2}"; }
DAV="$NC_URL/remote.php/dav/files"

# fresh accounts without the sample files of Nextcloud, so the screenshots show only the scene
occ config:system:set skeletondirectory --value="" >/dev/null
for uid in alice bob; do
	occ user:delete "$uid" >/dev/null 2>&1 || true
	$COMPOSE exec -T -u www-data -e OC_PASS="$PASSWORD" nextcloud php occ user:add --password-from-env --display-name="$uid" "$uid" >/dev/null
done
occ user:setting alice settings display_name "Alice Martin" >/dev/null 2>&1 || true
occ user:setting bob settings display_name "Bob Durand" >/dev/null 2>&1 || true
for uid in alice bob; do
	occ user:setting "$uid" settings email "$uid@folderwatch.test" >/dev/null
	occ user:setting "$uid" core lang "$LANG_CODE" >/dev/null
	occ user:setting "$uid" core locale "${LANG_CODE}_$( [ "$LANG_CODE" = fr ] && echo FR || echo US )" >/dev/null 2>&1 || true
done

# a clean slate: no watches, no pending alerts, no old mail, no old notifications
for id in $(occ folderwatch:list --output=json | python3 -c 'import sys,json;print(" ".join(str(w["id"]) for w in json.load(sys.stdin)))'); do occ folderwatch:remove "$id" >/dev/null; done
curl -s -X DELETE "$MAILPIT_URL/api/v1/messages" >/dev/null
$COMPOSE exec -T db mariadb -unextcloud -pnextcloud nextcloud -e "DELETE FROM oc_notifications WHERE app='folderwatch'" 2>/dev/null

folders=("Documents" "Documents/To process" "Projects" "Projects/Client X" "Projects/Client X/Deliverables" "Invoices")
[ "$LANG_CODE" = fr ] && folders=("Documents" "Documents/À traiter" "Projets" "Projets/Client X" "Projets/Client X/Livrables" "Factures")
for f in "${folders[@]}"; do dav alice -X MKCOL "$DAV/alice/$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$f")" >/dev/null; done
INBOX="${folders[1]}"; PROJECT="${folders[3]}"; DELIVERABLES="${folders[4]}"; INVOICES="${folders[5]}"
q() { python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$1"; }

for path in "$INBOX" "$PROJECT"; do
	curl -s -o /dev/null -u "alice:$PASSWORD" -H 'OCS-APIRequest: true' -X POST "$NC_URL/ocs/v2.php/apps/files_sharing/api/v1/shares" -d "path=/$path&shareType=0&shareWith=bob&permissions=31" || true
done

occ folderwatch:add alice "/$INBOX" >/dev/null
occ folderwatch:add alice "/$PROJECT" --events=created,modified,deleted --frequency=daily --digest-hour=17 --channel=mail >/dev/null
occ folderwatch:add alice "/$INVOICES" --no-recursive --events=created --frequency=hourly >/dev/null
# shares are mounted at the root of Bob's files, under their own name
BOB_INBOX=$(basename "$INBOX"); BOB_PROJECT=$(basename "$PROJECT"); BOB_DELIVERABLES="$BOB_PROJECT/$(basename "$DELIVERABLES")"
occ folderwatch:add bob "/$BOB_PROJECT" --channel=notification >/dev/null

# a few files of Alice's own, so the folder list looks lived in
for name in "Budget-2026.xlsx" "Roadmap.md" "Logo.png"; do
	echo "demo: $name" > /tmp/fw-demo-file
	dav alice -T /tmp/fw-demo-file "$DAV/alice/$name" >/dev/null
done

put() { # user folder name
	echo "demo: $3" > /tmp/fw-demo-file
	dav "$1" -T /tmp/fw-demo-file "$DAV/$1/$(q "$2")/$(q "$3")" >/dev/null
}
sleep 1
for name in "Invoice-2026-014.pdf" "Contract-signed.pdf" "Delivery-note.docx"; do put bob "$BOB_INBOX" "$name"; done
for name in "Mockups-v3.fig" "Specification.md"; do put bob "$BOB_DELIVERABLES" "$name"; done
put bob "$BOB_PROJECT" "Meeting-notes.txt"
sleep 2
occ folderwatch:flush
