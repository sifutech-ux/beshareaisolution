# Fresh VPS database decision — 23 October 2026 MYT

**23 October 2026 rehearsal update:** The owner-authorized [disposable MySQL 8.4.13 compatibility and restore rehearsal](rehearsal/README.md) is complete. Diagnostic SQL, 400 existing checks, restricted-role checks and isolated synthetic restore pass. The original foundation backup guard and catalog inspection are incompatible with MySQL; initialization/ledger acceptance and standby remain **BLOCKED**. Historical NOT RUN / separate-test-approval text below describes the earlier planning state, not this completed rehearsal. No production action is authorized.

**Readiness: BLOCKED for service start. Planning is reviewable; no deployment is authorized.** This addendum to [RUNBOOK.md](RUNBOOK.md) records the owner's decision: create a fresh MySQL/MariaDB database on VPS 2061175, with OS backend and workers in dedicated containers/networks/volumes. Marketing stays on Hostinger Web Hosting/CDN. It supersedes the default assumption in Gate 4 that every old row will be imported. It does not waive preservation, schema testing, recovery or security gates.

Fresh means a new, independently owned database volume. It does **not** mean the old database or administrative state is disposable. There are two separate datasets: synthetic standby fixtures, and a future production dataset whose retained identities/configuration must be explicitly reconciled. No automatic import, new live onboarding or regenerated encryption keys for existing ciphertext.

## Observed evidence and limits

Fresh read-only Hostinger calls confirm the existing database `u879723783_beshare_os`, assigned to `beshareaisolution.com`, reported usage 2 MB, limit 3072 MB, external endpoint `srv2050.hstgr.io:3306`. Existing application account has broad DDL/DML privileges; remote-access rule remains `%`. Nothing was revoked or altered. Administrative size is not a row count or evidence of no important data. Engine/version, table definitions, live ownership and actual SQL contents remain UNKNOWN because no approved read-only SQL route is available.

VPS list confirms 2 CPUs, 8192 MB RAM and 102400 MB disk. Docker list reports IPO (2), Copilot (5), copilot-production (3) containers healthy, with no OS project. Caddy occupies TCP 80/443 on IPv4/IPv6; IPO occupies 127.0.0.1:8090. No free port is certified. Metrics requested for 23 October 00:00–08:00 MYT returned 15 samples: CPU 5.88–7.33%, RAM 1,659,162,624–1,696,612,352 bytes, disk used 12,260,524,032–12,260,950,016 bytes. These historical samples do not certify current MemAvailable, inodes, swap/OOM, I/O or complete host listeners.

GitHub confirms #63 open draft at `c955270cf393c435bc51356ce9f261eda4ceb2fb` and #64 open draft at `bd446629c5eec8c87e58e93b849adb5dd6b4446d` before this addendum. Neither is merged. #63 is the candidate security source; this documentation branch does not contain its runtime fixes.

## Preservation register — read-only collection before any live initialization

Use [metadata inventory](checks/production-inventory.sql) and [ownership inventory](checks/ownership-inventory.sql) through a custodian-approved restricted identity. Validate table/column existence first. Keep detailed outputs private; publish status and redacted aliases only.

| State | Evidence to retain | Fresh-target disposition requiring review |
| --- | --- | --- |
| Database schema | Version/vendor, SQL mode/timezone/charset, DDL, indexes, CHECK/FK, triggers/definers, migration ledger and hashes | Compare to 0001–0003; retain old DB unchanged even if target starts empty |
| Users and admin access | Counts, active/suspended state, owner/admin membership and tenant mapping; no password hashes in reports | Decide selective identity migration versus verified re-enrolment; never invent an owner |
| Connections and onboarding | Tenant → connection → WABA → phone → Meta business/app crosswalk; token/key versions and lifecycle state | Retain required identity and decryptability; no resubscription/registration implied |
| Legacy/admin files | Config/data JSON, order/message stores, uploads, dedupe, `BESHARE_STOR`/`BESHARE_MUAT` overrides | Record paths, sizes, modes, ownership and retention; content remains private |
| Private configuration | Presence and custodian of DB config, app secret, verifier, token keys/version, legacy business/WABA binding, environment override names | New DB credentials; original keys only if approved encrypted state is retained; never print values |
| Meta administration | Dated callback URL, subscribed fields/app per WABA, phone override, permissions and business ownership | Preserve settings; read-only dashboard evidence only |
| Pending state | Webhook/job counts by status, review/ambiguous requests, encrypted spool, rate state, audit logs, sessions, all writers/schedules | Reconcile pending work; never replay ambiguous outbound; invalidate sessions only at approved cutover |

The custodian signs each item as retain, separately approved selective transfer, or retain at source only, with rationale and recovery owner. Unknown is a stop for production activation, not permission to discard. Old DB, credentials, remote rules and files stay unchanged in this task. Synthetic standby never uses production credentials or customer state.

## Engine and migration acceptance

Proposed target family: MySQL 8.4; exact patch and image digest remain **UNSELECTED / BLOCKED** until tested. MariaDB is an alternative requiring its own exact patch/digest acceptance. Existing MariaDB 13.0.2 results are historical lab evidence, not a recommendation for production and not MySQL certification. Do not substitute the installed MariaDB client/server for a MySQL test.

No DB process, service or migration is started in this update. The requested runtime compatibility proof remains pending the separately approved disposable test run. That run must use synthetic data, no external credentials, no published ports or egress, and a fresh lab volume distinct from both future standby and existing projects.

1. Pin P0 source tree and SHA-256 of all three migration files; select exact engine/image digest and PHP/PDO versions. Record server-reported version, SQL mode, timezone and collation.
2. Verify the target is the deliberately created empty lab schema, named `u879723783_beshare_os` to satisfy `Contract::DATABASE`. Refuse an existing/partial schema. Acquire a single-operator migration lock.
3. Initialize 0001 through its reviewed foundation procedure and backup-proof contract. `os/bin/migrate.php` handles only 0001. Do not present it as a 0001–0003 runner. Verify 8 tables, 5 owner triggers and ledger/checksum.
4. Apply 0002 then 0003 through a reviewed maintenance procedure with hashes, preconditions, per-statement journal and postconditions. Verify 11 tables total, composite tenant FKs, generated columns and CHECK enforcement; 0003 readiness/lease fields and dispatching/review states. Keep an external version journal for 0002/0003 until a reviewed integrated ledger writer exists; do not fabricate rows.
5. Exercise valid owner creation and invalid owner transitions; cross-tenant FK rejection, duplicate phone/message prevention, invalid status rejection, UTF-8 and encrypted byte round trips, PDO transactions/locking, and actual worker queries under restricted accounts. Re-run P0's 400-check suite adapted to the **exact** target engine, with synthetic transports.
6. Inject failure between DDL statements and reject rerun/partial state. DDL may commit implicitly. Recover into a new lab volume from a verified backup, then compare schema fingerprints/counts and run the suite again. Do not execute foundation down SQL against retained state.

Evidence table required before acceptance:

| Test | Required recorded result | Current |
| --- | --- | --- |
| 0001 → 0002 → 0003 | Version/digest, hashes, journal, schema fingerprint, assertions | NOT RUN |
| Least privilege | Positive app/worker operations and negative DDL/cross-schema/grant tests | NOT RUN |
| P0 regression on selected engine | Per-suite counts, exit codes, zero real outbound | NOT RUN |
| Restore and partial-DDL failure | Verified schema/counts/keys, measured timings, retained diagnostics | NOT RUN |
| Isolation/private routes | Effective failed external connections, no host ports, denied private HTTP paths | NOT RUN |

## Least-privilege identities

Separate credentials per service; never reuse the broad hosting application identity. Scope all grants to the dedicated schema and expected private client source. Use container DNS `db` for application connectivity: localhost inside the application container is not the DB container.

| Identity | Proposed scope | Explicit exclusions |
| --- | --- | --- |
| Web runtime | Table-specific SELECT/INSERT/UPDATE/DELETE only where exercised by reviewed endpoints; audit INSERT, ledger SELECT | No DDL, FILE, PROCESS, SUPER, GRANT OPTION, account management or other schemas |
| Worker | SELECT on users/businesses/memberships/connections/events/jobs; UPDATE on connections/events/jobs; INSERT/UPDATE on projections as needed; audit INSERT | No identity/membership mutation, no DDL, no DELETE unless demonstrated necessary |
| Migration operator | Temporary schema-scoped CREATE/ALTER/INDEX/REFERENCES/TRIGGER plus ledger DML as required by procedure | Not mounted in web/worker; revoke after maintenance; no global root access |
| Inventory/monitor | Selected metadata and aggregate/status reads, preferably approved views | No raw payload/token reads or state changes |
| Backup operator | Minimum engine-specific consistent dump/binlog privileges established by rehearsal | Separate custody; no app use, no all-schema blanket grants |
| Bootstrap/trigger definer | Protected maintenance identity; stable locked-login definer with only trigger-required reads | Root secret never mounted into runtime; do not remove definer before testing triggers |

This is a proposed grant matrix, not executable grants or proof of least privilege. Derive final table-level grants from exercised paths and SHOW GRANTS, test denials using each identity, and review definer requirements before revoking migration access. Application tenant authorization remains mandatory even with schema-scoped grants.

## Persistent storage, secrets and fail-closed standby

Follow [COMPOSE-DESIGN.md](COMPOSE-DESIGN.md): dedicated project/directory, internal network only, no host ports, external networks, Docker socket or IPO/Copilot mounts. No phpMyAdmin service. Administration uses a private local socket/exec through existing authenticated SSH/Tailscale, with source-restricted ACLs and host-key checks. Tailscale installation/access is not assumed verified; do not expose 3306 publicly or create a remote rule.

Reserve separate persistent volumes for DB data, lifecycle spool, rate state, sessions and retained legacy store/uploads. Define owner UID/GID and paths before start; backups live off-VPS under separate credentials. Do not mount a whole repository writable. Use read-only runtime filesystems, tmpfs for temporary files, capability drop, no-new-privileges and bounded logs. DB runtime writable paths must match the pinned image. No automatic migration scripts on container boot.

Secrets remain outside Git with protected parent directories and service-specific file mounts. Use the real PHP configuration loader paths described in Compose design; generic `_FILE` variables do not configure PHP. Remove conflicting inherited environment overrides. No real Meta/AI credentials in synthetic standby. Missing image digest, secrets, schema version, P0 artifact or isolation evidence must stop acceptance; no fallback to the old DB.

All profiles manual, restart `no`, no schedulers. Worker remains a disabled one-shot `run`, exact sync and override flags false; exclude readiness-record commands. P0's legacy sender is disabled, but there is no universal outbound flag: network enforcement and blocked onboarding/override routes are also required. Verify no-egress from each container, not merely YAML. Current hosted production outbound state remains UNKNOWN.

Initial cap remains 1 CPU / 2.25 GiB total. Obtain current host sockets, network/subnet and volume inventory, resource limits, MemAvailable, free space/inodes, swap/OOM, timers and I/O through an approved read-only host route. Do not print container environment/secrets. Allow for encrypted backups, binlogs, two restores and working space; require ≥2 GiB available and ≥20 GiB free **after** reservations plus stable existing workload health. Historical graphs alone cannot pass this gate.

## Recovery, observability and rollback

Use the timed procedure in [GATE-CLOSURE.md](GATE-CLOSURE.md): separately held keys, off-host encrypted base backups plus gap-checked binlogs and coordinated file/spool watermarks. Proposed retention 7 daily/4 weekly with 7-day continuous log chain. RPO ≤15 minutes and RTO ≤2 hours remain unproven targets. Fresh initialization still needs backup/restore proof before valuable state is accepted. Synthetic restore does not prove production-sized recovery.

Rehearse corrupt/missing backup, log gap, lost key, interrupted migration, duplicate replay and source unavailability. Validate trigger/FK/CHECK behavior, counts/chunk hashes and offline decrypt success without printing contents. Recovery must use a new OS-only volume, never whole-VPS restore or another product's volume.

Capture release/schema identifiers, redacted errors, durable ingest/dedupe counts, queue review/lease states, spool age, DB connection/disk status, backup chain age and workload health. Any attempted outbound or isolation failure aborts standby acceptance. Alert at recoverable watermark age >10 minutes, breach >15 minutes; logs rotate 10 MB ×3. Alerts need a separately approved destination, not borrowed IPO/Telegram credentials.

Standby rollback stops only the new OS project and retains its evidence/volumes. No prune, down -v, source DB deletion or host restart. A future live rollback must freeze writes and reconcile destination deltas before changing authority; never flip back to stale data. Only a verified secure source artifact can receive callbacks. If none exists, fail closed with retriable errors/durable signed spool rather than returning to the unsigned legacy handler.

## Minimum owner actions and next engineering gate

Validation in this update: offline verifier PASS for pinned source ancestry/tree and 24 file hashes; signup JavaScript suite PASS 30 checks on #63; local Markdown links/fences and whitespace checks PASS. The historical 400-check PHP/DB result in GATE-CLOSURE.md was not rerun. No DB-version compatibility or restore success is inferred from these static/JavaScript checks.

Migration SHA-256 pins (unchanged between main and #63):

| Migration | SHA-256 |
| --- | --- |
| 0001 | `506862ea92234627c2fbe8bed636689b61063dbadb1b4155e15c63c804894b2c` |
| 0002 | `a6804229f6edf9f515fe0b29b16d6e378f1df84e3753b30809672513782aaa64` |
| 0003 | `2881083056c097537a133da0c78ea7b10f249c0241d309abc359e6b18d4312c0` |

1. Name P0 reviewer and DB/Meta/backup custodians; supply approved read-only SQL and host-inspection routes or private redacted evidence. Do not send passwords/tokens in chat.
2. Confirm the preservation register and off-VPS backup/key custody destination. Existing provisional recovery targets remain as above; no deletion decision is implied.
3. After the exact candidate test package is prepared, separately authorize a disposable synthetic engine/migration/restore test. No current engine acceptance is claimed. Engineering must then produce a pinned runtime artifact/Compose and effective isolation results.
4. Only when prerequisites pass, review a concrete synthetic-only standby start on srv2061175. Data transfer, production secrets, DNS, Meta/WABA, public ingress, merge/deploy and live messaging each remain outside that approval.

The future public ingress decision is not required to review this plan. Caddy/IPO/Copilot remain untouched. No services, SQL writes/imports, backup copies, outbound or live probes were performed by this addendum.
