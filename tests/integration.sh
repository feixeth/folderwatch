#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ZEDD
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# End-to-end check against a running dev instance (make dev), through the same doors a user or
# an admin would use: WebDAV, OCS, occ and the mailbox.
#
#   - two users, one shares a folder with the other
#   - both watch it
#   - one uploads with curl -T, folderwatch:flush, notifications read through the OCS API,
#     mails read in Mailpit
#
# Variables (defaults suit docker-compose.dev.yml):
#   NC_URL       http://localhost:8080
#   MAILPIT_URL  http://localhost:8025
#   OCC          command that runs occ as the web server user
#   KEEP=1       leave the users, files and mails in place to look at them

set -u

NC_URL=${NC_URL:-http://localhost:8080}
MAILPIT_URL=${MAILPIT_URL:-http://localhost:8025}
OCC=${OCC:-docker compose -f "$(dirname "$0")/../docker-compose.dev.yml" exec -T -u www-data nextcloud php occ}
PASSWORD='Passw0rd-e2e-'$RANDOM$RANDOM
RUN=$(date +%s)$RANDOM
ALICE="fwe${RUN}a"
BOB="fwe${RUN}b"
FOLDER="FW-Inbox-$RUN"
DAV="$NC_URL/remote.php/dav/files"
OCS="$NC_URL/ocs/v2.php"
TMP=$(mktemp -d)
failures=0
checks=0

occ() { $OCC "$@"; }
# runs occ with OC_PASS in its environment: with docker the variable has to be handed to the container
occ_env() {
	case "$OCC" in
		docker*) OC_PASS="$PASSWORD" docker compose -f "$(dirname "$0")/../docker-compose.dev.yml" exec -T -u www-data -e OC_PASS nextcloud php occ "$@" ;;
		*) OC_PASS="$PASSWORD" $OCC "$@" ;;
	esac
}

pass() { checks=$((checks + 1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { checks=$((checks + 1)); failures=$((failures + 1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }

expect_eq() { # description expected actual
	if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected [$2], got [$3]"; fi
}
expect_contains() { # description haystack needle
	case "$2" in *"$3"*) pass "$1" ;; *) fail "$1" "[$3] not found in: $(printf '%s' "$2" | head -c 300)" ;; esac
}
expect_link() { # description text host file-name: the text links to the file on that host (with or without index.php)
	if printf '%s' "$2" | grep -Eq "http://$3:$PORT/(index\\.php/)?f/$(file_id_of "$4")\\b"; then pass "$1"; else fail "$1" "no link to $4 on $3 in: $(printf '%s' "$2" | head -c 300)"; fi
}
expect_not_contains() {
	case "$2" in *"$3"*) fail "$1" "unexpected [$3] in: $(printf '%s' "$2" | head -c 300)" ;; *) pass "$1" ;; esac
}

json() { python3 -c "import sys,json; d=json.load(sys.stdin); $1"; }

# the settings the host checks change, put back as they were
restore_hosts() {
	[ -n "${HOSTS_SAVED:-}" ] || return 0
	if [ -n "$OLD_CLI_URL_H" ]; then occ config:system:set overwrite.cli.url --value="$OLD_CLI_URL_H" >/dev/null 2>&1; else occ config:system:delete overwrite.cli.url >/dev/null 2>&1; fi
	occ config:system:delete overwritecondaddr >/dev/null 2>&1
	occ config:system:set trusted_domains 0 --value="$OLD_TRUSTED_0" >/dev/null 2>&1
	if [ -n "$OLD_TRUSTED_1" ]; then occ config:system:set trusted_domains 1 --value="$OLD_TRUSTED_1" >/dev/null 2>&1; else occ config:system:delete trusted_domains 1 >/dev/null 2>&1; fi
	HOSTS_SAVED=
}

cleanup() {
	restore_hosts
	[ -n "${KEEP:-}" ] && { echo "kept: $ALICE / $BOB (password $PASSWORD)"; return; }
	for uid in "$ALICE" "$BOB"; do occ user:delete "$uid" >/dev/null 2>&1; done
	rm -rf "$TMP"
}
trap cleanup EXIT

curl_ok() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "Folder Watch end-to-end ($NC_URL)"

echo "- setting up"
$OCC status >/dev/null 2>&1 || { echo "cannot run occ with: $OCC"; exit 2; }
# the password goes through the environment of the container, never on a command line
for uid in "$ALICE" "$BOB"; do
	OC_PASS="$PASSWORD" occ_env user:add --password-from-env --display-name="$uid" "$uid" >/dev/null 2>&1 || { echo "cannot create $uid"; exit 2; }
	occ user:setting "$uid" settings email "$uid@folderwatch.test" >/dev/null
	occ user:setting "$uid" core lang en >/dev/null
done
curl -s -X DELETE "$MAILPIT_URL/api/v1/messages" >/dev/null

expect_eq "alice creates a folder" 201 "$(curl_ok -u "$ALICE:$PASSWORD" -X MKCOL "$DAV/$ALICE/$FOLDER")"
share=$(curl -s -u "$ALICE:$PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' -X POST "$OCS/apps/files_sharing/api/v1/shares" -d "path=/$FOLDER&shareType=0&shareWith=$BOB&permissions=31" | json 'print(d["ocs"]["meta"]["statuscode"])')
expect_eq "alice shares it with bob" 200 "$share"

echo "- watching with occ"
add=$(occ folderwatch:add "$ALICE" "/$FOLDER" 2>&1)
expect_contains "alice watches the folder" "$add" "created: /$FOLDER for $ALICE"
add=$(occ folderwatch:add "$BOB" "/$FOLDER" --channel=notification 2>&1)
expect_contains "bob watches it too, notifications only" "$add" "channels: notification,"
listing=$(occ folderwatch:list --user="$ALICE" --output=json)
expect_eq "the list shows alice's watch" "/$FOLDER" "$(printf '%s' "$listing" | json 'print(d[0]["path"])')"

echo "- bob uploads through WebDAV"
for name in report.pdf budget.xlsx notes.txt; do
	echo "content of $name" > "$TMP/$name"
	expect_eq "PUT $name" 201 "$(curl_ok -u "$BOB:$PASSWORD" -T "$TMP/$name" "$DAV/$BOB/$FOLDER/$name")"
done
echo junk > "$TMP/.DS_Store"; echo junk > "$TMP/Thumbs.db"
curl_ok -u "$BOB:$PASSWORD" -T "$TMP/.DS_Store" "$DAV/$BOB/$FOLDER/.DS_Store" >/dev/null
curl_ok -u "$BOB:$PASSWORD" -T "$TMP/Thumbs.db" "$DAV/$BOB/$FOLDER/Thumbs.db" >/dev/null
sleep 2 # the queue is written after the response

listing=$(occ folderwatch:list --user="$ALICE" --output=json)
expect_eq "3 events are queued for alice (ignored names left out)" 3 "$(printf '%s' "$listing" | json 'print(d[0]["pending"])')"
listing=$(occ folderwatch:list --user="$BOB" --output=json)
expect_eq "nothing is queued for bob, who did it himself" 0 "$(printf '%s' "$listing" | json 'print(d[0]["pending"])')"

echo "- flushing"
flush=$(occ folderwatch:flush 2>&1)
expect_contains "flush reports the alert" "$flush" "alert(s) sent."

notifications() { # user -> subjects of folderwatch notifications, one per line
	curl -s -u "$1:$PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' "$OCS/apps/notifications/api/v2/notifications" \
		| json 'print("\n".join(n["subject"] for n in d["ocs"]["data"] if n["app"] == "folderwatch"))'
}
alice_notes=$(notifications "$ALICE")
expect_eq "alice got one notification" "3 new items in $FOLDER by $BOB" "$alice_notes"
expect_eq "bob got none" "" "$(notifications "$BOB")"

mails_to() { curl -s "$MAILPIT_URL/api/v1/search?query=to:$1%40folderwatch.test" ; }
alice_mails=$(mails_to "$ALICE")
expect_eq "alice got one mail" 1 "$(printf '%s' "$alice_mails" | json 'print(len(d["messages"]))')"
mail_id=$(printf '%s' "$alice_mails" | json 'print(d["messages"][0]["ID"])')
mail_text=$(curl -s "$MAILPIT_URL/api/v1/message/$mail_id" | json 'print(d["Subject"]); print(d["Text"])')
expect_contains "the mail subject says what happened" "$mail_text" "3 new items in $FOLDER by $BOB"
for name in report.pdf budget.xlsx notes.txt; do
	expect_contains "the mail lists $name" "$mail_text" "$name"
done
expect_not_contains "the mail does not list .DS_Store" "$mail_text" ".DS_Store"
expect_not_contains "the mail does not list Thumbs.db" "$mail_text" "Thumbs.db"
expect_not_contains "the mail does not carry file contents" "$mail_text" "content of"
# (the share itself sends bob a mail of Nextcloud's own, "... shared ... with you")
expect_eq "bob, notification only, got no alert mail" 0 "$(mails_to "$BOB" | json 'print(len([m for m in d["messages"] if "shared" not in m["Subject"]]))')"

echo "- the other way round: alice uploads, bob is alerted"
echo "from alice" > "$TMP/hello.txt"
expect_eq "PUT hello.txt" 201 "$(curl_ok -u "$ALICE:$PASSWORD" -T "$TMP/hello.txt" "$DAV/$ALICE/$FOLDER/hello.txt")"
sleep 2
expect_eq "alice's own upload queues nothing for her" 0 "$(occ folderwatch:list --user="$ALICE" --output=json | json 'print(d[0]["pending"])')"
expect_eq "it queues one event for bob" 1 "$(occ folderwatch:list --user="$BOB" --output=json | json 'print(d[0]["pending"])')"
occ folderwatch:flush
expect_eq "bob got a notification about the single file" "$ALICE added hello.txt in $FOLDER" "$(notifications "$BOB")"

echo "- the link opens the file for a recipient who renamed the shared folder"
RENAMED="Mine-$RUN"
expect_eq "bob renames the shared folder on his side" 201 "$(curl_ok -u "$BOB:$PASSWORD" -X MOVE -H "Destination: $DAV/$BOB/$RENAMED" "$DAV/$BOB/$FOLDER")"
occ folderwatch:flush >/dev/null
echo "after the rename" > "$TMP/renamed.txt"
expect_eq "PUT renamed.txt as alice" 201 "$(curl_ok -u "$ALICE:$PASSWORD" -T "$TMP/renamed.txt" "$DAV/$ALICE/$FOLDER/renamed.txt")"
sleep 2
occ folderwatch:flush >/dev/null
renamed_id=$(curl -s -u "$ALICE:$PASSWORD" -X PROPFIND -H 'Depth: 0' --data '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' "$DAV/$ALICE/$FOLDER/renamed.txt" | grep -o '<oc:fileid>[0-9]*' | grep -o '[0-9]*$')
bob_link=$(curl -s -u "$BOB:$PASSWORD" -H 'OCS-APIRequest: true' -H 'Accept: application/json' "$OCS/apps/notifications/api/v2/notifications" \
	| json 'print(next((n["link"] for n in d["ocs"]["data"] if n["app"] == "folderwatch" and "renamed.txt" in n["subject"]), ""))')
expect_contains "bob's notification links to the file by its id" "$bob_link" "/f/$renamed_id"
expect_not_contains "and not to alice's path" "$bob_link" "$FOLDER"
opened=$(curl -s -o /dev/null -D - -u "$BOB:$PASSWORD" "$bob_link" | tr -d '\r')
expect_contains "following it, bob lands in his own folder" "$opened" "$RENAMED"
expect_contains "on that very file" "$opened" "$renamed_id"
expect_eq "bob puts the folder back" 201 "$(curl_ok -u "$BOB:$PASSWORD" -X MOVE -H "Destination: $DAV/$BOB/$FOLDER" "$DAV/$BOB/$RENAMED")"

echo "- links keep to a trustworthy host, whatever overwrite.cli.url says"
# The address is worked out in the web request that uploads the file and stored with the event. Two names for
# this instance: alias-a is the public one, alias-b another way in; curl --resolve points both at the dev instance.
# overwritecondaddr keeps overwritehost from applying, so that a request keeps the host it came in by. Alice uploads and wants to hear about
# her own actions: with Teams and group folders installed Nextcloud cannot serve a share recipient without
# overwrite.cli.url, which is not what is tested here.
OLD_CLI_URL_H=$(occ config:system:get overwrite.cli.url 2>/dev/null || true)
OLD_TRUSTED_0=$(occ config:system:get trusted_domains 0 2>/dev/null || echo localhost)
OLD_TRUSTED_1=$(occ config:system:get trusted_domains 1 2>/dev/null || true)
HOSTS_SAVED=1
PORT=${NC_URL##*:}
# (overwritehost comes from the container's environment: limit it to an address no request comes from)
occ config:system:set overwritecondaddr --value='^10\.255\.255\.' >/dev/null
occ config:system:set trusted_domains 0 --value="alias-a.test" >/dev/null
occ config:system:set trusted_domains 1 --value="alias-b.test" >/dev/null
occ user:setting "$ALICE" folderwatch ignore_own no >/dev/null

# uploads $2 through the host $1, flushes, and prints the mail about it (subject, then text, then html)
mail_after_upload() {
	echo "$2" > "$TMP/$2"
	local status id
	status=$(curl_ok --resolve "$1:$PORT:127.0.0.1" -u "$ALICE:$PASSWORD" -T "$TMP/$2" "http://$1:$PORT/remote.php/dav/files/$ALICE/$FOLDER/$2")
	[ "$status" = 201 ] || { echo "PUT FAILED $status"; return; }
	sleep 2
	occ folderwatch:flush >/dev/null
	id=$(mails_to "$ALICE" | json "print(next((m['ID'] for m in d['messages'] if '$2' in m['Subject']), ''))")
	[ -n "$id" ] || { echo "NO MAIL"; return; }
	curl -s "$MAILPIT_URL/api/v1/message/$id" | json 'print(d["Subject"]); print(d["Text"]); print(d["HTML"])'
}
file_id_of() { # file name in alice's folder
	curl -s -u "$ALICE:$PASSWORD" -X PROPFIND -H 'Depth: 0' --data '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' "$DAV/$ALICE/$FOLDER/$1" | grep -o '<oc:fileid>[0-9]*' | grep -o '[0-9]*$'
}

occ config:system:delete overwrite.cli.url >/dev/null 2>&1
mail=$(mail_after_upload alias-a.test via-first.txt)
expect_link "no overwrite.cli.url, first trusted domain: the link comes from the request" "$mail" alias-a.test via-first.txt
expect_not_contains "with nothing of alice's own paths in it" "$mail" "dir="
mail=$(mail_after_upload alias-b.test via-second.txt)
expect_not_contains "no overwrite.cli.url, another host: no link at all" "$mail" "/f/"
expect_not_contains "and no button" "$mail" "href="

occ config:system:set overwrite.cli.url --value="http://alias-a.test:$PORT" >/dev/null
mail=$(mail_after_upload alias-a.test via-public.txt)
expect_link "the configured host is kept" "$mail" alias-a.test via-public.txt
mail=$(mail_after_upload alias-b.test via-other.txt)
expect_link "another host is rebuilt on the configured one" "$mail" alias-a.test via-other.txt
expect_not_contains "the other host appears nowhere" "$mail" "alias-b"

occ config:system:set overwrite.cli.url --value="http://localhost:$PORT" >/dev/null
mail=$(mail_after_upload alias-a.test via-local.txt)
expect_link "a local overwrite.cli.url, first trusted domain: the request's host" "$mail" alias-a.test via-local.txt
restore_hosts

echo "- removing a watch"
watch_id=$(occ folderwatch:list --user="$BOB" --output=json | json 'print(d[0]["id"])')
occ folderwatch:remove "$watch_id" >/dev/null
expect_eq "bob has no watch left" '[]' "$(occ folderwatch:list --user="$BOB" --output=json | tr -d ' \n')"

echo
if [ "$failures" -eq 0 ]; then
	printf '\033[32mAll %d checks passed.\033[0m\n' "$checks"
else
	printf '\033[31m%d of %d checks failed.\033[0m\n' "$failures" "$checks"
fi
exit $((failures > 0))
