# BeShare Marketing: social connections and publisher

This release connects one selected Facebook Page, one linked Instagram Professional account and one Threads account per BeShare account. It provides real OAuth exchange and queued publishing adapters. Account credentials, app approval and a running worker are deployment requirements. Tests use mocked transport and do not establish production approval or successful live delivery.

## Environment

Configure these on the **dedicated account service and its worker**, never in the static website:

| Variable | Purpose |
| --- | --- |
| `MARKETING_TOKEN_KEY` | A Fernet key for encrypting provider tokens in the account database. Generate with `Fernet.generate_key()` from `cryptography`; provision through the existing secret manager. |
| `MARKETING_META_APP_ID` | Meta app ID for Facebook Login. |
| `MARKETING_META_APP_SECRET` | Meta app secret. |
| `MARKETING_META_API_VERSION` | Supported Graph API version, initially `v27.0`. |
| `MARKETING_META_LOGIN_CONFIG_ID` | Optional Facebook Login for Business **User access token** configuration ID. One configuration is shared by Facebook and Instagram; when set, its permissions replace the OAuth scope query. Include the union described below. Keep WhatsApp Embedded Signup configurations separate. |
| `MARKETING_THREADS_APP_ID` | The **Threads-specific** app ID shown in the Threads use-case settings, not the parent Meta app ID. |
| `MARKETING_THREADS_APP_SECRET` | The secret corresponding to that Threads app ID, not the parent Meta app secret. |
| `MARKETING_THREADS_API_VERSION` | Threads API version, initially `v1.0`. |
| `MARKETING_OAUTH_REDIRECT_URI` | Exact HTTPS return URL: `https://beshareaisolution.com/marketing/connect.html`. Register the identical URI in both app configurations. Staging uses its own HTTPS callback and credentials. |
| `MARKETING_DATA_DELETION_STATUS_URL` | Public HTTPS status endpoint for Meta deletion confirmations: `https://akaun.beshareaisolution.com/api/akaun/copilot/marketing/provider/deletion-status`. Must have this exact path with no query or fragment; never derived from an incoming Host header. Required by the account service for deletion callbacks. |
| `MARKETING_PUBLISH_ENABLED` | `1` enables admission to the queue and processing by the worker. The default is disabled. This flag is not a worker heartbeat. |

Install `cryptography` in the account/worker image. Missing/invalid encryption configuration fails closed. Retain the encryption key with the database backup. Replacing it without a migration makes existing ciphertext unusable and requires reconnection; no plaintext fallback exists.

The existing `ACCOUNT_DATABASE_URL`, `ACCOUNT_DATABASE_NAME` and `ACCOUNT_DATABASE_ROLE` guard applies. The worker does not read the trading database or use `DATABASE_URL` as fallback.

### Operator credential entry

`marketing/bin/configure-social.py` prepares a **new** private Compose env file
without changing services. Its default is a no-write plan. Run the reviewed
checkout on the deployment host in an interactive terminal, adding `--write`
only when the operator is ready to enter the two app secrets privately:

```sh
python3 marketing/bin/configure-social.py \
  --output /opt/beshare-copilot-production/marketing.env \
  --meta-app-id 1473513278172621 \
  --meta-login-config-id 1151269530711228 \
  --threads-app-id 2429078661181153
```

The write mode requires a real terminal, refuses any existing destination or
symlink, disables secret echo, generates the Fernet key once on that host and
creates an owner-only `0600` file. It prints variable names without their values.
No secret belongs in a command argument, chat, source control or a screenshot.
If a file already exists, use the established secret-management process; do not
delete it merely to rerun this helper or accidentally rotate the encryption key.
After private entry, add this same provider file to the account and worker's
reviewed `env_file` lists, preserving their existing files and the worker's
separate database-only environment. Validate the resolved values privately and
recreate only those two services. The helper itself does not edit Compose,
restart a process, call a provider or enable publishing.

## Platform setup

Activation settings below were checked against official Meta documentation on **18 November 2026**.

The dedicated development app prepared for this deployment is **BeShare AI Marketing**,
parent Meta app ID `1473513278172621`, in the BeShare Ai Solution business portfolio.
Its **BeShare Marketing Social** configuration ID is `1151269530711228`, using
the General login variation and **User access token** with the five permissions
in the table below. Threads has its own app ID, `2429078661181153`. Both redirect
settings contain the exact OAuth return URL above. These are public identifiers;
their corresponding secrets belong only in the protected server environment.
The app is unpublished: these settings do not prove successful real-account
OAuth, publication, App Review or public customer access.

### App and Facebook Login configuration

An existing WhatsApp Business app is usable only if its dashboard offers the required compatible use cases/products. Meta explicitly allows **Manage everything on your Page** and **Access Threads API** together; existing apps display only compatible additions, and added use cases cannot be removed. If the required options are unavailable, use a separate app supporting Pages and Facebook Login for Business. Do not select the consumer **Authenticate and request data from users with Facebook Login** use case for this Pages integration.

In **Facebook Login for Business > Configurations**, create a configuration using **User access token**, select the needed Page/Instagram assets and permissions, and provision its ID as `MARKETING_META_LOGIN_CONFIG_ID`. The code exchanges a Facebook user token and calls `/me/permissions` and `/me/accounts`; an existing WhatsApp Embedded Signup configuration that issues a system-user token does not match this flow and must stay separate.

The current code shares one configuration ID between Facebook and Instagram and omits the `scope` query when that ID is set. Configure the union of the permissions needed by the enabled formats:

| Permission | Used for |
| --- | --- |
| `pages_show_list` | Discover the user's eligible Pages and linked Instagram accounts. |
| `pages_read_engagement` | Page access and Instagram publishing through Facebook Login. |
| `pages_manage_posts` | Publish to the selected Facebook Page. |
| `instagram_basic` | Access the linked Instagram Professional account. |
| `instagram_content_publish` | Publish Instagram media. |

Retain permissions required by the chosen Meta use case; the Pages use case automatically includes `business_management`, `pages_show_list` and `public_profile`. In **Facebook Login for Business > Settings > Valid OAuth redirect URIs**, register exactly `https://beshareaisolution.com/marketing/connect.html`, matching `MARKETING_OAUTH_REDIRECT_URI` without a query or fragment.

### Facebook and Instagram assets

Without a configuration ID, Facebook Login requests `pages_show_list`, `pages_read_engagement` and `pages_manage_posts`. The selected Page must return a content-creation task; missing tasks or `MANAGE` alone are insufficient. Ordinary video publication through `/{page-id}/videos` uses these same three Page permissions. The endpoint-specific v27 reference and Permissions Reference distinguish this from `publish_video`, which is for live-video streaming; this release does not request live streaming. Personal Facebook profile publishing is not supported. Graph calls include `appsecret_proof` when using provider tokens.

The server independently inspects both the user token and each candidate Page
token, verifies the app and returned user/Page identity, and bounds saved access
expiry by the token metadata. The separately verified user discovery permission
is retained when it is absent from the Page token's action scopes. Older stored
connections without verified authorizer/app identity require reconnection.

Instagram uses the Facebook Login route; without a configuration ID it requests `pages_show_list`, `pages_read_engagement`, `instagram_basic` and `instagram_content_publish`. The selector lists eligible **Business or Creator** accounts linked to the user's Facebook Pages. Publishing uses the selected Page access token. This is not the separate Instagram Login or Unified Login integration.

Meta's Instagram publishing guide additionally requires `ads_management` and `ads_read` **if the connecting user's role on the linked Page was granted through Business Manager**. Check the actual Page access arrangement. The default scope query does not include these permissions; when applicable, include them in the shared Facebook Login configuration and satisfy their access/review requirements. Granting these permissions does not enable ad execution in this release. If Meta requires Page Publishing Authorization for the linked Page, complete it before publishing.

### Threads credentials and testers

Add **Access Threads API**, then enable `threads_basic` and `threads_content_publish`. In its **Settings**, use the Threads-specific app ID and matching secret; the parent Meta app credentials are a separate pair. Under **Client OAuth Settings**, register exactly `https://beshareaisolution.com/marketing/connect.html`.

For testing, use **App roles > Roles > Add People > Threads Tester**. The invited Threads user must accept the invitation in **Threads > Account Settings > Website permissions**. An unaccepted invitation or a Facebook app role alone does not establish that this Threads testing flow is ready.

Threads uses `threads_basic` and `threads_content_publish`. Its current official endpoints use `threads.com` and `graph.threads.com`. The server exchanges the short-lived token for a long-lived token, obtains an app access token and inspects the user's token through `debug_token`. Actual granted scopes, validity, app/user identity and expiry are checked before saving. An exchange alone is insufficient because `threads_basic` can permit an exchange without publishing permission. The server records the expiry and requires reconnection before scheduling beyond it. There is no claim of automatic indefinite refresh.

### App roles and public release

Initial Facebook/Instagram checks use an app-role account with the necessary Page tasks; Threads uses an accepted Threads tester. Public customer access requires the relevant permissions to pass App Review/Advanced Access and the app to be **Live/Published**. Facebook Login for Business also requires Advanced Access to `public_profile` for external users. Connect the required business portfolio and complete the dashboard's applicable verification/review requirements before opening customer onboarding.

Marketing-specific public pages are `/marketing/privasi.html` and
`/marketing/padam-data.html`; the existing `/privasi/` remains the WhatsApp
policy. Use the deployed Marketing privacy page and deletion-instructions page
for the app-level Basic settings. Meta permits deletion instructions or a
functioning callback for that app-level requirement. Register callback URLs only
after their runtime configuration and HTTPS routes have been verified.

### Provider deauthorization and deletion callbacks

The account service implements these public, provider-authenticated routes:

| Method and path | Purpose |
| --- | --- |
| `POST /api/akaun/copilot/marketing/provider/meta/deauthorize` | Remove the matching Meta grant and provider-derived metadata. |
| `POST /api/akaun/copilot/marketing/provider/meta/data-deletion` | Apply the same removal and return JSON `url` and `confirmation_code`. |
| `GET /api/akaun/copilot/marketing/provider/deletion-status?code=…` | Human-readable generic completion status for an unguessable 64-character hexadecimal code. |

POST accepts a bounded URL-encoded `signed_request`, verifies HMAC-SHA256 with
the configured Meta app secret over the encoded payload, and resolves the
app-scoped authorizing `user_id`. Invalid signatures/forms, missing configuration
and unsupported methods are rejected before opening the database. No BeShare
bearer session or browser Origin is required for a valid provider callback.
Never register the OAuth browser return page as a lifecycle callback.

Removal is limited to records matching the provider app and verified authorizer,
including their grants across BeShare accounts. It deletes encrypted tokens,
connection identities, matching pending candidates/states, publishing bindings,
attempt metadata and stored provider publication IDs. Queued posts are cancelled;
potentially in-flight writes remain `uncertain`. User-authored campaigns, drafts,
references, results and the BeShare login remain. Published platform posts are
not deleted. An already accepted provider request cannot be recalled.

Minimal hashed subject/receipt records and opaque confirmation codes remain to
prevent retries or an older in-flight OAuth flow from restoring deleted access.
The revocation watermark never moves backwards. Canonically equivalent callback
replays return the existing receipt and do not clear a later reconnection.
Status responses do not reveal account IDs, tokens or post content.

Meta's data-deletion signed-request and JSON confirmation contract is documented.
The deauthorization adapter uses the same authenticated request format, but still
requires an actual provider-originated deauthorization check before operational
acceptance. No undocumented timestamp freshness limit is imposed.

**Threads lifecycle callbacks remain disabled (503)** because the reviewed
official Threads guides list callback fields but do not establish their signing
key, payload identity or acknowledgement contract. Do not register these reserved
routes or treat a static instructions page as a working Threads callback. Use
the published deletion instructions/support path while completing this provider
verification. A successful app-role OAuth test is not public-release approval.

The code defaults remain `v27.0` for Meta Graph API and `v1.0` for Threads. At the review date, Meta's versioning guide explicitly identifies `v27.0` as latest, and its Threads publishing examples use `v1.0`.

Official references checked for this integration:

- [Meta app creation and compatible use cases](https://developers.facebook.com/documentation/development/create-an-app)
- [Facebook Login for Business configurations and access requirements](https://developers.facebook.com/documentation/facebook-login/facebook-login-for-business)
- [Facebook Page publishing](https://developers.facebook.com/documentation/pages-api/posts)
- [Page video endpoint and publishing permissions](https://developers.facebook.com/docs/graph-api/reference/page/videos/)
- [Meta permissions reference](https://developers.facebook.com/documentation/development/permissions)
- [Meta data-deletion callback contract](https://developers.facebook.com/documentation/development/create-an-app/app-dashboard/data-deletion-callback)
- [Instagram with Facebook Login setup](https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-facebook-login/get-started)
- [Instagram publishing permissions and Page-token requirements](https://developers.facebook.com/documentation/instagram-platform/content-publishing)
- [Threads credentials and tester invitations](https://developers.facebook.com/documentation/threads/get-started)
- [Threads use-case settings, callbacks and publishing requirements](https://developers.facebook.com/documentation/development/create-an-app/threads-use-case)
- [Threads authorisation](https://developers.facebook.com/documentation/threads/get-started/get-access-tokens-and-permissions)
- [Threads publishing](https://developers.facebook.com/documentation/threads/posts)
- [Threads documentation index and token-debug guide](https://developers.facebook.com/documentation/threads/llms.txt)
- [Meta's official Threads API collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api)
- [Graph API versioning](https://developers.facebook.com/docs/graph-api/guides/versioning/)

## HTTP contract

The connection routes below use the existing authenticated BeShare bearer session and origin checks. Base: `/api/akaun/copilot/marketing/connections`. Provider callbacks above are a separate signed-request interface.

- `GET /`: safe connection descriptors, selected account names/IDs and capabilities. No tokens or app secrets.
- `POST /{facebook|instagram|threads}/begin {}`: returns `authorization_url`. State is random, owner-bound, stored as a hash, single-use and valid for ten minutes.
- `POST /complete {code,state}`: auto-detects the platform using the owner-bound state. A platform-specific `/{platform}/complete` variant is also accepted.
- Facebook/Instagram completion returns `candidates` containing IDs/names and `selection_required:true`. Candidate credentials remain encrypted server-side for ten minutes.
- `POST /{platform}/select {candidate_id}`: selects an owned, unexpired candidate and consumes pending choices.
- Threads completion stores the single authenticated account directly.
- `DELETE /{platform}`: removes BeShare's stored token and pending authorisation state. It does not claim to revoke the entire app grant at the provider; users can also remove that grant in the platform's settings.

`connect.html` immediately removes OAuth query parameters before loading external resources, applies `Referrer-Policy: no-referrer`, and restricts scripts using a CSP hash. `connect.js` keeps return codes/candidates only in memory and uses the existing account session. Losing the callback page requires starting the connection again. Provider errors are redacted. No provider response bodies or request URLs containing exchange credentials are logged.

The callback also captures the initial BeShare session and checks it before and
after asynchronous requests and before account selection. A changed, removed or
malformed session permanently stops that callback page, aborts pending browser
requests and clears its candidate UI; start a fresh connection from Marketing.
Aborting a browser request does not undo a request already processed by the server.

## Publishing workflow

Saving a draft/planned post does not publish. The separate schedule action checks the current connection, permissions, format and token validity for the selected time. It pins the selected platform account to the post. Selecting a different Page later cannot redirect an already queued post to that new Page.

Supported first-release formats:

| Platform | Formats |
| --- | --- |
| Facebook Page | Text, link, single image, video |
| Instagram Professional | Single image, video/Reel |
| Threads | Text, link, single image, video |

Media must have a public HTTPS URL fetchable by the platform and meet the platform's media specification. This release does not fetch customer media server-side, transcode media or publish carousels/Stories. All direct server requests are limited to the fixed provider hosts, with TLS, timeouts, bounded response sizes and redirects disabled.

Threads uses a conservative 500-byte UTF-8 text limit; Instagram captions are limited to 2,200 characters. Server checks remain authoritative.

For a source checkout, run the worker in the same account-only environment:

```sh
PYTHONPATH=copilot/account python3 copilot/account/marketing_worker.py --once
PYTHONPATH=copilot/account python3 copilot/account/marketing_worker.py --interval 15
```

The release image produced by `marketing/Dockerfile` copies the worker to
`/app/marketing_worker.py`; it does not contain the source checkout layout above.
Its worker command inside that image is `python /app/marketing_worker.py --once`
for a bounded tick, or `python /app/marketing_worker.py --interval 15` under the
existing supervisor. First inspect the actual image entrypoint, Compose service
and override order. Define the worker with the same account database, encryption
key and runtime user, while omitting the HTTP service's published port and HTTP
healthcheck. A Compose `command` alone may not replace an HTTP-specific
entrypoint. Do not guess the service name or start an improvised second process
inside the live web container. See [release.md](release.md) for the release and
rollback sequence. Multiple worker instances use owner locks, conditional claims
and leases to avoid concurrent delivery of the same job.

Every publishing attempt is durably recorded. The worker commits a request marker before a provider write. Instagram/Threads containers and Facebook videos are polled on later ticks without blocking sleep. A successful media publication is recorded with the provider ID.

- Explicit rate-limit rejections retry at most three times, with increasing delay.
- A timed-out write, ambiguous server response or worker crash during an external write becomes `uncertain`; it is not automatically replayed.
- Accepted Facebook video IDs remain recorded even if a later status check fails.
- Failed/uncertain deliveries expose a safe message, not raw platform errors or credentials.
- Closed campaigns are cancelled before their first external write. An already submitted video can finish processing at the provider.
- Cancellation and disconnection cannot retract a request already accepted by the provider. Published posts are not deleted by these local operations.

For `uncertain`, inspect the actual platform before preparing a replacement post. Keep the stored attempt as evidence; do not manually reset it to queued. This release does not automatically reconcile uncertain publication IDs or undo published content.

`worker_configured`/`publishing_enabled` describe configuration. `worker_health:'not_checked'` is explicit: the web service has no worker heartbeat. Operational supervision is required to ensure due jobs are processed.

## Deployment validation

Run the mocked suite before deployment:

```sh
PYTHONPATH=copilot/account python3 -m unittest discover -s copilot/tests -p 'test_marketing_social.py' -v
```

Then, with the approved controlled account, verify OAuth, correct Page selection, token isolation, one scheduled post per supported format, a disconnected account, media-processing status, and operational worker supervision. A live social posting test is an external publication and must use the specifically authorised test content/account.

No Meta Ads or Google Ads activation, spend, pixel installation or competitor scraping is implemented by these social connection routes. Capability responses keep ad execution unavailable.
