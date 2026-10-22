# Staged migration runbook — do not execute from this PR

## Gate 0 — evidence and secure artifact

Read the [audit](README.md). Resolve P0 review and identify a secure immutable release artifact in a separate approved change. Do not merge old #41/#42 heads to recover code already in main. Capture a complete selected-file manifest for OS, not a whole-site upload that could overwrite marketing/Copilot. Separate release approval from documentation acceptance.

Obtain read-only SQL evidence through an approved connection. Record engine/version, database identity, UTC/SQL mode, character set, collation, table definitions/indexes/CHECKs/FKs, trigger bodies/definers, `schema_migrations` and row counts. Do not use `SELECT *` on token/message tables. Suggested reads, **not run here**:

```sql
SELECT DATABASE(), VERSION(), @@version_comment, @@sql_mode, @@time_zone;
SELECT version, name, checksum, applied_at FROM schema_migrations ORDER BY version;
SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE() ORDER BY table_name;
SELECT trigger_name, event_manipulation, event_object_table, action_timing
FROM information_schema.triggers WHERE trigger_schema = DATABASE();
SELECT business_id, id AS connection_id, waba_id, phone_number_id,
       token_state, webhook_state, registration_state, messaging_state
FROM whatsapp_connections ORDER BY business_id, id;
```

Inspect `SHOW CREATE TABLE` for each inventory table and `SHOW CREATE TRIGGER` for each discovered trigger in private evidence, not public logs. Verify query column names against actual schema first. Reconcile each connection with an active business, current owner membership and Meta asset ownership. Store IDs privately or redacted in PRs, never token ciphertext/key material or customer contents. Check unique phone assignments and cross-tenant inconsistencies. Check 0002/0003 columns before querying sync/readiness fields. A migration row alone does not prove the corresponding DDL is intact.

Inventory source files outside Git: private configs, legacy JSON, uploads, lifecycle spool, rates, sessions and logs. Record size, owner/mode, hash and retention without contents. Confirm source schedulers and database writers; account cron absence does not prove no worker exists. Record actual Meta app callback URL, fields, subscribed app per WABA and any phone callback override via read-only dashboard evidence. No subscribe/register/onboard/override operation belongs in inventory.

## Gate 1 — backup and restore rehearsal

Agree RPO/RTO with owner; proposed targets for approval are RPO zero at final frozen handoff and RTO ≤30 minutes, not current capabilities. Choose encrypted off-VPS storage, custodian, retention (proposal: 7 daily / 4 weekly), access controls and key recovery route. Do not store backup credentials with dumps or in Git.

After separate data-copy approval, create a consistent logical export with schema, data, triggers, routines/events where present, charset and binary-safe encoding. Use a protected client config, not passwords in argv. Capture source identity/version, UTC timestamp, selected schema, export SHA-256, counts and backup manifest. Transactional dump requires no concurrent DDL; coordinate source writers/file snapshot for legacy JSON and spool consistency. Do not copy a live MySQL datadir between engines/versions. Preserve DEFINER semantics deliberately with a reviewed target account mapping; do not blindly strip security clauses.

Restore first to a disposable isolated target with no Meta/AI credentials, internet egress, scheduler or public route. Keep original keys separately available to authorized offline validation for production-shaped encrypted samples. Restore success requires matching schema/triggers/counts, expected constraints rejecting invalid tenant assignments, decrypting an approved sample without output, auth/tenant denial tests, signed webhook dedupe and rollback drill. Record duration, bytes, counts/checksums, engine/image digest and reviewer. A successful dump or available VPS snapshot is not restore proof. No backup/restore was performed in this audit.

## Gate 2 — schema order and compatibility

1. Determine actual source state from definitions. 0001 contains 8 tables + 5 owner triggers; 0002 adds 3 coexistence tables and connection/event changes; 0003 adds worker claim/readiness fields and `dispatching`/`review` status values.
2. For an existing source-derived restore, **do not rerun 0001**. Preserve its data and ledger. For a truly empty synthetic lab only, use the reviewed foundation initialization process and its backup-proof/checksum contract; `migrate.php` is not a generic multi-version runner.
3. If exact 0001 exists and 0002 does not, rehearse **0002 then 0003**. If 0002 exists exactly, rehearse only 0003. If both exist exactly, apply neither. Mixed/partial/mismatched definitions mean STOP and a tailored recovery plan; never rerun an entire ALTER script blindly.
4. `os/bin/migrate.php` applies only 0001. 0002/0003 are forward-only SQL and have no existing integrated version runner. Prepare a separate reviewed migration procedure with exclusive operator lock, preflight definitions, script hashes, per-statement outcome journal, postcondition checks and approval record. Do not fabricate existing migration ledger entries.
5. MySQL DDL can implicitly commit; multi-statement rollback is not guaranteed. On a rehearsal failure, retain diagnostics and recreate the disposable target from backup. Production failure requires explicit recovery; do not execute `0001_foundation.down.sql` or drop shared data.
6. Validate exact MySQL target, including triggers/generated columns/CHECK/FK behavior, SQL mode, encoding, session UTC, key ciphertext and driver behavior. MariaDB test success is insufficient to certify MySQL. Do not relax constraints to make an import pass.

Finish schema validation before serving Phase B/C code, because reads reference the added columns. Neither SQL files being hosted nor Phase C PHP loading proves this gate is passed.

## Gate 3 — synthetic standby, outbound OFF

Implement and validate the [Compose design](COMPOSE-DESIGN.md) offline, then request the separately scoped standby start on the identified VPS. Recheck current host capacity, sockets and conflicting project names first. Capture IPO/Copilot health before/after through read-only inspection.

Start only synthetic isolated services once authorized. No real tokens, production DB DSN, Meta subscription, callback or DNS. Verify effective no-egress behavior, no public listener and no overlap with other project networks/volumes. Prove sync/override flags cannot be activated by HTTP and startup exits before DB/Graph while disabled. No readiness timestamps are manufactured.

Run P0 HTTP/DB and auth/tenant suites on the selected secure artifact in isolation; repeat target-engine schema/restore tests. Test missing configs, wrong signatures, unknown/mismatched WABA/phone, revoked membership, cross-tenant access, CSRF, duplicate events, private-file HTTP denial and lifecycle-spool failure. No live production probes. Health checks must be side-effect-free and cannot send messages or mark callback readiness.

Acceptance: no egress/public route, zero outbound calls, no automatic worker schedule, limits enforced, all isolation/security/restore checks documented and no measurable degradation to existing workloads. A stopped skeleton or successful YAML parse alone is not standby acceptance.

## Gate 4 — final data handoff and one callback endpoint

This gate needs a separate concrete approved release plan and resolved public ingress. Before the window, revalidate current Meta requirements: the code assumes a 24-hour Coexistence sync deadline and no safe repeat for ambiguous sync POSTs. Official documentation was not retrievable in this audit. Do not begin a real Business App signup merely to test readiness.

1. Record current callback/fields/phone override and secure rollback target. Obtain a tested ingress route and TLS certificate without changing Caddy/Copilot under current constraints. Keep marketing DNS/CDN unchanged. Retain `/whatsapp/` paths and validate host-only cookies, HTTPS detection and proxy trust at the future OS origin; force re-login rather than sharing cookies across products.
2. Freeze OS/legacy writes and stop only identified OS workers during the approved window. Ingress must either durably spool signed requests with verified replay or return retriable failure during the brief freeze; never acknowledge and discard events. Establish Meta retry tolerance and maximum freeze duration before release. Do not run two independent consumers/writers.
3. Take final consistent dump + required files/keys, restore to target, apply only missing approved migrations in order, compare counts/checksums and current event watermark. Both source and destination outbound remain OFF. Source stays frozen for rollback evidence.
4. Cut over **one effective Meta callback** for the intended app/phone scope. This plan chooses the reviewed OS webhook at the future OS origin; inventory phone overrides so they do not silently send elsewhere. Do not combine app-level cutover and an unreviewed phone override, dual forwarding, parallel legacy processing or dual DB writes. Never replay historical side effects. Legacy event dedupe is bounded/file-based; reconcile in-flight IDs and spooled events before accepting traffic.
5. Verify challenge and a separately authorized signed test event, durable tenant routing, duplicate suppression and required field delivery. Confirm actual WABA subscription evidence before recording callback/field readiness; those record commands do not perform verification. Outbound and synchronization remain OFF.
6. Only after intake acceptance, owner-approved eligible spare number and current eligibility checks, plan Coexistence onboarding/sync separately. Confirm active connection, verified token, correct tenant, subscription, deadline, callback/field evidence and job authorization. Preserve jobs in `review`; never repeat an uncertain Meta POST just because a timeout occurred. No phone register/deregister is implied.

## Observability and rollback

Before traffic, emit redacted structured logs with release hash, correlation/event identifier hash, event type, result and duration. Never log raw payloads, message contents, access tokens, OAuth URLs/query strings, secrets or full phone numbers. Log rotation proposal: 10 MB × 3 per container, retained under policy; keep audit trail separate. Hosting currently reports PHP error logging OFF; target logging needs explicit configuration and safe exception handling.

Monitor HTTP error rate/latency, invalid signatures, unknown mappings, dedupe counts, durable ingest success, spool depth/oldest age, DB connections/disk/backup age, worker disabled state, expired leases and `review` jobs. Proposed alerts: any outbound call in standby; any isolation/tenant failure; sustained 5xx >1% for 5 minutes; spool age >5 minutes; memory/disk threshold breach; backup older than 24 hours; any job entering review. Thresholds require tuning from rehearsal. Notifications must use a separately approved channel; do not reuse Telegram/IPO credentials.

Abort cutover for tenant mismatch, lost durable writes, unexpected outbound, failed signature/auth guards or existing workload degradation. For standby failure, stop only the new project and preserve its volumes/evidence. Never run host-wide prune/down/restart or restore the entire VPS.

For live intake failure, disable outbound/worker first. Restore callback routing only to a **verified secure** source artifact with compatible schema/keys and replay plan; pre-P0 legacy handler is not an acceptable fallback. If no secure fallback exists, retain signed events durably or fail closed with retriable responses. Do not silently re-enable legacy sending or unauthenticated Desk.

If the destination accepted new writes, the old DB is stale: freeze intake, reconcile destination delta/dedupe/spool into the chosen authoritative dataset with counts and timestamps before resuming. Never flip DSNs back and discard new messages. A schema rollback means restore to a new isolated volume and reconcile accepted events, not blindly execute down SQL. Record actual RPO/RTO and recovery outcome. Retain source and encrypted backups until the agreed verification period ends; deletion is a separate action.

## Evidence required to close each gate

| Gate | Evidence artifact | Current result |
| --- | --- | --- |
| 0 | Secure release manifest + redacted DB/Meta/mapping inventory | BLOCKED |
| 1 | Encrypted backup manifest + measured restore rehearsal | BLOCKED |
| 2 | Target-engine schema fingerprints + ordered migration journal | BLOCKED |
| 3 | Compose/image review + no-egress/no-public-port + synthetic tests | NOT STARTED |
| 4 | Approved ingress/cutover + signed delivery + single writer + rollback drill | NOT AUTHORIZED |

This documentation change validates source ancestry and read-only infrastructure observations only. It does not certify an operational WhatsApp AI OS or Phase A–E deployment.
