# Compound for WooCommerce - test-store convenience targets.
# `make dev` brings up the demo store; `make seed` populates it. Both need the
# Compound stack running + seeded (in the compound repo: make dev && make seed).

.DEFAULT_GOAL := help
COMPOSE = docker compose -f docker-compose.test.yml

.PHONY: help
help: ## List available commands
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

.PHONY: dev
dev: ## Bring up + provision the WordPress + WooCommerce demo store (:8888)
	@[ -f woocommerce.zip ] || curl -sSL -o woocommerce.zip https://downloads.wordpress.org/plugin/woocommerce.zip
	@echo "  Bringing up the WooCommerce store (first run pulls images + installs WordPress)..."
	@$(COMPOSE) up -d >/dev/null
	@until $(COMPOSE) exec -T cli wp core version >/dev/null 2>&1; do sleep 3; done
	@if ! $(COMPOSE) exec -T cli wp core is-installed >/dev/null 2>&1; then \
	  $(COMPOSE) exec -T cli wp core install --url=http://localhost:8888 --title="Acme Peptides" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email >/dev/null; \
	  $(COMPOSE) exec -T cli wp core update >/dev/null; \
	fi
	@$(COMPOSE) exec -T cli wp plugin is-active woocommerce >/dev/null 2>&1 || $(COMPOSE) exec -T cli wp plugin install /tmp/woocommerce.zip --activate >/dev/null
	@$(COMPOSE) exec -T cli wp plugin activate compound-woocommerce >/dev/null 2>&1 || true
	@echo "  Store: http://localhost:8888   (run 'make seed' to populate it)"

.PHONY: seed
seed: ## Seed the store: products + theme + gateway (needs the compound repo seeded too)
	bash bin/setup-test-store.sh

.PHONY: down
down: ## Stop the test store
	$(COMPOSE) down
