# Copilot: four product improvements

Three guided starts on Utama lead to marketing drafts, weekly actions and an explanation of the user's recorded numbers. A persistent collection holds marketing drafts and voice summaries. Pelan has a weekly outcome review, included as user-supplied context in subsequent advice. After a voice session, the user may correct the transcript, request a summary, save that summary, and separately confirm proposed actions.

## Behavior and boundaries

- Existing five modules and Aurora design retained. Onboarding opens Utama; generating an AI response requires a button press.
- Up to 200 saved items per account, including archives. Search titles/previews; edit title, channel, content and status; archive/restore. Status `digunakan` is self-reported, not automatic publishing.
- Drafts and weekly reviews have optimistic versions and retry-safe saves. A conflict preserves the local edit and displays the account copy. No drafts or transcripts are written to browser localStorage.
- One review per week, optionally linked to an owned task in that week; current or preceding 90 days. Last four reviews enter AI context. Review notes do not create financial records and do not prove sales causality.
- Voice transcript is temporary, lost on reload or replaced after the next session. Maximum 30,000 characters; oversize input is rejected rather than clipped. Summary requests reuse existing authenticated advice/quotas with a stable retry key. Raw transcript is not added to account chat history. Existing service/provider processing and result retention still apply.
- Summary saving and action confirmation are separate. Only existing trusted, server-recorded proposals can change tasks/numbers. Editable summary text cannot replace a proposal payload.
- No new AI provider, API key, billing plan, outbound reminder or automatic publishing. No changes to WhatsApp AI OS or investment data.

## Source layout

`account/` contains the account-only Python modules needed for reproducible tests. Billing/HTTP modules match the existing billing source. The release Dockerfile deliberately copies **only** `pelanggan_copilot.py` and `pelanggan_hasil.py`, validating base-image hashes first. Other modules are baseline fixtures, not replacements for production modules.

## Validation

```sh
PYTHONPATH=copilot/account python3 -m unittest discover -s copilot/tests -p 'test_*.py' -v
node --test copilot/tests/hasil-core.test.js
```

The GitHub workflow adds PostgreSQL 16 in an isolated database. Test connections are restricted to the loopback database named `copilot_kuota_test`. The suite includes existing account/quota/action regressions and new ownership, capacity, validation, conflicting-write and idempotent-retry cases.

For local visual QA:

```sh
python3 copilot/tests/preview.py .
```

Open http://localhost:8000/qa. This uses a temporary SQLite account and visibly labelled synthetic AI replies, with a preview-only transcript button. It never calls the real model or customers. Do not deploy `tests/preview.py` as a service.

## Release order / rollback

1. Pass the PR checks. Record current account image and override; take the existing dedicated account database backup.
2. Build `copilot/account/Dockerfile` from verified `copilot-account:billing-7118f90` (or explicitly verify newer baseline hashes). A hash mismatch blocks the build; do not bypass it blindly.
3. Deploy the account layer through the existing Compose service. Schema initialization adds `copilot_work` and `copilot_review`; no existing records are rewritten. Verify authenticated collection/review/context routes with a controlled account; ensure foreign account access is denied.
4. Only then merge/release the frontend. Shared hosting follows main. Check desktop/mobile and an actual voice session with the controlled account.
5. If needed, roll back frontend first, then restore the previous account image/Compose override. Leave the two additive tables in place to preserve saved work; do not drop them as part of routine rollback.

Live AI tone and microphone transcription quality require a real post-release session. The local synthetic preview proves UI wiring and confirmation flow, not live model quality.
