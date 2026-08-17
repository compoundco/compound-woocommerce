variable "region" {
  description = "AWS region to deploy the test store into."
  type        = string
  default     = "us-east-1"
}

variable "aws_account_id" {
  description = "Account this store must be deployed into. Terraform refuses to run if the resolved credentials belong to a different account. Leave empty to skip the check."
  type        = string
  default     = ""
}

variable "aws_profile" {
  description = "Named AWS CLI profile to use. Leave empty to fall back to the default credential chain (AWS_PROFILE, environment, instance role)."
  type        = string
  default     = ""
}

variable "name" {
  description = "Name prefix for every resource. Change it to run more than one store side by side."
  type        = string
  default     = "compound-store"
}

variable "instance_type" {
  description = "EC2 instance type. t3.small (2 GB) is enough for WordPress + MariaDB + Caddy; bump to t3.medium if the store feels slow."
  type        = string
  default     = "t3.small"
}

variable "root_volume_size" {
  description = "Root EBS volume size in GB. Holds the OS, docker images, WordPress uploads, and the MariaDB data directory."
  type        = number
  default     = 30
}

# --- Access -------------------------------------------------------------------

variable "allowed_https_cidrs" {
  description = <<-EOT
    CIDRs allowed to reach the store on 443. Defaults to the whole internet so the
    site is genuinely "live". Port 80 is always open to 0.0.0.0/0 regardless: Let's
    Encrypt solves the HTTP-01 challenge from its own (unpublished) IP ranges, so
    narrowing 80 would break certificate issuance and renewal.
  EOT
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "acme_email" {
  description = "Contact email for Let's Encrypt (expiry notices). Optional - leave empty to issue anonymously."
  type        = string
  default     = ""
}

variable "site_domain" {
  description = "Custom domain for the store (e.g. chefspeps.com). When set, the store is served at https://<site_domain> with its own Let's Encrypt cert instead of the <dashed-ip>.sslip.io fallback. DNS for this domain MUST point an A record at the instance's Elastic IP before apply, so Caddy can complete the ACME (HTTP-01) challenge."
  type        = string
  default     = ""
}

variable "basic_auth_user" {
  description = "When set, Caddy password-protects the whole store (a staging gate) with HTTP basic auth for this username. The Compound webhook path is exempted so fulfillment callbacks still reach the store. Empty = no basic auth."
  type        = string
  default     = ""
}

variable "basic_auth_hash" {
  description = "bcrypt hash of the basic-auth password (generate with `caddy hash-password` or `htpasswd -bnBC 10 '' <pass>`). Stored as a hash, never plaintext. Required when basic_auth_user is set."
  type        = string
  default     = ""
}

# --- WordPress ----------------------------------------------------------------

variable "site_title" {
  description = "WordPress site title."
  type        = string
  default     = "Acme Peptides"
}

variable "wp_admin_user" {
  description = "WordPress admin username."
  type        = string
  default     = "admin"
}

variable "wp_admin_email" {
  description = "WordPress admin email."
  type        = string
  default     = "admin@example.com"
}

variable "wp_admin_password" {
  description = "WordPress admin password. Leave empty to have Terraform generate one (read it back with `terraform output -raw wp_admin_password`)."
  type        = string
  default     = ""
  sensitive   = true
}

# --- Compound gateway ---------------------------------------------------------

variable "compound_api_key" {
  description = <<-EOT
    Compound secret API key (sk_...) with orders:write + charges:write. Server-side
    only; it is stored as an SSM SecureString and never reaches the browser. Leave
    empty to provision the store with the gateway unconfigured and set the key later
    in WooCommerce -> Settings -> Payments -> Compound.
  EOT
  type        = string
  default     = ""
  sensitive   = true
}

variable "compound_environment" {
  description = "Compound environment the gateway runs against: sandbox or live."
  type        = string
  default     = "sandbox"

  validation {
    condition     = contains(["sandbox", "live"], var.compound_environment)
    error_message = "compound_environment must be either \"sandbox\" or \"live\"."
  }
}

variable "compound_orders_url" {
  description = "Base URL of the Compound orders API, as reachable from AWS. The local default (host.docker.internal:4003) will not resolve here."
  type        = string
  default     = "https://api.compound.dev"
}

variable "compound_payments_url" {
  description = "Base URL of the Compound payments API, as reachable from AWS."
  type        = string
  default     = "https://api.compound.dev"
}

variable "compound_webhook_secret" {
  description = "Shared secret used to HMAC-verify inbound Compound webhooks at /wp-json/compound/v1/webhook. Leave empty to generate one."
  type        = string
  default     = ""
  sensitive   = true
}

variable "seed_products" {
  description = "Seed the demo storefront products (SKUs matched to the Compound catalog) on first boot."
  type        = bool
  default     = true
}
