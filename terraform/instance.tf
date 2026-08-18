locals {
  # Graviton families carry a "g" in the family segment (t4g, m7g, c7gd); everything
  # else is x86_64. Picking the AMI from the instance type means `t4g.small` just
  # works instead of failing with an exec-format error deep in cloud-init.
  cpu_architecture = can(regex("^[a-z0-9]+g[a-z]*\\.", var.instance_type)) ? "arm64" : "x86_64"
}

data "aws_ssm_parameter" "al2023" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-${local.cpu_architecture}"
}

resource "aws_instance" "this" {
  ami                    = data.aws_ssm_parameter.al2023.value
  instance_type          = var.instance_type
  subnet_id              = aws_subnet.public.id
  vpc_security_group_ids = [aws_security_group.wordpress.id]
  iam_instance_profile   = aws_iam_instance_profile.instance.name

  root_block_device {
    volume_size           = var.root_volume_size
    volume_type           = "gp3"
    encrypted             = true
    delete_on_termination = true
  }

  metadata_options {
    http_tokens   = "required" # IMDSv2 only
    http_endpoint = "enabled"
  }

  user_data = templatefile("${path.module}/templates/user-data.sh.tftpl", {
    bucket      = aws_s3_bucket.deploy.id
    ssm_prefix  = local.ssm_prefix
    region      = var.region
    expected_ip = aws_eip.this.public_ip
  })

  # Deliberately false. user-data only runs on first boot, so a change here does not
  # take effect until the instance is replaced - and replacing it destroys the
  # database, uploads, and TLS certificates. Push changes with `make aws-deploy`,
  # which re-syncs the S3 bundle onto the running instance instead.
  user_data_replace_on_change = false

  # Same reasoning, for the same reason. The AMI comes from the rolling
  # "ami-amazon-linux-latest" SSM pointer, so Amazon publishing a new AL2023 image
  # changes this value and forces replacement - which destroys the database, uploads,
  # and TLS certificates on the next apply, whatever that apply was actually for.
  # Ignore it so a fresh image is picked up when the instance is deliberately rebuilt,
  # never as a side effect of an unrelated change.
  lifecycle {
    ignore_changes = [ami]
  }

  # The bundle must exist in S3 before user-data tries to download it.
  depends_on = [
    aws_s3_object.plugin,
    aws_s3_object.compose,
    aws_s3_object.caddyfile,
    aws_s3_object.provision,
    aws_s3_object.deploy_script,
    aws_iam_role_policy.instance,
    aws_iam_role_policy_attachment.ssm,
    aws_ssm_parameter.db_password,
    aws_ssm_parameter.wp_admin_password,
    aws_ssm_parameter.compound_api_key,
    aws_ssm_parameter.compound_webhook_secret,
  ]

  tags = { Name = var.name }
}
