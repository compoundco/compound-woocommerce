terraform {
  required_version = ">= 1.5"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.60"
    }
    archive = {
      source  = "hashicorp/archive"
      version = "~> 2.4"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}

provider "aws" {
  region  = var.region
  profile = var.aws_profile != "" ? var.aws_profile : null

  # A hard guard, not a hint: Terraform aborts before touching anything if the
  # resolved credentials belong to any other account. Cheap insurance against
  # standing a public store up in the wrong place because a shell had a stale
  # AWS_PROFILE.
  allowed_account_ids = var.aws_account_id != "" ? [var.aws_account_id] : null

  default_tags {
    tags = {
      Project   = "compound-woocommerce"
      Name      = var.name
      ManagedBy = "terraform"
    }
  }
}
