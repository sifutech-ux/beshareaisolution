# Business knowledge centre

The **Pengetahuan** screen lets an active business owner prepare business facts,
public-page extracts, original product/combo images, and payment details. Saving a
draft preserves the current AI knowledge. Publication creates the approved snapshot
used by subsequent eligible replies; it does not turn on AI or replay old messages.

## Owner workflow

1. Open **Pengetahuan** in the intended business workspace. Existing approved text
   seeds the first draft. Drafts and payment fields remain in browser memory until
   **Simpan draf** succeeds; closing the page discards unsaved edits.
2. Enter facts directly or add a website, landing-page, advertisement, or Facebook
   Page URL. **Baca pautan** imports the readable page text into the editable draft.
   Review the extracted title and text, remove unrelated material, and choose which
   sources to include. If the page redirects, use its final public URL. For login
   walls, blocked pages, or JavaScript-only content, paste the relevant text manually.
3. Add a product, combo, or reference-image record. Upload or select an original
   image, add its title and product facts, and describe when it should be sent.
   A saved customer-facing caption is used verbatim; otherwise the AI supplies the
   caption. The system does not infer product facts from image pixels.
4. If payment information should be offered, fill in the approved bank, account
   holder, account number, instructions, and optional original QR image. Check the
   displayed details and scan the QR yourself before approving it. The system does
   not verify a QR recipient or confirm that payment was received.
5. **Simpan draf**, then **Semak & terbitkan**. Review the included facts, images, and
   payment preview. Publication requires the content confirmation and, when payment
   information is enabled, a separate payment confirmation. Any edit closes review
   and requires another save. A conflicting server revision must be reloaded after
   preserving any local edits that are still needed.
6. Configure **Ujian & kawalan AI** separately. Select the business connection, list
   the permitted test senders, set the daily attempt limit, and enable the existing
   pilot when its server prerequisites are ready. Send a new message from a permitted
   test number and inspect the inbox result. Taking over the conversation stops AI.

### First publication

For a workspace with no `ai_reply_settings` row, select its own WhatsApp connection
under **Ujian & kawalan AI** before publishing. The server checks that this selected
connection belongs to the current business and is not disconnected. It creates the
settings row and the publication in one transaction, with AI disabled, an empty test
allowlist, a daily limit of 20, zero quota use, and no activation time. Connection
readiness and server pilot eligibility are checked separately when enabling replies.

For an existing workspace, publication preserves the selected connection, enabled
state, test senders, activation time, daily limit, and consumed quota. It increments
the knowledge revision so an in-flight answer based on an older version cannot be
sent. The old AI controls cannot overwrite the published knowledge text.

Knowledge requests (`knowsave`, `knowpublish`, `knowimport`) and multipart uploads
must include `expected_business_public_id` from the editor's workspace. The server
compares it with fresh authenticated membership before performing the action. A
missing or changed scope returns HTTP 409, preventing an old browser tab from saving
its content into a different business selected in another tab. This field is a
conflict guard; authorization still comes from the session and current membership.

## Pilot limits and reply behavior

| Area | Current application limit or behavior |
| --- | --- |
| Page import | One owner-requested public HTTPS page, port 443; no redirects, login credentials, cookies, JavaScript, or linked-resource crawling |
| Fetch bounds | 512 KiB response body, 16 KiB headers, 10-second cURL timeout; private/reserved addresses rejected and the validated DNS address pinned |
| Sources | Up to 8 records; each URL 2,048 UTF-8 bytes, title 160 bytes, and imported/editable text 8,000 bytes |
| Draft | At most 60,000 UTF-8 JSON bytes; disabled records can remain in the draft |
| Published business text | At most 8,000 UTF-8 bytes in total, including the labels, titles, and URLs added while compiling enabled records |
| Image files | JPEG or PNG only, up to 2 MiB each; maximum dimension 8,192 pixels and maximum area 16,777,216 pixels |
| Image library | Up to 40 uploads / 80 MiB per business; up to 20 product/combo/reference records in a draft |
| Upload capacity | Removing a draft image record does **not** delete its upload or reclaim capacity; there is no image-deletion workflow in this pilot |
| One reply | One text message **or** one original image with a caption; no multi-image sequence or additional text message |
| Captions | Saved caption field: 1,000 UTF-8 bytes; dispatched image caption: at most 1,024 Unicode characters |
| Payment choice | A selected bank/QR response is assembled from the approved structured fields on the server; a QR is sent as the original uploaded image |
| Unsupported cases | Unreadable sources use manual text entry; uncertain answers and unsupported inbound media follow the existing human-handoff behavior |

Bank fields are excluded from the image-selection catalogue sent to the model.
Ordinary text replies remain model-generated; deterministic payment rendering applies
when the structured bank/QR option is selected. No payment receipt, stock reservation,
or order confirmation is performed by this feature.

Only published, enabled image IDs are eligible for selection. The worker resolves an
ID to that business's private media and rechecks approval, knowledge revision, human
takeover, and connection state before sending. An upload is followed by at most one
WhatsApp message request. An uncertain message result is never automatically resent.

## Additive deployment

The application uses PHP 8.3 with PDO MySQL, OpenSSL, cURL, DOM, and Fileinfo.
CI integration fixtures run on MySQL 8.4. Knowledge-centre shared reads use
`LOCK IN SHARE MODE` for compatibility with both MySQL and MariaDB; verify the
target database version during release preflight. Preserve the existing private
configuration and encryption key. Ensure
the PHP upload/request limits allow a 2 MiB file plus multipart overhead. There is no
new provider credential or Meta account-registration step for this feature.

Migration `os/migrations/0005_knowledge_centre.up.sql` adds only `knowledge_centres`
and `knowledge_media`, then records version `0005` in `schema_migrations`. Existing
0001–0004 migrations are not replayed. Drafts, published documents, and original
image bytes are encrypted; media previews require current workspace membership.

Run from the application checkout after the normal database backup and release
review. The first command is read-only:

```sh
php os/bin/prepare-knowledge-centre.php
```

Review `status`, `checksum`, and `tables`. A `ready` result requires the existing
foundation and the matching 0004 AI schema. Apply using the exact reviewed 64-character
hexadecimal checksum, then inspect again:

```sh
php os/bin/prepare-knowledge-centre.php --apply --checksum=REPLACE_WITH_REVIEWED_64_HEX_CHECKSUM
php os/bin/prepare-knowledge-centre.php
```

The expected final status is `installed`. An identical repeated apply is idempotent.
The installer uses an advisory lock and rejects mismatching checksums or partial
installations. MySQL/MariaDB DDL commits implicitly: a failure does not automatically undo a
created table, and the installer never deletes tables to recover. Inspect a partial
installation against the reviewed migration before any manual repair.

### Rollback

Stop AI and its scheduled reply worker, allow any bounded in-flight operation to
finish, and restore the previous coordinated application/worker release. Preserve
the additive tables, their data, and the 0005 ledger record. There is no down/drop
command. Do not mix old legacy knowledge writers with the image-aware worker.
Previously published compiled text remains in `ai_reply_settings`; code rollback
does not restore an older text revision. Reapprove the intended text and verify
compatibility before reenabling replies. Any data restoration is a separate,
reviewed operation using the normal backup procedure.

## Verification

The following checks use synthetic data and make no provider or WhatsApp calls:

```sh
php os/tests/check-knowledge-source.php
php os/tests/check-knowledge-replies.php
node os/bin/check-knowledge-ui.mjs
```

The database integration check covers tenant authorization, encrypted drafts/media,
publication, preserved AI controls, and worker behavior. It explicitly creates and
drops only the disposable `beshare_knowledge_test` database on loopback. Supply a
disposable MySQL service and synthetic credentials through `BESHARE_AI_TEST_USER`
and `BESHARE_AI_TEST_PASSWORD`; never point it at an application database:

```sh
BESHARE_KNOWLEDGE_TEST_DSN='mysql:host=127.0.0.1;port=33306;dbname=beshare_knowledge_test' php os/tests/check-knowledge-centre.php
```

The `owner-ai-replies.yml` workflow runs these checks alongside the existing transport,
AI settings, and durable worker tests. Passing these checks does not imply deployment,
schema installation on a live host, or a successful real WhatsApp image delivery.
