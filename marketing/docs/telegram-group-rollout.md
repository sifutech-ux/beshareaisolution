# BeShare Marketing · Telegram group rollout (controlled)

This is a staged, opt-in rollout guide. Merging GitHub code does **not** update the running account backend or start Telegram polling/publishing.

## Read-only prerequisites

1. Reconfirm the current Docker Compose project, service names, account image digest, private account database identity and network names against the live Hostinger VPS. The last known project path was /opt/beshare-copilot-production, but do not assume it is still current.
2. Check whether marketing-telegram-worker already exists. Do not overwrite an existing service or restart the whole stack.
3. Confirm the new source has passed BeShare AI Group Distribution CI and general account regressions. A failing full Marketing workflow due to downloading FFmpeg is infrastructure failure, not a pass; inspect and report separately.
4. Verify a dedicated Telegram bot was created through @BotFather. Do not reuse AI OS credentials. Store the token only in a private server-side environment file and the relevant account service config, never in UI, source, shell history, ChatGPT, or logs.
5. One bot = one poller. Verify that this bot has no webhook and is not being polled by another service before enabling getUpdates.

## Release gates

**Gate A: Account API (no Telegram network activity)**

Build/review a new account image using the existing marketing/Dockerfile *only after* comparing its baseline SHA expectations against the actual running image. Never disable its SHA checks just to force a build. Confirm code includes marketing_groups.py and marketing_group_worker.py plus the group schema hook.

Back up the dedicated account database using existing backup procedure. Deploy the new account image with MARKETING_GROUP_PUBLISH_ENABLED=0 and no Telegram worker. Confirm:
- Previous Copilot, Marketing Facebook/Instagram/Threads and login endpoints still work.
- Authorization and account isolation return correct 401/404 statuses; the Group Manager GET works only when logged in.
- POST /groups permits a manual WhatsApp record and a pending Telegram destination. Verify an unauthorized client cannot access another owner's group.
- POST /groups/jobs refuses with 503 while the feature flag is off.
- The new group tables are additive; no IPO or AI OS schema/service touched.

**Gate B: Telegram bot verification (no publishing)**

Prepare private worker env with only:
- ACCOUNT_DATABASE_URL, ACCOUNT_DATABASE_NAME and ACCOUNT_DATABASE_ROLE (same guarded account DB);
- IPO_DATABASE_NAME when supplied by the account DB guard;
- MARKETING_TELEGRAM_BOT_TOKEN for this dedicated bot.

Also supply MARKETING_TELEGRAM_BOT_TOKEN privately to the account API service so /groups/{id}/verify can call getMe. Do not copy broad account or social provider secrets into the worker.

Use marketing/deploy/telegram-group-worker.compose.yaml as a **fragment** to merge just the new service into the reviewed production override. Keep the same Compose file order and networks. Resolve template placeholders to the immutable reviewed image and private env paths, then inspect the semantic diff without printing secret values.

Keep:
- MARKETING_GROUP_WORKER_ENABLED=0
- MARKETING_GROUP_PUBLISH_ENABLED=0

Start only marketing-telegram-worker with the reviewed Compose files and --no-deps --no-build --pull never. A first tick should report worker_enabled=false and no Telegram sending.

Then enable only MARKETING_GROUP_WORKER_ENABLED=1 for the single Telegram worker. Leave MARKETING_GROUP_PUBLISH_ENABLED=0 in **both** account API and worker. Test one temporary group with an authorized admin using the button "Pilih group di Telegram". The callback should verify the destination after matching the one-use code and both admin roles. An ordinary group member must fail. Confirm UI status updates on manual Refresh.

**Gate C: controlled text-only posting (explicit later approval)**

Only after Gate A and B pass, set MARKETING_GROUP_PUBLISH_ENABLED=1 in both account API and Telegram worker, and restart only these reviewed services as needed. Use exactly one small owned test group and an explicitly approved non-promotional test message. Validate exactly one delivery, status receipt, a revoked admin refusing sends and no silent duplicate after network error. Disable publish flag immediately if issues occur.

Do **not** enable public or third-party group marketing before the admin-consent and opt-out rules have been reviewed. The WhatsApp group mode remains manual.

## Safe rollback

First turn off new job admissions by setting MARKETING_GROUP_PUBLISH_ENABLED=0 on the account API. Stop only the Telegram worker; treat jobs left in sending/uncertain as requiring human investigation, never replay them blindly. Roll back the reviewed account image separately if needed, preserving additive group tables and existing backups. Never run Compose down for the entire project.

## Operational notes

- Telegram permits group deep linking via startgroup; when used with a payload, the resulting update is /start@bot <payload>.
- Do not show bot tokens or signed verification codes in logs. Group verification links expire in 15 minutes.
- A group admin may revoke posting rights at any time; the worker must recheck and fail closed.
- All send times are stored as UTC epoch seconds; UI interprets them as Malaysia time.
- Group Manager is source-connected to the existing BeShare account frontend; GitHub merge is not backend rollout.
