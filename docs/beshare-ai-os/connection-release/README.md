# WhatsApp connection release candidate — 23 October 2026 (MYT)

## What this candidate changes

Visitors following the WhatsApp connection link currently lose that destination when authentication is required. The existing login and registration handlers always open the dashboard. This candidate preserves only the internal `sambungan` destination and restores it after successful owner authentication.

The return value is scoped to the current browser tab, survives a login-page refresh when session storage is available, and is consumed after successful authentication. Logout clears it. Other roles, invalid values and external URLs fall back to the dashboard. If browser storage is unavailable, the current page keeps a memory fallback; a refreshed page without usable stored state falls back to the dashboard. Returning to Sambungan never automatically launches Meta.

This branch assembles a focused release on main `69e4d3fb7693db6d199dce7005eed73d65f60069`:

- Eleven Security P0 runtime files are byte-identical to PR #63, `c955270cf393c435bc51356ce9f261eda4ceb2fb`.
- Three onboarding UI files come from PR #68, `d44a8512b2151a8f79ca646735cc0b9e593edede`. Only `ruang.js` gains the authentication return behavior and `ruang.html` receives its cache version.
- The compatible P0 fixtures and signup-launch check are included. The P0 runner includes the already implemented phone-override suite, as in #68.
- A dedicated CI workflow runs only syntax and the new authentication-return check.

[manifest.json](manifest.json) identifies all fourteen runtime paths, their original blobs and candidate blobs. This candidate does not include #65/#66 migration-tool, standby or offline MVP changes. Original branches and PRs remain unchanged.

## Existing onboarding work preserved

The public WhatsApp CTA points to:

https://beshareaisolution.com/whatsapp/ruang.html#sambungan

PR #68 already distinguishes a requested Meta window from a confirmed result, handles SDK launch exceptions and gives a 15-second no-response advisory while accepting late callbacks. The previous browser observation did not establish why no Meta popup/completion was visible. This candidate does not claim that an external Meta popup problem has been diagnosed or repaired.

## Validation and provenance

Newly executed against the modified workspace JavaScript:

- `node os/bin/check-auth-return.mjs`: **25 checks passed**.
- `node --check whatsapp/ruang.js`: passed.
- The VM fixture executes the complete workspace script with synthetic DOM, API and storage behavior. It performs no network requests and does not bind a number or start Meta signup.
- [Captured output](auth-return-checks.txt).

The checks cover owner login and registration, refreshing the login page, failed-login retry, staff/admin/invalid-role fallback, invalid and external targets, stale destination replacement, logout, denied storage, separate tabs and no automatic Meta launch.

The 451 previous P0/onboarding checks were **not rerun**. Their reports remain evidence for their original commits, not a new certification of this assembled release:

- [Security P0 source report](https://github.com/sifutech-ux/beshareaisolution/blob/c955270cf393c435bc51356ce9f261eda4ceb2fb/os/tests/SECURITY-P0-20261023.md)
- [Onboarding execution report](https://github.com/sifutech-ux/beshareaisolution/blob/d44a8512b2151a8f79ca646735cc0b9e593edede/os/tests/onboarding/README.md)

A separate source review confirmed the P0 runtime imports and included regression-source dependencies resolve against main plus this candidate. They do not require #65 or #66. Existing application regression tools are preserved for future necessary checks; no broad rerun is part of this continuation.

## Publication status and the remaining concrete dependency

**Prepared only. No merge, deployment, production configuration/database change, Meta callback change, subscription or real number binding occurred.**

The current session can read and write GitHub but does not expose a Hostinger operation tool. Actual deployment-target configuration was therefore not inspected. The prior P0 report records the public domain under Hostinger account `u879723783`; this is inherited evidence, not a new server inspection or a decision to move services.

Before the separately authorized publication, establish the actual target and read its existing private ownership binding:

- `legacy_business_id`: the OS business that owns the existing legacy store.
- `legacy_waba_id`: the WABA that owns the existing fixed legacy phone.
- The existing app secret must match the app delivering the selected callback.

No secret value belongs in this repository. Missing binding deliberately produces 503 for authenticated legacy Desk/intake; it must not be guessed from a new signup. Anonymous Desk requests remain denied. No schema migration is introduced here; the existing Coexistence implementation still needs the actual target's 0001–0003 schema. Existing target directory protections and backup evidence must be resolved from the target rather than inferred from prior local tests.

The complete fourteen-file runtime set and verified private configuration must be treated together when publication is authorized. Preserve the existing callback/subscription and worker flags during that code release. Do not merge this draft merely to trigger an automatic main deployment before the target/configuration dependency is resolved.

Security P0 keeps the legacy sender unconditionally disabled. This candidate prepares the protected connection journey; it does not complete or enable customer AI auto reply. Subsequent live number binding and outbound work remain distinct actions requiring the existing owner's authorization.
