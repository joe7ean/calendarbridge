# Optional per-host overrides (copy Makefile.local.example → Makefile.local)
-include Makefile.local

NC_HOST     ?= root@10.0.0.100
NC_APPS_DIR ?= /var/lib/nextcloud/apps
APP_ID      ?= calendarbridge
DEPLOY_PATH  = $(NC_APPS_DIR)/$(APP_ID)
OCC         ?= ssh $(NC_HOST) 'php83 /usr/share/webapps/nextcloud/occ'

# Files to exclude from rsync (dev-only; vendor/ is included after `composer install --no-dev`)
RSYNC_EXCLUDE = \
	--exclude=node_modules \
	--exclude='.git' \
	--exclude='.cursor' \
	--exclude='*.plan.md' \
	--exclude=tests \
	--exclude=phpunit.xml \
	--exclude='*.log' \
	--exclude=Makefile.local

.PHONY: help build composer-prod deploy deploy-fast enable disable upgrade migrate watch dev test clean

help:
	@echo ""
	@echo "  Solo maintainer / test instances"
	@echo "  ─────────────────────────────────"
	@echo "  1. cp Makefile.local.example Makefile.local  (set NC_HOST, NC_APPS_DIR, OCC)"
	@echo "  2. make deploy    # composer --no-dev + npm build + rsync"
	@echo "  3. make enable     # first time only (or after fresh NC)"
	@echo "  Override for another host: make deploy NC_HOST=root@other.box"
	@echo ""
	@echo "  make composer-prod   composer install --no-dev (runtime autoload → vendor/)"
	@echo "  make build           Build JS/CSS assets (production)"
	@echo "  make dev             Development JS build"
	@echo "  make watch           Watch + rebuild on change"
	@echo "  make deploy          composer-prod + build + rsync to $(NC_HOST):$(DEPLOY_PATH)"
	@echo "  make deploy-fast     rsync only (no composer/npm; use after make deploy once)"
	@echo "  make enable          Enable app on NC instance"
	@echo "  make disable         Disable app on NC instance"
	@echo "  make upgrade         Run NC upgrade (maintenance mode)"
	@echo "  make migrate         Disable + enable app (DB migrations)"
	@echo "  make logs            Tail NC log on remote"
	@echo "  make test            Hint for PHPUnit"
	@echo "  make clean           Remove built assets + node_modules"
	@echo ""

# ─── Composer (production autoload only) ────────────────────────────────────

composer-prod:
	@command -v composer >/dev/null 2>&1 || { echo "composer not found; install Composer or run: composer install --no-dev"; exit 1; }
	composer install --no-dev --optimize-autoloader --no-interaction

# ─── JS Build ───────────────────────────────────────────────────────────────

node_modules: package.json
	npm ci

build: node_modules
	npm run build

dev: node_modules
	npm run dev

watch: node_modules
	npm run watch

# ─── Deploy ─────────────────────────────────────────────────────────────────

deploy: composer-prod build
	@echo "→ Deploying to $(NC_HOST):$(DEPLOY_PATH) ..."
	ssh $(NC_HOST) 'mkdir -p $(DEPLOY_PATH)'
	rsync -az --delete $(RSYNC_EXCLUDE) ./ $(NC_HOST):$(DEPLOY_PATH)/
	ssh $(NC_HOST) 'chown -R nextcloud:www-data $(DEPLOY_PATH)'
	@echo "→ Done. Run 'make enable' if this is the first deployment."

# Quick deploy without composer/npm (use when only PHP/templates/lib changed, or JS already built)
deploy-fast:
	@echo "→ Fast deploy (no composer/npm) to $(NC_HOST):$(DEPLOY_PATH) ..."
	ssh $(NC_HOST) 'mkdir -p $(DEPLOY_PATH)'
	rsync -az --delete $(RSYNC_EXCLUDE) ./ $(NC_HOST):$(DEPLOY_PATH)/
	ssh $(NC_HOST) 'chown -R nextcloud:www-data $(DEPLOY_PATH)'

# ─── NC App Management ────────────────────────────────────────────────────────

enable:
	$(OCC) app:enable $(APP_ID)
	$(OCC) maintenance:repair --include-expensive

disable:
	$(OCC) app:disable $(APP_ID)

upgrade:
	$(OCC) maintenance:mode --on
	$(OCC) upgrade
	$(OCC) maintenance:mode --off

migrate:
	$(OCC) app:disable $(APP_ID)
	$(OCC) app:enable $(APP_ID)

logs:
	ssh $(NC_HOST) 'tail -f /var/lib/nextcloud/data/nextcloud.log | python3 -c "import sys,json; [print(json.dumps(json.loads(l), indent=2)) for l in sys.stdin]" 2>/dev/null || tail -f /var/lib/nextcloud/data/nextcloud.log'

# ─── Test ────────────────────────────────────────────────────────────────────

test:
	@echo "Note: PHP unit tests require 'composer install' first."
	@echo "Run: composer install && ./vendor/bin/phpunit"

clean:
	rm -rf js/ css/ node_modules/
