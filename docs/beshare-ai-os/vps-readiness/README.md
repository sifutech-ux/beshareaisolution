# WhatsApp AI OS — VPS migration and Coexistence readiness

Audit date: 23 October 2026, Asia/Kuala_Lumpur. **Decision: BLOCKED for starting standby services; readiness documentation is complete.** This is a documentation-only proposal, based on `main` at `69e4d3fb7693db6d199dce7005eed73d65f60069`. It neither includes nor releases PR #63.

The marketing website stays on Hostinger Web Hosting/CDN. The proposed destination for OS PHP, its MySQL-compatible database and its workers is VPS `srv2061175`. No service was started, migration/restore executed, DNS/Meta/WABA changed, or message sent. IPO, Telegram, Business Copilot and Caddy are outside the change boundary.

Latest owner decision: [Fresh VPS database plan](FRESH-DATABASE.md) selects a fresh isolated target while retaining the old DB and requiring a preservation register. It records new read-only observations and pending exact-engine tests; it supersedes a default whole-database import assumption, not the safety gates. [Pre-Standby Gate Closure](GATE-CLOSURE.md) contains prior pinned-source tests, access gaps and the owner-requested provisional RPO ≤15 minutes / RTO ≤2 hours.

Read [the migration runbook](RUNBOOK.md) and [the isolated Compose design](COMPOSE-DESIGN.md). All future actions there are plans, not executed steps or release authorization.

## Evidence vocabulary

- **CONFIRMED**: directly observed in this audit; the claim is limited to the observation.
- **CODE ONLY**: implementation exists, without operational acceptance evidence. Hosted file presence can be confirmed while operation remains CODE ONLY.
- **UNKNOWN**: no adequate observation was available.
- **BLOCKED**: a specific missing prerequisite prevents the next gate.

## GitHub reconciliation

Fetched `origin/main`; checked each head with `git merge-base --is-ancestor HEAD origin/main`; read current PR metadata through GitHub. No PR was merged, closed or retargeted.

| Work | GitHub status observed | Head in main | Operational status |
| --- | --- | --- | --- |
| [#37 Embedded Signup](https://github.com/sifutech-ux/beshareaisolution/pull/37) | Open draft | `40f299a` yes | CODE ONLY; app/asset eligibility UNKNOWN |
| [#40 Phase A](https://github.com/sifutech-ux/beshareaisolution/pull/40) | Merged | `c9b459c` yes | CODE ONLY; no live Coexistence acceptance |
| [#41 Phase B](https://github.com/sifutech-ux/beshareaisolution/pull/41) | Open draft, historical Phase A base | `fab6a42` yes | CODE ONLY; schema and subscription UNKNOWN |
| [#42 Phase C](https://github.com/sifutech-ux/beshareaisolution/pull/42) | Open draft, historical Phase B base | `2ea5c35` yes | Worker source hosted; runtime UNKNOWN |
| Phase D / phone override | Commit `bdd71c9` | yes | Source hosted; active override UNKNOWN |
| Phase E / lifecycle bridge | Commit `50b2759` | yes | Source hosted; active delivery UNKNOWN |
| [#43 event ordering](https://github.com/sifutech-ux/beshareaisolution/pull/43) | Merged | `4d45ac0` yes | CODE ONLY |
| [#44 single-asset fallback](https://github.com/sifutech-ux/beshareaisolution/pull/44) | Merged | `0941c21` yes | CODE ONLY |
| [#63 Security P0](https://github.com/sifutech-ux/beshareaisolution/pull/63) | Open draft | `c955270` NO | BLOCKED as release dependency; not deployed by this audit |

Phase D/E are feature labels mapped to the two commits above; no separate D/E PR merge is asserted. Old draft PR status does not mean their code is absent. Conversely, ancestry is not deployment proof.

## Hosting evidence

Hostinger `hosting_websites_list` confirms enabled `beshareaisolution.com`, account `u879723783`, root `/home/u879723783/domains/beshareaisolution.com/public_html`, type `other`. CDN configuration itself was not independently inspected and remains UNKNOWN; preserving the existing marketing/CDN route is the requirement.

`hosting_php_get` confirms PHP **8.3.35**, mbstring and nd_pdo_mysql enabled, curl/openssl built in. `display_errors=Off`, `log_errors=Off`, configured PHP memory and upload limits 2048M. Do not copy those large hosting limits into a shared VPS. The configured PHP session storage defaults outside the document root. Runtime SAPI/CLI equivalence is not proven by the settings API.

Fresh read-only source retrieval matched main after trimming outer whitespace (the API drops final newlines):

| Hosted path | SHA-256 of trimmed content |
| --- | --- |
| `webhook/index.php` | `58f7623a23bd29508bc7960738970a92deddbe4980bed5aa52c112a335a43b33` |
| `whatsapp/sambungan/webhook.php` | `ef222a1bac7016848671e63437057712434f49ff86e4a6bee0864ac7dd2bdded` |
| `os/src/CoexistenceWorker.php` | `522b3c97452847040177bbca82e457db78f13c106cb9cc6d6101e726ece9b09b` |
| `os/src/PhoneOverride.php` | `a54f2c221da11edc87313a955b76220e8055511820b0fc1876e941c074e67449` |
| `os/src/LifecycleBridge.php` | `4c9f0ccf885d0dda445f96556ab1be1b0513447723ee028f1d14765481b8adf5` |

This is a five-file sample, not a whole-site release manifest. The hosted legacy webhook still matches pre-P0 main: it can process legacy messages before the lifecycle signature boundary and contains an outbound path. **Do not claim production outbound is OFF.** P0 makes that sender unconditionally false and gates Desk access, but its branch is not main. The earlier P0 report's 400 checks are historical lab evidence, not tests repeated here or MySQL certification.

Hosted reads of migrations 0002/0003 were refused with HTTP 422 (sensitive-content filter); `os/.htaccess` was refused with HTTP 403 (Cloudflare challenge). No bypass was attempted. File existence would not prove migration application anyway.

`hosting_databases_list` confirms `u879723783_beshare_os`, assigned to this domain, reported usage 2 MB / limit 3072 MB, external host `srv2050.hstgr.io:3306`. The reported application user has broad privileges including ALTER/DROP; target runtime credentials must be separate from migration credentials. This list does **not** identify engine/version, table contents, applied migrations or business ownership. Connected operations did not provide a SQL inspection endpoint. No remote-access rule was added and no private credential file was requested.

`hosting_cron-jobs_list` returned four jobs for another site's Telegram/briefings, none naming BeShare OS or `sync-coexistence.php`. This confirms only that account cron list; VPS timers, external schedulers and manual worker executions remain UNKNOWN. Those unrelated jobs were left untouched.

## Application and persistence inventory

| Component | Code evidence / migration consequence | Status |
| --- | --- | --- |
| Marketing and portal | Root HTML/CSS/JS plus account/advice assets; keep existing hosting. Repository README's “100% static” does not describe current OS PHP. Do not deploy the whole repo wholesale. | CONFIRMED code |
| OS client | `whatsapp/ruang.js` uses same-origin relative PHP calls and sessionStorage for UI/signup state. Login and OS UI should move together under one origin in a later release. Browser state is not a server backup. | CODE ONLY |
| PHP backend | `whatsapp/akaun/pintu.php`, `whatsapp/sambungan/*`, `os/src/*`; bespoke PHP bootstrap, PDO MySQL, mbstring, OpenSSL, JSON/session/hash, CA bundle; CLI for workers. No Composer/package manifest found for this subsystem. | CODE ONLY |
| Foundation | 0001 has 8 tables and 5 owner/membership triggers. `Contract::DATABASE` hardcodes `u879723783_beshare_os`; preserve this name on isolated target or separately change/test the contract. | CODE ONLY; deployed definitions UNKNOWN |
| Coexistence storage | 0002 adds sync states/deadlines and contacts/messages/jobs; 0003 adds callback/field readiness and job lease/review states. Plaintext contact/message projection columns coexist with encrypted webhook payloads. | CODE ONLY |
| Tenant identity | users → business_users → businesses → whatsapp_connections; unique phone ID and tenant/connection composite FK. P0 resolves events by WABA AND phone. Actual rows and legacy JSON ownership unverified. | UNKNOWN production mapping |
| Token/secrets | AES-256-GCM token and webhook payload material in DB; private PHP config outside repo/web root; key version must travel with encrypted data. Env takes precedence in some loaders: audit inherited env without printing values. | CODE ONLY; custody/presence UNKNOWN |
| Legacy data | `admin/stor` JSON, message/order/config files and bounded dedupe; `whatsapp/meja/muat` uploads; `BESHARE_STOR`/`BESHARE_MUAT` overrides. Legacy webhook has hardcoded `admin/stor` paths: an override alone does not relocate all storage. | CODE ONLY; size/content UNKNOWN |
| Other state | Private rate directory, lifecycle-spool encrypted retry files, PHP sessions, audit records. Explicitly persist spool/rate, choose session invalidation at cutover, preserve legacy file ownership. | CODE ONLY |
| Worker | `os/bin/sync-coexistence.php run` is gated by exact `BESHARE_COEXISTENCE_SYNC_ENABLED=true`. Readiness-record subcommands write DB even when run flag is OFF. Phone override has its own flag. No general AI draft/sender queue is established by these workers. | CODE ONLY |
| External dependencies | Meta Graph v26.0 in OS, v21.0 in legacy send path; browser Meta SDK; future AI draft pipeline needs separate implementation/review. Do not infer all Graph operations are disabled just because sync flag is OFF. | CODE ONLY |

## VPS evidence and isolation boundary

Hostinger list confirms ID **2061175**, hostname `srv2061175.hstgr.cloud`, running Ubuntu 26.04 LTS, KVM 2, **2 CPUs / 8192 MB RAM / 102400 MB disk**. No OS containers were listed.

| Existing project | Observed state | Published ports |
| --- | --- | --- |
| `beshare-ipo` | 2 healthy containers, app + PostgreSQL 17 | `127.0.0.1:8090` → app 8080 |
| `copilot` | 5 healthy containers | Caddy owns TCP 80/443 on IPv4 and IPv6 |
| `copilot-production` | 3 healthy containers | No host ports in returned list |

Other internal ports include PostgreSQL 5432, account 8767, advice 3000 and Caddy admin 2019. Do not share their networks/volumes or repurpose these services. This is the Docker project list, not a complete host socket/timer inventory. No free host port is certified.

Hostinger metrics for 22 October MYT (48 samples) report CPU 5.94–11.71%, RAM 1.11–1.56 GiB, disk used 8.34–11.43 GiB. These historical samples suggest room for a capped rehearsal but do not establish current headroom, I/O throughput or peak safety. Before start, recheck memory available, free disk/inodes, swap, OOM events, container limits, host listeners, timers and Docker networks through approved read-only server access.

Firewall 378885 is synced, with accept rules for TCP 22/80/443 from any source. No firewall edits made. Listed VPS backups: 21 October 2026 14:44 MYT, 21 October 06:35 MYT and 20 October 02:12 MYT. Existence is CONFIRMED; application consistency and restore are UNKNOWN. Whole-VPS restore would affect IPO/Copilot and is not an OS rollback method.

## Blockers and minimum owner inputs

| Gate | What is missing | Minimum next action |
| --- | --- | --- |
| Offline/synthetic standby | Reviewed secure artifact, selected DB engine/version, isolated image/Compose validation, current VPS headroom/socket inventory | Owner identifies release reviewer and permits a later narrowly scoped standby start only after engineering supplies those checks; no production credentials needed for synthetic fixtures |
| Production-shaped rehearsal | Live schema/version/trigger evidence, tenant–WABA–phone/store ownership, encrypted backup and separate key custody, restore proof | Owner provides an approved read-only DB inspection route or redacted schema/mapping evidence; confirm backup destination/custodian and RPO/RTO. Do not paste tokens or passwords into chat |
| Live callback cutover | Actual app callback, fields, WABA subscriptions and phone override; supported ingress without Caddy changes | Owner confirms Meta admin access and intended OS hostname; engineer presents a concrete ingress choice and separate cutover change |
| Coexistence activation | Tech Provider/partner eligibility, permissions/Advanced Access, Business App number eligibility, spare test number, current Meta rules | Owner confirms a non-critical eligible test number and dashboard evidence; no live signup before sync-readiness gate |

The app/config IDs in source identify intended configuration only. Meta callback/subscriptions/phone overrides, token validity and actual ownership are UNKNOWN; there was no connected Meta dashboard/API inspection. Official Meta documentation fetch returned HTTP 429, so the code's 24-hour/once-per-onboarding assumptions remain a revalidation gate, not newly certified platform policy.

No additional broad permission is needed to review this draft. Starting services, creating backups of production data, restoring/migrating, and changing routing/Meta require a later explicit scoped instruction.
