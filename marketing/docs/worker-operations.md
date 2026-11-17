# Marketing worker: disabled deployment and operational checks

This source template adds one separately supervised worker to the observed
`copilot-production` project. Merge its new service into the existing production
override so the normal Hostinger deployment sees it; do not leave it as a third
runtime overlay. It reuses the account image and existing `data` and `edge`
networks. It does not replace the account service or publish HTTP ports. Initial
publishing is fixed to `0`.

## Verified starting point

- Project directory: `/opt/beshare-copilot-production`.
- Existing file order: `docker-compose.yaml`, then `docker-compose.override.yaml`.
- Current image reference: `copilot-account:marketing-4f86a43`.
- Recorded immutable image ID:
  `sha256:1e9078a9b7b4a4fe5d9ebca720615f8b41e586a8bf7eb407be5682f9b5198d0d`.
- Runtime: `nobody`, working directory `/app`.
- Current HTTP entrypoint launches `/app/entrypoint.sh`, cleans unrelated
  credentials, runs its environment check and starts `akaun_pelayan.py`.

Reconfirm these observations before executing commands. A newer deployment
requires reviewing its image identity; do not silently use the old tag. Check
that `marketing-worker` is not already an existing service before adding it.

The template overrides the HTTP entrypoint. Its short Python launcher narrows
the environment and uses `execve` to run the unchanged
`/app/marketing_worker.py`. Secrets remain environment values, not command-line
arguments. The existing worker's startup runs `pelanggan_pangkalan.buka()` and
the additive schema before entering its loop, including when publishing is
disabled. This checks the dedicated database name, role and restricted rights;
it does not use `DATABASE_URL` as a fallback.

## Prepare the narrow private environment

Create `/opt/beshare-copilot-production/marketing-worker.env` using the existing
private secret-management process, with owner-only permissions. Do not copy
the full `.env`, `copilot-openai.env` or account environment file into it.

The required initial values are the existing account database's exact
`ACCOUNT_DATABASE_URL`, `ACCOUNT_DATABASE_NAME` and `ACCOUNT_DATABASE_ROLE`.
Retain `IPO_DATABASE_NAME` if the existing guard configuration supplies it.
Copy only the relevant `MARKETING_*` values listed in
[social-setup.md](social-setup.md) when their app configuration is ready. The
worker and account service must share the same `MARKETING_TOKEN_KEY`; do not
generate a second key for the worker.

No `AKAUN_SECRET`, OpenAI, Stripe or Telegram credential is required by this
worker. The launcher also drops those settings if inherited from image defaults.
Preserve values exactly using the deployment's env-file escaping conventions;
do not print resolved environment values to verify them. Do not commit the
private file. Its publishing flag cannot override the template's initial `0`.

If a private `marketing.env` is used for shared provider settings, keep only the
database guard values in `marketing-worker.env` and add the shared file as the
second worker `env_file` entry. Share that same provider file/key with the account
service through its reviewed configuration. The shared file should contain only
the relevant Marketing settings, not the broad account/OpenAI environment.

## Inspect and start only the new worker

First confirm that the reviewed local tag still resolves to the recorded image:

```sh
docker image inspect --format '{{.Id}}' copilot-account:marketing-4f86a43
```

Require the image ID to match the recorded, reviewed deployment. Back up
`/opt/beshare-copilot-production/docker-compose.override.yaml` privately, then
merge **only** `services.marketing-worker` from
`marketing/deploy/worker.compose.yaml` into it. Refuse a conflicting existing
worker definition; preserve all current services, settings and override order.
Resolve the template's image and env-file placeholders to the verified image
reference and absolute private file path(s) during this merge. Do not depend on
temporary exported shell variables that a later Hostinger deployment will lack.
Review the semantic difference before atomically replacing the override; never
replace the full override with this small source fragment.

Confirm the observed Compose project still supplies the `data`/`edge` networks
and no later file changes the initial flag, ports, runtime or environment.
Inspect resolved configuration privately; never print the full `config` output
because it can contain credentials. A safe service-name check, using only the
two observed production files, is:

```sh
docker compose -p copilot-production \
  -f /opt/beshare-copilot-production/docker-compose.yaml \
  -f /opt/beshare-copilot-production/docker-compose.override.yaml config --services
```

Start only the new worker, using the same two-file order:

```sh
docker compose -p copilot-production \
  -f /opt/beshare-copilot-production/docker-compose.yaml \
  -f /opt/beshare-copilot-production/docker-compose.override.yaml \
  up -d --no-deps --no-build --pull never marketing-worker
```

There is no account-service recreation, whole-project `down`, new database or
volume operation in this step. The template has no provider activation command.

## Prove the disabled process is operating

Use the same project and two files for these service-scoped checks:

```sh
docker compose -p copilot-production \
  -f /opt/beshare-copilot-production/docker-compose.yaml \
  -f /opt/beshare-copilot-production/docker-compose.override.yaml ps marketing-worker
```

```sh
docker compose -p copilot-production \
  -f /opt/beshare-copilot-production/docker-compose.yaml \
  -f /opt/beshare-copilot-production/docker-compose.override.yaml logs --no-color --timestamps --tail 8 marketing-worker
```

Require a stable running process, no rising restart count, and fresh JSON ticks
with `enabled:false`, `processed:0` and publication counts at zero. Initial
database/configuration failures prevent reaching this loop. The worker must not
run an HTTP listener or publish a host port. Confirm the account service's
container/image and existing HTTP checks remain unchanged.

An inherited HTTP healthcheck is explicitly disabled. This avoids a false
failure; it does **not** create a positive worker-health assertion.

## Monitoring when publishing is later activated

Current API capabilities deliberately report `worker_health:'not_checked'`.
`publishing_enabled` and `worker_configured` only describe configuration. A
running container can remain in a caught database-error loop without restarting,
so process state alone does not prove useful work.

Operational supervision should cover:

- **Tick progress:** while idle, successful ticks normally follow the configured
  15-second pause. Busy ticks take longer because each batch can include ten
  jobs and provider requests have 20-second timeouts; do not use 15 seconds as a
  rigid failure threshold. Repeated `ok:false` ticks require investigation.
- **Queue progress:** read only aggregate due-job counts and oldest due time,
  then compare successive observations. A growing due queue with no attempt
  progress indicates a worker/configuration problem even if the process runs.
- **Expired leases:** inspect active `publishing` attempts whose lease has
  expired. Leases are 120 seconds. The existing worker recovers a request that
  might already have reached a provider as `uncertain`; it does not blindly
  publish it again.
- **Uncertain/failed outcomes:** alert the operator and check the actual platform
  when required. Do not reset `uncertain` records to queued.

Use the guarded account connection for these aggregate queries, without printing
tokens, customer content or raw provider responses. Relevant read-only queries
are status counts in `marketing_posts`, due count/oldest `scheduled_at`, and
active attempts joined by `marketing_posts.attempt_id` to
`marketing_publish_attempts.id`. Restrict lease inspection to current
`publishing` posts so completed attempts are not mistaken for stalled jobs.

While the flag is disabled, ticks return before opening a new database connection;
they prove loop liveness after the startup guard, not continuous database health.
There is currently no persisted heartbeat or automated liveness alarm. For the
controlled activation, use process/tick/queue checks and verify one authorised
job's provider result. Persistent monitoring is needed before treating unattended
customer publishing as operationally assured.

Any later enabling of the flag belongs to the reviewed activation configuration
for both account and worker, after app, connection and controlled-content gates.
This disabled template is not that activation override.

## Stop or roll back the worker only

For this disabled rollout, no jobs have been admitted or claimed by the worker.
Use the same two files and project:

```sh
docker compose -p copilot-production \
  -f /opt/beshare-copilot-production/docker-compose.yaml \
  -f /opt/beshare-copilot-production/docker-compose.override.yaml stop marketing-worker
```

```sh
docker compose -p copilot-production \
  -f /opt/beshare-copilot-production/docker-compose.yaml \
  -f /opt/beshare-copilot-production/docker-compose.override.yaml rm -f marketing-worker
```

Keep the shared account service, database, tables and encryption key intact.
Do not add `--volumes` or remove unrelated services. Retain the private worker
configuration according to the existing secret-management policy.
Remove only the newly added `marketing-worker` service entry from the override
after reviewing its current contents, so a later normal deployment does not
recreate a deliberately removed worker. Do not restore an old whole override
over legitimate account/provider changes made since the worker was added.

After any future activation, first disable new admissions and inspect in-flight
attempts. The current worker has no graceful drain endpoint: stopping its process
cannot undo an external request already accepted. Preserve uncertain attempts
and their provider evidence rather than replaying them during rollback.
