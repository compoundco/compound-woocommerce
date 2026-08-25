# Compound for WooCommerce - test-store convenience targets.
# `make dev` brings up the demo store; `make seed` populates it. Both need the
# Compound stack running + seeded (in the compound repo: make dev && make seed).
# The aws-* targets stand the same store up on AWS as a live site (see terraform/).

.DEFAULT_GOAL := help
COMPOSE = docker compose -f docker-compose.test.yml
TF = terraform -chdir=terraform

# Two stores, two Terraform workspaces over the same config, each with its own state:
#   default -> staging, stg.chefspeps.com, wired to Compound staging (terraform.tfvars)
#   prod    -> production, chefspeps.com, wired to Compound production (prod.tfvars)
# The workspace and the var-file must always move together - applying prod.tfvars in the
# default workspace would rewrite the staging store into the production one. The *-prod
# targets below bind the pair, so never call terraform directly for the prod store.
TF_PROD = terraform -chdir=terraform
PROD_WS = prod

.PHONY: help
help: ## List available commands
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

.PHONY: dev
dev: ## Bring up + provision the WordPress + WooCommerce demo store (:8888)
	@[ -f woocommerce.zip ] || curl -sSL -o woocommerce.zip https://downloads.wordpress.org/plugin/woocommerce.zip
	@echo "  Bringing up the WooCommerce store (first run pulls images + installs WordPress)..."
	@$(COMPOSE) up -d >/dev/null
	@until $(COMPOSE) exec -T cli wp core version >/dev/null 2>&1; do sleep 3; done
	@if ! $(COMPOSE) exec -T cli wp core is-installed >/dev/null 2>&1; then \
	  $(COMPOSE) exec -T cli wp core install --url=http://localhost:8888 --title="Chefs Peps" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email >/dev/null; \
	  $(COMPOSE) exec -T cli wp core update >/dev/null; \
	fi
	@$(COMPOSE) exec -T cli wp plugin is-active woocommerce >/dev/null 2>&1 || $(COMPOSE) exec -T cli wp plugin install /tmp/woocommerce.zip --activate >/dev/null
	@$(COMPOSE) exec -T cli wp plugin activate compound-woocommerce >/dev/null 2>&1 || true
	@echo "  Store: http://localhost:8888   (run 'make seed' to populate it)"

.PHONY: seed
seed: ## Seed the store: products + theme + gateway + webhook endpoint (needs the compound repo seeded)
	bash bin/setup-test-store.sh

.PHONY: build
build: ## Build compound-woocommerce.zip from the current working tree (.build/), to test before pushing
	bash bin/build-zip.sh

.PHONY: sim-shipped sim-delivered sim-exception
sim-shipped: ## Simulate the pharmacy shipping an order (ORDER=<compound_order_id>)
	bash bin/sim-fulfillment.sh shipped $(ORDER)
sim-delivered: ## Simulate delivery (ORDER=<compound_order_id>)
	bash bin/sim-fulfillment.sh delivered $(ORDER)
sim-exception: ## Simulate a cold-chain excursion (ORDER=<compound_order_id>)
	bash bin/sim-fulfillment.sh exception $(ORDER)

.PHONY: smoke
smoke: ## Headless checkout (METHOD=card|ach|crypto); prints WC status + Compound order id
	bash bin/smoke-test.sh

.PHONY: down
down: ## Stop the test store
	$(COMPOSE) down

.PHONY: reset
reset: ## Wipe the test store (WordPress + db volumes); destructive, local only
	@if [ "$(CONFIRM)" != "yes" ]; then \
	  printf "Type 'yes' to wipe the WooCommerce store data: "; read ans; \
	  [ "$$ans" = "yes" ] || { echo "Aborted - nothing was deleted."; exit 1; }; \
	fi
	$(COMPOSE) down -v
	@echo "Store wiped. Run 'make dev' to reprovision, then 'make seed'."

# --- AWS: the same store, live on the internet (terraform/) --------------------

.PHONY: aws-up
aws-up: ## Stand the store up on AWS (EC2 + Caddy TLS); ~4 min, then prints the URL
	@[ -f terraform/terraform.tfvars ] || { \
	  echo "terraform/terraform.tfvars not found."; \
	  echo "  cp terraform/terraform.tfvars.example terraform/terraform.tfvars"; \
	  echo "  then set compound_api_key + the Compound API URLs, and re-run."; \
	  exit 1; }
	$(TF) init -input=false
	$(TF) apply -input=false
	@echo ""
	@echo "  Store:  $$($(TF) output -raw site_url)"
	@echo "  Admin:  $$($(TF) output -raw admin_url)  (user '$$($(TF) output -raw wp_admin_user)')"
	@echo "  Pass:   make aws-creds"
	@echo ""
	@echo "  First boot installs WordPress + WooCommerce and issues a TLS certificate;"
	@echo "  give it 2-3 min after apply returns. Watch it: make aws-logs"

.PHONY: aws-deploy
aws-deploy: ## Push the current plugin code + config to the running AWS store
	bash bin/aws-deploy.sh

.PHONY: aws-url
aws-url: ## Print the live store's URLs
	@echo "Store:    $$($(TF) output -raw site_url)"
	@echo "Admin:    $$($(TF) output -raw admin_url)"
	@echo "Webhook:  $$($(TF) output -raw webhook_url)"

.PHONY: aws-creds
aws-creds: ## Print the WordPress admin login + the webhook signing secret
	@echo "user:            $$($(TF) output -raw wp_admin_user)"
	@echo "password:        $$($(TF) output -raw wp_admin_password)"
	@echo "webhook secret:  $$($(TF) output -raw compound_webhook_secret)"

.PHONY: aws-ssh
aws-ssh: ## Shell onto the instance via SSM Session Manager (no open SSH port)
	AWS_PROFILE=$$($(TF) output -raw aws_profile) \
	  aws ssm start-session --region $$($(TF) output -raw region) --target $$($(TF) output -raw instance_id)

.PHONY: aws-logs
aws-logs: ## Tail the first-boot bootstrap log on the instance
	AWS_PROFILE=$$($(TF) output -raw aws_profile) \
	  aws ssm start-session --region $$($(TF) output -raw region) \
	  --target $$($(TF) output -raw instance_id) \
	  --document-name AWS-StartInteractiveCommand \
	  --parameters command='tail -n 200 -f /var/log/compound-store-bootstrap.log'

.PHONY: aws-up-prod
aws-up-prod: ## Stand up / update the PRODUCTION store at chefspeps.com (separate instance)
	@[ -f terraform/prod.tfvars ] || { echo "terraform/prod.tfvars not found."; exit 1; }
	$(TF_PROD) init -input=false
	$(TF_PROD) workspace select -or-create $(PROD_WS)
	$(TF_PROD) apply -input=false -var-file=prod.tfvars
	@echo ""
	@echo "  Store:  $$($(TF_PROD) output -raw site_url)"
	@echo "  IP:     $$($(TF_PROD) output -raw public_ip)   <- point chefspeps.com + www here"
	@echo ""
	@echo "  Caddy cannot issue a certificate until those A records resolve to that IP."

.PHONY: aws-deploy-prod
aws-deploy-prod: ## Push the current plugin code + config to the PRODUCTION store
	$(TF_PROD) workspace select $(PROD_WS)
	bash bin/aws-deploy.sh --workspace $(PROD_WS) --var-file prod.tfvars

.PHONY: aws-url-prod
aws-url-prod: ## Print the PRODUCTION store's URLs + Elastic IP
	@$(TF_PROD) workspace select $(PROD_WS) >/dev/null
	@echo "Store:    $$($(TF_PROD) output -raw site_url)"
	@echo "Admin:    $$($(TF_PROD) output -raw admin_url)"
	@echo "Webhook:  $$($(TF_PROD) output -raw webhook_url)"
	@echo "IP:       $$($(TF_PROD) output -raw public_ip)"

.PHONY: aws-creds-prod
aws-creds-prod: ## Print the PRODUCTION store's WordPress login + webhook secret
	@$(TF_PROD) workspace select $(PROD_WS) >/dev/null
	@echo "user:            $$($(TF_PROD) output -raw wp_admin_user)"
	@echo "password:        $$($(TF_PROD) output -raw wp_admin_password)"
	@echo "webhook secret:  $$($(TF_PROD) output -raw compound_webhook_secret)"

.PHONY: aws-down
aws-down: ## Tear the AWS store down (destroys the instance and all its data)
	$(TF) destroy
