# Disposable MySQL 8.4 compatibility and restore rehearsal

**23 October 2026 MYT — diagnostic tests PASS; approved migration path and standby BLOCKED.**

The owner authorized this local synthetic rehearsal. PR #64 was checked at `c98abb6c79c2d402e73d328af386ef89eeecd232`; Security P0 #63 remains an open draft at `c955270cf393c435bc51356ce9f261eda4ceb2fb`. Runtime code is exported from that P0 commit, not from the documentation branch. No application runtime file or migration was changed in either PR by this rehearsal.

## Acceptance decision

| Area | Result | Evidence / limitation |
| --- | --- | --- |
| Exact MySQL execution | PASS | MySQL Community 8.4.13, Linux arm64; PHP/PDO/mysqlnd 8.3.36. Not target VPS architecture certification. |
| Original foundation procedure | **BLOCKED** | Genuine empty MySQL dump/proof rejected with `backup_server_unbound`, exit 4, before DB connection. `BackupProof.php` requires `MariaDB` in the version. |
| Catalog inspection | **BLOCKED** | Independent execution of the migrator's `cc.TABLE_NAME` reference gives MySQL error 1054. MySQL's CHECK_CONSTRAINTS has no TABLE_NAME column. Further definition-normalization compatibility is unproven. |
| Diagnostic 0001→0002→0003 | PASS | All 26 original SQL statements, per-statement hashes/timings, 8→11→11 tables and 5 triggers. Exclusive named operator lock; empty-schema precondition. This bypasses the blocked foundation procedure for diagnostics only. |
| Ledger acceptance | **BLOCKED** | `schema_migrations` deliberately stays empty. No fake 0001 or 0002/0003 rows. Diagnostic DDL success does not approve initialization. |
| Existing regression suite | PASS | 121 P0 HTTP/DB + 102 auth + 64 Meta + 37 coexistence + 33 worker + 13 lifecycle = 370 PHP checks; 30 JavaScript checks; **400 total**. |
| Restricted-role checks | PASS, bounded | 31 assertions on source, again on plain restore, again on encrypted restore. Includes actual Accounts registration and CoexistenceSync dueJobs/recoverInterrupted queries. Not every endpoint or active dispatch path; migration operator grants remain unaccepted. |
| Persistence | PASS | Restart same DB container/volume; all row, DDL and trigger hashes unchanged. Not power-loss or storage-failure durability testing. |
| Backup / isolated restore | PASS, synthetic | Dedicated backup account, single-transaction logical export, independent new volume, source stopped, matching canonical schema/trigger hashes and all table counts/row hashes. |
| Encrypted recovery | PASS, synthetic | AES-256-GCM; checksum verification, missing/wrong key and tampered envelope rejected with zero plaintext; independent second restored volume passes the same 31 assertions. |
| Interrupted DDL | PASS, bounded | Interrupt after statement 2; retain partial volume; blind rerun exits 5 (`nonempty_schema_refused`). Recovery uses a different new volume from verified full backup, never retries partial DDL or runs down SQL. |
| Isolation | PASS, local lab | Every executed DB/PHP container has `network=none`, no published ports, no host bind mounts or Docker socket. DB uses Unix sockets, `--skip-networking --mysqlx=0`; failed external-connect probes report network unreachable. |
| Standby / production recovery | **BLOCKED** | No production start, Linux VPS image/capacity/ingress proof, offsite custody, binlog/PITR gap tests, coordinated files/spool watermark or production-sized recovery proof. |

## Exact artifacts and evidence

- MySQL pull reference: `mysql@sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69`.
- Executed MySQL arm64 manifest: `sha256:b265fd63b9c8168801c1628b0b21e915846e81192f5a493682a1184cbd70d896` ([OCI index](evidence/oci-mysql.json)).
- PHP base: `php@sha256:cdb77e306d6bb99db40e513fbe036ecc6c53b575cbcb7e581387845fef26d755`.
- Executed PHP arm64 manifest: `sha256:df5f75cae26638b2ebd23e7d1a681b837a1f536d9fe611f5f30ae594a6af40a2` ([OCI index](evidence/oci-php_base.json)).
- Local tooling image ID: `sha256:701b58a4c6e3305c4cff95dd77e02ae6caf0676cda3e72442d494918c494d1ae`. This is a lab image, not a published secure production artifact. OS packages were resolved during build; recorded installed versions do not make rebuilding byte-reproducible.
- [Image metadata](evidence/images.json), [server state](evidence/version.json), [PHP versions](evidence/php.txt), [tooling](evidence/tooling.txt), [source integrity](evidence/source-integrity.json).
- [Migration journal](evidence/migration-journal.jsonl), [foundation refusal](evidence/foundation-gate.json), [catalog failure](evidence/catalog-probe.txt), [original SQL-mode refusal](evidence/original-test-mode.json).
- [Regression output](evidence/p0-regression.json), [signup checks](evidence/signup-js.json), [grants](evidence/grants.txt), [restricted source](evidence/restricted-source.json), [restricted restored](evidence/restricted-restored.json).
- [Pre-backup fingerprints](evidence/fingerprint-before.json), [restored fingerprints](evidence/fingerprint-restored.json), [comparison](evidence/restore-comparison.json), [encrypted recovery](evidence/encrypted-recovery.json).

Migration SHA-256 values remain:

| File | SHA-256 |
| --- | --- |
| 0001 | `506862ea92234627c2fbe8bed636689b61063dbadb1b4155e15c63c804894b2c` |
| 0002 | `a6804229f6edf9f515fe0b29b16d6e378f1df84e3753b30809672513782aaa64` |
| 0003 | `2881083056c097537a133da0c78ea7b10f249c0241d309abc359e6b18d4312c0` |

The server default database collation was `utf8mb4_0900_ai_ci`; original migrations explicitly create their tables with `utf8mb4_unicode_ci`. Strict SQL mode and UTC session are recorded. No server constraints or authentication policy were relaxed.

## Timings and recovery limits

The dataset has **211 synthetic rows across 11 tables**, including 200 multilingual messages, two businesses/owners, one encrypted synthetic token and an ambiguous job retained in review. Dump size is 61,005 bytes.

| Operation | Measured duration |
| --- | ---: |
| Restart + wait + fingerprint | 2.098 s |
| Consistent logical dump | 0.042 s |
| Plain restore import | 0.129 s |
| Backup encryption | 0.034 s |
| Decryption + plaintext SHA-256 match | 0.030 s |
| Encrypted-backup restore import | 0.119 s |
| Fresh recovery DB creation, import, app creation and validation | 5.843 s |

Plain backup SHA-256: `88713a7b6e6d5a66c3de46a04419d03386ddf30a7ff244fee9ae69905b820666`.
Encrypted envelope SHA-256: `0b3298961161dbbda8a46012f2766b938c55f36db037880ddeef4c0e04cae21a` (61,033 bytes).

Timers use a monotonic clock. The 5.843-second recovery timer starts **after** decryption and excludes image downloads, package build, human detection/approval, backup retrieval and production traffic. None of these measurements establishes **RPO ≤15 minutes or RTO ≤2 hours**. Although log_bin is enabled, no binlog capture/retention, missing-log/gap detection or PITR was exercised. There was no ongoing-write workload. Offsite access/key custody and legacy files/spool/session/rate-state coordinated recovery remain untested.

Raw SHOW CREATE TABLE hashes differed after mysqldump restored explicit column `CHARACTER SET utf8mb4` clauses. The comparison removes only that exact redundant clause from both sides; it retains collation, types, indexes, CHECKs, FKs, generated expressions and AUTO_INCREMENT. All canonical DDL hashes, row hashes, counts and trigger hashes match. Trigger creation timestamps are excluded, while bodies, SQL mode and definer are retained. No unrelated mismatch is ignored.

## Roles and harness scope

Only synthetic fixed lab credentials are used. PHP receives no production environment/configuration. Source export contains tracked code only; no untracked customer files, credential files, VPS mounts or existing database files are copied. The runtime's baseline GET verifier block is compared as code; its real verifier is never used.

Web has table-specific DML, audit INSERT and ledger SELECT. Worker cannot mutate identities or DELETE jobs; it can read/update scoped queues and write projections/audit. Backup has schema SELECT/SHOW VIEW/TRIGGER, with `--no-tablespaces --set-gtid-purged=OFF`; no global PROCESS/FILE/GRANT. MySQL's TRIGGER privilege is not a read-only privilege and requires separate custody. No routines/events exist in the fixture; privileges for dumping them are not certified. Trigger bodies are unchanged, rebound deliberately to a locked `definer@localhost` with SELECT/TRIGGER only on businesses/membership tables. This lab mapping is not authorization to strip or rewrite production definers.

DDL, fixture administration and historical regression setup use a lab maintenance/root identity. Restricted-role checks are separate from the historical suites. Production tenant isolation still depends on application authorization: table grants are not row-level security. A production migration account cannot be accepted while the original migration procedure fails.

The adapter changes only the test runner: replace MariaDB initialization with a fixed disposable socket; compare the baseline verifier from a pinned exported file; redirect copied suite DSNs; remove obsolete `NO_AUTO_CREATE_USER` while retaining strict mode; retain the image's PHP extension scan directory. Assertions and runtime source are unchanged. The initial attempt's missing PDO extension and literal DDL-hash mismatch are preserved under [initial attempt](attempts/initial/p0-regression.json); they are not counted as passes. Current evidence reflects the corrected harness.

PHP built-in HTTP listens only on container loopback. Production reverse-proxy private-route denial, read-only runtime filesystem, complete service-specific paths/mounts, target CPU architecture and aggregate VPS resource envelope remain separate gates. No production runtime/Compose is delivered by this lab.

## Reproduction and rollback

Use a dedicated local Docker engine, never a VPS/production Docker context. `lab.py` hardcodes the separate Colima Unix socket, pins P0/MySQL/PHP and refuses existing rehearsal container names. It does not accept an external DSN. Review the fixed prefix before a new run; use a new prefix to retain old evidence/volumes. Do not reuse an existing partial database. The local Colima profile was created with no host mounts, no SSH-agent forwarding and no port forwarder; no login service was installed.

1. Pull the two pinned references above. Build the lab-only Dockerfile using `--build-arg PHP_IMAGE=<pinned PHP reference>` and tag `beshare-mysql84-lab:20261023`.
2. Run `python3 lab.py`, then `python3 continue.py`, then `python3 secure-recovery.py` in this directory. Inspect every exit/result; the scripts deliberately preserve BLOCKED evidence rather than assert production readiness.
3. Capture source integrity and effective isolation, then stop only the exact rehearsal containers and the `beshare-rehearsal` Colima profile. See [shutdown evidence](evidence/shutdown.json). No deletion or global cleanup is required.

Backups remain excluded from Git in `.local-backups/` with private permissions; the random synthetic encryption key remains separately in the stopped disposable app container. Containers and volumes are retained for diagnostics. No production backup/key custody is implied. No `prune`, `down -v`, down migration, host restart, old-DB import or removal occurs. A future live rollback must retain writes and use a verified secure artifact as described in the parent runbook.

## Minimum before standby

1. Fix and review MySQL backup-proof/version binding and catalog inspection in the runtime change; test real 0001 initialization, ledger checksum, partial-state refusal and all subsequent definition checks on the exact target architecture/engine. Prepare an approved 0002/0003 maintenance procedure and temporary migration-role tests. Do not bypass either guard in production.
2. Obtain P0 review and a pinned secure runtime/Compose; prove private HTTP routes, no egress, service-specific persistent storage/secrets, disabled outbound/worker scheduling and resource limits on the intended host. Recheck current capacity/listeners and IPO/Copilot health through approved read-only access.
3. Name DB/Meta/backup custodians; complete the preservation/admin/Meta inventory and off-VPS backup/key custody. Exercise coordinated recovery, binlog gaps/PITR and realistic data volume before any RPO/RTO claim.
4. Review a concrete synthetic-only standby plan and obtain its separate start approval. This rehearsal does not authorize production startup, source import/migration, merge/deploy, DNS, Meta callback/WABA changes, outbound activation or real messages.

No Hostinger/VPS execution or production credentials were used. Copilot, IPO, Telegram and Caddy were untouched.

References: [MySQL CHECK_CONSTRAINTS columns](https://dev.mysql.com/doc/refman/8.4/en/information-schema-check-constraints-table.html), [removed NO_AUTO_CREATE_USER mode](https://docs.oracle.com/cd/E17952_01/mysql-8.0-en/upgrading-from-previous-series.html).
