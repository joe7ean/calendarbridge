NC_HOST     ?= root@10.0.0.100
NC_APPS_DIR ?= /var/lib/nextcloud/apps
APP_ID      ?= calendarbridge
DEPLOY_PATH  = $(NC_APPS_DIR)/$(APP_ID)
OCC          = ssh $(NC_HOST) 'php83 /usr/share/webapps/nextcloud/occ'

# Files to exclude from rsync (build artifacts, dev files)
RSYNC_EXCLUDE = \
	--exclude=node_modules \
	--exclude=vendor \
	--exclude='.git' \
	--exclude='.cursor' \
	--exclude='*.plan.md' \
	--exclude=tests \
	--exclude=phpunit.xml \
	--exclude='*.log'

.PHONY: help build deploy enable disable watch test clean

help:
	@echo ""
	@echo "  make build     Build JS/CSS assets (production)"
	@echo "  make dev       Build JS/CSS assets (development)"
	@echo "  make watch     Watch + rebuild on change"
	@echo "  make deploy    Build + rsync to $(NC_HOST):$(DEPLOY_PATH)"
	@echo "  make enable    Enable app on NC instance"
	@echo "  make disable   Disable app on NC instance"
	@echo "  make upgrade   Run DB migration on NC instance"
	@echo "  make logs      Tail NC log on remote"
	@echo "  make test      Run PHP unit tests locally"
	@echo "  make clean     Remove built assets"
	@echo ""

# ─── JS Build ─────────────────────────────────────────────────────────────────

node_modules: package.json
	npm ci

build: node_modules
	npm run build

dev: node_modules
	npm run dev

watch: node_modules
	npm run watch

# ─── Deploy ───────────────────────────────────────────────────────────────────

deploy: build
	@echo "→ Deploying to $(NC_HOST):$(DEPLOY_PATH) ..."
	ssh $(NC_HOST) 'mkdir -p $(DEPLOY_PATH)'
	rsync -az --delete $(RSYNC_EXCLUDE) ./ $(NC_HOST):$(DEPLOY_PATH)/
	ssh $(NC_HOST) 'chown -R nextcloud:www-data $(DEPLOY_PATH)'
	@echo "→ Done. Run 'make enable' if this is the first deployment."

# Quick deploy without full rebuild (useful during development)
deploy-fast:
	@echo "→ Fast deploy (no rebuild) to $(NC_HOST):$(DEPLOY_PATH) ..."
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

# ─── Test ─────────────────────────────────────────────────────────────────────

test:
	@echo "Note: PHP unit tests require 'composer install' first."
	@echo "Run: composer install && ./vendor/bin/phpunit"

clean:
	rm -rf js/ css/ node_modules/
