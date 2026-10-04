# Ogami developer commands. Everything runs inside Docker (ADR 0008).
# Run `make` or `make help` to list targets.
#
# Adding targets: give each target a `## description` comment so `help` lists it,
# and group related targets under a `##@ Section` header (e.g. `##@ Frontend`).
# Aggregate targets (`test`, `qa`) depend on per-area targets (`backend-test`,
# `backend-qa`, later `frontend-test`, `frontend-qa`), so a new area only needs
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

##@ Help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "Usage: make \033[36m<target>\033[0m [ARGS=\"...\"]\n"} \
		/^[a-zA-Z0-9_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } \
		/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

##@ Docker

.PHONY: build
build: ## Build the container images
	$(DOCKER_COMPOSE) build --pull

.PHONY: up
up: ## Start every service in the background and wait until healthy
	$(DOCKER_COMPOSE) up --detach --wait

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

##@ Quality

.PHONY: test
test: backend-test ## Run every test suite

.PHONY: qa
qa: backend-qa ## Run every static check
