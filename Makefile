# BillMySales for Dolibarr: development tasks. Every tool runs in a
# container (docker/Dockerfile) with the lowest PHP the plugin supports, so
# the host's PHP doesn't matter: make PHP_VERSION=8.5 check tests another one.

.PHONY: tools install lint lint-js fix analyse test check check-docs check-version check-tag release-notes build e2e e2e-clean clean

PLATFORM    = dolibarr
SLUG        = billmysales
PHP_VERSION ?= 7.4

VERSION := $(shell sed -n "s/^ *\$$this->version *= *'\(.*\)';\$$/\1/p" plugin/core/modules/modBillmysales.class.php)
ZIP      = dist/module_$(SLUG)-$(VERSION).zip

# The tag includes a checksum of docker/Dockerfile: a change builds a new image.
TOOLS_IMAGE = billmysales-plugin-tools:php$(PHP_VERSION)-$(shell cksum docker/Dockerfile | cut -d ' ' -f 1)
DOCKER_RUN  = docker run --rm -u "$$(id -u):$$(id -g)" -e HOME=/tmp -v "$(CURDIR):/app" -w /app
TOOLS       = $(DOCKER_RUN) -v billmysales-composer-cache:/tmp/composer $(TOOLS_IMAGE)
NODE_IMAGE ?= node:24-alpine
NPM         = $(DOCKER_RUN) -e npm_config_cache=/tmp/npm -v billmysales-npm-cache:/tmp/npm $(NODE_IMAGE) npm

tools:
	docker image inspect $(TOOLS_IMAGE) > /dev/null 2>&1 || \
		docker build --build-arg PHP_VERSION=$(PHP_VERSION) -t $(TOOLS_IMAGE) docker
	docker run --rm -v billmysales-composer-cache:/tmp/composer alpine chmod 0777 /tmp/composer

vendor/autoload.php: composer.json $(wildcard composer.lock) | tools
	$(TOOLS) composer install --no-interaction --no-progress
	touch $@

node_modules/.package-lock.json: package.json package-lock.json
	docker run --rm -v billmysales-npm-cache:/tmp/npm alpine chmod 0777 /tmp/npm
	$(NPM) ci --no-audit --no-fund
	touch $@

install: vendor/autoload.php node_modules/.package-lock.json

# Style (PHP CS Fixer), then the required docblocks (phpcs.xml). Dolibarr has
# no automated security ruleset to run here (see phpcs.xml); Dolistore's own
# validation runs when the module is submitted/deployed there.
lint: install check-docs lint-js
	$(TOOLS) composer phpcs
	$(TOOLS) composer phpcs-rules

# ESLint (eslint.config.js): recommended rules, JSDoc on every function and
# the style (@stylistic; make fix applies it).
lint-js: node_modules/.package-lock.json
	$(NPM) run lint

# Every PHP file starts with its docblock, after declare(strict_types=1).
check-docs:
	@for f in $$(find plugin tests -name '*.php'); do \
		awk 'NR == 1 && $$0 != "<?php" { exit 1 } /^declare\(strict_types=1\);$$/ { getline; getline; exit ($$0 == "/**") ? 0 : 1 }' "$$f" \
			|| { echo "$$f: no docblock after declare(strict_types=1);" >&2; exit 1; }; \
	done

fix: install
	$(TOOLS) composer phpcs-fix
	$(NPM) run lint-fix

analyse: install
	$(TOOLS) composer phpstan

test: install
	$(TOOLS) composer tests

check: lint analyse test check-version

# The version in the module's own metadata ($this->version) is the only
# source; everything else must match it: CHANGELOG.md (latest entry, with a
# date).
check-version:
	@test -n "$(VERSION)" || { echo "No \$$this->version in the module descriptor" >&2; exit 1; }
	@grep -m1 '^## \[' CHANGELOG.md | grep -q "^## \[$(VERSION)\] - [0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\}$$" || { echo "CHANGELOG.md: the latest entry is not $(VERSION) with a date" >&2; exit 1; }
	@echo "Version $(VERSION) OK"

# Used by the release workflow: the pushed tag must be v<version>.
check-tag:
	@test "$(TAG)" = "v$(VERSION)" || { echo "Tag $(TAG) doesn't match the plugin version $(VERSION)" >&2; exit 1; }

# The CHANGELOG entry of the version (the release's notes).
release-notes:
	@awk '/^## \[/{p = index($$0, "[$(VERSION)]") > 0; next} p' CHANGELOG.md

# The installable zip: plugin/ as the "billmysales" folder Dolibarr's module
# deployment expects (Dolistore's own naming convention: module_<name>-<version>.zip
# containing a <name>/ folder, not <name>-<version>/), the production
# dependencies (none today: the plugin has no runtime Composer packages) and
# no development files.
build: check-version
	rm -rf dist/build $(ZIP)
	mkdir -p dist/build
	cp -R plugin dist/build/$(SLUG)
	$(MAKE) tools
	$(TOOLS) sh -c 'cd dist/build && zip -rq -X ../$(notdir $(ZIP)) $(SLUG)'
	rm -rf dist/build
	@echo "Built $(ZIP)"

# End-to-end tests (tests/e2e/run.sh): the module in the Dolibarr Docker
# stack, an invoice through its real lifecycle, deliveries to a local
# receiver. The stack's development ports and 8099 must be free.
e2e: install build
	TOOLS_IMAGE=$(TOOLS_IMAGE) tests/e2e/run.sh

# Removes a stack kept with E2E_KEEP=1 and the results (var/e2e; make clean
# removes them too).
e2e-clean:
	-[ -f var/e2e/stack/compose.yaml ] && (cd var/e2e/stack && docker compose down -v --remove-orphans)
	-docker rm -f $(PLATFORM)-e2e-receiver
	rm -rf var/e2e

clean:
	rm -rf dist var .phpunit.cache vendor node_modules
