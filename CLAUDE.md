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
