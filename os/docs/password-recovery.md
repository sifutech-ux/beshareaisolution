# BeShare AI OS password recovery

`#lupa` queues a single-use recovery link for active accounts. `#pulih=<token>` captures the 256-bit secret in memory, removes it from browser history before requests, and shows the password/confirmation form. A page reload requires reopening the email. Reset does not automatically log the user in.

The same response is returned for known, unknown and email-throttled accounts. Five requests per IP/email per 15 minutes, a per-account 60-second mail cooldown and a separate reset-attempt limit apply. File locks serialize rate reservations. Links expire after 30 minutes. Only SHA-256 digests are used for authentication; temporary outbox secrets are AES-GCM encrypted with the existing private token key and erased after delivery/expiry. Account-row locks serialize simultaneous redemptions. Reset revokes every outstanding link and every prior session (including legacy, owner panel and media requests).

## Deployment

Additive migration 0008 creates two new tables; it does not modify account records. Apply with the reviewed SQL checksum using the CLI-only `os/bin/prepare-password-recovery.php --apply --checksum=...`. It refuses partial or mismatched installations and serializes installation. Existing sign-in remains available before migration.

Schedule `/usr/bin/php /home/u879723783/domains/beshareaisolution.com/public_html/os/bin/process-password-recovery.php` every minute. For an initial deployment, the cron command may run the checksum-bound, idempotent installer before the worker. Both scripts are CLI-only. The worker uses the existing private database/token configuration and Hostinger's built-in PHP mail transport, with fixed `noreply@beshareaisolution.com` sender and fixed HTTPS reset origin. It attempts at most five messages per run and three attempts per message, two minutes apart. Provider mail limits also apply (Hostinger's built-in mail currently documents 10/minute, 100/day); authenticated transactional mail is a later scaling option.

The worker writes a private readiness heartbeat. The HTTP recovery endpoints return 503 until migration is installed and that heartbeat is less than five minutes old. No HTTP request runs DDL. Cron output reports only accepted/failed counts; acceptance by the mail transport is not proof of inbox delivery. Check actual receipt before declaring email delivery verified. No production account password should be changed as a deployment test.

Rollback: remove only the newly created recovery cron job and its private readiness heartbeat; keep the additive tables and credential session checks. Reverting session checks after a real reset would restore pre-reset legacy sessions. Existing account, WhatsApp and AI data must not be modified by deployment.

## Validation

`os/tests/check-password-recovery.php` uses the explicitly guarded, disposable MySQL admin fixture and a fake mail transport. It covers request parity, CSRF/origin, encrypted queue, retry/cleanup, password policy, expiry, inactive users, replay, old/new authentication, legacy/current/admin session revocation and simultaneous redemption. It never connects to production or sends email.

`node os/tests/check-password-recovery-ui.mjs` executes the real workspace script with synthetic browser state. Existing connection-return and owner/admin UI checks must also pass. The platform-admin CI workflow runs the new integration checks on MySQL 8.4.
