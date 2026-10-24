#!/bin/sh
# End-to-end scenarios for Calendar Bridge against a real Nextcloud (ADR 0013, stage 1).
# Runs ON the Nextcloud host as root; `make e2e` copies this directory there and starts it.
#
# Environment (defaults match the LAN test instance):
#   NC_ROOT   Nextcloud installation   /usr/share/webapps/nextcloud
#   PHP       PHP CLI binary           php
#   WEB_USER  user owning Nextcloud    owner of $NC_ROOT/config/config.php
#   PORT      local feed server port   8765
#
# The script temporarily enables allow_local_remote_servers and the app's allow_http
# (the feed is served from 127.0.0.1) and restores both settings on exit.
# It never touches feeds other than the one it creates.
set -u

NC_ROOT=${NC_ROOT:-/usr/share/webapps/nextcloud}
PHP=${PHP:-php}
WEB_USER=${WEB_USER:-$(stat -c %U "$NC_ROOT/config/config.php")}
PORT=${PORT:-8765}
SLOW_PORT=$((PORT + 1))
DIR=$(cd "$(dirname "$0")" && pwd)
WORK=$(mktemp -d /tmp/cb-e2e.XXXXXX)
FEED="$WORK/feed.ics"
SERVICE_USER=cb-e2e-service
GROUP=cb-e2e
FAILED=0

as_web() { su -s /bin/sh "$WEB_USER" -c "$*"; }
# occ_rc keeps occ's exit code; occ filters the memory-limit notice for readable output.
occ_rc() { as_web "cd '$NC_ROOT' && $PHP -d memory_limit=512M occ $*" >/dev/null 2>&1; }
occ() { as_web "cd '$NC_ROOT' && $PHP -d memory_limit=512M occ $*" 2>&1 | grep -v 'memory limit'; }
die() { echo "SETUP FAILED: $*" >&2; exit 100; }
# quote <arg>: single-quote one argument for the `su -c` command string
quote() { printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"; }
h() {
	args=''
	for a in "$@"; do args="$args $(quote "$a")"; done
	as_web "NC_ROOT='$NC_ROOT' $PHP -d memory_limit=512M '$DIR/harness.php'$args" 2>/dev/null | grep -v 'memory limit'
}
# field <json> <php expression on $d>
field() { printf '%s' "$1" | $PHP -r '$d = json_decode(stream_get_contents(STDIN), true); echo json_encode(eval("return $argv[1];"), JSON_UNESCAPED_SLASHES);' "$2"; }

check() { # check <description> <actual> <expected>
	if [ "$2" = "$3" ]; then
		echo "  ok   $1"
	else
		echo "  FAIL $1: expected $3, got $2"
		FAILED=$((FAILED + 1))
	fi
}

without_event() { # without_event <uid> < input > output
	awk -v uid="$1" '
		/^BEGIN:VEVENT/ { buf = $0; inev = 1; next }
		inev { buf = buf "\n" $0; if (/^END:VEVENT/) { inev = 0; if (index(buf, "UID:" uid "\r") == 0 && index(buf, "UID:" uid "\n") == 0 && buf !~ ("UID:" uid "$")) print buf }; next }
		{ print }'
}

# ── setup ───────────────────────────────────────────────────────────────────
PREV_LOCAL=$(occ config:system:get allow_local_remote_servers)
PREV_HTTP=$(occ config:app:get calendarbridge allow_http)
cleanup() {
	[ -n "${FEED_ID:-}" ] && h cleanup "$FEED_ID" >/dev/null
	[ -n "${SRV_PID:-}" ] && kill "$SRV_PID" 2>/dev/null
	[ -n "${SLOW_PID:-}" ] && kill "$SLOW_PID" 2>/dev/null
	if [ -n "$PREV_LOCAL" ]; then occ config:system:set allow_local_remote_servers --type=boolean --value="$PREV_LOCAL" >/dev/null
	else occ config:system:delete allow_local_remote_servers >/dev/null; fi
	if [ -n "$PREV_HTTP" ]; then occ config:app:set calendarbridge allow_http --value="$PREV_HTTP" >/dev/null
	else occ config:app:delete calendarbridge allow_http >/dev/null; fi
	rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null
occ config:app:set calendarbridge allow_http --type=boolean --value=1 >/dev/null
if ! occ_rc user:info "$SERVICE_USER"; then
	as_web "cd '$NC_ROOT' && OC_PASS=E2e-$(head -c 18 /dev/urandom | base64 | tr -d '/+=')x9 $PHP occ user:add --password-from-env '$SERVICE_USER'" >/dev/null 2>&1
	occ_rc user:info "$SERVICE_USER" || die "could not create service user $SERVICE_USER"
fi
occ_rc group:info "$GROUP" 2>/dev/null || occ_rc group:add "$GROUP" || true

cp "$DIR/fixtures/series.ics" "$FEED"
chmod -R a+rX "$WORK"
$PHP -S 127.0.0.1:$PORT -t "$WORK" >/dev/null 2>&1 &
SRV_PID=$!
CB_FEED_FILE="$FEED" $PHP -S 127.0.0.1:$SLOW_PORT "$DIR/slow-router.php" >/dev/null 2>&1 &
SLOW_PID=$!
sleep 1
$PHP -r 'exit(@file_get_contents($argv[1]) === false ? 1 : 0);' "http://127.0.0.1:$PORT/feed.ics" \
	|| die "feed server on port $PORT not reachable"

FEED_ID=$(field "$(h create "http://127.0.0.1:$PORT/feed.ics" CB-E2E "$SERVICE_USER" "$GROUP")" '$d["id"]')
case "$FEED_ID" in ''|*[!0-9]*) die "could not create feed: $FEED_ID" ;; esac
echo "feed $FEED_ID"

# ── scenarios ───────────────────────────────────────────────────────────────
echo "initial sync"
R=$(h sync "$FEED_ID")
check "3 objects added" "$(field "$R" '$d["added"]')" 3
O=$(h objects "$FEED_ID")
check "series stored as one object" "$(field "$O" '$d["cbtest-series-1.ics"]["vevents"]')" 3
check "series keeps both overrides" "$(field "$O" '$d["cbtest-series-1.ics"]["overrides"]')" 2
check "series carries its time zone" "$(field "$O" '$d["cbtest-series-1.ics"]["tz"]')" '["Europe/Berlin"]'
check "attendees stripped" "$(field "$O" '$d["cbtest-single-1.ics"]["attendees"]')" false
check "unsafe UID gets hashed URI" "$(field "$O" 'count(array_filter(array_keys($d), fn($k) => preg_match("/^[0-9a-f]{40}\\.ics$/", $k)))')" 1
check "read-only group share" "$(field "$(h shares "$FEED_ID")" '$d[0]["principaluri"] . ":" . $d[0]["access"]')" "\"principals/groups/$GROUP:3\""

echo "unchanged feed with new DTSTAMP"
sed 's/^DTSTAMP:.*/DTSTAMP:20991231T000000Z/' "$DIR/fixtures/series.ics" > "$FEED"
R=$(h sync "$FEED_ID")
check "nothing written" "$(field "$R" '[$d["added"], $d["updated"], $d["deleted"]]')" '[0,0,0]'

echo "changed override"
sed 's/(moved)/(moved again)/' "$DIR/fixtures/series.ics" > "$FEED"
check "one update" "$(field "$(h sync "$FEED_ID")" '$d["updated"]')" 1
cp "$DIR/fixtures/series.ics" "$FEED"; h sync "$FEED_ID" >/dev/null

echo "remove and re-add an event, twice"
for round in 1 2; do
	without_event cbtest-single-1 < "$DIR/fixtures/series.ics" > "$FEED"
	check "round $round: deleted" "$(field "$(h sync "$FEED_ID")" '$d["deleted"]')" 1
	cp "$DIR/fixtures/series.ics" "$FEED"
	check "round $round: re-added" "$(field "$(h sync "$FEED_ID")" '$d["added"]')" 1
done
check "trash bin empty" "$(field "$(h trashed "$FEED_ID")" '$d["trashed"]')" 0

echo "object left in the trash bin by 0.2.0"
h softdelete "$FEED_ID" cbtest-single-1.ics >/dev/null
check "re-created after purge" "$(field "$(h sync "$FEED_ID")" '$d["added"]')" 1
check "trash bin empty" "$(field "$(h trashed "$FEED_ID")" '$d["trashed"]')" 0

echo "empty feed → quarantine (ADR 0012)"
printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//e2e//EN\r\nEND:VCALENDAR\r\n' > "$FEED"
R=$(h sync "$FEED_ID")
check "nothing deleted, 3 held" "$(field "$R" '[$d["deleted"], $d["held"]]')" '[0,3]'
check "status quarantine, not an error" "$(field "$R" '[$d["status"], $d["errors"]]')" '[3,0]'
check "relay calendar intact" "$(field "$(h objects "$FEED_ID")" 'count($d)')" 3
check "second sight within a day still held" "$(field "$(h sync "$FEED_ID")" '$d["held"]')" 3
occ_rc calendarbridge:check; check "occ check reports a warning" "$?" 1
h set "$FEED_ID" quarantineSinceAgo 90000 >/dev/null
R=$(h sync "$FEED_ID")
check "confirmed after a day: applied" "$(field "$R" '[$d["deleted"], $d["status"]]')" '[3,0]'
cp "$DIR/fixtures/series.ics" "$FEED"
check "events come back" "$(field "$(h sync "$FEED_ID")" '$d["added"]')" 3

echo "mass deletion → quarantine, reject and apply via occ"
{
	sed '/^END:VCALENDAR/d' "$DIR/fixtures/series.ics"
	i=1; while [ $i -le 12 ]; do
		printf 'BEGIN:VEVENT\r\nUID:bulk-%d\r\nDTSTAMP:20261024T000000Z\r\nDTSTART:202612%02dT100000Z\r\nSUMMARY:Bulk %d\r\nEND:VEVENT\r\n' $i $i $i
		i=$((i + 1))
	done
	printf 'END:VCALENDAR\r\n'
} > "$WORK/big.ics"
cp "$WORK/big.ics" "$FEED"
check "12 bulk events added" "$(field "$(h sync "$FEED_ID")" '$d["added"]')" 12
cp "$DIR/fixtures/series.ics" "$FEED"
R=$(h sync "$FEED_ID")
check "12 of 15 disappearing are held" "$(field "$R" '[$d["deleted"], $d["held"], $d["status"]]')" '[0,12,3]'
occ_rc calendarbridge:quarantine "$FEED_ID" --reject
check "rejected result is shown as rejected" "$(field "$(h state "$FEED_ID")" '$d["quarantine"]["rejected"]')" true
h set "$FEED_ID" quarantineSinceAgo 200000 >/dev/null
h sync "$FEED_ID" >/dev/null
check "rejected result is not applied after a day" "$(field "$(h objects "$FEED_ID")" 'count($d)')" 15
occ_rc calendarbridge:quarantine "$FEED_ID" --apply
check "admin apply deletes" "$(field "$(h objects "$FEED_ID")" 'count($d)')" 3
check "quarantine cleared" "$(field "$(h state "$FEED_ID")" '$d["quarantine"]')" null

echo "background job honours the interval"
JOB=$(field "$(h jobid "$FEED_ID")" '$d["jobId"]' | tr -d '"')
h set "$FEED_ID" lastSyncedAgo 60 >/dev/null
BEFORE=$(field "$(h lastsync "$FEED_ID")" '$d["lastSyncedAt"]')
occ background-job:execute "$JOB" --force-execute >/dev/null
check "not due: skipped" "$(field "$(h lastsync "$FEED_ID")" '$d["lastSyncedAt"]')" "$BEFORE"
h set "$FEED_ID" lastSyncedAgo 7200 >/dev/null
BEFORE=$(field "$(h lastsync "$FEED_ID")" '$d["lastSyncedAt"]')
occ background-job:execute "$JOB" --force-execute >/dev/null
AFTER=$(field "$(h lastsync "$FEED_ID")" '$d["lastSyncedAt"]')
check "due: synced" "$([ "$AFTER" -gt "$BEFORE" ] && echo yes || echo no)" yes

echo "concurrent syncs"
h set "$FEED_ID" feedUrl "http://127.0.0.1:$SLOW_PORT/feed.ics" >/dev/null
h sync "$FEED_ID" > "$WORK/first" &
FIRST_PID=$!
sleep 1
SECOND=$(h sync "$FEED_ID")
wait "$FIRST_PID"
check "second sync is rejected while locked" "$(field "$SECOND" '$d["locked"] ?? false')" true
check "first sync completes" "$(field "$(cat "$WORK/first")" '$d["skipped"]')" false
h set "$FEED_ID" feedUrl "http://127.0.0.1:$PORT/feed.ics" >/dev/null

echo "errors back off"
h set "$FEED_ID" feedUrl "http://127.0.0.1:$PORT/missing.ics" >/dev/null
R=$(h sync "$FEED_ID")
check "404 is an error" "$(field "$R" '[$d["status"], $d["errors"]]')" '[1,1]'
check "next attempt scheduled" "$(field "$(h state "$FEED_ID")" '$d["nextAttemptAt"] !== null')" true
h set "$FEED_ID" lastSyncedAgo 7200 >/dev/null
BEFORE=$(field "$(h lastsync "$FEED_ID")" '$d["lastSyncedAt"]')
h set "$FEED_ID" nextAttemptAt 3600 >/dev/null
occ background-job:execute "$JOB" --force-execute >/dev/null
check "job waits for the backoff" "$(field "$(h lastsync "$FEED_ID")" '$d["lastSyncedAt"]')" "$BEFORE"
h set "$FEED_ID" feedUrl "http://127.0.0.1:$PORT/feed.ics" >/dev/null
check "recovery clears the error" "$(field "$(h sync "$FEED_ID")" '[$d["status"], $d["errors"]]')" '[0,0]'
check "and the backoff" "$(field "$(h state "$FEED_ID")" '$d["nextAttemptAt"]')" null

echo "calendar properties and self-healing"
h rename "$FEED_ID" "CB-E2E renamed" "#ff0000" >/dev/null
check "relay calendar renamed and recoloured" "$(field "$(h calendar "$FEED_ID")" '[$d["name"], $d["color"]]')" '["CB-E2E renamed","#ff0000"]'
OLD_CAL=$(field "$(h calendar "$FEED_ID")" '$d["id"]')
h dropcalendar "$FEED_ID" >/dev/null
R=$(h sync "$FEED_ID")
check "missing calendar is recreated" "$(field "$R" '[$d["added"], $d["calendarId"] !== '"$OLD_CAL"']')" '[3,true]'
check "and shared again" "$(field "$(h shares "$FEED_ID")" 'count($d)')" 1

echo "occ"
occ_rc calendarbridge:check; check "check is healthy" "$?" 0
check "list shows the feed" "$(occ calendarbridge:list | grep -c "CB-E2E renamed")" 1

echo
if [ "$FAILED" -eq 0 ]; then echo "ALL SCENARIOS PASSED"; else echo "$FAILED CHECK(S) FAILED"; fi
exit "$FAILED"
