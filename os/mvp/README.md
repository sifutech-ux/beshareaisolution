# Synthetic Gemini draft foundation

This module is deliberately disconnected from the existing public webhook, legacy Desk and production database. It implements the requested foundation with an injected Gemini-shaped transport; it contains no networking, credentials, sending adapter or live model claim. The default constructor is disabled.

Run `php os/tests/mvp/check.php`. Tests create a private temporary SQLite database, seed two synthetic tenants, sign a Meta-shaped text event, persist inbound/handoff, select only the latest approved unexpired knowledge, reserve usage, generate a mock draft, and claim it as an authorized operator. Every assertion is named in `../tests/mvp/evidence/mvp-local.json`.

## Contract

- HMAC-SHA256 verifies exact bytes before JSON parsing/storage. Missing/short secret, body >16 KiB, invalid signature, unknown WABA+phone pair, invalid shape, unsupported message type and timestamps beyond ±300 seconds fail closed. Only one text message per envelope is supported.
- Tenant is derived from server-owned route data. Caller-supplied tenant metadata is ignored. Event ID is unique per tenant; a replay with different raw bytes is rejected. A legitimate retry with changed envelope metadata therefore requires operator investigation.
- Dedicated synthetic SQLite tables are not a migration into the OS MySQL database. Foreign keys enforce tenant-scoped approvals and claims. SQLite `BEGIN IMMEDIATE` serializes reservations; independent-process tests exercise contention and a crash after reservation.
- Latest knowledge revision must have an active tenant approver and future expiry; it is checked again after transport returns. Revocation, edits, expiry or tenant suspension discard the generated draft. An operator claim during generation wins.
- Per-tenant UTC-day call and conservative abstract-unit ceilings, ten-second minimum interval, bounded input/knowledge/output and `maxOutputTokens=512` control work. Units are not token metering or financial guarantees. Reservations remain charged on ambiguity/failure. No automatic retry, no tools, no sending.
- Before the injected transport runs, a durable review handoff and usage reservation already exist. API exceptions, malformed/truncated responses and crashes leave the item for human review. Exception text is never stored. Provider latency cannot be bounded by this synchronous closure; a future live adapter must enforce timeout, cancellation and actual price/token accounting.
- `inbox(tenant, actor)` and `claim(tenant,event,actor)` require an active server-owned operator record. A future HTTP adapter MUST derive actor from the existing authenticated session and add CSRF; accepting an HTTP actor argument would defeat this boundary. Display draft/inbound as escaped text. There is no browser endpoint in this PR.

Synthetic text is stored as plaintext in a private disposable SQLite file. This is not approved storage for customer data. Before a real pilot, implement an OS MySQL repository using existing tenant membership and TokenSeal, production lifecycle/retention controls, durable authenticated inbox UI, reviewed provider timeout/cost adapter, and staging approval. None of those are implied by this offline test result.
