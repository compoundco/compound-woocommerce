#!/usr/bin/env bash
# Push the current plugin code + store config to the running AWS store.
#
# `terraform apply` refreshes the deploy bundle in S3; this then tells the
# instance to pull it and re-run provisioning. The instance is never replaced, so
# the database, uploads, and TLS certificate survive.
#
#   make aws-deploy        # staging store, default workspace
#   make aws-deploy-prod   # production store, prod workspace
#
# There are two stores sharing this config, separated by Terraform workspace. The
# workspace and the var-file have to move together: applying prod.tfvars against the
# default workspace would rewrite the staging store into the production one. Both are
# passed explicitly rather than inferred, so a wrong pair is a visible mistake here
# instead of a silent one in AWS.
set -euo pipefail
cd "$(dirname "$0")/.."

TF_DIR=terraform
WORKSPACE=default
VAR_FILE=terraform.tfvars

while [ $# -gt 0 ]; do
  case "$1" in
    --workspace) WORKSPACE="$2"; shift 2 ;;
    --var-file) VAR_FILE="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

if [ ! -d "$TF_DIR/.terraform" ]; then
  echo "No Terraform state yet. Stand the store up first:  make aws-up"
  exit 1
fi

echo "==> workspace $WORKSPACE, var-file $VAR_FILE"
terraform -chdir="$TF_DIR" workspace select "$WORKSPACE"

# Guard the pair. The store's `name` is what every unique resource derives from, so a
# mismatched workspace/var-file combination shows up here as the state's name not
# matching the file's - before apply rewrites one store into the other.
#
# POSIX classes, not \s: BSD sed (macOS, where this is usually run) does not understand
# \s and silently leaves the line unsubstituted, so WANT_NAME came out as the whole
# `name = "compound-store"` line and the guard refused a pair that actually matched.
WANT_NAME="$(grep -E '^[[:space:]]*name[[:space:]]*=' "$TF_DIR/$VAR_FILE" | head -1 | sed -E 's/^[^=]*=[[:space:]]*"([^"]*)".*/\1/')"
HAVE_NAME="$(terraform -chdir="$TF_DIR" output -raw store_name 2>/dev/null || echo "")"
if [ -n "$HAVE_NAME" ] && [ -n "$WANT_NAME" ] && [ "$HAVE_NAME" != "$WANT_NAME" ]; then
  echo "REFUSING: workspace '$WORKSPACE' holds store '$HAVE_NAME', but $VAR_FILE says '$WANT_NAME'." >&2
  echo "Applying this pair would rewrite one store into the other." >&2
  exit 1
fi

echo "==> terraform apply (uploads the plugin + config bundle to S3)"
terraform -chdir="$TF_DIR" apply -input=false -auto-approve -var-file="$VAR_FILE"

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
