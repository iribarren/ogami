# Ogami developer commands. Everything runs inside Docker (ADR 0008).
# Run `make` or `make help` to list targets.
#
# Adding targets: give each target a `## description` comment so `help` lists it,
# and group related targets under a `##@ Section` header (e.g. `##@ Frontend`).
# Aggregate targets (`test`, `qa`) depend on per-area targets (`backend-test`,
# `backend-qa`, `frontend-test`, `frontend-qa`), so a new area only needs
# to add its own target and append it to the aggregate's prerequisites.

.DEFAULT_GOAL := help
SHELL := /bin/bash

# Files created in the containers belong to the host user.
export UID := $(shell id -u)
export GID := $(shell id -g)

DOCKER_COMPOSE ?= docker compose
# No pseudo-TTY when stdin is not a terminal (CI, agents, pipes); override with TTY=.
TTY ?= $(shell [ -t 0 ] || echo -T)
PHP_EXEC := $(DOCKER_COMPOSE) exec $(TTY) php
NODE_EXEC := $(DOCKER_COMPOSE) exec $(TTY) node

# Pass-through arguments: make composer ARGS="require foo/bar"
ARGS ?=
# Limit build/up to some services (CI jobs): make build up SERVICES="php"
SERVICES ?=

##@ Help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "Usage: make \033[36m<target>\033[0m [ARGS=\"...\"]\n"} \
		/^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } \
		/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

##@ Docker

.PHONY: build
build: ## Build the container images (SERVICES="php" to limit)
	$(DOCKER_COMPOSE) build --pull $(SERVICES)

.PHONY: up
up: ## Start the services in the background, wait until healthy, install dependencies (SERVICES="php" to limit)
	$(DOCKER_COMPOSE) up --detach --wait $(SERVICES)
	@# The node service installs its own dependencies on start; php needs Composer.
	@if $(DOCKER_COMPOSE) ps --services --status running | grep -qx php; then \
		$(MAKE) --no-print-directory --silent backend-install; \
	fi

.PHONY: down
down: ## Stop and remove the containers (keeps volumes)
	$(DOCKER_COMPOSE) down --remove-orphans

.PHONY: ps
ps: ## Show service status
	$(DOCKER_COMPOSE) ps

.PHONY: logs
logs: ## Follow service logs (ARGS="php" to filter)
	$(DOCKER_COMPOSE) logs --follow $(ARGS)

##@ Backend

.PHONY: sh
sh: ## Open a shell in the php container
	$(PHP_EXEC) bash

.PHONY: composer
composer: ## Run Composer (ARGS="install")
	$(PHP_EXEC) composer $(ARGS)

.PHONY: console
console: ## Run the Symfony console (ARGS="debug:router")
	$(PHP_EXEC) php bin/console $(ARGS)

# Installs Composer dependencies on a fresh clone (or after composer.lock changes).
backend/vendor/autoload.php: backend/composer.lock
	$(PHP_EXEC) composer install --no-interaction
	@touch $@

.PHONY: backend-install
backend-install: backend/vendor/autoload.php ## Install backend Composer dependencies

.PHONY: backend-test
backend-test: backend-install ## Run backend tests: PHPUnit (unit + integration) and Behat
	$(PHP_EXEC) php bin/console doctrine:database:create --if-not-exists --env=test --quiet
	$(PHP_EXEC) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test --quiet
	$(PHP_EXEC) vendor/bin/phpunit
	$(PHP_EXEC) vendor/bin/behat --strict

.PHONY: backend-qa
backend-qa: backend-install ## Run backend QA: PHPStan + PHPat, PHP-CS-Fixer and Rector (dry runs)
	$(PHP_EXEC) php bin/console cache:warmup --env=dev --quiet
	$(PHP_EXEC) vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	$(PHP_EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(PHP_EXEC) vendor/bin/rector process --dry-run --no-progress-bar

.PHONY: backend-fix
backend-fix: backend-install ## Apply Rector and PHP-CS-Fixer changes to the backend
	$(PHP_EXEC) php bin/console cache:warmup --env=dev --quiet
	$(PHP_EXEC) vendor/bin/rector process --no-progress-bar
	$(PHP_EXEC) vendor/bin/php-cs-fixer fix

##@ Frontend

.PHONY: node-sh
node-sh: ## Open a shell in the node container
	$(NODE_EXEC) bash

.PHONY: pnpm
pnpm: ## Run pnpm in the frontend (ARGS="add zod")
	$(NODE_EXEC) pnpm $(ARGS)

# Installs pnpm dependencies on a fresh clone (or after pnpm-lock.yaml changes).
# The node service also runs `pnpm install` when it starts.
frontend/node_modules/.modules.yaml: frontend/pnpm-lock.yaml
	$(NODE_EXEC) pnpm install --frozen-lockfile
	@touch $@

.PHONY: frontend-install
frontend-install: frontend/node_modules/.modules.yaml ## Install frontend pnpm dependencies

.PHONY: frontend-qa
frontend-qa: frontend-install ## Run frontend QA: ESLint, Prettier (check) and TypeScript
	$(NODE_EXEC) pnpm lint
	$(NODE_EXEC) pnpm format:check
	$(NODE_EXEC) pnpm typecheck

.PHONY: frontend-fix
frontend-fix: frontend-install ## Apply ESLint fixes and Prettier formatting to the frontend
	$(NODE_EXEC) pnpm lint:fix
	$(NODE_EXEC) pnpm format

.PHONY: frontend-test
frontend-test: frontend-install ## Run frontend unit and component tests (Vitest)
	$(NODE_EXEC) pnpm test

.PHONY: frontend-build
frontend-build: frontend-install ## Build the SPA for production into frontend/dist
	$(NODE_EXEC) pnpm build

.PHONY: storybook
storybook: frontend-install ## Run Storybook at http://localhost:6006 (Ctrl+C to stop)
	$(NODE_EXEC) pnpm storybook

.PHONY: storybook-build
storybook-build: frontend-install ## Build the static Storybook into frontend/storybook-static
	$(NODE_EXEC) pnpm build-storybook

# Password of the seeded e2e users; the playwright service reads it from the environment.
export E2E_PASSWORD ?= e2e-password-123

.PHONY: e2e-seed
e2e-seed: backend-install ## Migrate the dev database and create the e2e users (idempotent)
	$(PHP_EXEC) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PHP_EXEC) php bin/console app:user:create e2e-player@example.test --role=SOLO_PLAYER --password="$(E2E_PASSWORD)" --if-missing --no-interaction
	$(PHP_EXEC) php bin/console app:user:create e2e-manager@example.test --role=GAME_MANAGER --password="$(E2E_PASSWORD)" --if-missing --no-interaction

.PHONY: e2e
e2e: frontend-install e2e-seed ## Seed the e2e users, then run the Playwright tests against the running stack (ARGS="--grep smoke")
	$(DOCKER_COMPOSE) run --rm $(TTY) playwright node_modules/.bin/playwright test $(ARGS)

##@ API contract (ADR 0005)

API_DIR := frontend/src/shared/api

.PHONY: api-spec
api-spec: backend-install ## Export the backend OpenAPI spec to frontend/src/shared/api/openapi.json
	$(PHP_EXEC) php bin/console nelmio:apidoc:dump --format=json > $(API_DIR)/openapi.json

.PHONY: api-client
api-client: frontend-install ## Generate the TypeScript API types from openapi.json (schema.d.ts)
	$(NODE_EXEC) pnpm api:client

.PHONY: api
api: api-spec api-client ## Export the spec and regenerate the TypeScript API types

.PHONY: api-check
api-check: backend-install frontend-install ## Fail when the committed spec or API types are stale
	@$(PHP_EXEC) php bin/console nelmio:apidoc:dump --format=json | diff -u $(API_DIR)/openapi.json - \
		|| { echo "$(API_DIR)/openapi.json is stale: run 'make api' and commit the result." >&2; exit 1; }
	@$(NODE_EXEC) sh -c 'pnpm exec openapi-typescript src/shared/api/openapi.json --output /tmp/schema.d.ts \
		&& diff -u src/shared/api/schema.d.ts /tmp/schema.d.ts' \
		|| { echo "$(API_DIR)/schema.d.ts is stale: run 'make api-client' and commit the result." >&2; exit 1; }
	@echo "API spec and types are up to date."

##@ Quality

.PHONY: test
test: backend-test frontend-test ## Run every test suite

.PHONY: qa
qa: backend-qa frontend-qa api-check ## Run every static check
