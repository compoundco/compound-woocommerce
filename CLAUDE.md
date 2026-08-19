# Claude repository instructions

The canonical, mandatory storefront compliance rules are in [`COMPLIANCE.md`](COMPLIANCE.md).
Read that file in full before planning, editing, reviewing, or generating content for this
repository.

These requirements apply to the entire repository, including application code, checkout behavior,
themes and styles, product data, seed scripts, fixtures, tests, documentation, metadata, and all
shopper-facing copy. Treat them as release-blocking invariants:

- Preserve all technical controls and fail-closed checks.
- Apply all product naming and content restrictions to production and demo content alike.
- Do not weaken, hide, bypass, or make optional any required compliance behavior.
- If a request conflicts with the SOP, stop and identify the conflict before making changes.
- Only revise a policy when the repository owner explicitly requests that exact revision, and keep
  the SOP and implementation synchronized.

For storefront-affecting work, include compliance validation in the final handoff and identify any
merchant evidence or production configuration that cannot be completed in code.

## Branching (default to a feature branch)

`main` and `stage` are protected. **Never commit or push directly to either.** Both reject
direct pushes.

- Cut a feature branch from `stage`: `feat/<short-description>`, or `fix/`, `infra/`,
  `docs/`. Do this by default, without being asked.
- If you already have edits on `main` or `stage`, branch first and move them there.
- Open a pull request into `stage`. CI runs PHP lint and coding standards, shell checks,
  and the storefront content rules; all must pass before it can merge.
- `main` only accepts pull requests from `stage`. A required check rejects any other
  source branch, so nothing reaches `main` without having gone through `stage` first.
- Promote by opening a pull request from `stage` into `main`.

The content check greps for pharmaceutical brand names, which is the one part of
`COMPLIANCE.md` a machine can reliably catch. It does **not** replace the manual
pre-publication review: claims, dosage language, protocols, and testimonials still need a
human read before anything ships.

Deploying the stores is still a local `make aws-deploy` / `make aws-deploy-prod`, not CI.
Their Terraform state is a local file rather than the shared S3 backend, so a runner has
no state to work against.
