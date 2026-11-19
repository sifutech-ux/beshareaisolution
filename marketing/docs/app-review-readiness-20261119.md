# Threads App Review readiness — 19 November 2026

This is a preparation record for **BeShare AI Marketing**, not an approval,
compliance attestation, submitted application, or record of a new live test.
It describes the source in this checkout and the dashboard observations supplied
by the live Meta review audit. Any feature being added in this checkout must be
deployed and verified before it is demonstrated or represented as available.

## Current position

The live audit on 19 November 2026 reported:

- Parent Meta app `1473513278172621` is **Published**.
- The connected **BeShare Ai Solution** business portfolio is **Verified**, as
  shown in the App Review verification step.
- The existing App Review draft is **Not submitted** and contains nine permissions.
- `threads_basic` and `threads_content_publish` are **Ready for testing**, with
  seven and three recorded API calls respectively.
- Both Threads permissions' test-API-call requirements are displayed as
  **Completed**. Partial usage descriptions were saved in Meta for both
  permissions. The allowed-usage forms still need genuine screencasts and the
  required agreements. No screencast was uploaded or agreement accepted in this
  audit; the submission remains incomplete.

These observations do not establish approval for customers without app roles.
Meta requires both permission approval through App Review and a published app
before non-role users can grant Threads permissions. Accepted Threads testers
can use the testing route. A customer's report of a code-like error is not
enough to identify that customer's exact error code or account eligibility.

### Prepared app icon

The live App settings step reported a missing app icon and required a square
512–1024 pixel JPG, GIF or PNG no larger than 5 MB. The prepared file is
`marketing/assets/meta-app-icon.png`: **1024 × 1024 PNG, 35,730 bytes**.

It was rasterized directly from the repository's `favicon.svg` with Inkscape,
preserving the existing gradient triangle, dark rounded background and
transparent corners. The PNG was opened for visual inspection and its dimensions,
format and size were checked. **Prepared only:** this source audit has not
uploaded it to Meta or verified that Meta has accepted it.

## Implementation supported by this checkout

| Capability | Source evidence | Limit of the evidence |
| --- | --- | --- |
| Threads authorization | `copilot/account/marketing_social.py`: `SCOPES`, `_begin`, `_complete` | Requests only `threads_basic` and `threads_content_publish`; a successful authorization is not public-customer approval. |
| Connected-account identity | `_complete` reads `/me` with `id,username`, checks token validity/scopes/user identity, and saves the selected connection for the signed-in BeShare account. | Does not establish the real-world identity of the customer or business. |
| User-directed publication | `marketing/marketing.js`: `schedulePost`; `copilot/account/pelanggan_marketing.py`: `_schedule`; `copilot/account/marketing_worker.py` | Saving a draft does not publish. A separate approval and schedule action queues publication to the pinned account. |
| Threads publishing | Worker creates a container at `/{account}/threads`, checks processing status, and calls `/{account}/threads_publish`. | A returned provider post ID establishes provider acceptance, not an independent public-viewer visibility check. |
| Published-post destination | `copilot/account/marketing_publication.py` and the publication-receipt UI in this checkout | Reads a known post's ID, owner and permalink and checks the connected identity. Deployment and a genuine live check are required before claiming this new receipt flow is operational. |
| Disconnect | `marketing_social.py`: `_disconnect` | Clears the saved provider token and marks the connection disconnected. This is not proof of every retention, backup or platform-side deletion practice. |

Tokens are encrypted by the source implementation and provider calls are made
from the account service. That code observation must not be expanded into an
unverified claim about operational access controls, subprocessors, retention,
backups, incident response, or legal compliance.

## Candidate permission descriptions

The following English wording is for the release represented by this checkout.
Recheck it against the deployed UI and resolve the evidence gaps below before
copying it into a final submission.

### `threads_basic`

> BeShare AI Marketing is a business content-planning and publishing workspace.
> After a business user signs in to BeShare and authorizes Threads, we read their
> Threads account ID and username to identify the connected account and display
> that identity in their own workspace. For posts published from BeShare, we read
> the existing post's ID, owner and permalink so the user can open the correct
> published post. This supports management of the user's own Threads presence.
> We do not use this permission to browse other people's Threads content or
> display a customer's account data in another customer's workspace.

### `threads_content_publish`

> BeShare AI Marketing lets a business user prepare text, link, image or video
> content for their own connected Threads profile. The user reviews the content
> and selected destination, then explicitly approves a publication time. Our
> server creates the Threads container, checks its processing status, and
> publishes it using the permission granted by that user. We show the recorded
> publication result in the user's workspace and provide the provider's post
> link when it has been resolved. Saving a draft or connecting an account does
> not publish content. The user can disconnect Threads from the Connections
> section.

## Reviewer journey to demonstrate

1. Open `https://beshareaisolution.com/marketing/` and sign in using the verified
   review-access arrangement. The reviewer account and access instructions have
   **not** been established in this audit.
2. Open **Sambungan** (Connections), choose Threads, and show the actual Threads
   authorization flow and both requested permissions. Return to BeShare and
   show the connected username.
3. Open **Kempen** (Campaigns) and a suitable campaign, then **Kandungan**
   (Content). Create a clearly identified review test post in a supported format.
4. Show the post preview and connected Threads identity. Explain that
   **Simpan draf** saves a draft, while **Lulus & jadualkan** is the explicit
   approval to publish. The current UI requires a time at least one minute in
   the future and displays Malaysia time.
5. Approve the test publication, show the real queued/processing outcome, and
   wait for the provider-confirmed result. Do not replace an uncertain or failed
   result with a manually edited status.
6. Open the resolved provider link and show that the same content exists on the
   expected native Threads profile. Return to the BeShare record to demonstrate
   the relationship between the content, account and provider post.
7. Show the existing BeShare-created own-post records and their actual individual
   provider verification. Identify exactly what is read from Threads and what
   is stored locally. The evidence gap below remains until that demonstration
   has been assessed against the current `threads_basic` review requirement.

Use an existing authorized test account for preparation. The review-access
arrangement must support Meta's actual review process; do not assume a reviewer
can pass a tester-only gate. Do not invite customers, grant roles, share
credentials, or publish new test content merely because this document describes
those steps.

## Genuine screencast checklist

- [ ] Confirm the exact production/review build and its capabilities first.
- [ ] Record the real OAuth permission and linking screens, including the return
  to the user's connected identity in BeShare.
- [ ] Show how each requested permission is used; do not submit a static mockup
  or a sequence that pretends an unperformed action succeeded.
- [ ] Show actual content creation, explicit user approval, processing and final
  result, then the same post in native Threads.
- [ ] Demonstrate the actual `threads_basic` reads and assess the own-post
  retrieval evidence gap; do not imply a remote feed import is implemented.
- [ ] Use clear English captions for the Malay UI and translate the relevant
  controls. Meta's tutorial says reviewers do not rely on audio explanations.
- [ ] Keep the app name, relevant account identity, controls and result legible.
  Exclude passwords, tokens, app secrets and unrelated customer data.
- [ ] Verify the completed recording can be opened and follows the current
  dashboard's file, duration and format requirements; these limits were not
  verified by this source audit.
- [ ] Supply working reviewer access and exact test instructions through the
  appropriate review fields, never through source control or this document.
- [ ] Review the description and agreement with an authorized operator before
  attesting or submitting. A draft description is not an agreement acceptance.

## Unresolved items before submission

### Own-post retrieval evidence

The [official Permissions Reference](https://developers.facebook.com/documentation/development/permissions),
under `threads_basic` → **Screencast Requirements**, states:

> Retrieve a list of the user's own Threads posts and demonstrate how to view post details such as text, images, and videos

This checkout's existing Marketing list contains records created in BeShare.
The inspected Threads code reads account identity and a known post's
owner/permalink; it does **not** implement a general remote Threads-post
list/import or read the post's text, images and videos back from Threads.

The current functionality can be demonstrated faithfully with BeShare-published
own-post records and individual provider verification. Whether this meets the
quoted review requirement is **not established by this audit**. Assess that
evidence against the current review form or reviewer feedback before submission.
Keep the rationale limited to real functionality; do not describe the local
list as a native Threads content synchronization feature.

### Information that is not verified

- Reviewer sign-in, credentials, account entitlement and repeatable access to
  all demonstrated screens.
- Business legal details and authorized representative. The portfolio's
  **Verified** status was observed; it does not independently establish the
  accuracy of every legal or data-handling answer in the submission.
- Actual data-controller and processor identities, subprocessors, data sharing,
  retention periods, backup deletion, access-review procedures and incident
  response. Source code and a published privacy page do not prove these answers.
- Completeness and accuracy of privacy/deletion disclosures against operating
  practice. Existing source pages are `marketing/privasi.html` and
  `marketing/padam-data.html`; their presence alone is not an attestation.
- Readiness or necessity of the other permissions in the nine-permission draft.
  This document covers only the two Threads permissions; do not submit unrelated
  permissions based on this audit.
- Dato' Anwar's exact Threads error text, account handle and tester status.
- Production deployment and live validation of the new publication-receipt and
  customer-access messaging changes in this checkout.

`MARKETING_THREADS_CUSTOMER_ACCESS=approved`, if used by the UI rollout, is an
operator declaration for the interface. It neither requests nor grants Meta
approval. Set it only after the relevant real approval is confirmed.

## Official references checked on 19 November 2026

1. [Threads API — Get started](https://developers.facebook.com/documentation/threads/get-started):
   required permissions, non-role approval plus publication, and tester
   invitation/acceptance.
2. [Meta Permissions Reference](https://developers.facebook.com/documentation/development/permissions):
   `threads_basic` and `threads_content_publish` allowed usage and screencast
   requirements; Business Verification is listed for Advanced Access requests.
3. [App Review overview](https://developers.facebook.com/documentation/resp-plat-initiatives/individual-processes/app-review):
   non-role access and the requirement that reviewers can access and test the app.
4. [App Review tutorial](https://developers.facebook.com/docs/app-review/submission-guide/):
   permission-specific recordings, recent successful API usage and accessible
   review instructions. Confirm any changing requirements in the live dashboard.
5. [Access Verification](https://developers.facebook.com/documentation/development/release/access-verification):
   the current page explicitly says **Access Verification is no longer required**
   and links the 9 October 2026 retirement announcement. The older Tech Providers
   page still describes the legacy gate; do not impose that obsolete separate
   process. This retirement does not replace App Review or permission approval.
6. [Meta sample repository — OAuth errors returning JSON](https://github.com/fbsamples/threads_api/issues/24):
   historical provider-side evidence that some Threads failures can remain on a
   raw JSON page instead of reaching the application callback. It is not proof
   of a particular customer's present error.
