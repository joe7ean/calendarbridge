NC_HOST     ?= root@10.0.0.100
NC_APPS_DIR ?= /var/lib/nextcloud/apps
APP_ID      ?= calendarbridge
DEPLOY_PATH  = $(NC_APPS_DIR)/$(APP_ID)
NC_ROOT     ?= /usr/share/webapps/nextcloud
NC_PHP      ?= php
OCC          = ssh $(NC_HOST) 'php -d memory_limit=512M $(NC_ROOT)/occ'
E2E_DIR     ?= /tmp/cb-e2e-src

# Packaged app tarball (see `make release`)
DIST_DIR         ?= dist
RELEASE_STAGING  ?= .release-staging
RELEASE_VER      := $(shell sed -n 's/.*<version>\([^<]*\)<\/version>.*/\1/p' appinfo/info.xml | head -1)
RELEASE_NAME     := $(APP_ID)-$(RELEASE_VER)
RELEASE_TAR      := $(DIST_DIR)/$(RELEASE_NAME).tar.gz

# Prefer project-local Composer from `scripts/setup-build-env.sh` (.dev-tools/ is gitignored).
COMPOSER_PHAR    := $(abspath .dev-tools/composer.phar)
ifneq ($(wildcard $(COMPOSER_PHAR)),)
COMPOSER         ?= php $(COMPOSER_PHAR)
else
COMPOSER         ?= composer
endif

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

.PHONY: help setup build dev watch deploy deploy-fast enable disable upgrade migrate logs test e2e version-check clean composer-prod release release-clean

help:
	@echo ""
	@echo "  make setup     First-time: PHP + local Composer for release builds (see scripts/setup-build-env.sh)"
	@echo "  make build     Build JS/CSS assets (production)"
	@echo "  make dev       Build JS/CSS assets (development)"
	@echo "  make watch     Watch + rebuild on change"
	@echo "  make deploy        Build + rsync to $(NC_HOST):$(DEPLOY_PATH)"
	@echo "  make deploy-fast   Rsync only (no npm build; use when js/ is already current)"
	@echo "  make enable    Enable app on NC instance"
	@echo "  make disable   Disable app on NC instance"
	@echo "  make upgrade   Run full Nextcloud upgrade (occ upgrade)"
	@echo "  make migrate   Disable + enable app (apply app DB migrations)"
	@echo "  make logs      Tail NC log on remote"
	@echo "  make test      Run PHP unit tests locally"
	@echo "  make e2e       Run end-to-end scenarios on $(NC_HOST) (app must be deployed)"
	@echo "  make version-check  Verify info.xml, package.json and CHANGELOG agree"
	@echo "  make clean     Remove built assets"
	@echo "  make release   Build JS, then $(RELEASE_TAR)"
	@echo "  make release-clean  Remove dist/ and staging dir"
	@echo ""

setup:
	@bash scripts/setup-build-env.sh

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
	@if [ -x ./vendor/bin/phpunit ]; then ./vendor/bin/phpunit; \
	else echo "Run: composer install  then  ./vendor/bin/phpunit" >&2; exit 1; fi

# End-to-end scenarios against the deployed app on $(NC_HOST) (see tests/e2e/run.sh).
e2e:
	ssh $(NC_HOST) 'rm -rf $(E2E_DIR)'
	scp -rq tests/e2e $(NC_HOST):$(E2E_DIR)
	ssh $(NC_HOST) 'chmod -R a+rX $(E2E_DIR) && cd /tmp && NC_ROOT=$(NC_ROOT) PHP=$(NC_PHP) sh $(E2E_DIR)/run.sh'

version-check:
	@pkg=$$(sed -n -E 's/^  "version": "([^"]*)".*/\1/p' package.json | head -1); \
	log=$$(sed -n -E 's/^## \[([0-9][0-9.]*)\].*/\1/p' CHANGELOG.md | head -1); \
	echo "info.xml=$(RELEASE_VER) package.json=$$pkg CHANGELOG=$$log"; \
	if [ "$(RELEASE_VER)" != "$$pkg" ] || [ "$(RELEASE_VER)" != "$$log" ]; then \
		echo "Version mismatch" >&2; exit 1; \
	fi

clean:
	rm -rf js/ css/ node_modules/

# ─── Release tarball (manual copy to server apps/) ───────────────────────────

composer-prod: composer.json
	@if ! command -v composer >/dev/null 2>&1 && [ ! -f $(COMPOSER_PHAR) ]; then \
		echo "No Composer found. Install php-cli, then run:  make setup" >&2; \
		exit 1; \
	fi
	$(COMPOSER) install --no-dev --no-interaction --optimize-autoloader

# vendor/ is deliberately not shipped: the app has no runtime PHP dependencies and
# Nextcloud maps OCA\CalendarBridge\ to lib/ itself. Shipping it would also risk
# leaking the dev-only OCP stubs into a production instance.
release: build
	@echo "→ Staging $(APP_ID)/ for tarball $(RELEASE_VER) ..."
	@rm -rf "$(RELEASE_STAGING)" && mkdir -p "$(RELEASE_STAGING)/$(APP_ID)" "$(DIST_DIR)"
	rsync -a appinfo/ "$(RELEASE_STAGING)/$(APP_ID)/appinfo/"
	rsync -a lib/ "$(RELEASE_STAGING)/$(APP_ID)/lib/"
	rsync -a templates/ "$(RELEASE_STAGING)/$(APP_ID)/templates/"
	rsync -a img/ "$(RELEASE_STAGING)/$(APP_ID)/img/"
	rsync -a js/ "$(RELEASE_STAGING)/$(APP_ID)/js/"
	rsync -a composer.json "$(RELEASE_STAGING)/$(APP_ID)/"
	@test -f composer.lock && rsync -a composer.lock "$(RELEASE_STAGING)/$(APP_ID)/" || true
	@if [ -d screenshots ]; then rsync -a screenshots/ "$(RELEASE_STAGING)/$(APP_ID)/screenshots/"; fi
	@if [ -f LICENSE ]; then rsync -a LICENSE "$(RELEASE_STAGING)/$(APP_ID)/"; fi
	@if [ -f README.md ]; then rsync -a README.md "$(RELEASE_STAGING)/$(APP_ID)/"; fi
	@if [ -f SECURITY.md ]; then rsync -a SECURITY.md "$(RELEASE_STAGING)/$(APP_ID)/"; fi
	tar -czf "$(RELEASE_TAR)" -C "$(RELEASE_STAGING)" "$(APP_ID)"
	@rm -rf "$(RELEASE_STAGING)"
	@echo "→ $(RELEASE_TAR)  (extract inside nextcloud apps/ so you get apps/$(APP_ID)/)"

release-clean:
	rm -rf "$(DIST_DIR)" "$(RELEASE_STAGING)"
