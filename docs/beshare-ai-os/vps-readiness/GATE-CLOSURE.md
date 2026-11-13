# Pre-Standby Gate Closure — 23 October 2026 MYT

**Decision: BLOCKED.** This follow-up closes source-reconciliation and repeatable lab-test evidence only. No standby started; no production data copied, migrations run, live messages sent, outbound activated, DNS/Meta/WABA edits or merge. Caddy, Business Copilot, IPO and Telegram remain outside the write boundary.

## Pinned source and test evidence

GitHub metadata and fetched Git objects agree:

- main: `69e4d3fb7693db6d199dce7005eed73d65f60069`.
- #63: `c955270cf393c435bc51356ce9f261eda4ceb2fb`, open draft, not merged.
- #64 before this update: `9cafde2cdc1737b2ea9a68e574c37b101861df4f`, open draft, not merged.
- main is an ancestor of #63. Therefore #63 already contains the current Coexistence main code; no cherry-pick or merge is needed to form this source candidate. It changes 17 files; migrations 0001–0003, CoexistenceWorker, PhoneOverride and LifecycleBridge are byte-identical to main. P0 changes the webhook routing boundary, so unchanged workers alone would not establish compatibility.
- [Source manifest](checks/source-manifest.json) pins the source tree and SHA-256 of the changed files plus migration/worker/override/lifecycle paths. This is not a container/image manifest or reviewer approval. Rerun reconciliation if either head moves.

Fresh local execution on the clean #63 checkout, PHP 8.3.36 / MariaDB 13.0.2, synthetic fixtures, private Unix DB socket with DB networking disabled, loopback HTTP, disabled PHP outbound transports and mocked Meta transports:

| Test | Result |
| --- | --- |
| `python3 os/tests/run-security-p0.py --extended`: P0 HTTP/DB | PASS 121 |
| Existing auth / Meta / Coexistence / worker / lifecycle suites | PASS 102 / 64 / 37 / 33 / 13 |
| `node os/bin/check-signup-event.mjs` | PASS 30 |
| Total behavioral checks | **PASS 400** |
| PHP lint under os, whatsapp, webhook | PASS 63 files |
| `python3 docs/beshare-ai-os/vps-readiness/checks/verify-source.py` on readiness branch | PASS source reconciliation |

These tests cover signature rejection before effects, cross-tenant denial, CSRF, membership revocation, missing binding/config failure, WABA+phone routing, dedupe, and existing Coexistence behavior. They do not prove host firewall isolation, real Meta subscriptions, production schema, production outbound state, target MySQL compatibility, image HTTP denial or restore/RPO/RTO. Local disposable test processes are not VPS standby services. No runtime code is changed by this PR.

## Gate register

PASS is limited to the named evidence, never inherited by a broader gate.

| Gate | Status | Evidence / shortest closure action |
| --- | --- | --- |
| P0 + current Coexistence source reconciliation | PASS | Ancestry, pinned source manifest, 400 fresh checks above |
| Reviewed secure deploy artifact | BLOCKED | #63 remains draft; reviewer, immutable built image, secret-free build manifest and public-path denial tests absent |
| Production schema/engine/DDL | UNKNOWN | Hosting confirms DB existence only; approved SQL read route not supplied |
| Tenant–WABA–phone and legacy-store ownership | UNKNOWN | No DB row evidence or Meta owner evidence; use staged queries and private alias crosswalk |
| Callback/fields/WABA subscriptions/phone override | UNKNOWN | No authorized Meta read session or redacted admin evidence available; do not infer from hosted files or DB readiness timestamps |
| Secret custody and recovery | UNKNOWN | No custodian attestation or offline approved decrypt proof; do not retrieve secret values into chat/Git |
| Exact target DB compatibility | BLOCKED | MySQL 8.4 remains candidate; available local server is MariaDB 13.0.2, not Oracle MySQL. Source engine unknown; target version/digest unselected |
| VPS plan/project/port/firewall inventory | PASS | Fresh Hostinger reads below; no claim of complete host inventory |
| Current host capacity and effective isolation | BLOCKED | Historical metrics available; MemAvailable, inodes, I/O, OOM/swap, complete sockets/networks and actual deny-egress proof missing |
| Backup and isolated restore, RPO/RTO | BLOCKED | No application-consistent off-host backup/key custody/rehearsal evidence; provider snapshots insufficient |
| Public HTTPS without Caddy changes | BLOCKED for cutover | 80/443 already owned; initial synthetic standby must have no public route |
| Standby start | BLOCKED / not authorized here | Requires secure artifact, selected target tests, isolation and current capacity evidence, then separate scoped start |

## Fresh read-only infrastructure evidence

Observation window approximately 23 October 2026 07:05–07:11 MYT. Hostinger operations: `vps_virtual-machines_list`, `vps_docker_list`, `vps_docker_containers` (IPO), `vps_firewall_get`, `vps_backups_list`, `vps_virtual-machines_metrics`, `hosting_websites_list`, `hosting_databases_list`, `hosting_databases_list-remote-connections`.

VPS 2061175 remains running, 2 CPU / 8192 MB RAM / 102400 MB disk. Project list shows IPO 2, Copilot 5 and copilot-production 3 containers, all healthy; no WhatsApp OS project. IPO publishes loopback 8090; Caddy publishes 80/443 IPv4 and IPv6. Firewall 378885 is synced, accepts TCP 22/80/443 from any source. No changes made. These reads do not enumerate host-native processes or prove absence of other listeners.

Metrics requested from 00:00 to 07:07 MYT, 23 October: 14 historical samples, latest 06:48:25 MYT; CPU 5.88–6.55%, RAM 1,659,162,624–1,696,612,352 bytes (~1.55–1.58 GiB), disk used 12,260,524,032–12,260,888,576 bytes (~11.42 GiB). This suggests room for the proposed capped lab but cannot certify available memory, transient peaks, filesystem space or I/O. IPO's detailed container read also reports both containers healthy; no load test was performed.

Latest listed VPS backup remains 21 October 2026 14:44:18 MYT. The API's `restore_time=1800` is provider metadata, not measured OS recovery. Whole-VPS restore would disturb existing workloads and is prohibited by this plan.

Hosting confirms enabled beshareaisolution.com and `u879723783_beshare_os`, reported 2 MB, external endpoint srv2050.hstgr.io:3306. The existing app user includes ALTER/DROP/DML privileges. **New finding:** remote rule is `%` (all remote hosts at the account-rule layer); network reachability is not proven. This is not an approved read-only identity. Do not use it for this audit or revoke it without identifying consumers and a separate change plan. Owner should supply a restricted read identity/approved execution route. No credential extraction, new remote rule, SQL endpoint deployment or production probes were attempted.

## Minimum evidence packet to request

1. **DB custodian:** approved read-only connection route via protected credentials, or privately generated outputs of [metadata inventory](checks/production-inventory.sql). Confirm schema identity first. Then private SHOW CREATE TABLE/TRIGGER and [ownership inventory](checks/ownership-inventory.sql) after validating columns. Include applied ledger checksums, actual constraints/definers, engine/version/collation/SQL mode and binlog capability. Empty/denied metadata is UNKNOWN. Avoid broad full-table scans until cost is approved.
2. **Ownership/Meta admin:** dated private crosswalk: tenant alias → active owner membership → connection alias → WABA/phone alias → actual owning Meta business/app. Confirm legacy JSON store maps to exactly the configured business/WABA. Read current app callback URL and subscribed fields, WABA subscribed-app identity, phone override status, app permissions and intended test-number eligibility. Record which UI/API supplied each fact and observation time. A shared WABA requires review, not an automatic assumption of misownership. No register/subscribe/override or readiness-write commands.
3. **Secret custodian:** attest named owner, protected storage location reference, access list, mode/owner, version identifiers and recovery procedure for DB credentials, Meta app secret/verifier, token-encryption keys and backup-encryption keys. Verify environment override presence without values. Match every DB/spool key version to recoverable keys; validate decryption of an approved sample only inside isolated offline rehearsal and record boolean success. Never include token/ciphertext/key bytes or hashes of low-entropy secrets in PRs. Missing keys are a hard stop.
4. **Owner:** name P0 reviewer, DB/Meta evidence custodians, backup destination and recovery/key custodian; select exact target engine after source evidence. Provide an approved host read route or operator-generated capacity/socket/network evidence. No passwords/tokens in chat. No need to choose a public hostname to complete the offline gate.

No connected Meta read capability or approved production SQL session was found in the available tools/context. Hosting discovery returned administrative DB inventory/rules, not SQL execution. Access remains UNKNOWN, not proof that the platform cannot support it.

## Recovery design: RPO ≤15 minutes, RTO ≤2 hours

These owner-requested provisional targets supersede the earlier 30-minute RTO proposal. **Neither target is achieved or measured.** RPO is the age of the last fully recoverable committed application state at incident time, including DB, legacy files, uploads and spool. RTO starts at incident declaration and ends when verified secure intake can resume; include provisioning, key retrieval, restore, validation and routing/operator time.

Preferred design after source capabilities are known: consistent base backup plus continuously archived encrypted binlogs to separate failure-domain storage, with bounded archive lag and gap detection. MySQL PITR restores the base then applies subsequent log events; [official MySQL PITR documentation](https://dev.mysql.com/doc/refman/8.4/en/point-in-time-recovery.html). Binlog availability, retention, required read privileges and provider support are UNKNOWN. Do not enable/change binlogging or replication in this task.

If shared hosting cannot expose binlogs, frequent consistent logical exports are an alternative only after proving worst-case snapshot age + export/upload/verification delay + failure/retry allowance stays ≤15 minutes without harming production. A cron every 15 minutes alone cannot establish that. If this bound cannot be met, **binlog/PITR-capable hosting or another reviewed change-capture arrangement is required**, or the owner must revise the target. Daily/provider backups do not meet the design target. DB binlogs do not protect legacy files/spool: use coordinated snapshots/versioned deltas and a common recovery watermark for those too.

Baseline proposal: encrypted daily base backups, archived logs/deltas within 5 minutes, 7 days of a continuous recoverable log chain plus 7 daily/4 weekly verified bases, subject to storage/privacy policy. A retained base without its required logs is not a PITR point. Alert at recoverable-point age >10 minutes; target breach >15 minutes. These are future design thresholds, not installed jobs. Key custody stays separate from backup storage; a second authorized custodian must prove retrieval.

Logical export requires consistent transactional tables, no concurrent DDL and deliberate coordination for nontransactional/filesystem state. Preserve triggers/routines/events where present and review DEFINER mapping. [mysqldump documentation](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html) explains single-transaction limitations; do not assume a dump covers external files. Record source UTC watermark and binlog/GTID position where supported, dump/file hashes, bytes and schema identity in an encrypted manifest. No actual export authorized/executed here.

### Isolated restore rehearsal procedure (not executed)

1. Once data-copy scope and destination are separately approved, provision a disposable isolated environment with the exact selected engine/version/image digest, private volume/socket, no public ports, no Meta/AI credentials, no internet egress and no application workers/schedulers. Never use IPO/Copilot volumes or whole-VPS restore. Prefer an independent lab host if shared-host resource reservations cannot be demonstrated.
2. Start a timed incident scenario using an existing encrypted backup; retrieve backup and separately held keys. Verify hashes and complete log chain before import. Record timing for retrieval (budget 15 min), provisioning (15), restore/replay (45), validation (30), operator/routing readiness (15): total budget 120 min, not measured durations.
3. Restore baseline; replay only intended schema's reviewed log chain to the agreed boundary on the disposable server. Confirm the procedure cannot apply unrelated schemas, execute scheduler events or connect externally. Missing/corrupt segment or missing key must stop recovery visibly. Do not substitute a live data-directory copy.
4. Verify exact DDL/triggers/constraints and migration ledger, deterministic ordered chunk hashes/counts at the same snapshot, key-version coverage and offline decrypt success. Rehearse only missing 0002 then 0003; partial definitions stop. Never manufacture ledger rows. Record engine-specific syntax/definer/collation/SQL-mode differences rather than disabling constraints.
5. Test invalid owner/tenant writes rejected, correct WABA+phone routing, replay/dedupe and signed-event durable intake with synthetic transport. Test archived event/file watermark reconciliation, duplicate replay, corrupt/missing backup/log/key, and loss of source. Record every failure and stop condition. Do not deliver to Meta or send messages.
6. Compute RPO from incident timestamp minus the latest common successfully restored watermark, and RTO from all timed stages. Repeat at representative data/log volume and adverse lag, include legacy files. Require ≤900 seconds and ≤7200 seconds respectively, all checks passing, reviewer/custodian attestation. A small synthetic run alone cannot certify production targets.
7. Preserve redacted evidence and protected failure diagnostics; stop only lab services and retain data per policy. Disposal of production-derived copies requires the approved retention procedure. No such data was copied in this audit.

Capacity decision remains BLOCKED. Current 1 CPU / 2.25 GiB proposal needs actual peak headroom and disk/inode/I/O measurement including encrypted base + log retention + two restored datasets + working space. If reservation leaves <2 GiB available or <20 GiB free after all data/log allowance, use extra isolated capacity or reduce scope based on measurements; do not purchase/provision here. Even above those thresholds, throughput must meet the 2-hour budget and existing workload health must remain stable.

## Shortest route to standby

In parallel where access permits: reviewer assesses #63; custodians return the evidence packet; engineering selects exact DB target and builds a pinned isolated image/Compose from the reconciled source. Run target-engine migrations/security/restore tests and effective no-egress/private-HTTP-denial checks, then obtain fresh complete host capacity/network evidence. Re-evaluate every gate; present a concrete separately scoped synthetic standby-start request only after prerequisites pass. Production-shaped restore, public ingress and live callback cutover remain distinct later actions. This update neither merges P0 nor authorizes a start.
