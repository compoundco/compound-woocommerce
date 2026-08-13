# AWS test store

Stands the WooCommerce demo store up on AWS as a live, TLS-terminated site so
checkout can be tested from a real browser (and so Compound can deliver webhooks
back to it, which `localhost` cannot receive).

It is the local `docker-compose.test.yml` stack, unchanged in shape, on one EC2
instance with Caddy in front for HTTPS.

```
VPC 10.20.0.0/16 (one public subnet)
└─ EC2 t3.small ── Elastic IP ── https://<dashed-ip>.sslip.io
   └─ docker compose
      ├─ caddy:2-alpine           :80/:443, automatic Let's Encrypt certificate
      ├─ wordpress:7.0-php8.3     the storefront + this plugin (mounted read-only)
      ├─ mariadb:lts              docker volume on the encrypted gp3 root disk
      └─ wordpress:cli-php8.3     wp-cli, used by the provisioner

S3   deploy bundle: the plugin zip + docker-compose.yml + Caddyfile + deploy.sh + provision.sh
SSM  SecureStrings: db password, wp admin password, Compound API key, webhook secret
IAM  instance role: read that bucket + those parameters, and Session Manager
```

Roughly **$15-20/month** (instance + 30 GB gp3 + the Elastic IP). `make aws-down`
takes it to zero.

## Stand it up

```bash
cp terraform/terraform.tfvars.example terraform/terraform.tfvars
$EDITOR terraform/terraform.tfvars     # Compound API key + the API base URLs
make aws-up
```

Apply takes about a minute; the instance then spends 2-3 more installing
WordPress, WooCommerce, and the Storefront theme, seeding the four demo products,
configuring the gateway, and getting a certificate. Watch it with `make aws-logs`.

Then:

```bash
make aws-url      # storefront / admin / webhook URLs
make aws-creds    # admin password + the webhook signing secret
```

## Push a plugin change

```bash
make aws-deploy
```

Re-uploads the plugin and config to S3 and re-runs provisioning on the instance
via SSM. It never replaces the instance, so the database, uploads, and the TLS
certificate survive.

Everything except `user-data.sh.tftpl` is updatable this way, including the deploy
logic itself (`/usr/local/bin/compound-store-deploy` is a wrapper that fetches
`deploy.sh` from the bundle on every run). `user-data.sh.tftpl` is the one file
that is frozen after first boot, which is why it does as little as possible.

Two things it deliberately does *not* do: replace the plugin directory (it clears
the contents instead, because the directory is bind-mounted into running
containers and swapping the inode makes the plugin silently vanish), and let the
`.env` umask escape its subshell (it extracts the plugin root-only, and the
containers run as uid 33).

## What you have to decide

**Where Compound lives.** The gateway's `compound_orders_url` /
`compound_payments_url` default to `https://api.compound.dev`. The local
`host.docker.internal:4003` / `:4005` do not resolve from AWS, so point these at a
deployed Compound stack, or expose your local one through a tunnel (ngrok,
Tailscale Funnel, Cloudflare Tunnel). Until they resolve, the storefront works but
Place Order fails at the `POST /v1/orders` call.

**The API key.** Locally `make seed` mints one by signing into the seeded demo
brand. There is no identity service to sign into from AWS, so paste an existing
`sk_...` into `terraform.tfvars`. Left empty, the store still comes up with the
gateway installed but disabled.

**The webhook secret.** Terraform generates one unless you set it. Read it with
`make aws-creds` and configure the same value on the Compound side, pointed at the
`webhook_url` output.

## Notes

- **No domain needed.** `sslip.io` resolves `54-1-2-3.sslip.io` to `54.1.2.3`, so
  Caddy can get a real Let's Encrypt certificate for the Elastic IP without a DNS
  zone. Swap in your own hostname by pointing an A record at the `public_ip`
  output and editing `templates/Caddyfile.tftpl`.
- **No SSH port.** Shell access is `make aws-ssh` (SSM Session Manager), which
  needs the [session-manager-plugin][smp] installed locally.
- **Port 80 is open to the internet** and has to stay that way: Let's Encrypt
  validates HTTP-01 from unpublished source addresses, so restricting it breaks
  issuance and the 60-day renewal. Restrict 443 with `allowed_https_cidrs` if you
  want the store private.
- **State holds secrets.** The generated admin password and the Compound API key
  are in `terraform.tfstate`; `.gitignore` covers it and `*.tfvars`. Move to an S3
  backend if more than one person runs this.
- **This is a test store, not production.** Single instance, no backups, no
  multi-AZ; the database dies with the instance. Terminating and re-applying gives
  a clean store, not a restored one.

[smp]: https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html
