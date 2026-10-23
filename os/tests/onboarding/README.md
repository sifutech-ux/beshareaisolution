# Coexistence onboarding execution — 23 October 2026 (Asia/Kuala_Lumpur)

## Result
Implemented on `fix/coexistence-onboarding-gate-20261023`, stacked on #66 (`9c8f688`). Not deployed. The public entry now links directly to the authenticated connection route and no longer calls the real workspace a demo. Signup distinguishes requesting a popup from a confirmed result, handles SDK exceptions without opening a server signup session, and gives an advisory after 15 seconds without a response. A late callback remains usable. Coexistence instructions now name the correct button; ordinary Embedded Signup options are preserved.

## Real browser and public readback
- Opened https://beshareaisolution.com/whatsapp/ and followed Workspace to `ruang.html#sambungan`.
- Existing browser session was already authenticated. Did not log it out, enter credentials, or claim that this proved anonymous denial.
- Clicked **Sambung WhatsApp Business App** once. Production displayed “Tetingkap Meta dibuka”, but no Meta popup/tab or completion was observable through this browser. This proves the button handler ran, not that Meta onboarding succeeded. The existing implementation also requests a temporary signup session (`sambung`); this limited launch action was exercised. No authorization code, new number binding, subscription, or registration was completed.
- Public `awam.php`: HTTP 200, ready=true, public App ID/Configuration ID present, Graph v26.0. This only proves public launch parameters, not Meta approval, token permissions, owner eligibility, or private readiness.
- Cookie-free session read: HTTP 200, ok=true, **no authenticated user**. The UI's anonymous login gate was separately verified in the browser fixture.
- Downloaded public `ruang.js` with TLS verification enabled: byte-identical to #66 baseline. SHA-256 and allowlisted public response fields are in `public-readback.json`; no cookies, CSRF, tokens or private config retained.
- Python's local CA store initially rejected TLS; the successful read used system curl with normal certificate verification. No certificate check was bypassed.
- No request was sent to either production webhook endpoint.

## PR snapshot (GitHub API, this run)
| PR | Head | State | Relation |
|---|---|---|---|
| #63 Security P0 | c955270 | Open, Draft, not merged | Base main |
| #64 rehearsal evidence | bd04d67 | Open, Draft, not merged | Historical evidence, base main |
| #65 MySQL compatibility | e179dc3 | Open, Draft, not merged | Base #63 |
| #66 standby/Gemini foundation | 9c8f688 | Open, Draft, not merged | Base #65 |

Fetched main remains `69e4d3f`. #66 CI run 39960101212 is completed/success. No PR merged or retargeted. Production P0 receipt is not established; the production webhook was not used.

## Coexistence A–E code and current validation
These are the Coexistence implementation phases, not the broader product blueprint phases.

| Phase | Code inspected | Executed evidence |
|---|---|---|
| A: Business App onboarding | `WhatsAppGateway`, `MetaGraph`, `coexistenceLoginOptions`; featureType=whatsapp_business_app_onboarding, FINISH event handling | Meta 64 checks; signup helpers 30; launch 19; mock preserves ordinary flow and user-activation ordering |
| B: webhook/archive/sync | `WhatsAppWebhook`, `CoexistenceArchive`, `CoexistenceSync`, migration 0002 | Coexistence 37 checks; signed events, history/echoes, scope and replay |
| C: gated worker | `CoexistenceWorker`, `ActivationGate`, migration 0003 | Worker 33 checks; activation defaults off unless exact string true |
| D: gated phone override | `PhoneOverride`, `PhoneOverrideGate` | Override 32 checks; added previously omitted suite to extended P0 runner; defaults off |
| E: lifecycle/disconnection | `LifecycleBridge`, `WhatsAppWebhook` | Lifecycle 13 checks; verified disconnection and recovery path |

Private production flags, secrets, owner-to-WABA/phone binding, callback selection, subscriptions and production schema were not inferred from public IDs or read from secret files. The authenticated UI's existing connection labels are display evidence only, not an independent Meta verification.

## Tests executed now
`python3 os/tests/run-security-p0.py --extended`: **402 checks** (P0 121, auth 102, Meta 64, coexistence 37, worker 33, override 32, lifecycle 13). Disposable MariaDB datadir, private socket, loopback PHP; synthetic identities and payloads. PHP outbound curl and URL fopen disabled. Runner terminates lab processes on exit. This run is not a new MySQL 8.4 rehearsal.

Both local webhook entrypoints reject missing, malformed, tampered and wrong-secret signatures without storage/DB effects. Missing configuration fails closed. GET challenge behavior, signed ingestion, replay and tenant isolation are covered. See `security-coexistence-checks.txt`.

`node os/bin/check-signup-event.mjs`: **30 checks**.
`node os/bin/check-signup-launch.mjs`: **19 checks**, including anonymous/staff/admin denial, unloaded SDK, thrown SDK, synchronous cancellation, silent SDK advisory, late callback, immediate-code/state race and server denial. No network.

Total: **451 automated checks passed**. `node --check whatsapp/ruang.js` and `git diff --check` passed.

Browser fixture (`python3 os/tests/onboarding/serve.py`, prints ephemeral loopback URL):
1. `/fixture/anonymous` redirects connection route to #masuk; no signup button.
2. `/fixture/owner` exposes actual owner UI and uses local silent SDK; request hint then bounded advisory, no success claim.
3. `/fixture/throw` shows actionable SDK failure without an uncaught page failure.
4. Updated landing CTA reaches #sambungan and updated Coexistence instructions render.

The fixture serves real workspace code with only the SDK URL replaced, synthetic API responses and CSP blocking external connections/frames. All provider, exchange and binding actions are denied. Screenshots are synthetic, not live Meta completion evidence. Browser screenshots were captured during the iteration; the final text instructions were also read back after the last edit.

## Minimum live-test blocker
**A verified Security P0-protected target is not yet available for this live test.** #63 is still Draft/unmerged, and production receipt was not established. Do not use the current production webhook for a live number.

Once that prerequisite is satisfied through a separately approved release, a bounded one-number preflight must confirm the owner, target number/WABA/app, exact callback and schema/backup readiness. Callback changes, WABA subscription, DB migrations, deployment and number binding require the user's specific approval after that preflight. No such approval or action is claimed here. No Copilot, IPO, Telegram, Caddy, VPS or production deployment changes were made.
