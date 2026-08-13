#!/usr/bin/env bash
# Push the current plugin code + store config to the running AWS test store.
#
# `terraform apply` refreshes the deploy bundle in S3; this then tells the
# instance to pull it and re-run provisioning. The instance is never replaced, so
# the database, uploads, and TLS certificate survive.
#
#   make aws-deploy
set -euo pipefail
cd "$(dirname "$0")/.."

TF_DIR=terraform

if [ ! -f "$TF_DIR/terraform.tfstate" ] && [ ! -d "$TF_DIR/.terraform" ]; then
  echo "No Terraform state yet. Stand the store up first:  make aws-up"
  exit 1
fi

echo "==> terraform apply (uploads the plugin + config bundle to S3)"
terraform -chdir="$TF_DIR" apply -input=false -auto-approve

INSTANCE="$(terraform -chdir="$TF_DIR" output -raw instance_id)"
REGION="$(terraform -chdir="$TF_DIR" output -raw region)"
SITE="$(terraform -chdir="$TF_DIR" output -raw site_url)"

# Terraform may be pinned to a named profile; the CLI calls below have to act on
# that same account, not on whatever the ambient credentials happen to be.
PROFILE="$(terraform -chdir="$TF_DIR" output -raw aws_profile 2>/dev/null || true)"
[ -n "$PROFILE" ] && export AWS_PROFILE="$PROFILE"

echo "==> running the deploy on $INSTANCE"
CMD_ID="$(aws ssm send-command \
  --region "$REGION" \
  --instance-ids "$INSTANCE" \
  --document-name AWS-RunShellScript \
  --comment "compound-store deploy" \
  --timeout-seconds 900 \
  --parameters 'commands=["/usr/local/bin/compound-store-deploy"]' \
  --query Command.CommandId --output text)"

# send-command is async; poll until the invocation leaves the in-flight states.
STATUS=Pending
for _ in $(seq 1 120); do
  sleep 5
  STATUS="$(aws ssm get-command-invocation --region "$REGION" \
    --command-id "$CMD_ID" --instance-id "$INSTANCE" \
    --query Status --output text 2>/dev/null || echo Pending)"
  case "$STATUS" in
    Pending | InProgress | Delayed) printf '.' ;;
    *) break ;;
  esac
done
echo ""

aws ssm get-command-invocation --region "$REGION" \
  --command-id "$CMD_ID" --instance-id "$INSTANCE" \
  --query StandardOutputContent --output text | tail -30

if [ "$STATUS" != "Success" ]; then
  echo ""
  echo "Deploy finished with status: $STATUS"
  aws ssm get-command-invocation --region "$REGION" \
    --command-id "$CMD_ID" --instance-id "$INSTANCE" \
    --query StandardErrorContent --output text | tail -30
  exit 1
fi

echo ""
echo "Deployed. $SITE"
