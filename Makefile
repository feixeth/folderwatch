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
# The archive is packed by GNU tar in a Linux container, never by the tar of this machine: the tar of macOS adds
# AppleDouble entries ("._folderwatch", "folderwatch/._README.md") for extended attributes, hides them when it lists
# an archive, and Nextcloud then refuses the app ("Extracted app folderwatch has more than 1 folder").
# Any Debian-based image with GNU tar will do.
TAR_IMAGE ?= $(NODE_IMAGE)
PACK = docker run --rm --user "$$(id -u):$$(id -g)" -v "$(CURDIR)/$(BUILD_DIR)/appstore:/src:ro" -v "$(CURDIR)/$(BUILD_DIR):/out" $(TAR_IMAGE) \
	sh -c 'tar -C /src --no-xattrs --no-acls --no-selinux --owner=0 --group=0 --numeric-owner --sort=name --exclude="._*" --exclude=.DS_Store -cf - $(APP_ID) | gzip -9n > /out/$(notdir $(TARBALL))'

.DEFAULT_GOAL := help
.PHONY: help dev dev-down dev-reset occ build watch \
	test test-unit test-js test-integration test-e2e \
	lint lint-php lint-js typecheck psalm cs-fix openapi check \
	appstore appstore-sign appstore-signature check-package check-archive test-install clean

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
	rsync -a --exclude='*.map' --exclude='._*' --exclude='.DS_Store' appinfo lib l10n img js templates openapi.json openapi-administration.json openapi-full.json LICENSE LICENSES README.md README.fr.md CHANGELOG.md $(PACKAGE_DIR)/
	@# rien de ce qui sert au développement ne part dans l'archive
	@bad=$$(find $(PACKAGE_DIR) \( -name node_modules -o -name tests -o -name tests-js -o -name src -o -name vendor -o -name '*.map' -o -name '.git*' \) | head -5); [ -z "$$bad" ] || { echo "fichiers de dev dans l'archive : $$bad"; exit 1; }
	$(MAKE) check-package
	$(PACK)
	$(MAKE) check-archive
	@echo "$(TARBALL)  ($$(du -h $(TARBALL) | cut -f1))"
	@shasum -a 256 $(TARBALL)

check-package: ## Le dossier à emballer ne contient ni ._* ni .DS_Store (avant de signer le code)
	python3 -I tools/check-archive.py $(PACKAGE_DIR) --app $(APP_ID)

check-archive: ## L'archive ne contient que folderwatch/, ni ._* ni .DS_Store (lue avec Python tarfile, avant toute signature)
	python3 -I tools/check-archive.py $(TARBALL) --app $(APP_ID)

appstore-sign: appstore ## Signe l'archive : make appstore-sign KEY=app.key CRT=app.crt
	@[ -n "$(KEY)" ] && [ -n "$(CRT)" ] || { echo "usage : make appstore-sign KEY=<clé privée> CRT=<certificat du développeur>"; exit 1; }
	$(MAKE) check-package
	$(COMPOSE) exec -T nextcloud sh -c 'rm -rf /tmp/sign && mkdir -p /tmp/sign'
	docker cp $(PACKAGE_DIR) $$($(COMPOSE) ps -q nextcloud):/tmp/sign/$(APP_ID)
	docker cp $(KEY) $$($(COMPOSE) ps -q nextcloud):/tmp/sign/app.key
	docker cp $(CRT) $$($(COMPOSE) ps -q nextcloud):/tmp/sign/app.crt
	$(COMPOSE) exec -T nextcloud chown -R www-data:www-data /tmp/sign
	$(OCC) integrity:sign-app --privateKey=/tmp/sign/app.key --certificate=/tmp/sign/app.crt --path=/tmp/sign/$(APP_ID)
	docker cp $$($(COMPOSE) ps -q nextcloud):/tmp/sign/$(APP_ID)/appinfo/signature.json $(PACKAGE_DIR)/appinfo/signature.json
	$(COMPOSE) exec -T nextcloud rm -rf /tmp/sign
	$(MAKE) check-package
	$(PACK)
	$(MAKE) check-archive
	@echo "Archive signée : $(TARBALL)"

appstore-signature: check-archive ## Signature de release : make appstore-signature KEY=app.key CRT=app.crt (refuse une archive qui n'est pas saine)
	@[ -n "$(KEY)" ] && [ -n "$(CRT)" ] || { echo "usage : make appstore-signature KEY=<clé privée> CRT=<certificat du développeur>"; exit 1; }
	openssl dgst -sha512 -sign $(KEY) $(TARBALL) | openssl base64 > $(TARBALL).signature.b64
	@openssl x509 -in $(CRT) -noout -pubkey > $(BUILD_DIR)/.pub.pem && openssl base64 -d -in $(TARBALL).signature.b64 -out $(BUILD_DIR)/.release.sig \
		&& openssl dgst -sha512 -verify $(BUILD_DIR)/.pub.pem -signature $(BUILD_DIR)/.release.sig $(TARBALL); status=$$?; rm -f $(BUILD_DIR)/.pub.pem $(BUILD_DIR)/.release.sig; [ $$status -eq 0 ]
	@shasum -a 256 $(TARBALL)
	@cat $(TARBALL).signature.b64

test-install: check-archive ## Installe l'archive dans un Nextcloud propre comme l'App Store : make test-install KEY=… CRT=… [NC_VERSION=33]
	@[ -n "$(KEY)" ] && [ -n "$(CRT)" ] || { echo "usage : make test-install KEY=<clé privée> CRT=<certificat du développeur> [NC_VERSION=35]"; exit 1; }
	bash tools/test-install.sh $(TARBALL) $(KEY) $(CRT) $${NC_VERSION:-35}

clean: ## Supprime build/ et js/
	rm -rf $(BUILD_DIR) js
