# Repository instructions

## Mandatory compliance SOP

Before making any change, read and follow [`COMPLIANCE.md`](COMPLIANCE.md) in full.

The SOP applies repository-wide and is a release-blocking requirement for all code, styling, copy,
fixtures, seed data, tests, documentation, and generated storefront content. Preserve every required
control and content restriction. Never weaken or bypass a compliance control to satisfy another
request.

If a requested change conflicts with the SOP, stop and explain the conflict. Only change a policy
when the repository owner explicitly directs that exact policy change; update `COMPLIANCE.md` and
the implementation together.

When handing off storefront-affecting work, state which compliance checks were performed and call
out any merchant documents, real product data, legal review, or production configuration still
required outside the repository.

## Branching (default to a feature branch)

`main` and `stage` are protected. **Never commit or push directly to either.** Both reject
direct pushes.

- Cut a feature branch from `stage`: `feat/<short-description>`, or `fix/`, `infra/`,
  `docs/`. Do this by default, without being asked.
- If you already have edits on `main` or `stage`, branch first and move them there.
- Open a pull request into `stage`. CI runs PHP lint and coding standards, shell checks,
  and the storefront content rules; all must pass before it can merge.
- Promote by opening a pull request from `stage` into `main`.

The content check greps for pharmaceutical brand names, which is the one part of
`COMPLIANCE.md` a machine can reliably catch. It does **not** replace the manual
pre-publication review: claims, dosage language, protocols, and testimonials still need a
human read before anything ships.

Deploying the stores is still a local `make aws-deploy` / `make aws-deploy-prod`, not CI.
Their Terraform state is a local file rather than the shared S3 backend, so a runner has
no state to work against.
