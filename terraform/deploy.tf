# The deploy bundle: the plugin source plus the three files that define how the
# store runs. Keeping them in S3 (rather than inlining everything in user-data)
# means `terraform apply` + `make aws-deploy` can push a code or config change to a
# running store without recreating the instance and losing its data.

resource "random_id" "suffix" {
  byte_length = 4
}

resource "aws_s3_bucket" "deploy" {
  bucket        = "${var.name}-deploy-${random_id.suffix.hex}"
  force_destroy = true
}

resource "aws_s3_bucket_public_access_block" "deploy" {
  bucket                  = aws_s3_bucket.deploy.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "deploy" {
  bucket = aws_s3_bucket.deploy.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

locals {
  repo_root = abspath("${path.module}/..")

  # Only the plugin's own files - never node_modules, .git, or the 20 MB
  # woocommerce.zip (WooCommerce is fetched from wordpress.org on the instance).
  # assets/** carries the block-checkout script (assets/js/blocks.js); without it
  # the block checkout shows "no payment methods available".
  plugin_files = concat(
    ["compound-gateway.php"],
    sort(tolist(fileset(abspath("${path.module}/.."), "includes/**/*.php"))),
    sort(tolist(fileset(abspath("${path.module}/.."), "assets/**/*"))),
  )
}

data "archive_file" "plugin" {
  type        = "zip"
  output_path = "${path.module}/.build/compound-woocommerce.zip"

  dynamic "source" {
    for_each = local.plugin_files

    content {
      content  = file("${local.repo_root}/${source.value}")
      filename = "compound-woocommerce/${source.value}"
    }
  }
}

resource "aws_s3_object" "plugin" {
  bucket = aws_s3_bucket.deploy.id
  key    = "compound-woocommerce.zip"
  source = data.archive_file.plugin.output_path
  etag   = data.archive_file.plugin.output_md5
}

resource "aws_s3_object" "compose" {
  bucket       = aws_s3_bucket.deploy.id
  key          = "docker-compose.yml"
  content_type = "text/yaml"

  content = templatefile("${path.module}/templates/docker-compose.yml.tftpl", {
    site_url = local.site_url
  })
}

resource "aws_s3_object" "caddyfile" {
  bucket = aws_s3_bucket.deploy.id
  key    = "Caddyfile"

  content = templatefile("${path.module}/templates/Caddyfile.tftpl", {
    hostname         = local.hostname
    redirect_domains = var.redirect_domains
    acme_email       = var.acme_email
    basic_auth_user  = var.basic_auth_user
    basic_auth_hash  = var.basic_auth_hash
  })
}

resource "aws_s3_object" "deploy_script" {
  bucket = aws_s3_bucket.deploy.id
  key    = "deploy.sh"

  content = templatefile("${path.module}/templates/deploy.sh.tftpl", {
    bucket     = aws_s3_bucket.deploy.id
    ssm_prefix = local.ssm_prefix
    region     = var.region
  })
}

resource "aws_s3_object" "provision" {
  bucket = aws_s3_bucket.deploy.id
  key    = "provision.sh"

  content = templatefile("${path.module}/templates/provision.sh.tftpl", {
    site_url = local.site_url
    # Pre-escaped for the SINGLE-QUOTED shell context it is interpolated into. A title
    # containing an apostrophe - "Chef's Peps" - otherwise closes the quote early and
    # leaves the rest of provision.sh unterminated, which kills the whole script with
    # "unexpected end of file" and leaves WordPress unprovisioned while the containers
    # still come up healthy. `'\''` is the POSIX way to put a literal quote inside one.
    site_title           = replace(var.site_title, "'", "'\\''")
    wp_admin_user        = var.wp_admin_user
    wp_admin_email       = var.wp_admin_email
    compound_environment = var.compound_environment
    orders_url           = var.compound_orders_url
    payments_url         = var.compound_payments_url
    seed_products        = var.seed_products
    sentry_dsn           = var.sentry_dsn
    sentry_environment   = var.sentry_environment
  })
}
