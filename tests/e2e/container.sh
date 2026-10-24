#!/usr/bin/env bash
# Run the end-to-end scenarios against a throw-away Nextcloud container.
#
#   tests/e2e/container.sh 35            # uses dist/calendarbridge-<info.xml version>.tar.gz
#   ENGINE=docker tests/e2e/container.sh 32 path/to/calendarbridge.tar.gz
#
# Needs podman or docker. The container uses SQLite and is removed afterwards.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash on Windows: do not rewrite container paths

NC_VERSION=${1:?usage: container.sh <nextcloud-major> [tarball]}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
VERSION=$(sed -n 's/.*<version>\([^<]*\)<\/version>.*/\1/p' "$ROOT/appinfo/info.xml" | head -1)
TARBALL=${2:-$ROOT/dist/calendarbridge-$VERSION.tar.gz}
ENGINE=${ENGINE:-podman}
NAME=cb-e2e-nc$NC_VERSION

[ -f "$TARBALL" ] || { echo "tarball $TARBALL not found (make release)" >&2; exit 2; }

# Podman/Docker on Windows expects native paths for `cp`.
hostpath() { if command -v cygpath >/dev/null; then cygpath -w "$1"; else printf '%s' "$1"; fi; }

cleanup() { $ENGINE rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

echo "== starting Nextcloud $NC_VERSION"
$ENGINE run -d --name "$NAME" --network=host \
	-e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=e2e-admin-pw-1234 \
	-e APACHE_DISABLE_REWRITE_IP=1 \
	"docker.io/library/nextcloud:$NC_VERSION-apache" >/dev/null

# The entrypoint installs Nextcloud, then starts Apache (which listens on :80).
occ() { $ENGINE exec -u www-data "$NAME" php occ "$@"; }
for _ in $(seq 1 120); do
	if occ status 2>/dev/null | grep -q 'installed: true'; then break; fi
	sleep 2
done
occ status | grep -E 'versionstring|installed'

echo "== installing $(basename "$TARBALL")"
$ENGINE cp "$(hostpath "$TARBALL")" "$NAME:/tmp/app.tar.gz"
$ENGINE exec "$NAME" sh -c 'mkdir -p /var/www/html/custom_apps && tar -xzf /tmp/app.tar.gz -C /var/www/html/custom_apps && chown -R www-data:www-data /var/www/html/custom_apps'
occ app:enable calendarbridge
occ background:cron >/dev/null

echo "== running scenarios"
$ENGINE cp "$(hostpath "$ROOT/tests/e2e")" "$NAME:/tmp/cb-e2e-src"
$ENGINE exec "$NAME" sh -c 'chmod -R a+rX /tmp/cb-e2e-src && cd /tmp && NC_ROOT=/var/www/html PHP=php WEB_USER=www-data PORT=18765 sh /tmp/cb-e2e-src/run.sh'
