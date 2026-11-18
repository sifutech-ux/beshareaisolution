# Marketing v1: account release and rollback

This checklist prepares the existing account service for Marketing. It does not
choose a container, Compose project, database, proxy, image or supervisor. Obtain
those values from the running deployment before substituting the placeholders.
The release keeps automatic publishing disabled and can provide campaign
planning, saved posts, reference notes and manually reported results without
social app credentials.

## 1. Record the actual deployment

- Identify the **account** container from the current Compose project and its
  observed service labels. Do not select an investment, WhatsApp or database
  container because its name looks similar.
- Record the exact current image ID, the named image reference, the Compose file
  order, project directory/name, service name and active override. Keep the old
  immutable image locally for rollback.
- Inspect the resolved account service privately. Preserve its environment,
  secrets, runtime user, restart policy, network, published port and existing
  persistent volumes. Do not print an unfiltered `docker inspect` or resolved
  Compose configuration into a transcript: both can include credentials.
- Check whether a bind mount replaces `/app` or any released Python file. Image
  replacement alone does not update code hidden behind such a mount.
- Confirm the existing account backup procedure and take a fresh backup. Keep its
  timestamp and restore instructions. If social credentials already exist,
  retain the corresponding `MARKETING_TOKEN_KEY` in the secret manager.

## 2. Run the bounded image preflight

From this repository checkout on the Docker host:

```sh
python3 marketing/bin/release-preflight.py --container ACCOUNT_CONTAINER
```

`ACCOUNT_CONTAINER` is the explicit name or ID observed in step 1. The helper
only reads selected Docker metadata and runs the current image by its immutable
ID in a disposable, network-disabled, read-only container. It checks the three
baseline hashes used by `marketing/Dockerfile`, imports `cryptography` and
`psycopg2`, and performs an in-memory Fernet encryption/decryption check. It does
not mount host volumes, pass deployment secrets, publish ports, contact a
database/provider, or modify the live account container.

Each probe receives a unique task-owned container name. A bounded cleanup runs
for that exact name even when the Docker client times out. If cleanup cannot be
verified, the helper fails and reports only that probe name for inspection; it
never deletes containers by prefix or chooses a live service for cleanup.

An image gate passes only when those checks pass, the selected account container
is running without restarting and no inspected mount hides a release code path.
It is **not** a database, HTTP, OAuth or worker health check. A health status, if
present, is the existing container's healthcheck result; no healthcheck output
is read.

If a baseline hash differs, compare the observed deployed source with this
release and resolve the version difference. Do not remove the guard or overwrite
a newer production file merely to make the build pass. If `cryptography` is
missing, prepare an explicitly reviewed dependency layer on the same verified
base; this release does not install packages into a running container. A missing
`psycopg2` import also blocks use of that image for the PostgreSQL account service.

### Explicit dependency layer when the live image lacks encryption

If the verified live image lacks `cryptography`, keep that running image
unchanged. Build the reviewed, pinned dependency layer on a local release tag
verified against its recorded immutable image ID, using the checked-in
encryption requirements:

```sh
docker build --pull=false -f marketing/Dockerfile.dependencies --build-arg ACCOUNT_BASE_IMAGE=VERIFIED_LIVE_REFERENCE -t DEPENDENCY_IMAGE .
python3 marketing/bin/release-preflight.py --container ACCOUNT_CONTAINER --dependency-image DEPENDENCY_IMAGE
```

Choose the dependency tag through the release convention, for example
`copilot-account:marketing-deps-RELEASE_SHA`. The supplied wheel lock targets the
observed CPython 3.12, Linux x86_64, glibc 2.34+ runtime; the Dockerfile restores
the observed `nobody` user. Review those choices again for a different runtime.
The explicit option keeps the live
baseline hash and PostgreSQL-import requirements, requires its container to be
healthy with no code-shadowing mounts, and checks encryption in the staged image.
The staged image must retain the exact live image's RootFS layers as a prefix,
add at least one layer, preserve its User/WorkingDir/Entrypoint/Cmd, retain the
baseline hashes, and pass both imports and the Fernet check. Image command
arguments are compared privately and are not printed. The default command above
continues to reject a live image missing encryption; there is no automatic
fallback to a different image.

Record the verified dependency image's immutable ID and its local release tag
for the final Marketing build. Verify that the tag still resolves to that ID
before building. The candidate check must keep the same explicit dependency
selection:

```sh
docker build --pull=false -f marketing/Dockerfile --build-arg ACCOUNT_BASE_IMAGE=VERIFIED_DEPENDENCY_REFERENCE -t CANDIDATE_IMAGE .
python3 marketing/bin/release-preflight.py --container ACCOUNT_CONTAINER --dependency-image DEPENDENCY_IMAGE --candidate-image CANDIDATE_IMAGE
```

The candidate must add layers on that exact dependency image, preserve its
runtime configuration and match the release source hashes. A candidate built
from another base is rejected even if its individual application hashes match.

## 3. Build and verify the candidate

Use `marketing/Dockerfile` from the repository root. The old
`copilot/account/Dockerfile` recipe is not suitable for the current Marketing
router: it does not copy the new Marketing dependencies.

```sh
docker build -f marketing/Dockerfile --build-arg ACCOUNT_BASE_IMAGE=VERIFIED_BASE_REFERENCE -t CANDIDATE_IMAGE .
python3 marketing/bin/release-preflight.py --container ACCOUNT_CONTAINER --candidate-image CANDIDATE_IMAGE
```

Resolve `VERIFIED_BASE_REFERENCE` to the immutable base established above; keep
the mapping recorded. The current baseline is the deployed `1e980edf` Marketing
account layer. Its HTTP server hash was intentionally updated from the
`4f86a43` baseline to include the provider lifecycle routes; the account router
and account/authentication baseline hashes are unchanged. Choose `CANDIDATE_IMAGE` through the existing release naming
convention. The second probe requires the candidate to descend from the exact
live image (or the explicitly verified dependency image) and preserve its
runtime. It checks the unchanged account/authentication file plus the seven
released Python files against this checkout, including the HTTP server and
provider lifecycle handler. It does not pull images or run the candidate's
normal entrypoint.

Confirm the account process uses the expected Python runtime and `/app` layout.
The helper invokes `python`, matching the release Dockerfile dependency check;
an unusual production entrypoint or interpreter still needs manual verification.
Keep the release's account and browser tests green before proceeding.

## 4. Replace only the account service

- Set `MARKETING_PUBLISH_ENABLED=0` explicitly in the account service's existing
  configuration. If a worker is already present, disable its publishing flag as
  well. Do not rely on an assumed environment default.
- Preserve the existing `ACCOUNT_DATABASE_URL`, `ACCOUNT_DATABASE_NAME` and
  `ACCOUNT_DATABASE_ROLE`; the existing identity guard must still pass. The
  release does not use `DATABASE_URL` as a fallback.
- Update only the observed account service's image through its established
  Compose override/release mechanism. Use that deployment's service-scoped
  recreation command. Do not run a whole-project `down`, rebuild unrelated
  services, change proxy ports, replace data volumes or touch investment data.
- Account schema initialization creates additive Marketing tables and indexes.
  It does not rewrite existing business records. Confirm the account service
  starts and its existing healthcheck remains healthy.

## 5. Verify before releasing the static frontend

Use a controlled account and the real account endpoint. Keep bearer tokens in
the existing authenticated session or a private secret input; do not put them
in URLs, logs or exported reports.

- A signed-out Marketing workspace request returns 401.
- The controlled account can save and reload a campaign, draft post, reference
  and daily result. Amounts remain exact decimal strings after reload.
- Repeating an identical save keeps one record/version; a conflicting stale
  edit returns 409 and preserves the newer record.
- A second controlled account cannot read or reference the first account's
  records. Foreign identifiers return 404 without record contents.
- Workspace capabilities report publishing disabled. An approved scheduling
  attempt is rejected while ordinary draft/planned saves continue to work.
- Existing sign-in, Copilot saved work/reviews and the established billing path
  continue to work. Use established read-only or sandbox billing verification;
  a deployment check does not require charging a customer.

The reviewed helper can perform this bounded HTTP check. Its default mode only
prints the plan; explicit execution runs inside the newly verified account
container while the host streams the checked-out script:

```sh
python3 marketing/bin/live-smoke.py
docker exec -i ACCOUNT_CONTAINER python - --run < marketing/bin/live-smoke.py
```

It creates two generated `@example.invalid` accounts through the normal API,
keeps passwords/tokens in memory, and removes only the exact synthetic account
and record IDs after proving their identity. Cleanup is verified through a new
guarded database connection. It performs billing GET checks, with no billing
writes, AI calls, OAuth or publishing. If cleanup cannot be verified, retain the
reported private recovery manifest and investigate those exact records; do not
replace this with a broad account-deletion command. A timed-out or otherwise
ambiguous HTTP write leaves cleanup pending and retains its identifiers, since
the server may still finish the request after the client has stopped waiting.

Release the static files through the repository's established deployment only
after the API checks pass. Confirm `/marketing/`, login return through
`/akaun/?selepas=marketing`, desktop/phone layouts and a browser reload retaining
saved account data. The OAuth return page must be served at
`/marketing/connect.html`; connection activation is a separate gate below.

## 6. Activate social publishing separately

Follow [social-setup.md](social-setup.md) for approved app credentials, the exact
OAuth return URI and a controlled account. Both the account service and worker
must use the same account database and encryption key.

The release image contains `/app/marketing_worker.py`. Inspect the observed
image entrypoint and the existing supervisor/Compose configuration before
defining a separate worker service. The worker needs the account environment
and provider egress; it does not need the HTTP service's published port or HTTP
healthcheck. Preserve the established runtime user and database restrictions.

With publishing still disabled, a supervised/one-shot worker invocation can
verify startup and database guard access without making provider requests:

```sh
python /app/marketing_worker.py --once
```

That command is **inside the verified release image**, not a host command using
the host's Python. It prints `enabled:false` when configured correctly. Its
startup can create the same additive schema as the account service. Do not
improvise a second background process inside the live HTTP container.

After worker supervision is verified, enable `MARKETING_PUBLISH_ENABLED=1` in
both explicitly selected services through their established configuration.
Use specifically authorised content and a controlled connected account for the
live publication check. Verify the selected Page/account, the scheduled job's
status transitions, the provider's actual post and the stored provider ID.
Inspect the worker's supervised process and safe logs, including restart state.
`worker_configured` and `publishing_enabled` are configuration flags;
`worker_health:'not_checked'` does not prove a worker is running.

The initial gate is passed only when one controlled due job is processed once
and its externally observed result matches the saved status. Further format
acceptance checks belong to the authorised publishing test plan. Meta/Google
ad spending, pixels and automatic competitor research remain outside v1.

## Rollback

1. Disable new publishing admissions in the account configuration. Prevent
   additional worker claims through the established supervisor/configuration.
   Inspect any attempts already in flight before terminating a process; an
   accepted external request cannot be undone by a local rollback.
2. Preserve the image IDs, relevant safe job statuses and the database backup.
   Keep `uncertain` attempts unchanged until the actual platform is checked.
   Do not manually reset them to queued or replay them as fresh requests.
3. Revert the static frontend through its established revision mechanism, then
   restore only the account service's prior image/override. Use the recorded
   immutable image instead of a tag that may have changed.
4. Stop/remove only a worker service introduced by this release, through the
   same supervisor. Leave unrelated services and volumes intact.
5. Preserve additive Marketing tables and the encryption key. Routine code
   rollback does not require restoring an older whole database, which would
   discard later legitimate account activity.
6. Verify existing sign-in, Copilot and the account healthcheck again. Confirm no
   unexpected worker is still claiming jobs. Record any accepted/uncertain
   provider activity that needs manual reconciliation.
