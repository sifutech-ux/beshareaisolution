# Stripe sandbox integration

Application billing is disabled by default. No live payment is enabled.
The approved restricted test credential is stored in `/etc/beshare/billing-sandbox.env`
on the Hostinger VPS and sibling `private/beshare-billing.json` on shared hosting,
both 0600 outside the public web root. No credential belongs in Git.
Catalogue IDs and API smoke-test evidence: `../os/docs/stripe-sandbox-catalogue.json`.

## Implemented

- Copilot bearer-authenticated checkout, status and portal under `/api/akaun/bil`.
- AI OS cookie/session-authenticated owner-only billing with existing CSRF,
  origin, rate and tenant checks. The browser cannot select a price/customer/owner.
- Checkout uses stored attempts and Stripe idempotency; ambiguous timeouts retain
  the attempt. A pending attempt older than 23 hours requires operator review.
- Copilot supports a seven-day first trial; missing payment method pauses it.
- OS requires inbound and successful outbound evidence on the same active WhatsApp
  connection before checkout. RM199 monthly plus RM299 setup; paid setup is remembered.
- Raw-body HMAC webhooks verify the environment and timestamp before database access.
  Duplicates are harmless; reordered events reconcile current Stripe subscription state.
  Only a paid matching invoice grants a paid-through period. A return URL never does.
- Billing failure cannot block the existing OS workspace payload.
- Fixed Stripe-hosted redirect origins and explicit sandbox account/business allowlists.

## Installation order

1. Run the isolated tests and check the exact deployed account file hashes.
2. Build `billing/copilot/Dockerfile` on the VPS using the existing account image as
   `BASE_IMAGE`. It verifies the two changed source baselines and preserves all other
   deployed modules, dependencies, runtime user and entrypoint. Do not overwrite the
   account database guard, intelligence modules or the trading stack.
3. Keep billing disabled. Deploy the additive PHP/frontend code. Inspect
   `php os/bin/prepare-stripe-billing.php`, then apply only its returned checksum using
   `--apply --checksum=HASH`. The migration adds two tables, with no existing data edits.
4. Register test-only snapshot event destinations (API version `2026-08-26.dahlia`):
   - `https://akaun.beshareaisolution.com/api/akaun/bil/webhook`
   - `https://beshareaisolution.com/whatsapp/bil/webhook.php`
   Subscribe only to the event types in `pelanggan_bil.EVENTS` / `StripeBilling::EVENTS`.
5. Store signing secrets privately. Copilot uses `BESHARE_STRIPE_WEBHOOK_SECRET`;
   OS reads sibling `private/beshare-billing.json` outside `public_html` (0600).
   Never display secret values, include them in commands/history, or put them in Git.
6. Enable sandbox for explicit synthetic QA account/business IDs only. Verify actual
   authenticated checkout -> signed webhook -> status -> portal. Keep live disabled.

Copilot environment also needs `BESHARE_STRIPE_MODE=test`, `BESHARE_BILLING_ENABLED`,
`BESHARE_STRIPE_SECRET_KEY`, `BESHARE_COPILOT_PRICE`, `BESHARE_COPILOT_AMOUNT=2900`,
`BESHARE_STRIPE_PORTAL` and `BESHARE_BILLING_ACCOUNT_ALLOWLIST`.
OS private JSON keys: `enabled`, `mode`, `secret_key`, `price`, `setup_price`, `portal`,
`webhook_secret`, `business_allowlist`. Use test IDs from the catalogue.

Rollback: disable billing first; restore the previous account image and PHP/frontend
release. Keep the additive billing tables and event history for reconciliation.

## Verification

Local: 19 Python billing/HTTP tests and 26 PHP billing checks passed.
GitHub CI: all five suites passed at commit 38b5a9f, including 33 Python tests,
26 SQLite PHP checks and 30 MySQL checks (actual migration included). The existing
account suite passed 41 tests, with 32 PostgreSQL tests skipped locally. Existing
Copilot audio/intelligence UI checks passed (12 tests). No real card charge.

`.github/workflows/stripe-billing.yml` runs the lifecycle tests on disposable
PostgreSQL and MySQL databases, including concurrent checkout and the exact MySQL
migration. No application database credential is used by these fixtures.

The standalone `stripe_sandbox_probe.py` verified all five test prices, synthetic
customers, both checkout totals and portal creation from the VPS. Those are API
smoke tests, not proof of the authenticated application flow.

## Deployed sandbox state — 13 November 2026

The account image and shared-hosting code are deployed. Migration 0009 installed
only `os_billing` and `os_billing_events`. Both Stripe test destinations are active
and their signing secrets are configured privately. Copilot permits one synthetic
QA account; the OS business allowlist is empty. Live billing remains disabled.

Both public webhook endpoints reject unsigned requests with 400 and accept a valid
signed test event for an unknown customer with 200/ignored. Shared-hosting PHP
verified the RM199 and RM299 test prices directly against Stripe. Its temporary
probe cron was removed after success.

The actual Copilot account API created a checkout and reused it on a repeated
request. Stripe Checkout completed a seven-day trial without collecting a payment
method. Stripe's webhook updated the account to `trialing`, active, with the first
trial consumed. The authenticated portal opened and displayed the zero-value paid
trial invoice. Stripe Portal schedules the tested trial cancellation with `cancel_at` while
`cancel_at_period_end` is false. Reconciliation now reads both, caps the access
date and restores the paid invoice period if the cancellation is undone.
This does not yet verify renewal or failed-payment behavior.

Evidence is stored privately on the VPS under
`/etc/beshare/billing-release-20261113/webhook-and-checkout.json` and on shared
hosting as `private/billing-sandbox-check-20261113.json`. These summaries contain
no credentials. The catalogue records endpoint identifiers and non-secret results.

## Still required before commercial launch

- Pass AI OS authenticated sandbox checkout using verified WhatsApp evidence.
- Enforce the paid cohorts, 12-month early pricing rules, trial usage and paid quotas.
- Integrate billing entitlements with AI text/voice and OS successfully-sent usage.
- Implement the OS top-up credit ledger before selling top-ups.
- Verify actual Stripe renewal, failure and cancellation deliveries.
- Use a separate live catalogue and approved live configuration.

Current billing status records do not yet enforce AI usage. Do not enable live
checkout until these launch requirements are complete. Payment links in the
catalogue are synthetic fixtures and must never be app checkout entrypoints.
