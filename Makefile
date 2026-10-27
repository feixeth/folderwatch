# SPDX-FileCopyrightText: 2026 ZEDD
# SPDX-License-Identifier: AGPL-3.0-or-later

APP_ID := folderwatch
VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
COMPOSE ?= docker compose -f docker-compose.dev.yml
OCC := $(COMPOSE) exec -T -u www-data nextcloud php occ
NC_PORT ?= 8080
# Node recent enough for @nextcloud/eslint-config (22.14+), whatever the local one is
NODE_IMAGE ?= node:24-slim
BUILD_DIR := build
PACKAGE_DIR := $(BUILD_DIR)/appstore/$(APP_ID)
TARBALL := $(BUILD_DIR)/$(APP_ID)-$(VERSION).tar.gz

.DEFAULT_GOAL := help
.PHONY: help dev dev-down dev-reset occ build watch \
	test test-unit test-js test-integration test-e2e \
	lint lint-php lint-js typecheck psalm cs-fix openapi check \
	appstore appstore-sign clean

help: ## Cette aide
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | sed 's/:.*## /\t/' | column -t -s "$$(printf '\t')"

## ---- Développement ---------------------------------------------------------

dev: ## Démarre Nextcloud + MariaDB + Mailpit + cron et active l'app (NC_VERSION=33 pour une autre version)
	$(COMPOSE) up -d
	$(COMPOSE) exec -T nextcloud chown www-data:www-data /var/www/html/custom_apps
	@until $(OCC) status 2>/dev/null | grep -q "installed: true"; do sleep 3; done
	$(OCC) background:cron
	$(OCC) config:system:set overwrite.cli.url --value=http://localhost:$(NC_PORT)
	$(OCC) config:system:set htaccess.RewriteBase --value=/
	$(OCC) maintenance:update:htaccess
	-$(OCC) app:disable firstrunwizard
	-$(OCC) app:install groupfolders
	$(OCC) app:enable $(APP_ID)
	@echo "Nextcloud : http://localhost:$(NC_PORT) (admin/admin) — Mailpit : http://localhost:$${MAILPIT_PORT:-8025}"

dev-down: ## Arrête l'environnement de dev
	$(COMPOSE) down

dev-reset: ## Repart de zéro (supprime les volumes)
	$(COMPOSE) down -v

occ: ## make occ ARGS="folderwatch:list"
	$(OCC) $(ARGS)

build: ## Compile le frontend (js/)
	@[ -d node_modules ] || npm ci
	npm run build

watch: ## Recompile le frontend à chaque modification
	@[ -d node_modules ] || npm ci
	npm run watch

## ---- Tests -----------------------------------------------------------------

test: test-unit test-js test-integration test-e2e ## Toute la batterie (le dev doit tourner)

test-unit: ## PHPUnit, sans Nextcloud
	composer install --no-interaction --prefer-dist
	vendor/bin/phpunit -c tests/phpunit.xml --testsuite unit

test-js: ## vitest
	@[ -d node_modules ] || npm ci
	npm test

test-integration: ## PHPUnit dans le conteneur : vrais utilisateurs, partages, événements, corbeille, Mailpit
	$(COMPOSE) exec -T -u www-data nextcloud php custom_apps/$(APP_ID)/vendor/bin/phpunit -c custom_apps/$(APP_ID)/tests/phpunit.integration.xml

test-e2e: ## Parcours complet par WebDAV, occ, OCS et Mailpit (tests/integration.sh)
	tests/integration.sh

## ---- Qualité ---------------------------------------------------------------

lint: lint-php lint-js typecheck psalm ## Tout le contrôle statique

lint-php: ## php-cs-fixer (nextcloud/coding-standard), sans modifier
	vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Applique php-cs-fixer
	vendor/bin/php-cs-fixer fix

psalm: ## Psalm, niveau 3
	vendor/bin/psalm --no-cache --threads=4

lint-js: ## ESLint (@nextcloud/eslint-config) dans un conteneur Node récent
	docker run --rm -v "$(CURDIR)":/app -w /app $(NODE_IMAGE) npx --no-install eslint src tests-js

typecheck: ## vue-tsc
	@[ -d node_modules ] || npm ci
	npm run typecheck

openapi: ## Régénère openapi*.json depuis les contrôleurs
	vendor/bin/generate-spec

check: lint test-unit test-js ## Ce qu'il faut avant de commiter (sans l'instance de dev)

## ---- Publication -----------------------------------------------------------

appstore: ## Archive build/folderwatch-<version>.tar.gz prête à être signée
	@xmllint --noout appinfo/info.xml
	$(MAKE) build openapi
	rm -rf $(BUILD_DIR)/appstore && mkdir -p $(PACKAGE_DIR)
	rsync -a --exclude='*.map' appinfo lib l10n img js templates openapi.json openapi-administration.json openapi-full.json LICENSE LICENSES README.md CHANGELOG.md $(PACKAGE_DIR)/
	@# rien de ce qui sert au développement ne part dans l'archive
	@bad=$$(find $(PACKAGE_DIR) \( -name node_modules -o -name tests -o -name tests-js -o -name src -o -name vendor -o -name '*.map' -o -name '.git*' \) | head -5); [ -z "$$bad" ] || { echo "fichiers de dev dans l'archive : $$bad"; exit 1; }
	tar -czf $(TARBALL) -C $(BUILD_DIR)/appstore $(APP_ID)
	@echo "$(TARBALL)  ($$(du -h $(TARBALL) | cut -f1))"
	@shasum -a 256 $(TARBALL)

appstore-sign: appstore ## Signe l'archive : make appstore-sign KEY=app.key CRT=app.crt
	@[ -n "$(KEY)" ] && [ -n "$(CRT)" ] || { echo "usage : make appstore-sign KEY=<clé privée> CRT=<certificat du développeur>"; exit 1; }
	$(COMPOSE) exec -T nextcloud sh -c 'rm -rf /tmp/sign && mkdir -p /tmp/sign'
	docker cp $(PACKAGE_DIR) $$($(COMPOSE) ps -q nextcloud):/tmp/sign/$(APP_ID)
	docker cp $(KEY) $$($(COMPOSE) ps -q nextcloud):/tmp/sign/app.key
	docker cp $(CRT) $$($(COMPOSE) ps -q nextcloud):/tmp/sign/app.crt
	$(COMPOSE) exec -T nextcloud chown -R www-data:www-data /tmp/sign
	$(OCC) integrity:sign-app --privateKey=/tmp/sign/app.key --certificate=/tmp/sign/app.crt --path=/tmp/sign/$(APP_ID)
	docker cp $$($(COMPOSE) ps -q nextcloud):/tmp/sign/$(APP_ID)/appinfo/signature.json $(PACKAGE_DIR)/appinfo/signature.json
	$(COMPOSE) exec -T nextcloud rm -rf /tmp/sign
	tar -czf $(TARBALL) -C $(BUILD_DIR)/appstore $(APP_ID)
	@echo "Archive signée : $(TARBALL)"

clean: ## Supprime build/ et js/
	rm -rf $(BUILD_DIR) js
