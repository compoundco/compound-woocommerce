output "site_url" {
  description = "The live storefront."
  value       = local.site_url
}

output "admin_url" {
  description = "WordPress admin."
  value       = "${local.site_url}/wp-admin"
}

output "webhook_url" {
  description = "Point Compound's order.shipped / delivered / cancelled webhooks here."
  value       = "${local.site_url}/wp-json/compound/v1/webhook"
}

output "public_ip" {
  description = "Elastic IP of the instance. Stable across reboots and re-deploys."
  value       = aws_eip.this.public_ip
}

output "wp_admin_user" {
  value = var.wp_admin_user
}

output "wp_admin_password" {
  description = "Read it with: terraform output -raw wp_admin_password"
  value       = local.wp_admin_password
  sensitive   = true
}

output "compound_webhook_secret" {
  description = "Configure this same secret on the Compound side. terraform output -raw compound_webhook_secret"
  value       = local.compound_webhook_secret
  sensitive   = true
}

output "instance_id" {
  value = aws_instance.this.id
}

output "region" {
  value = var.region
}

output "aws_profile" {
  description = "Profile the helper scripts should pass to the AWS CLI so they act on the same account Terraform did. Empty means the default credential chain."
  value       = var.aws_profile
}

output "deploy_bucket" {
  value = aws_s3_bucket.deploy.id
}

output "ssh_command" {
  description = "Shell on the box (no open SSH port; requires the Session Manager plugin)."
  value       = "aws ssm start-session --region ${var.region} --target ${aws_instance.this.id}"
}

output "bootstrap_log_command" {
  description = "Watch the first boot provision itself."
  value       = "aws ssm start-session --region ${var.region} --target ${aws_instance.this.id} --document-name AWS-StartInteractiveCommand --parameters command='tail -f /var/log/compound-store-bootstrap.log'"
}
