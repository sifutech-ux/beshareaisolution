# BeShare global infrastructure discovery

Audit date: 19 October 2026, about 01:40–01:45 UTC. No service was migrated, deployed, restarted, or changed. No production database was opened. No secret value is recorded here.

Hostinger VPS is the owner's chosen permanent home for application runtimes. This document does not treat that decision as proof of where each service runs today.

## A. Global inventory

GitHub organization `sifutech-ux` exposed five repositories to this audit:

| Repository | Visibility | Role seen from code and live checks |
|---|---|---|
| `beshareaisolution` | public | Company site, Copilot pages, WhatsApp OS PHP |
| `beshare-ipo` | private | IPO AI bot and the Copilot account source on other branches |
| `ai-video-saas` | public | AI Video Studio, and the Copilot advice route on later branches |
| `whatsapp-ai-sales` | private | WhatsApp legacy dashboard and Meta sender |
| `dherbs` | public | Separate shop, D'Herbs. Not a BeShare AI runtime |

No other organization repository was visible.

## B. Runtime and database map

```text
beshareaisolution.com  --hcdn--> Hostinger website CDN
  pages: /, /penasihat/, /whatsapp/, /admin/
  PHP: /whatsapp/akaun/pintu.php executes

179.197.244.80  srv2061175.hstgr.cloud
  akaun.beshareaisolution.com      Python 3.12.15  service beshare-akaun
  akaun-staging.beshareaisolution.com  same health payload
  nasihat.beshareaisolution.com    Caddy -> Next.js route /api/copilot/nasihat
  nasihat-staging.beshareaisolution.com  Caddy -> Next.js site and the same route
  staging.beshareaisolution.com    nginx/1.28.3 HTML

beshare-ipo-bot.onrender.com      Render  Python 3.14.3  commit 75b6a1e  Telegram polling
beshare-whatsapp.onrender.com     Render  uvicorn
ai-video-saas-ten.vercel.app      Vercel  AI Video Studio
```

Copilot account code that returns `service: beshare-akaun` is `akaun_pelayan.py` on branches such as `cursor/copilot-vps-compose-cd0e`. That file is not on `beshare-ipo` `main`. The running process did not report its git commit.

`ai-video-saas` `main` (`216e2b7`) has no `/api/copilot/nasihat` route. The VPS route still answers, so the advice process is newer than that main commit. `cursor/nasihat-hos-cd0e` at `0598dc8` contains the route. That is a candidate, not a confirmed deploy.

## C. Current versus target

Current production is split:

- Public site and WhatsApp OS PHP stay on Hostinger website CDN (`server: hcdn`).
- Copilot account, advice, staging web, and Caddy already answer on VPS `srv2061175.hstgr.cloud`.
- IPO and the legacy WhatsApp dashboard stay on Render.
- Video Studio stays on Vercel.

Target, after a later approval, keeps one VPS and does not rebuild Copilot:

| Runtime | Target | Isolation rule |
|---|---|---|
| Copilot | Leave the current Caddy, account, advice, and Postgres where they are | Do not recreate project `copilot`, its volumes, or host ports 80/443 |
| Company site | Leave on Hostinger CDN until a separate cutover | Do not point the apex at the VPS in the same change as an app migration |
| IPO | New Compose project `beshare-ipo`, loopback port only | One Telegram poller. Trading flags stay false |
| WhatsApp legacy | New Compose project `beshare-whatsapp` after a file backup | Own volume. Own Meta webhook hostname |
| WhatsApp OS | Stay on the current PHP and MySQL until a dump exists | Own database name. Do not share Copilot Postgres |
| Video Studio | Stay on Vercel until media size and Supabase are known | Do not add ffmpeg renders to this VPS yet |

New projects use their own Compose name, network, and volume prefix. They do not attach to the Copilot network. They do not publish 80, 443, 5432, or 1055.

## D. VPS capacity

Confirmed from outside the machine:

- Hostname `srv2061175.hstgr.cloud`, address `179.197.244.80`.
- TCP 443 already serves Caddy, and through it nginx 1.28.3, Python 3.12.15, and Next.js.
- Account health is green on the production and staging account names.

Not visible from outside: CPU, RAM, disk, swap, `docker ps`, image digests, and Postgres size. Those are ACCESS REQUIRED. Do not place Video Studio or a second database on this VPS until those numbers are read. The existing Copilot stack already occupies the public web ports.

## E. Migration roadmap

Order is by operational risk. Copilot is first only so that it is explicitly left alone.

### 1. Business Copilot — do not migrate

1. Current runtime: VPS above. Account health and advice route are live.
2. Target: the same VPS and the same containers.
3. Prerequisite: a named backup of the existing Postgres volume and Caddy certificates before any other project is added.
4. Data procedure: none.
5. Deployment sequence: none.
6. Validation: `GET /healthz` on the account host still returns `beshare-akaun`. Advice `GET /api/copilot/nasihat` still returns 405. A real login is an owner check, not part of this audit.
7. Rollback: not applicable while nothing changes.
8. Downtime: none if this service is not restarted.
9. Depends on its own Postgres and on Gemini only inside the advice process. Gemini was not called.
10. Risk: a later Compose command from the wrong directory can take ports 80 and 443 or delete `copilot_postgres_data`.

### 2. WhatsApp legacy dashboard — backup before any move

1. Current runtime: `https://beshare-whatsapp.onrender.com` on Render. `/masuk` returned 200. `/healthz` returned 404 from uvicorn. Root timed out once.
2. Target: Compose project `beshare-whatsapp` on the VPS, published only on a new loopback port. Public name to be chosen later.
3. Prerequisites: Render plan and disk screen, a copy of `/app/data` or the JSON files, and the Meta webhook URL currently registered. Deployed commit is unknown. `main` is `45ede97`. Branch `cursor/whatsapp-360dialog-cd0e` is not proven live.
4. Data procedure: copy `clients.json`, `chat_history.json`, `orders.json`, `media.json`, `bank.json`, and `prompt.txt` from the Render disk. There is no database dump because the app stores JSON files. Google Sheets, if configured, stays outside the VPS.
5. Sequence: restore files into a new volume, start the container without the public webhook, send a synthetic webhook only in a test, then change the Meta callback during an approved window. Stop Render only after the new callback is verified.
6. Validation: `/masuk` returns 200, one known tenant still sees its own thread, and the other tenant does not. No second sender is running.
7. Rollback: point the Meta callback back to the Render URL and start that service again. Keep the Render disk until the new files have been compared.
8. Downtime: the gap between the old webhook and the new one. It was not measured. Inbound customer messages during that gap can be lost.
9. Depends on Meta Graph `v19.0` in the `main` code, OpenAI, and optional Google Sheets. 360dialog appears only on an unmerged branch.
10. Risk: Render disk may be ephemeral. A tracked `.env` exists on `main`. Its contents were not read. Rotate any credential that was committed before migration.

### 3. IPO AI — one poller, no orders

1. Current runtime: `https://beshare-ipo-bot.onrender.com/healthz` returned commit `75b6a1e`, Python 3.14.3, Telegram `polling`, lanes crypto, saham, and IPO all true, one restart, no last error. Keep-awake workflow `39182628888` succeeded at 00:51 UTC on 19 October 2026.
2. Target: the closed proposal in `beshare-ipo` `deploy/ipo/` (draft PR #39). One container. `127.0.0.1:8090` only. Entrypoint refuses live HATA and Moomoo flags.
3. Prerequisites: know whether `DATABASE_URL` is Postgres or empty, take a backup, and confirm Render is the only poller.
4. Data procedure: `pg_dump --format=custom` if Postgres, or a copy of `beshare_ipo.db` if that file exists on the Render disk. Restore into a new database that is not Copilot's. Compare CTO and CEO row counts before readers move.
5. Sequence: restore data, boot without Telegram tokens, stop Render, wait until polling has ended, then place the three tokens in one container.
6. Validation: health commit matches the image, `telegram` becomes `polling`, three lanes match the tokens that were moved, and no order is sent.
7. Rollback: stop the VPS container and start Render again while DNS still points at Render.
8. Downtime: Telegram silence between the two pollers. Not measured.
9. Depends on Telegram, Yahoo, HATA, optional Moomoo through Tailscale, and the IPO database. It must not use Copilot Postgres.
10. Risk: a second poller returns HTTP 409. Live database host and trading-flag values are unknown. Aurora PR #38 is not production.

### 4. WhatsApp OS — confirm the database before moving PHP

1. Current runtime: Hostinger CDN serves `https://beshareaisolution.com/whatsapp/` and executes `whatsapp/akaun/pintu.php`. A session read returned `ok: true` and `user: null`. `/os/` returns 403 for the directory.
2. Target: only after a MySQL dump. Preferred first step is to leave PHP on the website account. A VPS container is a later project `beshare-os` with its own MySQL volume.
3. Prerequisites: hPanel backup of database `u879723783_beshare_os`. The PHP code refuses any other database name. Whether that database is reachable from the live PHP process was not opened.
4. Data procedure: export that schema and the private config file that lives outside the repo. Do not copy the config into git.
5. Sequence: restore into a new MySQL, run the existing foundation checks against the copy, then switch only a test hostname.
6. Validation: session read still returns a logged-out state, one tenant cannot read another tenant's WABA row, and webhook verification still answers.
7. Rollback: point PHP config back at the original schema. Do not drop the original.
8. Downtime: none while the service stays on CDN. A later cutover was not measured.
9. Depends on Meta WhatsApp Cloud webhooks and the OS MySQL schema. It does not use the Copilot account service.
10. Risk: moving PHP before the dump can strand WABA connections and message rows. Directory 403 does not mean the app is unused; the account script is executing.

### 5. AI Video Studio — measure storage first

1. Current runtime: `https://ai-video-saas-ten.vercel.app/` returned 200 from Vercel, cache HIT, title AI Video Studio. Deployed commit was not in the headers.
2. Target: not this VPS yet. A later project `beshare-video` needs its own volume for ffmpeg output and a private network path to Supabase or a replacement database.
3. Prerequisites: Vercel project `ai-video-saas`, the Supabase project URL from the dashboard, and the size of stored media. `main` is `216e2b7` and uses `NEXT_PUBLIC_SUPABASE_URL` plus `ffmpeg-static`.
4. Data procedure: Supabase backup from the Supabase dashboard, plus any Vercel blob or upload bucket discovered there. None of those dashboards were opened.
5. Sequence: deferred until size and RAM are known.
6. Validation: a synthetic render completes and the public studio URL still answers.
7. Rollback: leave the Vercel alias in place until the VPS copy matches.
8. Downtime: none while it stays on Vercel.
9. Depends on Supabase and Gemini. Gemini was not called. The Copilot advice route on the VPS is a different process from this public studio URL.
10. Risk: ffmpeg and video files can exhaust the VPS that already runs Copilot.

### 6. Company website — already on Hostinger

1. Current runtime: `https://beshareaisolution.com/` returned 200, `server: hcdn`. Navigation and `home.css?v=20261013v4` match `main` `69e4d3f`. The header does not carry the git SHA.
2. Target: stay on the CDN. The VPS nginx copy on `staging.beshareaisolution.com` is not the public apex.
3. Prerequisites: none for a move that is not approved.
4. Data procedure: the site is static plus the PHP apps above.
5. Sequence: do not change DNS.
6. Validation: homepage, `/penasihat/`, and `/whatsapp/` still return 200.
7. Rollback: CDN remains the source.
8. Downtime: none.
9. The homepage links to Copilot pages, the WhatsApp OS page, and the Vercel studio. It does not link the IPO bot on the live homepage that was fetched.
10. Risk: replacing the CDN with the VPS in an app cutover would mix two changes.

### 7. D'Herbs — out of scope

GitHub Actions workflow `Terbitkan ke Hostinger` last succeeded on 9 October 2026 for `sifutech-ux/dherbs`. The built title is D'Herbs. No live shop domain was verified in this audit. Do not include it in the BeShare Compose projects unless the owner says it is part of this VPS.

## F. Risk register

| ID | Risk | Evidence | Mitigation already in this paper |
|---|---|---|---|
| R1 | Second IPO Telegram poller | Render health says `polling` and three lanes | One IPO container, tokens added only after Render stops |
| R2 | Copilot Postgres or ports overwritten | VPS already answers on 443 with Caddy and nginx 1.28.3 | No new publisher on 80/443. Do not run `down -v` in the Copilot directory |
| R3 | IPO database host unknown | Health JSON has no database field. Code uses `DATABASE_URL` or `beshare_ipo.db` | No cutover until a dump exists |
| R4 | WhatsApp JSON lost on Render restart | Files under `/app/data` or the workdir. No managed DB | Copy files before stopping Render |
| R5 | WhatsApp OS MySQL moved without a dump | Code requires database `u879723783_beshare_os` | Leave PHP in place until hPanel export |
| R6 | Video Studio fills the VPS disk | ffmpeg and Supabase are in code. Sizes are unknown | Keep Vercel |
| R7 | Tracked environment file in `whatsapp-ai-sales` | `main` contains `.env`. Contents were not opened | Rotate committed credentials. Do not print them |
| R8 | Advice and studio confused | VPS advice route is live. Public studio is Vercel. SHAs differ from `ai-video-saas` main | Treat them as two deployments |
| R9 | CTO and CEO rows mixed | IPO and Copilot both have desk isolation in code | Separate databases and a count check per desk |
| R10 | Auto buy or sell during a move | HATA and Moomoo flags are not visible on Render | IPO template refuses those flags |

## G. Provider access required

| Provider | Why | Status from this audit |
|---|---|---|
| Hostinger VPS panel for `srv2061175` | CPU, RAM, disk, `docker ps`, Postgres, certificates | Machine is reachable. Panel was not opened |
| Hostinger website panel behind `hcdn` | Deploy SHA, PHP version, MySQL, backups, cron | Site and PHP are reachable. Panel was not opened |
| DNS host for `beshareaisolution.com` | TTL and records before any future cutover | Records were resolved, not edited |
| Render, IPO service | `DATABASE_URL` presence, disk, trading flags, plan | Public health only |
| Render, WhatsApp service | Deployed SHA, disk, env names | Public pages only |
| Vercel project `ai-video-saas` | Production commit and env scope | Public HTTP only |
| Supabase | Project host, backups, storage size | Not opened. No project URL is copied here |
| Meta WhatsApp and, if used, 360dialog | Registered webhook URL | Not opened |
| Telegram | Confirm the three IPO bots after a future cutover | Not opened. Live lanes were inferred from health JSON |
| Gemini / OpenAI dashboards | Which deployment holds a key | Not opened and not called |
| Railway | No product file and no live hostname were found | Account not opened. No active Railway service was proven |
| Google Sheets | Legacy order sheet, if the function is configured | Not opened |

## H. Evidence

Checked 19 October 2026 without write calls:

- `GET https://beshare-ipo-bot.onrender.com/healthz` → 200, commit `75b6a1e`, Python 3.14.3, `telegram: polling`, three lanes true. Header `x-render-origin-server`.
- GitHub Actions `Keep Render awake` on `beshare-ipo` `main`, run `39182628888`, success.
- `GET https://beshareaisolution.com/` → 200, `server: hcdn`, CSS `home.css?v=20261013v4`, same navigation as `main` `69e4d3f`.
- `GET https://beshareaisolution.com/whatsapp/akaun/pintu.php?tindakan=sesi` → 200 JSON, logged out. The token in that body is not copied here.
- `GET https://beshareaisolution.com/os/` → 403, `server: hcdn`.
- DNS `akaun`, `nasihat`, `staging`, `akaun-staging`, and `nasihat-staging` → `179.197.244.80`. Reverse DNS `srv2061175.hstgr.cloud`.
- `GET https://akaun.beshareaisolution.com/healthz` and the staging account host → 200 `{"status":"ok","service":"beshare-akaun"}`, Python 3.12.15. Certificate CN `akaun.beshareaisolution.com`.
- `GET https://akaun.beshareaisolution.com/api/akaun/sesi` → 401, body asks for login. No login was attempted.
- `GET https://nasihat.beshareaisolution.com/api/copilot/nasihat` and the staging advice host → 405, `via: 1.1 Caddy`, Next.js `vary` headers.
- `GET https://nasihat.beshareaisolution.com/` → 404 from Caddy. `GET https://nasihat-staging.beshareaisolution.com/` → 200 Next.js HTML.
- `GET https://staging.beshareaisolution.com/` → 200, `nginx/1.28.3`.
- `GET https://beshare-whatsapp.onrender.com/masuk` → 200. `/healthz` → 404, `x-render-origin-server: uvicorn`.
- `GET https://ai-video-saas-ten.vercel.app/` → 200, `server: Vercel`, title AI Video Studio.
- `gh repo list sifutech-ux` returned the five repositories above.
- `beshare-ipo` draft PR #39 holds the IPO Compose proposal. It was not started.
- Copilot Compose shape on branch `cursor/copilot-vps-compose-cd0e`: Caddy 2.10, nginx 1.28, Postgres 16.15, account and advice images. Live image digests were not compared.

## Per-system report

### Business Copilot

SYSTEM: Business Copilot account, advice, and staging web  
REPOSITORY: Account behavior matches `beshare-ipo` `akaun_pelayan.py` on unmerged branches. Advice route is absent from `ai-video-saas` `main` and present on later advice branches. Web pages are `beshareaisolution`.  
CURRENT FRONTEND: `staging.beshareaisolution.com` is nginx 1.28.3 on the VPS. Public Copilot pages are the Hostinger CDN site.  
CURRENT BACKEND: Account Python 3.12.15 and advice Next.js behind Caddy, both on `srv2061175.hstgr.cloud`.  
DATABASE: Postgres is the design in the Copilot Compose branch. Live database name, host, and size were not opened.  
BACKGROUND WORKERS: None confirmed beyond the web processes.  
DEPLOYED COMMIT: UNKNOWN. Health does not return a SHA.  
DNS: `akaun`, `nasihat`, `staging`, `akaun-staging`, `nasihat-staging` all point at `179.197.244.80`.  
DEPLOYMENT METHOD: Consistent with Docker Compose and Caddy. The exact command and image digest are UNKNOWN.  
STATUS: CONFIRMED ACTIVE  
EVIDENCE: Account `/healthz`, advice HTTP 405, staging nginx header, reverse DNS.  
MIGRATION READINESS: Do not migrate. Preserve.  
BLOCKERS: Panel access for backups and `docker ps` before any neighboring project is added.

### BeShare IPO AI

SYSTEM: BeShare IPO AI, including three Telegram lanes  
REPOSITORY: `sifutech-ux/beshare-ipo`  
CURRENT FRONTEND: Same process as the bot. Public URL `https://beshare-ipo-bot.onrender.com`.  
CURRENT BACKEND: Render, Python 3.14.3, `Procfile` `web: python3 bot.py`.  
DATABASE: `DATABASE_URL` Postgres or local `beshare_ipo.db`. Live choice UNKNOWN.  
BACKGROUND WORKERS: In-process IPO scan, digests, price alerts, paper simulation, session research, and Telegram polling.  
DEPLOYED COMMIT: `75b6a1ec6d07ef2b11e7fc456ed851d5ad15b54e`  
DNS: Render Cloudflare origin. Not the Hostinger VPS.  
DEPLOYMENT METHOD: Render plus GitHub keep-awake cron. Auto-deploy setting was not opened. The live SHA equals `main`.  
STATUS: CONFIRMED ACTIVE  
EVIDENCE: `/healthz` and workflow run `39182628888`.  
MIGRATION READINESS: Not ready. Proposal only, PR #39.  
BLOCKERS: Database backup, single-poller cutover, unknown trading flags.

### WhatsApp AI OS

SYSTEM: WhatsApp AI OS  
REPOSITORY: `sifutech-ux/beshareaisolution` paths `whatsapp/` and `os/`  
CURRENT FRONTEND: Hostinger CDN, `server: hcdn`.  
CURRENT BACKEND: PHP on that same website account. `pintu.php` executes.  
DATABASE: Code requires MySQL database `u879723783_beshare_os` via `BESHARE_OS_DSN` or a private config file. Live server was not queried.  
BACKGROUND WORKERS: A worker exists in code. Live cron is UNKNOWN.  
DEPLOYED COMMIT: Homepage assets match `main` `69e4d3f`. PHP SHA is not in the HTTP headers.  
DNS: `beshareaisolution.com` → `191.101.104.45` and `212.1.212.212`, plus IPv6. This is not the VPS address.  
DEPLOYMENT METHOD: Hostinger website publish. GitHub workflow for this repo was not present.  
STATUS: CONFIRMED ACTIVE for the public PHP gate. Customer traffic volume is UNKNOWN.  
EVIDENCE: `pintu.php` JSON 200 and `/whatsapp/` HTML 200.  
MIGRATION READINESS: Not ready to leave the website account.  
BLOCKERS: MySQL backup and proof of which cron or webhook hostname Meta calls.

### WhatsApp AI legacy application

SYSTEM: WhatsApp legacy dashboard  
REPOSITORY: `sifutech-ux/whatsapp-ai-sales`  
CURRENT FRONTEND: Render, page title from `/masuk`.  
CURRENT BACKEND: Render, uvicorn, `Procfile` `web: uvicorn app.main:app`. `runtime.txt` on `main` says Python 3.11. The live patch version was not shown.  
DATABASE: JSON files, not a database server. Google Sheets writer exists in code. Sheet ID was not read.  
BACKGROUND WORKERS: No separate worker file on `main`.  
DEPLOYED COMMIT: UNKNOWN. `main` is `45ede97`.  
DNS: `beshare-whatsapp.onrender.com` → Render addresses `216.24.57.16` and `216.24.57.18`.  
DEPLOYMENT METHOD: Render. Auto-deploy was not opened.  
STATUS: CONFIRMED ACTIVE enough to serve `/masuk`. Message volume is UNKNOWN.  
EVIDENCE: `/masuk` 200 and uvicorn `/healthz` 404.  
MIGRATION READINESS: Not ready.  
BLOCKERS: File backup, webhook ownership, and the tracked `.env`.

### AI Video Studio

SYSTEM: AI Video Studio  
REPOSITORY: `sifutech-ux/ai-video-saas`  
CURRENT FRONTEND: Vercel, `ai-video-saas-ten.vercel.app`.  
CURRENT BACKEND: Vercel.  
DATABASE: Supabase client in `src/lib/supabase.ts`. Project host was not opened.  
BACKGROUND WORKERS: ffmpeg-static runs inside the app for stitching. No cron workflow in the repo.  
DEPLOYED COMMIT: UNKNOWN. `main` is `216e2b7`.  
DNS: Vercel addresses `216.198.79.3` and `64.29.17.3`.  
DEPLOYMENT METHOD: Vercel. Dashboard was not opened.  
STATUS: CONFIRMED ACTIVE  
EVIDENCE: HTTP 200, `server: Vercel`, title AI Video Studio.  
MIGRATION READINESS: Not ready.  
BLOCKERS: Supabase and media size, VPS RAM, and deployed SHA.

### Telegram investment bots

SYSTEM: Three IPO Telegram lanes inside the IPO process  
REPOSITORY: `sifutech-ux/beshare-ipo` `telegram_lanes.py`  
CURRENT FRONTEND: Telegram chats. Not separately hosted.  
CURRENT BACKEND: The Render IPO process.  
DATABASE: Same unknown IPO database.  
BACKGROUND WORKERS: Long polling in that process.  
DEPLOYED COMMIT: `75b6a1e`  
DNS: Not applicable. Delivery is Telegram.  
DEPLOYMENT METHOD: Same Render service.  
STATUS: CONFIRMED ACTIVE  
EVIDENCE: Health `lanes` all true and `telegram: polling`.  
MIGRATION READINESS: Same blockers as IPO.  
BLOCKERS: Must not run a second poller. Must not enable auto buy or sell.

### Shared authentication

SYSTEM: There is no single shared login service.  
REPOSITORY: Several.  
CURRENT FRONTEND: Each product has its own page.  
CURRENT BACKEND: Copilot account on the VPS. IPO uses Telegram Mini App HMAC plus a desk cookie. WhatsApp OS uses its PHP session. WhatsApp legacy uses its own cookie and JSON clients.  
DATABASE: Separate by design.  
BACKGROUND WORKERS: None shared.  
DEPLOYED COMMIT: See each system.  
DNS: See each system.  
DEPLOYMENT METHOD: See each system.  
STATUS: CONFIRMED as separate systems. A unified identity provider was not found.  
EVIDENCE: Different hosts, different session checks, and code that rejects the IPO Render host as a Copilot account host.  
MIGRATION READINESS: Do not merge these logins during a hosting move.  
BLOCKERS: None beyond keeping their databases apart.

### Other discovered service

SYSTEM: D'Herbs shop  
REPOSITORY: `sifutech-ux/dherbs`  
CURRENT FRONTEND: Hostinger, according to GitHub Actions. Live domain UNKNOWN.  
CURRENT BACKEND: Static site plus `api/index.php` in the repo. Live PHP was not requested.  
DATABASE: UNKNOWN.  
BACKGROUND WORKERS: The workflow runs on push, not on a timer.  
DEPLOYED COMMIT: UNKNOWN. Last successful workflow run was 9 October 2026.  
DNS: UNKNOWN.  
DEPLOYMENT METHOD: GitHub Actions `Terbitkan ke Hostinger`.  
STATUS: LEGACY / POSSIBLY UNUSED relative to BeShare AI. The workflow itself succeeded.  
EVIDENCE: `gh run list` for `sifutech-ux/dherbs`.  
MIGRATION READINESS: Out of scope.  
BLOCKERS: Owner must say whether this shop shares the BeShare VPS.

## Provider classification

| Provider | Class | Note |
|---|---|---|
| Hostinger website CDN | CONFIRMED ACTIVE | `beshareaisolution.com` |
| Hostinger VPS `srv2061175.hstgr.cloud` | CONFIRMED ACTIVE | Copilot names and staging |
| Render | CONFIRMED ACTIVE | IPO and WhatsApp legacy |
| Vercel | CONFIRMED ACTIVE | AI Video Studio |
| GitHub Actions | CONFIRMED ACTIVE | IPO keep-awake. D'Herbs publish. IPO test workflow exists |
| Caddy and nginx on the VPS | CONFIRMED ACTIVE | Response headers |
| Supabase | UNKNOWN / ACCESS REQUIRED | Used by Video Studio code |
| Railway | UNKNOWN / ACCESS REQUIRED | No live service found |
| Postgres for Copilot | UNKNOWN / ACCESS REQUIRED | Designed, not queried |
| Postgres or SQLite for IPO | UNKNOWN / ACCESS REQUIRED | Not queried |
| MySQL for WhatsApp OS | UNKNOWN / ACCESS REQUIRED | Name is required by code |
| Gemini and OpenAI | UNKNOWN / ACCESS REQUIRED | Not called |

## What this change does not do

No application file, DNS record, container, database, or workflow was modified. This file is documentation only.
