#!/usr/bin/env bash
# One-time / occasional setup for `make release` on Zorin OS and other Ubuntu-based distros.
# Does not modify git. Installs Composer into .dev-tools/ (gitignored) if PHP is available.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

APT_HINT() {
	cat <<'EOF'
Install system packages (Zorin / Ubuntu), then run this script again:

  sudo apt update
  sudo apt install -y make php-cli php-xml php-mbstring php-zip php-curl unzip rsync nodejs npm

Optional — newer Node.js (recommended if apt’s node is very old):

  # See https://github.com/nodesource/distributions
EOF
}

if ! command -v php >/dev/null 2>&1; then
	echo "PHP (php-cli) is not installed or not on PATH." >&2
	APT_HINT
	exit 1
fi

if ! php -r 'exit(version_compare(PHP_VERSION, "8.2.0", "<") ? 1 : 0);'; then
	echo "Need PHP >= 8.2 (found: $(php -r 'echo PHP_VERSION;'))." >&2
	exit 1
fi

mkdir -p .dev-tools
COMPOSER_PHAR="${ROOT}/.dev-tools/composer.phar"
if [[ ! -f "${COMPOSER_PHAR}" ]]; then
	echo "→ Downloading Composer to .dev-tools/composer.phar ..."
	curl -sS https://getcomposer.org/installer | php -- --install-dir=.dev-tools --filename=composer.phar
else
	echo "→ .dev-tools/composer.phar already present."
fi

php "${COMPOSER_PHAR}" self-update --stable -q 2>/dev/null || true

missing=()
for cmd in make node npm rsync tar curl; do
	command -v "${cmd}" >/dev/null 2>&1 || missing+=("${cmd}")
done
if ((${#missing[@]})); then
	echo "WARN: not on PATH: ${missing[*]}" >&2
	if [[ " ${missing[*]} " == *" node "* ]] || [[ " ${missing[*]} " == *" npm "* ]]; then
		APT_HINT
	fi
fi

echo ""
echo "Done. Build a release tarball with:"
echo "  make release"
echo ""
echo "Makefile will use .dev-tools/composer.phar when present (no global composer required)."
