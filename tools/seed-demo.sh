#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ZEDD
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Fills the dev instance with a small, realistic scene for screenshots: Alice watches four folders
# with different settings, Bob drops files in two of them, the alerts are sent.
# Usage: tools/seed-demo.sh fr|en
# Needs `make dev` (test instance only: the accounts are recreated).

set -eu
LANG_CODE=${1:-en}
NC_URL=${NC_URL:-http://localhost:8080}
MAILPIT_URL=${MAILPIT_URL:-http://localhost:8025}
PASSWORD=${DEMO_PASSWORD:-Passw0rd-test-42}
COMPOSE="docker compose -f $(dirname "$0")/../docker-compose.dev.yml"
occ() { $COMPOSE exec -T -u www-data nextcloud php occ "$@"; }
dav() { curl -s -o /dev/null -w '%{http_code}' -u "$1:$PASSWORD" "${@:2}"; }
q() { python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$1"; }
DAV="$NC_URL/remote.php/dav/files"

# names of the scene, in the language of the screenshots
if [ "$LANG_CODE" = fr ]; then
	INVOICES="Factures"; TOPROCESS="À traiter"; CONTRACTS="Contrats clients"; PHOTOS="Photos chantier"; DOCUMENTS="Documents"
	ALICE_FILES=("Budget-2026.xlsx:48210" "Planning.md:3120" "Logo.png:21870")
	INVOICE_FILES=("Facture-2026-013.pdf:96400")
	CONTRACT_FILES=("Contrat-Martin-SARL.pdf:214500" "Avenant-2026-02.pdf:88300")
	INBOX_FILES=("Devis-fournisseur-031.pdf:152300" "Compte-rendu-reunion.docx:43700" "Plan-etage-v2.pdf:512800")
	LATER_FILE="Bon-de-commande-signe.pdf:71400"
	PHOTO_FILES=("Fondations-mur-nord.jpg:1843200" "Echafaudage-est.jpg:1624000" "Charpente-01.jpg:1951000" "Charpente-02.jpg:1788000")
else
	INVOICES="Invoices"; TOPROCESS="To process"; CONTRACTS="Client contracts"; PHOTOS="Site photos"; DOCUMENTS="Documents"
	ALICE_FILES=("Budget-2026.xlsx:48210" "Roadmap.md:3120" "Logo.png:21870")
	INVOICE_FILES=("Invoice-2026-013.pdf:96400")
	CONTRACT_FILES=("Contract-Martin-LLC.pdf:214500" "Amendment-2026-02.pdf:88300")
	INBOX_FILES=("Supplier-quote-031.pdf:152300" "Meeting-minutes.docx:43700" "Floor-plan-v2.pdf:512800")
	LATER_FILE="Signed-order-form.pdf:71400"
	PHOTO_FILES=("Foundation-north-wall.jpg:1843200" "Scaffolding-east.jpg:1624000" "Roof-framing-01.jpg:1951000" "Roof-framing-02.jpg:1788000")
fi

# fresh accounts without the sample files of Nextcloud, so the screenshots show only the scene
occ config:system:set skeletondirectory --value="" >/dev/null
for uid in alice bob; do
	occ user:delete "$uid" >/dev/null 2>&1 || true
	$COMPOSE exec -T -u www-data -e OC_PASS="$PASSWORD" nextcloud php occ user:add --password-from-env --display-name="$uid" "$uid" >/dev/null
done
occ user:setting alice settings display_name "Alice Martin" >/dev/null 2>&1 || true
occ user:setting bob settings display_name "Bob Durand" >/dev/null 2>&1 || true
for uid in alice bob; do
	occ user:setting "$uid" settings email "$uid@example.com" >/dev/null
	occ user:setting "$uid" core lang "$LANG_CODE" >/dev/null
	occ user:setting "$uid" core locale "${LANG_CODE}_$( [ "$LANG_CODE" = fr ] && echo FR || echo US )" >/dev/null 2>&1 || true
done

# a clean slate: no watches, no pending alerts, no old mail, no old notifications
for id in $(occ folderwatch:list --output=json | python3 -c 'import sys,json;print(" ".join(str(w["id"]) for w in json.load(sys.stdin)))'); do occ folderwatch:remove "$id" >/dev/null; done
curl -s -X DELETE "$MAILPIT_URL/api/v1/messages" >/dev/null
$COMPOSE exec -T db mariadb -unextcloud -pnextcloud nextcloud -e "DELETE FROM oc_notifications WHERE app='folderwatch'" 2>/dev/null

put() { # user folder name:size  (the content is random bytes of a believable size)
	local name=${3%%:*} size=${3##*:}
	head -c "$size" /dev/urandom > /tmp/fw-demo-file
	dav "$1" -T /tmp/fw-demo-file "$DAV/$1/$(q "$2")/$(q "$name")" >/dev/null
}
put_root() { # user name:size
	local name=${2%%:*} size=${2##*:}
	head -c "$size" /dev/urandom > /tmp/fw-demo-file
	dav "$1" -T /tmp/fw-demo-file "$DAV/$1/$(q "$name")" >/dev/null
}
share() { # owner path with
	curl -s -o /dev/null -u "$1:$PASSWORD" -H 'OCS-APIRequest: true' -X POST "$NC_URL/ocs/v2.php/apps/files_sharing/api/v1/shares" -d "path=/$2&shareType=0&shareWith=$3&permissions=31"
}

# Alice's files, then Bob's folder of site photos, shared with her
for f in "$DOCUMENTS" "$INVOICES" "$TOPROCESS" "$CONTRACTS"; do dav alice -X MKCOL "$DAV/alice/$(q "$f")" >/dev/null; done
for f in "${ALICE_FILES[@]}"; do put_root alice "$f"; done
put alice "$DOCUMENTS" "Handbook.pdf:310400"
put alice "$DOCUMENTS" "Org-chart.png:64200"
for f in "${INVOICE_FILES[@]}"; do put alice "$INVOICES" "$f"; done
for f in "${CONTRACT_FILES[@]}"; do put alice "$CONTRACTS" "$f"; done
share alice "$TOPROCESS" bob
dav bob -X MKCOL "$DAV/bob/$(q "$PHOTOS")" >/dev/null
share bob "$PHOTOS" alice

# four watches, with different settings
occ folderwatch:add alice "/$INVOICES" --no-recursive --events=created --frequency=daily --digest-hour=8 --channel=mail >/dev/null
occ folderwatch:add alice "/$TOPROCESS" >/dev/null
occ folderwatch:add alice "/$CONTRACTS" --events=created,modified,deleted --frequency=daily --digest-hour=17 >/dev/null
occ folderwatch:add alice "/$PHOTOS" --events=created --channel=notification >/dev/null

# Bob drops files: three at once in "to process", then four photos
sleep 1
for f in "${INBOX_FILES[@]}"; do put bob "$TOPROCESS" "$f"; done
for f in "${PHOTO_FILES[@]}"; do put bob "$PHOTOS" "$f"; done
sleep 2
occ folderwatch:flush
# and later one more file in "to process": a second, single-file alert
sleep 1
put bob "$TOPROCESS" "$LATER_FILE"
sleep 2
occ folderwatch:flush
rm -f /tmp/fw-demo-file
