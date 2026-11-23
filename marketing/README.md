# BeShare AI Marketing

A Malay campaign workspace inside the existing BeShare site. It uses the same
account session and dedicated Copilot account database. This release connects
campaign planning, content preparation, publishing readiness, references and
reported outcomes in one workflow.

## Delivered workflow

1. Sign in through `/akaun/?selepas=marketing`; the return destination is an
   explicit allowlist, not a user-supplied redirect URL.
2. Save a campaign: offer, audience, goal, destination, planned budget, dates and
   selected channels. Export a brief or tagged destination links.
3. Write platform-specific content or request a draft through the existing
   authenticated Copilot AI endpoint and quota. Review and save it to the account.
4. Keep a content plan on the calendar even before connecting a provider.
5. Connect a supported provider through official OAuth. Explicitly approve a
   saved post for the publishing queue when server configuration is ready.
6. Save research links, observations and hypotheses. Record daily results and
   export them to CSV. These are labelled manual records, not verified attribution.
7. Open **Facebook Ads** to connect a Meta ad account, view its campaign results
   across placements and create a paused campaign from a saved BeShare plan.
   Finish the ad set, audience, budget, placements and creative in Meta Ads Manager.

### Facebook Ads setup

Use the existing Meta app, redirect URI and encrypted account service. Enable
Marketing API access for the app and grant `ads_read` and `ads_management` to
the user login. If using Facebook Login for Business, set
`MARKETING_META_ADS_LOGIN_CONFIG_ID` on the account service to a **User access
token** configuration with ad account asset selection and those permissions.
When unset, the Ads flow requests both scopes explicitly. The Page publishing
configuration remains independent. Check access for customer accounts in the
Meta App Dashboard before rolling out. [Meta Marketing API](https://developers.facebook.com/docs/marketing-apis/)

Build `marketing/Dockerfile.facebook-ads` on the current account image and
release the account service before serving the static files. The additive
`marketing_ads_creations` table records one creation per owner, ad account and
BeShare plan. A lost provider response leaves an outstanding marker. Inspect
Ads Manager before taking any further action. No worker or new secret key is
needed. The campaign creation endpoint always requests `PAUSED`. Campaigns
in a Meta special ad category use Ads Manager directly. Reports use the ad
account currency and display missing values separately from zero.

The frontend does not store marketing records or social tokens in localStorage.
Only the existing BeShare account session uses the established session key.
Account records persist in the server database and are scoped to the signed-in
owner. Versioned writes preserve stale edits and reject conflicting changes.

## Scope that remains separate

- Meta Ads and Google Ads campaign activation, spending and optimization are not
  implemented in this release. A planned budget or channel selection spends no money.
- Pixel, Conversions API, Google conversion tagging and verified CRM/ecommerce
  attribution are not implemented. UTM links are identifiers, not a tracking system.
- Research links and notes are entered by the user. There is no automatic web
  retrieval or claim to know competitors' conversions or returns.
- Studio and Copilot links use existing systems. This release does not claim a
  completed automatic asset-import or customer synchronization integration.
- OAuth and provider publishing require real app configuration, relevant access
  approvals and live acceptance testing. Mocked tests verify integration behavior,
  not a provider's approval of the deployed application.

## Code map

| Path | Purpose |
| --- | --- |
| `marketing/index.html`, `marketing.css`, `marketing.js`, `core.js` | Responsive workspace and browser helpers |
| `marketing/connect.html`, `connect.js` | Owner-bound OAuth return and account selection |
| `copilot/account/pelanggan_marketing.py` | Versioned campaign, post, source and result API |
| `copilot/account/marketing_social.py` | Provider configuration, encrypted credentials and official OAuth |
| `copilot/account/marketing_worker.py` | Durable scheduled publishing worker |
| `copilot/account/pelanggan_copilot.py` | Existing account-router and additive schema hook |
| `marketing/Dockerfile` | Verified extension of the current account image |
| `marketing/docs/social-setup.md` | Provider setup, environment and publishing operations |
| `marketing/tests/preview.py` | Isolated temporary-account UI QA; never a production service |

The release image copies only the Marketing modules, its Copilot router hook and
the existing saved-outcomes module. Other account files in the repository remain
baseline fixtures. Do not replace the running account service with all fixtures.

## Verification

```sh
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=copilot/account python3 -m unittest discover -s copilot/tests -p 'test_*.py' -v
node --test marketing/tests/*.test.mjs copilot/tests/hasil-core.test.js
node --check marketing/marketing.js
node --check marketing/connect.js
```

Install `cryptography` for encrypted social credentials and their tests. The CI
workflow also installs `psycopg2-binary` and starts an isolated PostgreSQL 16
database for the opt-in database tests. Provider transport tests use mocks and
must not call customer accounts.

For a standalone local UI check:

```sh
MARKETING_UI_QA=1 PORT=3000 python3 marketing/tests/preview.py .
```

Open `http://localhost:3000/qa`. The preview has an unmistakable test banner and a
temporary example account. It uses real account/Marketing persistence but clears
all social configuration and rejects live AI requests. It does not make posts or
run ads. Where a managed preview daemon is provided, launch this command through
that daemon's development script instead of starting an additional server.

## Release sequence

Use the [detailed release and rollback checklist](docs/release.md), including the
bounded image preflight, when the existing Docker deployment is accessible.

1. Pass the proposed branch's account, browser-helper and PostgreSQL checks.
2. Record the deployed account image and Compose configuration and take the
   existing dedicated account-database backup. Confirm the baseline hashes used
   by `marketing/Dockerfile` against that image.
3. Build from the verified existing account image with `cryptography` installed:
   `docker build -f marketing/Dockerfile --build-arg ACCOUNT_BASE_IMAGE=<verified-image> -t copilot-account:marketing-v1 .`
   The build deliberately fails if the expected account/router/server files or
   encryption dependency do not match. Do not remove the checks to force a build.
4. Deploy the account extension with publishing disabled. Schema creation adds
   Marketing tables; it does not rewrite or migrate existing business records.
   Verify sign-in, owner isolation and a create/read/update cycle on a controlled
   account before releasing the frontend.
5. Release the frontend through the existing repository deployment. Test
   `/marketing/` on desktop and phone, including login return and stale writes.
6. Configure provider apps and the encrypted token key as environment secrets.
   Follow `docs/social-setup.md`; run a controlled OAuth and publishing acceptance
   test, and verify the worker is running before enabling automatic publishing.
7. Keep ad spending disabled; it is outside this release.

Do not change investment/IPO services, WhatsApp OS tables, unrelated containers,
proxy ports, DNS or existing persistent volumes to ship this module.

### Rollback

Disable new publishing admissions first, stop the Marketing worker cleanly and
inspect jobs already in flight. A provider request can have succeeded even if its
response was lost; do not blindly replay uncertain attempts. Roll back frontend
and then the account image/Compose override. Preserve additive Marketing tables
and the encryption key so saved work and connection data remain recoverable.

## Acceptance criteria

- An unauthenticated request cannot read or mutate a workspace.
- Two accounts cannot retrieve or reference each other's records or credentials.
- Repeated identical saves are safe; stale different saves return a conflict.
- Saving a plan does not publish. Scheduling requires explicit approval and a
  valid connected destination and server configuration.
- Queue binding cannot silently switch to a different provider account.
- A lost publishing response is not retried as a fresh post automatically.
- Manual references/results and unconfigured integrations remain visibly labelled.
