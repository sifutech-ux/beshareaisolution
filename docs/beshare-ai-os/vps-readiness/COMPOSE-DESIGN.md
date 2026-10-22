# Isolated Compose design — proposal only

No runnable Compose, image, credentials or VPS files are created by this change. This specification deliberately requires implementation and offline validation before a later standby start. Do not use existing main as a secure deploy artifact: P0 is still a separate draft.

## Services and resource envelope

Proposed project `beshare-whatsapp-os-standby`, dedicated directory `/opt/beshare-whatsapp-os-standby`, with OS web/PHP, MySQL and worker services. No `container_name`, external networks, host networking, Docker socket, privileged mode, or mounts from IPO/Copilot. Every service belongs to an explicit manual standby profile, restart policy `no`; profile selection is convenience, not a security boundary (explicit service targeting can bypass it).

| Service | Proposed limits | Network / start behavior |
| --- | --- | --- |
| OS web/PHP | 0.35 CPU, 512 MiB, 128 PIDs; 128 MiB per PHP request | Only isolated internal network; no published ports; reviewed non-root web/PHP image, restricted public entrypoints |
| MySQL | 0.50 CPU, 1536 MiB, 256 PIDs; tune buffer pool ≤512 MiB initially | Same internal network, no published 3306; empty dedicated named data volume; no auto-migration init scripts |
| Coexistence worker | 0.15 CPU, 256 MiB, 64 PIDs | Same network; one-shot exact `run` command; sync false, override false; no cron/loop/restart |

This caps the new services at 1 CPU / 2.25 GiB initially; it is a budget proposal, not a performance guarantee. Pause future start if existing workload health deteriorates, available RAM <2 GiB after reservation, or free disk <20 GiB including backup/restore allowance. Rehearsal must account for dump + two restored datasets + logs, not just today's reported 2 MB DB.

Pin images to reviewed immutable digests and record source commit, PHP version/extensions and exact MySQL distribution/version. Target MySQL 8.4 is a candidate, **not certified**: existing evidence is MariaDB lab testing. If source is MariaDB, separately decide whether to preserve that engine first or validate conversion to MySQL. Do not silently substitute PostgreSQL from another project. Validate CHECK constraints, generated columns, foreign keys, trigger definers, SQL mode, collations and byte-preserving ciphertext restore on the chosen engine.

## Required Compose contract (not an executable example)

- `networks.os_internal.internal: true`; all three services attach only to it. No ports in initial standby. A later loopback-only binding requires observed free-port evidence and separate approval; 8090 is already occupied. No DNS change.
- OS/worker root filesystem read-only, drop all capabilities, `no-new-privileges`, non-root UID; tmpfs for temporary files. MySQL writes only its own data/run locations using its image-supported UID. Verify UID and mounted secret readability in a disposable lab.
- OS and worker receive `BESHARE_COEXISTENCE_SYNC_ENABLED=false` and `BESHARE_PHONE_OVERRIDE_ENABLED=false`. No invented `OUTBOUND=false` variable: there is no universal existing send kill switch. Require P0's disabled legacy send plus network isolation and no live Meta/AI credentials for standby.
- P0 is not sufficient to disable every onboarding Graph request. Standby must deny onboarding/override mutations at ingress, have no internet egress, and use synthetic assets. Verify failed external connection attempts from both OS and worker; internal-network configuration alone is not evidence of effective isolation.
- Fix worker command to `php os/bin/sync-coexistence.php run`. Do not run `record-callback`/`record-fields`: they write state independently of the run flag. Phone override CLI is excluded. No automation that resumes `review` jobs or reruns ambiguous POSTs.
- Secrets use per-service file mounts under `/run/secrets`, host source outside repo with parent mode 0700 and restrictive file ownership; Compose secrets are not encrypted-at-rest storage. Never commit real secret files, use command-line passwords, or log `docker inspect` environment wholesale.
- `BESHARE_OS_DB_CONFIG=/run/secrets/os-db.php` (PHP array with dsn/user/password) and `BESHARE_OS_WEBHOOK_CONFIG=/run/secrets/os-webhook.php` (PHP array) match current loaders; a generic `_FILE` suffix is not implemented by these PHP classes. MySQL's image-specific password-file option must be verified independently. Root DB secret never mounts into OS/worker.
- Remove inherited DB/token env overrides in runtime configuration. Keep database name `u879723783_beshare_os` to satisfy `Contract::DATABASE`. Application and migration accounts are distinct; runtime gets only needed DML, migration credentials appear only in a separate approved one-shot maintenance context.
- Private config includes app secret, verifier, AES key/key version and, if legacy is included, P0's verified string `legacy_business_id` and `legacy_waba_id`. Never guess bindings. Synthetic standby uses synthetic values only. Production-shaped restore later requires original decryption keys with separate encrypted custody; changing a key without re-encryption strands existing data.

## Durable state and public boundaries

Use project-scoped named volumes for MySQL, lifecycle spool, rate-limit state and any retained legacy store/uploads. Explicit paths: `BESHARE_OS_RATE_DIR`, `BESHARE_OS_LIFECYCLE_SPOOL`, `BESHARE_STOR`, `BESHARE_MUAT`. Spool must be writable outside repo/web root; a read-only secret directory is not a valid default spool parent.

Legacy webhook code also directly addresses `admin/stor/data.json` and `webhook-dilihat.txt`; review/refactor these paths or provide a specifically denied private mount before including it. No broad writable repo mount. Prefer excluding legacy public Desk/admin routes from initial standby entirely, retaining their data only in protected rehearsal storage.

An OS image must expose only selected public HTML/assets and PHP entrypoints. Deny `os/`, tests, migrations, private configs, hidden files, raw legacy stores/uploads, admin internals and directory listing at web-server level. Do not assume nginx honors `.htaccess`. Require actual HTTP denial checks before declaring readiness. PHP sessions should use a private writable path; plan deliberate re-login at final origin change, not a blind session copy.

## Routing decision

Proposed future hostname: `os.beshareaisolution.com` (candidate only). Move OS UI and PHP together to retain same-origin fetches, `/whatsapp/` cookie path and CSRF assumptions. Marketing remains unchanged; later only its OS link changes. A backend-only split would need explicit cookie/CORS/CSRF redesign and is not this plan.

Caddy already owns 80/443 on this VPS. A DNS A record pointing a new hostname at the same IP does not create a new ingress, and a second proxy cannot bind those occupied sockets. Under the no-Caddy-change constraint, standby has **no public route**. For a later release, evaluate an independently managed external ingress/tunnel terminating standard HTTPS to a dedicated OS listener, or another isolated ingress/IP. Availability, security and cost are UNKNOWN; do not provision one here. If all acceptable options need Caddy changes, live cutover remains blocked until the user explicitly revises that constraint. Do not assume a nonstandard public port is acceptable to Meta.

Official references: [Compose internal networks](https://docs.docker.com/reference/compose-file/networks/), [Compose secrets](https://docs.docker.com/compose/how-tos/use-secrets/), [MySQL 8.4 ALTER TABLE](https://dev.mysql.com/doc/refman/8.4/en/alter-table.html). MySQL documents `DROP CONSTRAINT`; that syntax alone is not a reason to rewrite 0002/0003. Full migration compatibility still requires rehearsal.
