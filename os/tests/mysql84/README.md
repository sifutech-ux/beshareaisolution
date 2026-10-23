# MySQL 8.4 foundation migrator compatibility

23 October 2026 MYT. **Foundation compatibility PASS; VPS standby still gated.**

This change is based on Security P0 PR #63 at `c955270cf393c435bc51356ce9f261eda4ceb2fb`. PR #64 at `bd04d67` is documentation based on main, not the P0 runtime, so this is a separate stacked runtime PR. No migration SQL bytes or P0 security behavior changed.

## Change

- BackupProof accepts the MySQL 8.4 version family as well as MariaDB. Dump header, manifest, database name, SHA-256 and empty inventory remain mandatory. The connected server's exact VERSION must now match the proof before any migration lock or DDL.
- MySQL joins CHECK_CONSTRAINTS using schema/name, reading the table from TABLE_CONSTRAINTS. MariaDB retains its table-qualified join (constraint names can repeat across tables there). MySQL NOT ENFORCED checks are rejected.
- Catalog comparison decodes MySQL's escaped `_utf8mb4` ASCII enum literals and canonicalizes the condition in a CASE generated expression. It does not discard CHECK clauses, owner expressions, columns, keys or triggers. Different literals and expressions remain mismatches.
- The original foundation state machine and ledger transaction remain intact: only successful full DDL plus matching definitions writes `0001`; complete-unrecorded, partial and drift states refuse repair/replay.

Official catalog references: [CHECK_CONSTRAINTS](https://dev.mysql.com/doc/refman/8.4/en/information-schema-check-constraints-table.html), [TABLE_CONSTRAINTS / ENFORCED](https://dev.mysql.com/doc/refman/8.4/en/information-schema-table-constraints-table.html).

## Results

| Test | Result | Evidence |
|---|---|---|
| MySQL Community 8.4.13, Linux arm64 | PASS | `evidence/foundation.json`, `ledger.txt`, `catalog.json` |
| MariaDB 11.8.9, Linux arm64 | PASS | `evidence-mariadb/foundation.json`, `rerun.json`, `ledger.txt`, `catalog.json` |
| Genuine 0001 ledger | PASS both engines | Exactly one row, original SQL SHA-256 `506862ea92234627c2fbe8bed636689b61063dbadb1b4155e15c63c804894b2c`; no manually fabricated success row |
| MySQL migration/app roles and negative tests | PASS, 15 assertions | `evidence/checks.json`, `grants.txt`: app cannot CREATE, DROP TRIGGER or insert/update ledger; schema/check/generated drift, wrong server/database/checksum and concurrent lock rejected |
| Interrupted/unrecorded DDL | PASS, two separate fixtures | `evidence/interrupted.json`: after 2 statements → exit 5 partial; after all 13 → exit 7 complete-unrecorded; ledger stays empty |
| Static compatibility | PASS, 20 new assertions + existing foundation suite | `check-mysql84.php`, `evidence/static-*.txt`; original MariaDB captured fixture still agrees |
| Existing application regressions | PASS, **400 per engine** | 121 P0 + 102 auth + 64 Meta + 37 coexistence + 33 worker + 13 lifecycle + 30 JS; `evidence*/regression.json`, `signup.json` |
| MySQL persistence and encrypted restore | PASS, bounded synthetic | `evidence/recovery.json`, fingerprints: real ledger + one multilingual synthetic user, fresh separate restore volume, source stopped, all canonical DDL/trigger/row hashes match |
| Wrong/missing encryption key, tampered envelope, corrupt checksum | PASS | Zero plaintext on authentication failures; SHA-256 compared before import |
| Isolation | PASS local | `evidence/isolation.json`, `egress-*.json`: network none, no published ports, named volumes only, no production/host mounts |
| 0002/0003 production migration procedure | **BLOCKED / outside this fix** | Application suites execute their unchanged DDL, but foundation migrator intentionally does not run or ledger them |
| VPS standby / production recovery SLO | **BLOCKED** | No target-host execution, capacity certification, PITR or production-sized recovery test |

Restore includes two rows (one genuine migration row and one synthetic user), 8 tables and 5 triggers. Its 24,842-byte dump SHA-256 is `b8359d40b7f7ca168e0be4f87baa54da7321a338a340b9cc8614a6095acc25b1`. Recovery workflow took 8.56 seconds including local restart, encryption, new DB creation and import; excludes image retrieval and human response. This does **not** establish RPO ≤15 minutes / RTO ≤2 hours. DDL comparison removes only redundant ` CHARACTER SET utf8mb4` added by dump/import; raw hashes are retained. Trigger creation timestamps alone are excluded, not bodies/definers.

## Runtime and privileges

- MySQL index digest: `sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69`; arm64 manifest from PR #64: `sha256:b265fd63b9c8168801c1628b0b21e915846e81192f5a493682a1184cbd70d896`.
- MariaDB index digest: `sha256:f16d7a344413e519dff518f27bdb221388f84b0d274c97c4fb5f2cb0dc1b34fa`; arm64 manifest `sha256:3607e75203fd9f69fb7e0829ec2d8fe55c30ec9d8aa266f7580719711bb4a4e1` (`evidence-mariadb/oci-index.json`).
- PHP base index: `sha256:cdb77e306d6bb99db40e513fbe036ecc6c53b575cbcb7e581387845fef26d755`; lab tooling image ID `sha256:701b58a4c6e3305c4cff95dd77e02ae6caf0676cda3e72442d494918c494d1ae`. PHP/PDO/mysqlnd 8.3.36. Image IDs, digests, architecture and runtime source hashes are recorded in `evidence/`.
- Actual foundation execution uses `migration@localhost`, not root. Schema-local SELECT, INSERT, CREATE, ALTER, INDEX, REFERENCES, TRIGGER, EVENT, SHOW VIEW; no SUPER, FILE, GRANT OPTION, global DML or account creation. EVENT is privileged metadata access and must stay with the short-lived migration identity. Root only provisions lab accounts, negative fixtures and restore/regression setup. App has ledger SELECT only; the negative-test account's users-table DML is a test role, not a complete production grant template.
- MySQL with binlog initially rejected CREATE TRIGGER without SUPER (attempt r1, DDL stopped at statement 4, empty ledger). The successful disposable instances explicitly use `log_bin_trust_function_creators=1`; migrator does not set it or grant SUPER. This server-wide setting requires review for the isolated target instance. Do not apply it to a shared production server as an incidental fix. MariaDB's tested default binlog is off; see version evidence.
- Initial full-DDL attempt r2 stopped at definition mismatch because MySQL formats the owner CASE expression differently. Latest code refuses that complete-unrecorded state rather than backfilling it. A negative-fixture reset initially used latin1 session literals; fixing the harness to use the migration's utf8mb4 session restored exact comparison. Historical regression setup initially encountered already-created tables; final runs use independent empty volumes. These failed setup attempts are not counted as passes.
- Catalog inspection is bounded by the existing definition model and operator visibility. This is not exhaustive certification of arbitrary SQL expressions, all foreign-key options, index options or hidden pre-existing routines. Use an operator-verifiable empty dedicated database, not an unknown existing schema.

## Reproduction and cleanup

Requires the dedicated local Colima profile `beshare-rehearsal` (no host mounts), its fixed Docker socket, pinned images above, and the lab tooling Dockerfile from PR #64. Scripts never accept an external DSN. All credentials are fixed synthetic disposable values; no production environment is copied.

Choose a new prefix in lab.py and mariadb.py to retain existing evidence and volumes; resource collisions refuse initialization. Run from repository root:

1. `python3 os/tests/mysql84/lab.py`
2. `python3 os/tests/mysql84/verify.py`
3. `python3 os/tests/mysql84/recovery.py`
4. `python3 os/tests/mysql84/mariadb.py`
5. `python3 os/tests/mysql84/regression.py` and `python3 os/tests/mysql84/regression.py maria`
6. `python3 os/tests/mysql84/interruptions.py`
7. `php os/tests/check-mysql84.php` and `php os/bin/check-migration.php`

The regression adapter changes transport/setup only: dedicated socket, fixed baseline file, retained PHP extension scan directory, and removal of MariaDB-only NO_AUTO_CREATE_USER on MySQL. Assertions and runtime files are unchanged. The foundation catalog extractor reads the actual inspection SQL; the committed static fixture protects subsequent comparison regressions.

Stop only exact names in `evidence/isolation.json`, then the dedicated Colima profile. Do not prune, delete volumes, run down SQL or reuse a partial schema. All executed lab containers and the dedicated VM were stopped after testing; volumes retained. No production data, Hostinger DB, DNS, Meta callback/WABA, outbound, Copilot, IPO, Telegram or Caddy was touched; no merge or deploy.

## Minimum remaining gates and fastest MVP route

1. Review P0 #63 and this stacked compatibility fix; approve a deterministic 0002/0003 maintenance/ledger procedure. Test the intended VPS architecture and reviewed binlog/trigger policy with short-lived migration credentials, then revoke/lock migration access while retaining necessary trigger-definer privileges.
2. Pin a secure runtime and concrete isolated standby configuration: private routes, no outbound, disabled workers/sending, dedicated volumes/secrets, resource limits and current host capacity/port evidence. Inspect only the required host facts once; reuse the evidence already collected.
3. Name DB/Meta/backup custodians; confirm preservation inventory and off-VPS encrypted backup/key custody. Test realistic restore/PITR before claiming recovery objectives. Obtain separate approval for the concrete synthetic standby start.

Fastest useful MVP: one tenant and one verified number → signed, idempotent inbound → server-verified WABA + phone tenant mapping → tenant-approved knowledge → queued Gemini draft using a mocked transport first → stored human handoff/inbox with pause and ownership controls. First deliver a complete synthetic end-to-end draft visible to an authorized operator, with cross-tenant, duplicate, retry and handoff tests. Then separately authorize staging callback and Gemini credentials. Outbound sending/auto-replies require their own implementation and activation; this PR does not enable them.
