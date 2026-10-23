# Reproduction and evidence

Run `php os/tests/mvp/check.php` for an entirely local disposable synthetic SQLite test (45 checks with pcntl; 39 without). The six additional checks exercise two competing processes and a process exit after durable usage reservation. A skipped process suite is not a concurrency pass. The pinned PHP image currently runs 39; host PHP runs 45.

Build from repository root with `docker build --network none -f deploy/whatsapp-standby/Dockerfile -t beshare-mvp-synthetic:review .`. Run only on a dedicated local Docker engine, network none, read-only, non-root, no capabilities, bounded memory/PIDs and writable /tmp tmpfs. No provider keys are used. `evidence/runtime-image.txt` is a local image content ID, not a published registry digest.

MySQL lab requires the existing dedicated Colima profile and PHP tooling image documented in `../mysql84/README.md`. `ledger-lab.py` creates new exact-named resources and refuses reuse. Change its `beshare-forward-20261023-` prefix and the matching prefix in `recovery.py` for a repeat run, retaining previous volumes. Run ledger-lab.py, then recovery.py. `ledger-negative.php` is run on the reference fixture (copy the manifest into `/lab/manifest.json` first): completed-but-unrecorded DDL must never be adopted. No external DSN is accepted. The reference fixture deliberately executes DDL without writing ledger 0002/0003; the independent apply fixture writes genuine success rows through ForwardLedger.

After tests stop every exact prefixed container, retain volumes, and stop only the dedicated Colima profile. No prune or volume removal. Recovery adds a synthetic Unicode user, performs same-volume restart, encrypts/decrypts with disposable container-local keys, stops the source and restores to a new volume. That key placement is a test fixture, not the proposed off-VPS custody arrangement. Prior evidence is not rewritten.

CI workflow runs mock/static checks and confirms that standby remains blocked. Live MySQL/restore evidence is local arm64, not GitHub CI or target VPS evidence. Existing 400-check application evidence remains inherited from #65; this PR does not claim to have rerun those suites.
