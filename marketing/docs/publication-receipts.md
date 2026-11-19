# Publication receipts

Published content can display its actual destination and provider-returned post
link. A receipt confirms which account owns the provider post; it does not prove
that every external viewer can see it.

## Resolution and display

- Authenticated `POST /api/akaun/copilot/marketing/posts/<id>/publication` accepts
  only an empty JSON object. The normal account token, origin and owner checks
  apply. Responses contain `publication` with `platform`, `account_id`,
  `account_name`, `profile_url`, `post_url` and `resolved_at`.
- Only published posts with provider IDs can be resolved. Two bounded provider
  GET requests verify the original pinned destination, post ownership and link.
  A later connection switch cannot silently relabel a historical publication.
- The additive `marketing_publications` table stores the verified receipt.
  Normal workspace and post reads return the cached receipt without making
  provider calls. Provider data-deletion callbacks remove matching receipts.
- Opening a published post resolves a missing receipt. The card also offers
  **Semak pautan hantaran**. Failures leave the post locked and published and
  offer only another metadata lookup; this never republishes content.
- A valid receipt shows **Diterbitkan ke …**, **Buka hantaran …**, and, where the
  provider supplies a profile link, **Buka Page/Profil**. URLs use HTTPS and exact
  provider host allowlists. No URL is guessed from a provider ID.

Old posts require their original valid connection for the first lookup. Once
cached, the receipt remains associated with that original destination if a
different account is subsequently connected. A removed or restricted provider
post can still make an old link unavailable; opening it is a separate check.

## Threads onboarding readiness

`MARKETING_THREADS_CUSTOMER_ACCESS=approved` suppresses the testing-only notice.
Every other value defaults to `testing`. This is operator-maintained interface
information, not a substitute for Meta permission approval. Change it only after
the relevant live approval is confirmed. It does not disable connected-account
publishing or legitimate tester authorization.

Known OAuth failures returned to `connect.html` are reduced to customer-facing
categories, and raw query diagnostics are removed. An error page shown by
Threads before returning to BeShare remains under Threads' control.

## Account-service rollout

`marketing/bin/deploy-publication-receipts.py` and
`marketing/Dockerfile.publication` target the observed connection-health account
image. The helper requires an exact reviewed commit and tree, checks the current
immutable image and source hashes, builds without network access, and probes the
candidate before changing only the account service image. It preserves the
publishing flag, environment, media storage, worker and other services, and
restores the old image if the final checks fail. Private deployment logs and
configuration backups remain on the VPS with root-only access.

Deploy all four modules together: `marketing_publication.py`,
`pelanggan_marketing.py`, `marketing_social.py`, and `marketing_lifecycle.py`.
The worker requires no change. The new frontend remains usable against an older
backend and shows receipt actions only when the capability is advertised.
