# BeShare Group Distribution MVP — 23 November 2026

Status: source proposal only; publishing remains disabled by default. Do not deploy production without controlled tests.

## Scope

- A new Kumpulan & Saluran panel under the existing BeShare Marketing account.
- Per-tenant destinations: Telegram groups/channels with bot-admin verification, and WhatsApp groups for user-operated manual sharing.
- The owner acknowledges group promotion permission; Telegram automatic publishing additionally requires a group administrator to confirm the bot through an expiring one-time deep link.
- A separate Telegram bot receives private start commands and checks group admin rights and bot posting rights before verifying the destination. Only verified Telegram destinations can be scheduled.
- Text-only Telegram scheduling in this release, limited to one planned/successful group posting per 24 hours.
- A lost sendMessage acknowledgement becomes uncertain and is not automatically re-sent.
- WhatsApp group sharing uses a user-selected conversation and a manual Send action. It cannot claim automated delivery, read receipts, or bulk group posting.

## Requirements

1. Dedicated BotFather bot and private MARKETING_TELEGRAM_BOT_TOKEN stored server-side. Never reuse any existing AI OS Telegram bot.
2. Polling with getUpdates needs exactly one worker and no webhook for this dedicated bot.
3. Bot must be added as administrator in each permitted Telegram destination. Admin uses the one-time link while logged in on Telegram.
4. Public groups/channels may use @username; private group identification currently requires a numeric chat ID. Improve private group onboarding before broad release.
5. Deploy only on the validated account PostgreSQL database; never touch IPO, unrelated WhatsApp OS services, or existing database volumes.
6. Existing marketing/Dockerfile changes require validation against the exact running base image before staging. Do not blindly deploy to VPS.

## API

GET /api/akaun/copilot/marketing/groups — own destinations and up to 50 recent jobs.

POST /api/akaun/copilot/marketing/groups — register a group with approval acknowledged.

POST /api/akaun/copilot/marketing/groups/{id}/verify — issue 15-minute single-use admin linking URL. Store only SHA-256 code hash.

POST /api/akaun/copilot/marketing/groups/jobs — queue an explicitly approved Telegram text post for no more than 90 days ahead.

DELETE /api/akaun/copilot/marketing/groups/{id} — disable destination and cancel its queued Telegram jobs.

All API paths require an authenticated BeShare account and do not accept client-supplied Telegram bot tokens.

## Worker configuration (disabled until manually provisioned)

MARKETING_GROUP_WORKER_ENABLED=0 stops all verification and publishing activity.

MARKETING_GROUP_PUBLISH_ENABLED=0 allows linking verification if worker enabled but does not send scheduled messages.

MARKETING_TELEGRAM_BOT_TOKEN must be configured as a scoped server/worker secret. No secret should be placed in GitHub or the browser.

After explicit deployment approval, a separate single-replica worker can run:
python /app/marketing_group_worker.py --interval 15 --limit 10

The new worker’s Compose deployment/secret provisioning is intentionally NOT part of this source PR.

## Acceptance checks

Run:
PYTHONPATH=copilot/account python3 -m unittest copilot.tests.test_marketing_groups -v
node --check marketing/group-manager.js

Run the full existing Marketing CI suite and a sandbox Telegram pilot. Verify that a non-admin cannot claim a group, disabled destinations stop publishing, owner data never crosses accounts, and ambiguous responses never trigger blind retries.

No unsolicited group automation, arbitrary group scraping or WhatsApp Web bulk sender is included.
