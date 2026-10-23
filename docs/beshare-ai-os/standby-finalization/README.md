# Standby finalization and Gemini MVP foundation — 23 October 2026 MYT

**Offline implementation and evidence delivered; VPS start remains blocked.** No production changes, merge, deployment, callback/DNS/WABA changes, real API calls or outbound. No modifications to Copilot, IPO, Telegram or Caddy.

## Verified dependencies

GitHub heads read on this task: Security P0 #63 `c955270cf393c435bc51356ce9f261eda4ceb2fb`; readiness #64 `bd04d67cc76d9eed140b11bf5537a39baac8fc80`; MySQL fix #65 `e179dc3fcf159d31b918ef0f03d0f68af9719386`. All remain open Draft PRs. This branch is stacked on #65, which includes #63. #64 is historical readiness evidence based on main, not a runtime dependency to cherry-pick. Recheck heads and rerun changed dependencies before review/start.

## A. Fresh-only forward ledger procedure

`os/mvp/ForwardLedger.php` adds a narrow runner for empty synthetic business tables after the genuine foundation runner. It executes unchanged 0002/0003 SQL and only then records that version, after a full table/trigger fingerprint matches. It checks exact engine version, DB name, all migration checksums, ordered prior ledger, fresh tables and the same advisory lock as 0001. It does not backfill, adopt, repair or delete schema. A schema with completed DDL but no ledger is refused. It is not wired into production `migrate.php`.

Procedure for a separately approved fresh environment:

1. Freeze exact reviewed source/image/platform digests, SQL checksums and the engine-specific before/after fingerprint manifest. Independently review/reproduce the reference manifest; never auto-learn expected schema from the target. Fingerprints bind trigger definers and server formatting, so another engine/version/definer requires a separately reviewed manifest.
2. Inventory/preserve the old database without importing it by default. Confirm the dedicated new database is empty. Export the empty schema, hash it and bind the genuine foundation BackupProof to exact server/database. Keep proof/export outside the repository/web root.
3. Use a short-lived migration identity with schema-only SELECT, INSERT, CREATE, ALTER, INDEX, REFERENCES, TRIGGER, EVENT and SHOW VIEW as needed for full metadata visibility. Use strict SQL mode, UTC and utf8mb4. Keep clients/workers stopped throughout. No SUPER or account creation for the migrator. The lab's `log_bin_trust_function_creators=1` is an explicit isolated-instance policy, never a shared-host fix.
4. Execute the existing foundation runner with its proof; require genuine 0001. Apply 0002 then 0003 with `ForwardLedger::apply` and the independently reviewed manifest. Verify exact ledger/checksums and schema. Re-running an immediately completed step is idempotent. Running 0002 after 0003 is deliberately denied.
5. On any partial DDL, checksum change, postcondition failure or unrecorded complete schema: STOP. Retain volume, logs and proof. Rebuild another empty disposable target from the pinned artifact or restore a verified pre-migration backup into a separate volume. Never insert success rows manually, replay selected ALTERs, or use down SQL as repair.
6. Verify persistence and encrypted restore of the complete chain with source stopped. Revoke/lock migration login after review of trigger-definer privileges; do not blindly drop a definer. App identities must be table-scoped, denied ledger writes/DDL/account administration, and tested on the actual target before start.

Local MySQL 8.4.13 arm64 evidence: `os/tests/mvp/evidence/ledger-apply.json`, `ledger-partial.json`, `ledger-negative.json`, `mysql84-ledger-manifest.json`. Reference DDL execution intentionally has no 0002/0003 ledger; separate apply fixture uses the real runner. The tested restore includes all three genuine ledger rows plus one synthetic user, 11 tables and 5 triggers. Encrypted restore to a new volume matches schema/triggers/rows; missing/wrong key, tampering and checksum corruption deny. See `recovery.json` and fingerprint files. Bounded synthetic recovery is not a production RPO/RTO guarantee.

## Standby artifact and remaining gates

`deploy/whatsapp-standby/compose.yaml` pins MySQL by index digest, uses `network_mode: none`, no host ports, project-specific persistence/socket volumes, no restart, resource caps and a file-mounted root secret. Database service has an explicit approval profile; the default profile starts nothing. The synthetic-check image is separately built from pinned PHP and runs non-root/read-only with no capabilities/network/secrets. It is an offline test artifact, not an OS web runtime. Built image identity and tests are in evidence; a registry/platform digest and target-architecture validation still require review. Compose v2.39.4 parses this file successfully with placeholder non-secret paths; it was NOT brought up on the VPS.

A profile is an operator guard, not an authorization security boundary. `check-gates.py` reports remaining gates and never executes start. The checked-in gate record deliberately fails. No standby start script is provided.

Hostinger read-only snapshot around 21:11 MYT: VPS 2061175 running, 2 CPUs / 8192 MB memory / 102400 MB disk. Recent available samples: CPU 6.29–6.55%; RAM 1,676,091,392–1,680,502,784 bytes; used disk 10,940,416,000–10,940,436,480 bytes. Docker inventory reports ten existing healthy containers, no WhatsApp standby project; published mappings include 80/443 IPv4/IPv6 and 127.0.0.1:8090. This is sampled capacity and Docker port evidence, not complete host listener certification. Strict-host-key, noninteractive SSH failed with permission denied. No keys/passwords/firewall were changed. Current MemAvailable, inodes, swap/OOM, full listeners, target architecture and network/volume collisions remain unverified.

Backup custody remains a real owner decision: identify DB operator, backup custodian, alternate recovery custodian, off-VPS encrypted store, retention and independent key recovery route. Keep keys separately from dumps and outside Git/web roots; prove alternate-custodian decryption without exposing plaintext. Existing VPS snapshots are not application-consistent OS rollback and restoring the entire VPS would affect unrelated workloads. No custodian/approval has been invented.

Rollback for the future standby: stop only the exact new project services, keep volumes/keys/evidence, and remove no existing routes because this artifact adds none. Never host-wide prune, delete volumes, restart unrelated services or restore a whole VPS. Prior unsafe webhook/Desk versions are not an acceptable rollback target.

## B. Offline MVP and fastest pilot

See `os/mvp/README.md` for the implemented contract and deliberate limits. Signed synthetic inbound → server tenant mapping → latest approved tenant knowledge → injected Gemini-shaped mock → persisted draft/human handoff is exercised end to end, with negative tests and crash/concurrency checks. No real Meta/Gemini transport is supplied.

Fastest next step: review the stacked PRs and offline demonstration now, while resolving SSH read-only inventory and named backup/key custody. Before any customer pilot, replace the synthetic repository with reviewed OS MySQL/TokenSeal storage and authenticated inbox UI, validate target-platform runtime/grants/restore, then request separate standby-start approval. A later staging callback/provider credential approval is separate again. Automatic sending remains outside this foundation.
