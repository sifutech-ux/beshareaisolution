# Owner Cloud API connection and AI reply pilot

## Outcome and boundaries

This release connects the owner's existing Cloud API number to an existing BeShare OS
business, then processes incoming WhatsApp text through approved knowledge and Gemini.
The owner's setup uses a server-side connection import. Customer Embedded Signup remains
the route for the later first-client onboarding test.

The intended assets were verified from the owner's WhatsApp Manager screenshots on
23 October 2026. These are public identifiers, not credentials:

| Item | Target |
| --- | --- |
| Meta business portfolio | BeShare Ai Solution — `1249591211577465` |
| Messaging account / WABA | `1104940391945821` |
| Phone Number ID | `1443799975474056` |
| Display phone | `+60 11-5432 0018` |
| Observed Meta state | Connected, High quality, approved display name |

`owner_whatsapp_business_id` and `owner_ai_business_id` are **internal SaaS database IDs**.
They are not the portfolio ID or WABA ID. `owner_whatsapp_user_id` is the existing SaaS
owner user. The import verifies this membership from the database each time.

This work is based on the focused connection release PR #69. Deploy the combined release;
PR #69 alone keeps legacy outbound disabled and does not provide an AI sender. No live
connection, Meta change, deployment or real WhatsApp send is implied by passing CI.

## What is implemented

- A read-only preflight verifies token app, scopes, type and expiry, portfolio ownership
  through the exact WABA's `owner_business_info.id`, the exact phone/display number and
  the expected app subscription. It cannot create a number, change a phone account,
  subscribe an app or send a message.
- A matching preflight fingerprint allows one local transaction to bind the encrypted
  credential to the existing SaaS owner. A foreign tenant mapping is rejected. An identical
  existing mapping is preserved. Any replacement of an old same-tenant test connection
  must be named explicitly in both commands and is included in the fingerprint.
- The existing signed `/webhook/` callback can route only the configured owner 0018 slice
  into the OS queue. This avoids changing an app-wide callback or forwarding unrelated
  customers. The legacy owner JSON handler is skipped for that routed slice.
- An asynchronous worker stores encrypted incoming/outgoing history and makes at most
  one generation attempt and one sending attempt per inbound event. A delivery timeout
  becomes `unknown` and human handoff; the worker does not resend it automatically.
- The owner can approve business facts, nominate up to 10 test recipients, set a daily
  generation-attempt limit, enable or stop the pilot, and take over a conversation in the
  SaaS. Provider keys and Meta tokens are never returned to the browser.
- Status events update delivery evidence. History, echoes, duplicate events, stale inbound
  messages, unsupported media, human requests and disallowed recipients do not trigger AI
  sends. Generated text that was not confirmed sent is not counted as an AI reply in the UI.

Manual staff sending from the new inbox is not implemented in this pilot. Taking over
stops AI; it does not claim to send a staff message. Billing, broad automation rules and
analytics remain outside this change.

## Server prerequisites

Use the existing application host and database. PHP needs PDO MySQL, OpenSSL, cURL, and
POSIX only if importing from the protected legacy JSON source. The existing foundation
and Coexistence schema (0001–0003) must already be present. The new installer checks these
prerequisites and adds only 0004; it never replays the foundation migration.

Keep the current private configuration and encryption key. Add these **flat string** keys
to the existing private configuration outside the repository and web root. This is a list
of fields to set securely on the server, not a credential file to commit:

```text
owner_whatsapp_business_id = <existing SaaS business ID>
owner_whatsapp_user_id = <existing SaaS owner user ID>
owner_whatsapp_portfolio_id = 1249591211577465
owner_whatsapp_waba_id = 1104940391945821
owner_whatsapp_phone_number_id = 1443799975474056
owner_whatsapp_display_phone = +601154320018
owner_whatsapp_access_token = <existing authorized server-held token>

owner_intake_enabled = false
owner_ai_business_id = <same existing SaaS business ID>
owner_ai_enabled = false
gemini_api_key = <server-held Google key>
gemini_model = <supported model name verified for this project>
```

The existing `app_secret`, `token_key` and key version remain necessary. The configured
token must have the WhatsApp management and messaging permissions and must allow
`GET /<WABA_ID>?fields=id,name,owner_business_info`. Both the returned WABA `id` and its
`owner_business_info.id` must exactly match the configured WABA and business portfolio.
This proof is repeated on apply. Missing, malformed or mismatched ownership data and
failed reads block binding; there is no fallback to names, screenshots or another edge.
The importer does not enumerate the portfolio's assets or require the broader
`business_management` permission. Prefer the existing authorized system-user credential.
Meta documents the owner field as the source of the WABA owner's business portfolio ID
in [Managing credit lines](https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/share-and-revoke-credit-lines)
and its [official WABA request examples](https://www.postman.com/meta/whatsapp-business-platform/request/nem6vuw/waba-id).

Instead of `owner_whatsapp_access_token`, a protected copy of the existing token JSON can
be selected with `owner_whatsapp_legacy_token_path`. Specify exactly one source. The JSON
must be a regular non-symlink file, canonical absolute path, outside the repository and
`public_html`, owned by the CLI effective user with mode `0600`. Never pass credentials
on the command line, in a PR, or through chat.

## Controlled setup order

Run commands from the application checkout on the existing host. Prepare the normal
database backup according to the established deployment process before additive DDL.

1. **Install the additive tables.** Run the read-only preflight:

   ```sh
   php os/bin/prepare-ai-replies.php
   ```

   Review its `ready` result and checksum, then use that exact checksum:

   ```sh
   php os/bin/prepare-ai-replies.php --apply --checksum=<reported checksum>
   ```

   A repeated successful apply is idempotent. Partial installation or a changed checksum
   stops the installer; it never deletes tables to recover. Do not enable the pilot until
   the version 0004 record and all four tables are installed.

2. **Inspect the existing Meta assets and SaaS owner.** With private target fields set:

   ```sh
   php os/bin/bind-owner-whatsapp.php
   ```

   Check owner, WABA, phone, display, observed registration and app subscription. A screenshot
   showing Connected does not prove the server token or app subscription. If a fact is
   missing, resolve that specific prerequisite before binding. This CLI does not subscribe
   the app or refresh the state of an already-bound row. Do not use the Coexistence phone
   override command for 0018.

3. **Bind the reviewed assets locally.** Use the exact fingerprint from preflight:

   ```sh
   php os/bin/bind-owner-whatsapp.php --apply --fingerprint=<reported fingerprint>
   ```

   If the owner's SaaS business still has the earlier test number, review that row first.
   Add `--replace-connection=<old local connection ID>` to **both** preflight and apply.
   This clears only the reviewed old local token and soft-disconnects that local row. It
   does not unsubscribe or delete the old Meta asset. The incoming owner phone remains
   messaging-unverified until actual received/sent evidence exists.

4. **Enable signed owner intake.** Set private `owner_intake_enabled` to the exact string
   `true`. Keep the current app callback URL if it is `/webhook/`. A valid owner slice
   with a missing/mismatched local binding returns a retryable failure. Other legacy
   behavior and lifecycle processing remain scoped as before. If the app is already using
   `/whatsapp/sambungan/webhook.php`, that endpoint writes the same queue directly.

5. **Prepare the owner's pilot.** Once the provider configuration is present, set private
   `owner_ai_enabled` to `true` for the same internal business. This only permits the worker;
   the database/UI setting still starts disabled. In **Pengetahuan**, select the imported
   number, enter real approved business facts, nominate a different phone you control,
   choose the daily limit, explicitly approve the facts and enable the pilot.

   Saving a configuration starts a fresh activation time. Send a new test message after
   saving; queued older messages are archived without a new AI reply. Daily reservations
   are preserved when settings are edited or turned off/on, using UTC dates.

6. **Run the worker for the owner only.** Start with one bounded invocation:

   ```sh
   php os/bin/process-ai-replies.php --business=<internal SaaS business ID> --limit=10
   ```

   The command refuses a different private business ID and checks the completed 0004
   installation. It emits counters, not messages or tokens. After the one-message test,
   schedule this same command on the existing host (for example once per minute) using
   the established service account and private configuration. A database advisory lock
   prevents overlapping owner workers.

## One real end-to-end acceptance test

From the nominated test phone, send a new message to 0018 asking a question supported by
the approved facts. Confirm these in order:

1. The signed callback records one tenant-scoped incoming message.
2. One worker job reserves quota and generates one response.
3. Graph returns a message ID for the intended recipient.
4. WhatsApp receives the response; the matching delivery status appears in the SaaS inbox.
5. Refresh the inbox and confirm both incoming and outgoing history remain visible.
6. Take over that conversation, send one more test message, and confirm AI sends nothing.

Record the message/job identifiers and result without exporting message content or
credentials. A Graph send acceptance and a delivered/read webhook are separate evidence.
If sending is `unknown`, inspect that single attempt; do not rerun it as a new send.

**Hentikan AI** disables future generation and sends for this owner. Per-conversation
takeover pauses that thread. An already submitted provider request cannot be recalled.
To disable the release at the host, stop its scheduled worker and set `owner_ai_enabled`
to `false`; leave incoming data available for review. Do not delete 0004 history tables.

Only after owner use passes this real test should the first authorized client use
**Sambung WhatsApp Business App**. That validates customer onboarding independently;
the owner import and pilot are not evidence that Coexistence onboarding is complete.

## Focused validation

`.github/workflows/owner-ai-replies.yml` uses PHP 8.3 and disposable MySQL 8.4 databases.
It checks only the new owner import/intake, provider adapters, AI settings/schema and
durable reply worker. All Graph/Google responses and webhook payloads are synthetic.
It uses no production keys, no production database and no real WhatsApp recipient.
The existing small connection-return check may also run when `ruang.js` changes.
