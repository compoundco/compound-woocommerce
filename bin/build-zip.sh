#!/usr/bin/env bash
# Builds the installable plugin zip from the CURRENT working tree - including
# uncommitted changes - so you can test a change on a real WooCommerce site before
# pushing or tagging a release. Packages exactly what release.yml and chefspeps'
# own terraform deploy package: compound-gateway.php + includes/ + assets/, no
# vendor/ (composer's require-dev tools are lint-only, nothing runtime autoloads).
set -euo pipefail
cd "$(dirname "$0")/.."

OUT_DIR="${1:-.build}"
OUT_ZIP="$OUT_DIR/compound-woocommerce.zip"

rm -rf "$OUT_DIR/compound-woocommerce" "$OUT_ZIP"
mkdir -p "$OUT_DIR/compound-woocommerce"
cp compound-gateway.php "$OUT_DIR/compound-woocommerce/"
cp -R includes "$OUT_DIR/compound-woocommerce/"
cp -R assets "$OUT_DIR/compound-woocommerce/"

(cd "$OUT_DIR" && zip -r -q compound-woocommerce.zip compound-woocommerce)
rm -rf "$OUT_DIR/compound-woocommerce"

echo "Built: $OUT_ZIP"
echo "Upload it in wp-admin: Plugins -> Add New -> Upload Plugin. If compound-woocommerce is"
echo "already installed, WordPress will offer to replace it - confirm that; no need to"
echo "deactivate or delete it first."
