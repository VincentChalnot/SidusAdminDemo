DC ?= docker compose -f compose.yaml

.PHONY: help
help: ## This help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(TARGETS) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

-include .env

.PHONY: install
install: start ## Run docker instance and launch composer install
	$(DC) exec php composer install

.PHONY: start
start: ## Start docker
	$(DC) up --build -d

.PHONY: stop
stop: ## Stop and destroy docker images
	$(DC) down --remove-orphans

.PHONY: shell
shell: start ## Deploy to staging
	$(DC) exec php zsh -c "export COLUMNS=`tput cols`; export LINES=`tput lines`; exec zsh"

.PHONY: docker-status
docker-status: ## Diplay containers status
	$(DC) ps

.PHONY: cc
cc: ## Clear Symfony cache
	rm -rf var/cache/*
