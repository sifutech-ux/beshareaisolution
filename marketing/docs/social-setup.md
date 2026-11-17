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
| `MARKETING_META_LOGIN_CONFIG_ID` | Optional Facebook Login for Business configuration ID. When set, its configured permissions replace the OAuth scope query. Include the Page/Instagram permissions needed by this release in that Meta configuration. |
| `MARKETING_THREADS_APP_ID` | Threads app ID. |
| `MARKETING_THREADS_APP_SECRET` | Threads app secret. |
| `MARKETING_THREADS_API_VERSION` | Threads API version, initially `v1.0`. |
| `MARKETING_OAUTH_REDIRECT_URI` | Exact HTTPS return URL: `https://beshareaisolution.com/marketing/connect.html`. Register the identical URI in both app configurations. Staging uses its own HTTPS callback and credentials. |
| `MARKETING_PUBLISH_ENABLED` | `1` enables admission to the queue and processing by the worker. The default is disabled. This flag is not a worker heartbeat. |

Install `cryptography` in the account/worker image. Missing/invalid encryption configuration fails closed. Retain the encryption key with the database backup. Replacing it without a migration makes existing ciphertext unusable and requires reconnection; no plaintext fallback exists.

The existing `ACCOUNT_DATABASE_URL`, `ACCOUNT_DATABASE_NAME` and `ACCOUNT_DATABASE_ROLE` guard applies. The worker does not read the trading database or use `DATABASE_URL` as fallback.

## Platform setup

Facebook Login requests `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` and `publish_video`. The selected Page must be managed by the connecting user with content creation rights. Video scheduling additionally checks the granted `publish_video` scope. Personal Facebook profile publishing is not supported. Graph calls include `appsecret_proof` when using provider tokens.

Instagram uses the Facebook Login route and requests `pages_show_list`, `pages_read_engagement`, `instagram_basic` and `instagram_content_publish`. The selector lists only eligible Instagram Professional accounts returned through the user's linked Facebook Pages. This is not the separate Instagram Login integration.

Threads uses `threads_basic` and `threads_content_publish`. Its current official endpoints use `threads.com` and `graph.threads.com`. The server exchanges the short-lived token for a long-lived token, obtains an app access token and inspects the user's token through `debug_token`. Actual granted scopes, validity, app/user identity and expiry are checked before saving. An exchange alone is insufficient because `threads_basic` can permit an exchange without publishing permission. The server records the expiry and requires reconnection before scheduling beyond it. There is no claim of automatic indefinite refresh.

Appropriate platform review/access and user authorisation are required for accounts beyond app roles/testers. Do not bypass review by changing account ownership or using unrelated app credentials. Complete the platform's required app settings and review with a controlled test account first.

Official references checked for this integration:

- [Facebook Page publishing](https://developers.facebook.com/documentation/pages-api/posts)
- [Instagram publishing with Facebook Login](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/content-publishing/)
- [Threads authorisation](https://developers.facebook.com/documentation/threads/get-started/get-access-tokens-and-permissions)
- [Threads publishing](https://developers.facebook.com/documentation/threads/posts)
- [Threads documentation index and token-debug guide](https://developers.facebook.com/documentation/threads/llms.txt)
- [Meta's official Threads API collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api)

## HTTP contract

All routes use the existing authenticated BeShare bearer session and origin checks. Base: `/api/akaun/copilot/marketing/connections`.

- `GET /`: safe connection descriptors, selected account names/IDs and capabilities. No tokens or app secrets.
- `POST /{facebook|instagram|threads}/begin {}`: returns `authorization_url`. State is random, owner-bound, stored as a hash, single-use and valid for ten minutes.
- `POST /complete {code,state}`: auto-detects the platform using the owner-bound state. A platform-specific `/{platform}/complete` variant is also accepted.
- Facebook/Instagram completion returns `candidates` containing IDs/names and `selection_required:true`. Candidate credentials remain encrypted server-side for ten minutes.
- `POST /{platform}/select {candidate_id}`: selects an owned, unexpired candidate and consumes pending choices.
- Threads completion stores the single authenticated account directly.
- `DELETE /{platform}`: removes BeShare's stored token and pending authorisation state. It does not claim to revoke the entire app grant at the provider; users can also remove that grant in the platform's settings.

`connect.html` immediately removes OAuth query parameters before loading external resources, applies `Referrer-Policy: no-referrer`, and restricts scripts using a CSP hash. `connect.js` keeps return codes/candidates only in memory and uses the existing account session. Losing the callback page requires starting the connection again. Provider errors are redacted. No provider response bodies or request URLs containing exchange credentials are logged.

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
