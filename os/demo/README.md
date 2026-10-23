# BeShare — First Working Demo

An authenticated, local-only demo for **Kedai Seri Demo**, a synthetic business. Built on PR #66 (`9c8f688`); no production dependencies or outbound WhatsApp implementation.

## Open it (macOS / Linux)

Requires PHP 8.2+ CLI with PDO SQLite, cURL, sessions and POSIX; Python 3 for tests. No Composer/npm dependencies.

From the repository root:

```sh
php os/demo/start.php
```

1. Open **http://127.0.0.1:8766** on the same computer.
2. Open the `login.txt` file at the private path printed by the launcher. Copy its generated password into the login form. Do not share that file.
3. Edit the fictional business information → **Simpan versi baharu** → **Luluskan maklumat**.
4. Select **Guna contoh pertanyaan** → **Jana draf untuk semakan**.
5. Read the draft and expand its versioned source reference. **Saya ambil alih** persists owner responsibility. Nothing is sent.

Default is **MOCK**, a deterministic example repeating approved facts; it is not an intelligent model response. The label appears in the workspace and each stored draft. Existing draft provenance survives a mode change. No live Gemini call was tested for this delivery: no environment credential was accessible.

Stop with Ctrl-C in the launch terminal. Restart with the same command to retain this disposable state. Set `BESHARE_DEMO_PORT=8767` before launching if the port is occupied. Do not change the loopback binding or put this PHP development server behind a public tunnel.

## Optional real Gemini activation

Use a dedicated demo API key with provider quota/billing restrictions. Enter it in your own terminal using a hidden prompt, never in chat, source code, browser, a URL or a committed file. On the supplied macOS zsh:

```zsh
read -s 'GEMINI_API_KEY?Gemini API key: '; echo
export GEMINI_API_KEY
export BESHARE_DEMO_MODE=live
export BESHARE_GEMINI_MODEL=gemini-2.5-flash
php os/demo/start.php
unset GEMINI_API_KEY BESHARE_DEMO_MODE BESHARE_GEMINI_MODEL
```

Stop the mock process first. Keep the terminal open. Confirm the workspace says **LIVE · GEMINI**, then generate a new fictional question. A newly stored `LIVE Gemini` draft is the evidence of a successful response; the mode label alone is not proof. Errors remain in the human inbox, without automatic retries or silent mock fallback. Model availability and billing depend on the provider account. The adapter uses the fixed Google HTTPS endpoint, an API-key header, certificate verification, no redirects, 5-second connection/25-second total timeout, 64 KiB response ceiling, and the foundation's 512 output-token cap. Gemini 2.5 Flash thinking is disabled; thought-marked output is rejected, never shown as reasoning. See [Google's thinking configuration](https://ai.google.dev/gemini-api/docs/generate-content/thinking).

Only the fictional question and approved fictional knowledge go to Google when live mode is explicitly enabled. Source references show the exact knowledge version supplied, not chain-of-thought or a guarantee of factual grounding. Review every draft.

## Storage and boundaries

Uses the existing signed synthetic inbound → server-owned route → tenant → latest approved knowledge → usage reservation → draft → human-handoff foundation unchanged. Dedicated SQLite is intentionally appropriate for this disposable demo; it is **not OS MySQL/TokenSeal integration** and must not store customer data. Nothing connects to Meta, WABA, callbacks, DNS, production databases, Copilot, IPO, Telegram or Caddy.

Private state defaults to the OS temporary directory under `beshare-first-demo-<uid>` (0700 directory, 0600 files). The launcher prints the exact path. It contains the SQLite database, HMAC key, password hash, owner-only password reminder, login throttle and PHP sessions. OS temporary-file cleanup may remove it. For a fresh isolated demo, stop the server and launch with a new private directory:

```sh
BESHARE_DEMO_STATE="$(mktemp -d)" php os/demo/start.php
```

No destructive reset endpoint exists. Explicitly delete the old printed state directory only when its fictional data is no longer needed. No backups are promised.

Authentication: random 128-bit password; hashed verification; 20 login attempts per five minutes per local instance; regenerated session ID; HttpOnly/SameSite=Strict cookie; one-hour absolute expiry; logout revocation. Cookie is intentionally HTTP for loopback only. Host validation, exact same-origin POST checks and session CSRF tokens protect mutations. Tenant and actor are server constants; browser values cannot select another tenant. All user/provider text renders with `textContent`, never HTML. Strict CSP blocks external assets/scripts and framing.

Limits: 20 calls per UTC day, 81,920 conservative abstract units, 10-second spacing, 100 inbox items; failed/ambiguous calls retain reservations. These units are not currency billing guarantees. Knowledge approval expires after seven days; edits require new approval. HTTP knowledge is bounded to 2,500 bytes, inbound to 4,000 bytes. Single-worker local PHP intentionally serializes this one-owner demo; no production scaling or security certification is claimed.

## Verification

```sh
php os/tests/mvp/check.php
python3 os/tests/demo/check.py
```

Observed locally: **45 foundation checks + 20 HTTP integration checks passed**. HTTP tests start their own private temporary state and process group, never a real provider. They cover login, cookies, origin, CSRF, approval freshness, unapproved knowledge, mock provenance, usage/rate controls, claim persistence, invalid events, bounds and logout. Inherited foundation tests additionally cover two-tenant isolation, signature/replay, failures, crash and concurrency.

Browser walkthrough: login → edit → save → approve → generate mock → claim → reload. Captured desktop at 1440 px and narrow mobile layout at 320 px; these are browser viewport checks, not a physical-phone test. Screenshots contain only synthetic data:

- [Desktop](screenshots/desktop.png)
- [Mobile](screenshots/mobile.png)

No production deployment, merge or real WhatsApp send was performed.
