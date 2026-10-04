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
# Use `make <target> TTY=-T` in CI or when piping output (no pseudo-TTY).
TTY ?=
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

.PHONY: backend-test
backend-test: ## Run backend tests
	@echo "No backend tests yet."

.PHONY: backend-qa
backend-qa: ## Run backend static analysis and style checks
	@echo "No backend QA yet."

##@ Frontend

.PHONY: node-sh
node-sh: ## Open a shell in the node container
	$(NODE_EXEC) bash

##@ Quality

.PHONY: test
test: backend-test ## Run every test suite

.PHONY: qa
qa: backend-qa ## Run every static check
