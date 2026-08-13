# Secrets live in SSM Parameter Store as SecureStrings, not in the S3 deploy
# bundle. The instance role can read only this prefix; user-data pulls them at boot
# and writes /opt/compound-store/.env (root-owned, 0600).

locals {
  ssm_prefix = "/${var.name}"

  # The sslip.io wildcard resolver maps <dashed-ip>.sslip.io -> that IP, so the
  # store gets a real hostname (and therefore a real Let's Encrypt cert) with no
  # domain purchase and no Route 53 zone.
  hostname = "${replace(aws_eip.this.public_ip, ".", "-")}.sslip.io"
  site_url = "https://${local.hostname}"

  wp_admin_password       = var.wp_admin_password != "" ? var.wp_admin_password : random_password.wp_admin.result
  compound_webhook_secret = var.compound_webhook_secret != "" ? var.compound_webhook_secret : random_password.webhook.result
}

resource "random_password" "db" {
  length  = 32
  special = false # MariaDB env vars and shell interpolation both stay simple
}

resource "random_password" "wp_admin" {
  length           = 24
  special          = true
  override_special = "!@#%^*_-+="
}

resource "random_password" "webhook" {
  length  = 40
  special = false
}

resource "aws_ssm_parameter" "db_password" {
  name  = "${local.ssm_prefix}/db_password"
  type  = "SecureString"
  value = random_password.db.result
}

resource "aws_ssm_parameter" "wp_admin_password" {
  name  = "${local.ssm_prefix}/wp_admin_password"
  type  = "SecureString"
  value = local.wp_admin_password
}

resource "aws_ssm_parameter" "compound_api_key" {
  name = "${local.ssm_prefix}/compound_api_key"
  type = "SecureString"
  # Parameter Store rejects an empty value, so an unset key is stored as a
  # sentinel the provisioner treats as "leave the gateway unconfigured".
  value = var.compound_api_key != "" ? var.compound_api_key : "unset"
}

resource "aws_ssm_parameter" "compound_webhook_secret" {
  name  = "${local.ssm_prefix}/compound_webhook_secret"
  type  = "SecureString"
  value = local.compound_webhook_secret
}
