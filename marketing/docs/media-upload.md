# Direct media uploads

The content editor accepts one local image or video per post. Choosing or dropping
a file uploads it to account-owned storage and shows a preview. The author must
still save the draft or approve its schedule. Uploading does not publish a post.
The existing external HTTPS media URL option remains available.

## Application limits

| Input | Limit | Processing |
| --- | --- | --- |
| JPEG, PNG | 10 MiB; 8,192 pixels per side; 32 megapixels | Decode, correct EXIF orientation, remove metadata, flatten transparency on white, and encode JPEG at at most 8,000,000 bytes |
| MP4, MOV | 100 MiB; 3–300 seconds | Validate the container, H.264/HEVC video and optional AAC audio; preserve the video bytes |

There is no automatic video transcoding. An upload that is valid as a draft can
still require re-export before scheduling on a particular channel. Managed-media
checks at scheduling reject unsupported Instagram image proportions and
Instagram/Threads video dimensions, frame rates and container layout with an
actionable message. External URLs retain their existing provider-side checks.

Each account can hold 512 MiB / 200 ready or pending files, with two concurrent
uploads. The service also bounds global transfer and image processing work.
Successful uploads that are never attached to a saved post expire after 24 hours.
Saved post attachments do not expire automatically. Removing an attachment from
an editor updates its post reference only when the author saves the post.

## API and storage

Authenticated requests use the existing account session and allowed origins:

- `PUT /api/akaun/copilot/marketing/media/{32-character client ID}?name=...`
  receives raw bytes with the exact media Content-Type and Content-Length.
- `GET` on the same path reconciles an interrupted response before retrying.
- `DELETE` cancels an upload or removes an unreferenced attachment. It cannot
  remove media that a saved post still references.
- Posts store an optional owner-checked `media_id` and the canonical media URL.

Provider-fetchable URLs contain a random 256-bit token and a validated extension,
with no original filename or account identifier. Public GET/HEAD and single-byte
range requests require no account credential. There is no public file listing.
The media response has a fixed validated MIME type and nosniff/referrer headers.

Media is therefore accessible to anyone who possesses its opaque URL; these are
social publishing attachments, not a private document vault. The editor never
places the user's account token in a media URL. Provider workers receive the
same `media_url` shape used before this change.

The `marketing_media` and `marketing_media_links` tables are additive. Account
locks serialize reservation, finalization, cancellation and post attachment.
Uploads use private temporary files and become available only after validation
and the ready-row commit. Retry IDs compare the original file digest. Cancelled
IDs retain a temporary tombstone so a late upload cannot revive them. A bounded
cleanup loop runs in the account process every 30 minutes.

## Deployment

Use `marketing/Dockerfile.media` with the verified currently deployed account
image. It adds Pillow from `requirements-media.txt`, ffprobe from the maintained
Debian ffmpeg package, and the three changed account modules. The runtime remains
`nobody`; no credential files are copied into the image.

The account service needs these non-secret settings:

```text
MARKETING_MEDIA_ENABLED=1
MARKETING_MEDIA_ROOT=/var/lib/beshare-marketing-media
MARKETING_MEDIA_PUBLIC_BASE_URL=https://akaun.beshareaisolution.com
```

Mount persistent storage at `MARKETING_MEDIA_ROOT`, owned by the runtime user.
Back up that directory together with the account database. The public base URL
must route to the account service, and the reverse proxy must allow the accepted
upload size. The existing account proxy forwards this path without changes.
Do not mount this data over application code. The marketing worker, advice
service, their credentials and the publishing flag need no changes.

`marketing/bin/deploy-media.py` prepares a release from an exact reviewed Git
commit and tree, backs up the account database and configuration, builds and
probes the candidate, and replaces only the account service. It refuses a changed
baseline or unexpected configuration and rolls the service back on failure.
Its private status file contains progress without credentials. Rollback restores
the account image/configuration; retain the media directory and additive tables
for investigation rather than deleting uploaded data.

## Validation

The upload tests cover owner/session isolation, rejected file types and lengths,
image normalization, real video probing, quotas, interrupted transfers, replay,
cancel/finalize races, managed URL ownership, public HEAD/ranges and referenced
retention. Frontend tests cover chooser/drop validation, progress, preview,
reconciliation, cancelled/replaced sessions and editors, failed replacement,
save/schedule gating, and external URL compatibility.
