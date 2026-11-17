#!/usr/bin/env python3
"""Private, opt-in production smoke check for BeShare account Marketing.

Default invocation prints a plan and performs no network/database operations.
Run only inside the reviewed account container:
    python /tmp/marketing-live-smoke.py --run

Only normal HTTPS registration/login and account API paths are exercised.
No AI, email, OAuth, ads, checkout, portal, webhook or publisher call is made.
Passwords and bearer tokens remain in memory. No raw response/exception is
printed. A private recovery journal contains synthetic identifiers only.
Cleanup never uses a prefix, pattern, age range or unrestricted DELETE.
"""
import argparse
from datetime import datetime, timedelta, timezone
import importlib
import json
import os
from pathlib import Path
import re
import secrets
import signal
import ssl
import sys
import time
import urllib.error
import urllib.request

ORIGIN = "https://beshareaisolution.com"
BASE = "https://akaun.beshareaisolution.com"
PREFIX = "/api/akaun/copilot/marketing"
HEX32 = re.compile(r"^[a-f0-9]{32}$")
RESOURCE_TABLES = {
    "campaigns": ("marketing_campaigns", "campaign"),
    "posts": ("marketing_posts", "post"),
    "sources": ("marketing_sources", "source"),
    "results": ("marketing_results", "result"),
}
# These tables are checked only for the two exact synthetic account IDs.
# Any unexpected row causes a rollback/refusal, never a broad cleanup.
UNTOUCHED_OWNER_TABLES = (
    ("copilot_work", "akaun_id"),
    ("copilot_review", "akaun_id"),
    ("copilot_memory", "akaun_id"),
    ("copilot_action_receipt", "akaun_id"),
    ("copilot_voice_usage", "akaun_id"),
    ("pelanggan_pelan", "akaun_id"),
    ("pelanggan_tugas", "akaun_id"),
    ("pelanggan_angka", "akaun_id"),
    ("pelanggan_import", "akaun_id"),
    ("copilot_usage_counter", "scope"),
    ("copilot_usage_reservation", "owner_scope"),
    ("copilot_billing", "owner"),
    ("marketing_connections", "akaun_id"),
    ("marketing_oauth_states", "akaun_id"),
    ("marketing_oauth_candidates", "akaun_id"),
    ("marketing_post_bindings", "akaun_id"),
    ("marketing_publish_attempts", "akaun_id"),
)


class SmokeFailure(Exception):
    def __init__(self, code, http_status=None):
        self.code = code if re.fullmatch(r"[a-z0-9_.-]{1,80}", code or "") else "internal_failure"
        self.http_status = http_status if isinstance(http_status, int) else None
        super().__init__(self.code)


def need(condition, code, http_status=None):
    if not condition:
        raise SmokeFailure(code, http_status)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        return None


def load_account_modules():
    # Known account image paths only; no environment/file-content dump.
    for candidate in (Path("/app"), Path("/app/account"), Path("/app/copilot/account")):
        if (candidate / "pelanggan_pangkalan.py").is_file():
            sys.path.insert(0, str(candidate))
            break
    try:
        database = importlib.import_module("pelanggan_pangkalan")
        auth = importlib.import_module("pelanggan_akaun")
        billing = importlib.import_module("pelanggan_bil")
        social = importlib.import_module("marketing_social")
    except Exception:
        raise SmokeFailure("account_modules_unavailable") from None
    return database.buka, auth._verify_password, billing, social


class Smoke:
    def __init__(self, opener, verify_password, billing, social):
        self.open_database = opener
        self.verify_password = verify_password
        self.billing = billing
        self.social = social
        self.started = time.time()
        self.run_id = secrets.token_hex(16)
        self.accounts = [
            {
                "email": "marketing-smoke-" + self.run_id + "-" + letter + "@example.invalid",
                "password": secrets.token_urlsafe(32),
                "id": None,
                "token": None,
                "attempted": False,
                "registration_rejected": False,
            }
            for letter in ("a", "b")
        ]
        self.ids = {resource: [secrets.token_hex(16)] for resource in RESOURCE_TABLES}
        self.ids["posts"].append(secrets.token_hex(16))  # denied foreign-campaign attempt
        self.ids["results"].append(secrets.token_hex(16))  # duplicate daily tuple attempt
        self.journal = Path("/tmp/marketing-live-smoke-" + self.run_id + ".json")
        self.journal_written = False
        self.checks = []
        self.requests = 0
        self.last_request = None
        self.pending_mutation = None
        self.cleanup_result = {"status": "not_needed", "accounts_removed": 0, "records_removed": 0}
        self.http = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            NoRedirect(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        )

    def journal_save(self):
        data = {
            "suite": "marketing-live-smoke",
            "started_utc": datetime.fromtimestamp(self.started, timezone.utc).isoformat(),
            "account_api": BASE,
            "accounts": [{"email": a["email"], "id": a["id"], "registration_attempted": a["attempted"],
                          "registration_rejected": a["registration_rejected"]} for a in self.accounts],
            "record_ids": self.ids,
            "pending_mutation": self.pending_mutation,
            "credentials_included": False,
        }
        flags = os.O_WRONLY | os.O_CREAT | (os.O_TRUNC if self.journal_written else os.O_EXCL)
        if hasattr(os, "O_NOFOLLOW"):
            flags |= os.O_NOFOLLOW
        fd = os.open(str(self.journal), flags, 0o600)
        try:
            os.fchmod(fd, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8", closefd=False) as handle:
                json.dump(data, handle, separators=(",", ":"))
                handle.flush()
                os.fsync(handle.fileno())
        finally:
            os.close(fd)
        self.journal_written = True

    @staticmethod
    def allowed_request(method, path):
        exact = {
            ("POST", "/api/akaun/daftar"),
            ("POST", "/api/akaun/masuk"),
            ("GET", "/api/akaun/saya"),
            ("GET", "/api/akaun/bil"),
            ("GET", "/api/akaun/copilot/hasil/draf"),
            ("GET", "/api/akaun/copilot/hasil/semakan"),
            ("GET", PREFIX + "/workspace"),
        }
        if (method, path) in exact:
            return True
        resource = re.fullmatch(re.escape(PREFIX) + r"/(campaigns|posts|sources|results)/[a-f0-9]{32}", path)
        if resource and method in {"GET", "PUT"}:
            return True
        return method == "POST" and bool(re.fullmatch(re.escape(PREFIX) + r"/posts/[a-f0-9]{32}/schedule", path))

    def request(self, method, path, account=None, body=None, expected=200):
        need(self.allowed_request(method, path), "unapproved_http_path")
        headers = {
            "Origin": ORIGIN,
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": "BeShare-private-marketing-smoke/1.0",
        }
        if account is not None:
            need(isinstance(account.get("token"), str), "missing_test_session")
            headers["Authorization"] = "Bearer " + account["token"]
        raw = None if body is None else json.dumps(body, separators=(",", ":")).encode("utf-8")
        request = urllib.request.Request(BASE + path, data=raw, headers=headers, method=method)
        self.requests += 1
        self.last_request = method + " " + re.sub(r"/[a-f0-9]{32}(?=/|$)", "/{synthetic_id}", path)
        if method in {"POST", "PUT"}:
            need(self.pending_mutation is None, "previous_mutation_unresolved")
            self.pending_mutation = {"method": method, "path": path}
            self.journal_save()  # Commit identifiers only, before an HTTP write can leave this process.
        try:
            try:
                response = self.http.open(request, timeout=15)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                status = response.code
                cors = response.headers.get("Access-Control-Allow-Origin")
                declared_length = response.headers.get("Content-Length")
                content = response.read(500001)
        except (urllib.error.URLError, TimeoutError, OSError):
            raise SmokeFailure("http_network_or_tls_failure") from None
        need(len(content) <= 500000, "unexpected_large_response", status)
        if declared_length is not None:
            need(re.fullmatch(r"[0-9]+", declared_length) is not None
                 and int(declared_length) == len(content), "incomplete_http_response", status)
        # A fully received HTTP response, including a definite error, ends the
        # in-flight write. Network loss/interruption above deliberately leaves
        # the marker and journal intact: immediate DB absence proves nothing.
        if self.pending_mutation is not None:
            if method == "POST" and path == "/api/akaun/daftar" and isinstance(body, dict):
                for candidate in self.accounts:
                    if candidate["email"] == body.get("email"):
                        candidate["registration_rejected"] = 400 <= status < 500
            self.pending_mutation = None
            self.journal_save()
        need(cors == ORIGIN, "origin_cors_mismatch", status)
        try:
            data = json.loads(content)
        except (ValueError, UnicodeError):
            raise SmokeFailure("non_json_response", status) from None
        need(isinstance(data, dict), "invalid_json_shape", status)
        statuses = {expected} if isinstance(expected, int) else set(expected)
        need(status in statuses, "unexpected_http_status", status)
        if status == 200:
            need(data.get("ok") is True, "success_flag_missing", status)
        return status, data

    @staticmethod
    def no_private_fields(value):
        denied = {"password", "password_hash", "access_token", "refresh_token", "token_cipher", "client_secret", "attempt_id", "owner", "akaun_id"}
        if isinstance(value, dict):
            need(not (set(value) & denied), "private_field_in_resource_response")
            for item in value.values():
                Smoke.no_private_fields(item)
        elif isinstance(value, list):
            for item in value:
                Smoke.no_private_fields(item)

    def checkpoint(self, name):
        self.checks.append(name)

    def preflight(self):
        need(not self.social._enabled(), "publishing_must_be_disabled")
        connection = self.open_database()  # Existing guarded account DB identity.
        try:
            cursor = connection.cursor()
            for account in self.accounts:
                cursor.execute("SELECT id FROM pelanggan_akaun WHERE email=%s", (account["email"],))
                need(cursor.fetchone() is None, "synthetic_email_collision")
        finally:
            connection.close()
        # The normal route initializes the deployed schema, then rejects no token.
        self.request("GET", PREFIX + "/workspace", expected=401)
        connection = self.open_database()
        try:
            cursor = connection.cursor()
            tables = ["pelanggan_akaun"] + [x[0] for x in RESOURCE_TABLES.values()] + [x[0] for x in UNTOUCHED_OWNER_TABLES]
            for table in tables:
                cursor.execute("SELECT to_regclass(%s)", ("public." + table,))
                need(bool(cursor.fetchone()[0]), "required_table_missing")
            for resource, identifiers in self.ids.items():
                table = RESOURCE_TABLES[resource][0]
                for identifier in identifiers:
                    cursor.execute("SELECT id FROM " + table + " WHERE id=%s", (identifier,))
                    need(cursor.fetchone() is None, "synthetic_record_id_collision")
        finally:
            connection.close()
        self.journal_save()
        self.checkpoint("guarded_db_origin_and_unauthenticated_access")

    def lookup_test_account(self, connection, account, lock=False):
        cursor = connection.cursor()
        cursor.execute(
            "SELECT id,email,password_hash,created_at FROM pelanggan_akaun WHERE email=%s" + (" FOR UPDATE" if lock else ""),
            (account["email"],),
        )
        row = cursor.fetchone()
        if row is None:
            return None
        need(account["attempted"], "cleanup_unattempted_account")
        need(HEX32.fullmatch(str(row[0])) is not None, "cleanup_invalid_account_id")
        need(row[1] == account["email"], "cleanup_account_identity_mismatch")
        need(self.verify_password(account["password"], row[2]), "cleanup_password_proof_failed")
        try:
            created = datetime.fromisoformat(row[3].replace("Z", "+00:00")).timestamp()
        except (TypeError, ValueError, AttributeError):
            raise SmokeFailure("cleanup_creation_time_invalid") from None
        need(self.started - 5 <= created <= time.time() + 5, "cleanup_account_predates_run")
        need(account["id"] in (None, row[0]), "cleanup_account_id_changed")
        account["id"] = row[0]
        return row[0]

    def register_and_login(self):
        for account in self.accounts:
            account["attempted"] = True
            self.journal_save()  # Recovery on ambiguous registration response.
            _, registered = self.request("POST", "/api/akaun/daftar", body={"email": account["email"], "password": account["password"]})
            need(isinstance(registered.get("token"), str), "registration_token_missing")
            _, logged_in = self.request("POST", "/api/akaun/masuk", body={"email": account["email"], "password": account["password"]})
            account["token"] = logged_in.get("token")
            _, identity = self.request("GET", "/api/akaun/saya", account)
            connection = self.open_database()
            try:
                found = self.lookup_test_account(connection, account)
                need(found is not None and identity.get("akaun_id") == found, "public_api_and_container_db_mismatch")
            finally:
                connection.close()
            self.journal_save()
        need(self.accounts[0]["id"] != self.accounts[1]["id"], "test_accounts_not_distinct")
        self.checkpoint("two_normal_registration_and_login_sessions")

    def assert_empty_workspace(self, account):
        _, workspace = self.request("GET", PREFIX + "/workspace", account)
        for resource in RESOURCE_TABLES:
            need(workspace.get(resource) == [], "new_account_workspace_not_empty")
        caps = workspace.get("capabilities", {})
        need(caps.get("auto_publish") is False and caps.get("worker_enabled") is False, "public_publishing_not_disabled")
        need(all(not c.get("publishable") and not c.get("accounts") for c in workspace.get("connections", [])), "new_account_has_provider_connection")
        self.no_private_fields(workspace)
        return workspace

    def exercise(self):
        a, b = self.accounts
        self.assert_empty_workspace(a)
        self.assert_empty_workspace(b)
        _, legacy_before = self.request("GET", "/api/akaun/copilot/hasil/draf", a)
        need(legacy_before.get("draf") == [], "new_account_legacy_work_not_empty")
        self.checkpoint("new_account_isolation_and_disabled_capabilities")

        marker = "Synthetic smoke " + self.run_id[:12]
        current_day = (datetime.now(timezone.utc) + timedelta(hours=8)).date().isoformat()
        end_day = (datetime.now(timezone.utc) + timedelta(days=3, hours=8)).date().isoformat()
        future = (datetime.now(timezone.utc) + timedelta(days=2)).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        campaign_id = self.ids["campaigns"][0]
        campaign = {
            "version": 0, "title": marker, "product": "Synthetic service for private verification",
            "offer": "Synthetic offer. Never publish.", "audience": "Synthetic test audience",
            "goal": "leads", "budget_total": "12.30", "destination_url": "https://example.com/smoke",
            "start_date": current_day, "end_date": end_day, "channels": ["facebook"], "status": "draft",
        }
        path = PREFIX + "/campaigns/" + campaign_id
        _, saved = self.request("PUT", path, a, campaign)
        need(saved.get("campaign", {}).get("id") == campaign_id and saved["campaign"]["version"] == 1, "campaign_initial_save")
        _, retry = self.request("PUT", path, a, campaign)
        need(retry.get("replayed") is True and retry["campaign"]["version"] == 1, "campaign_retry_not_idempotent")
        changed = {**campaign, "version": 1, "title": marker + " revised"}
        _, updated = self.request("PUT", path, a, changed)
        need(updated["campaign"]["version"] == 2, "campaign_version_not_incremented")
        _, stale = self.request("PUT", path, a, {**changed, "title": marker + " stale"}, expected=409)
        need(stale.get("code") == "conflict" and stale.get("current", {}).get("version") == 2, "stale_write_not_rejected")
        self.checkpoint("campaign_save_idempotency_and_stale_version")

        payloads = {
            "posts": {"version": 0, "campaign_id": campaign_id, "platform": "facebook", "format": "text",
                      "body": marker + ". Private synthetic content; never publish.", "media_url": "",
                      "scheduled_at": future, "status": "planned"},
            "sources": {"version": 0, "campaign_id": campaign_id, "title": marker + " source",
                        "url": "https://example.com/reference", "observation": "Synthetic observation",
                        "hypothesis": "Synthetic hypothesis", "source_type": "reference"},
            "results": {"version": 0, "campaign_id": campaign_id, "date": current_day, "platform": "facebook",
                        "spend": "12.30", "revenue": "45.67", "impressions": 50, "clicks": 8,
                        "enquiries": 3, "qualified_leads": 2, "orders": 1, "note": marker + " manual result"},
        }
        for resource, payload in payloads.items():
            _, singular = RESOURCE_TABLES[resource]
            _, response = self.request("PUT", PREFIX + "/" + resource + "/" + self.ids[resource][0], a, payload)
            need(response.get(singular, {}).get("version") == 1, resource + "_initial_save")
        _, result_retry = self.request("PUT", PREFIX + "/results/" + self.ids["results"][0], a, payloads["results"])
        need(result_retry.get("replayed") is True and result_retry["result"]["version"] == 1, "result_retry_not_idempotent")
        self.request("PUT", PREFIX + "/results/" + self.ids["results"][1], a, payloads["results"], expected=409)
        self.checkpoint("post_reference_and_manual_result_save")

        _, workspace = self.request("GET", PREFIX + "/workspace", a)
        self.no_private_fields(workspace)
        for resource, (_, singular) in RESOURCE_TABLES.items():
            identifier = self.ids[resource][0]
            need([x.get("id") for x in workspace.get(resource, [])] == [identifier], resource + "_workspace_roundtrip")
            _, loaded = self.request("GET", PREFIX + "/" + resource + "/" + identifier, a)
            need(loaded.get(singular) == workspace[resource][0], resource + "_detail_roundtrip")
            self.no_private_fields(loaded)
            _, denied = self.request("GET", PREFIX + "/" + resource + "/" + identifier, b, expected=404)
            need(singular not in denied and "current" not in denied, "foreign_read_disclosed_record")
        need(workspace["campaigns"][0]["title"] == changed["title"], "stale_write_changed_campaign")
        need(workspace["results"][0]["spend"] == "12.30" and workspace["results"][0]["origin"] == "user_reported", "manual_money_or_origin_changed")
        self.request("PUT", path, b, campaign, expected=404)
        self.request("PUT", PREFIX + "/posts/" + self.ids["posts"][1], b, payloads["posts"], expected=404)
        self.assert_empty_workspace(b)
        self.checkpoint("owner_roundtrip_and_foreign_404_without_disclosure")

        post_path = PREFIX + "/posts/" + self.ids["posts"][0]
        _, schedule = self.request("POST", post_path + "/schedule", a,
                                   {"version": 1, "approved": True, "scheduled_at": future}, expected=409)
        need(schedule.get("code") == "connection_not_ready", "disabled_schedule_wrong_rejection")
        _, unchanged = self.request("GET", post_path, a)
        post = unchanged["post"]
        need(post["status"] == "planned" and post["version"] == 1 and not post.get("provider_post_id"), "disabled_schedule_mutated_post")
        self.checkpoint("disabled_schedule_never_queued")

        _, legacy_after = self.request("GET", "/api/akaun/copilot/hasil/draf", a)
        _, review = self.request("GET", "/api/akaun/copilot/hasil/semakan", a)
        need(legacy_after == legacy_before and review.get("semakan") == [], "legacy_saved_work_regression")
        self.checkpoint("existing_copilot_saved_work_reads")

        try:
            cfg = self.billing.config()
            expected_billing = 200 if self.billing.enabled_for(a["id"], cfg) else 403
            expected_billing_error = "Pembayaran belum dibuka untuk akaun ini." if expected_billing == 403 else None
        except self.billing.BillingError as error:
            expected_billing = error.status
            expected_billing_error = str(error)
        status, billing_view = self.request("GET", "/api/akaun/bil", a, expected=expected_billing)
        if status == 200:
            need(billing_view.get("status") == "none" and billing_view.get("active") is False
                 and billing_view.get("has_customer") is False, "unexpected_billing_entitlement")
        else:
            need(billing_view.get("ok") is False and billing_view.get("error") == expected_billing_error,
                 "billing_response_not_expected_configuration_gate", status)
        self.checkpoint("billing_read_only_" + str(status))
        self.verify_no_external_records()

    def verify_no_external_records(self):
        connection = self.open_database()
        try:
            cursor = connection.cursor()
            for account in self.accounts:
                if not account["id"]:
                    continue
                for table, column in UNTOUCHED_OWNER_TABLES:
                    cursor.execute("SELECT COUNT(*) FROM " + table + " WHERE " + column + "=%s", (account["id"],))
                    need(cursor.fetchone()[0] == 0, "unexpected_external_or_legacy_record")
        finally:
            connection.close()
        self.checkpoint("no_oauth_publish_ai_or_billing_records")

    def cleanup(self):
        if self.pending_mutation is not None:
            self.cleanup_result = {"status": "pending", "accounts_removed": 0, "records_removed": 0}
            # The handler may still commit after a client timeout. Do not open
            # the DB, wait, retry, delete fixtures, or discard the recovery IDs.
            raise SmokeFailure("cleanup_pending_ambiguous_mutation")
        if not any(a["attempted"] for a in self.accounts):
            if self.journal_written:
                self.journal.unlink(missing_ok=True)
            return
        connection = self.open_database()
        try:
            connection.rollback()
            cursor = connection.cursor()
            cursor.execute("SET LOCAL lock_timeout = '5s'")
            cursor.execute("SET LOCAL statement_timeout = '15s'")
            owners = []
            for account in self.accounts:
                if not account["attempted"]:
                    continue
                owner = self.lookup_test_account(connection, account, lock=True)
                if owner is not None:
                    owners.append(owner)
                else:
                    # A public success could have used another database. Never
                    # claim cleanup succeeded merely because this DB is empty.
                    need(account["registration_rejected"], "cleanup_registered_account_not_found")
            self.journal_save()
            records = []
            for resource, (table, _) in RESOURCE_TABLES.items():
                allowed_ids = set(self.ids[resource])
                for owner in owners:
                    cursor.execute("SELECT id,akaun_id FROM " + table + " WHERE akaun_id=%s FOR UPDATE", (owner,))
                    rows = cursor.fetchall()
                    need(all(identifier in allowed_ids and row_owner == owner for identifier, row_owner in rows),
                         "cleanup_unknown_record_refused")
                    records.extend((table, identifier, owner) for identifier, _ in rows)
            for owner in owners:
                for table, column in UNTOUCHED_OWNER_TABLES:
                    cursor.execute("SELECT COUNT(*) FROM " + table + " WHERE " + column + "=%s", (owner,))
                    need(cursor.fetchone()[0] == 0, "cleanup_unexpected_related_record_refused")
            # Fixed dependency order; each mutation targets one generated record ID AND its proven owner.
            order = {"marketing_results": 0, "marketing_sources": 1, "marketing_posts": 2, "marketing_campaigns": 3}
            for table, identifier, owner in sorted(records, key=lambda row: order[row[0]]):
                cursor.execute("DELETE FROM " + table + " WHERE id=%s AND akaun_id=%s", (identifier, owner))
                need(cursor.rowcount == 1, "cleanup_record_delete_count")
            removed_accounts = 0
            for account in self.accounts:
                if account["id"] not in owners:
                    continue
                cursor.execute("DELETE FROM pelanggan_akaun WHERE id=%s AND email=%s", (account["id"], account["email"]))
                need(cursor.rowcount == 1, "cleanup_account_delete_count")
                removed_accounts += 1
            connection.commit()
            self.cleanup_result = {"status": "deleted_pending_verification", "accounts_removed": removed_accounts, "records_removed": len(records)}
        except BaseException:
            connection.rollback()
            raise
        finally:
            connection.close()
        # Fresh guarded connection verifies committed deletion, not just local transaction visibility.
        connection = self.open_database()
        try:
            cursor = connection.cursor()
            for account in self.accounts:
                cursor.execute("SELECT COUNT(*) FROM pelanggan_akaun WHERE email=%s", (account["email"],))
                need(cursor.fetchone()[0] == 0, "cleanup_account_verification_failed")
                if account["id"]:
                    for table, _ in RESOURCE_TABLES.values():
                        cursor.execute("SELECT COUNT(*) FROM " + table + " WHERE akaun_id=%s", (account["id"],))
                        need(cursor.fetchone()[0] == 0, "cleanup_record_verification_failed")
            for resource, identifiers in self.ids.items():
                table = RESOURCE_TABLES[resource][0]
                for identifier in identifiers:
                    cursor.execute("SELECT COUNT(*) FROM " + table + " WHERE id=%s", (identifier,))
                    need(cursor.fetchone()[0] == 0, "cleanup_identifier_verification_failed")
        finally:
            connection.close()
        self.cleanup_result["status"] = "verified"
        self.journal.unlink(missing_ok=True)
        for account in self.accounts:
            account["token"] = None
            account["password"] = None


def interrupt(_signum, _frame):
    raise SmokeFailure("interrupted")


def main():
    parser = argparse.ArgumentParser(description="Private BeShare Marketing smoke test; no operations without --run.")
    parser.add_argument("--run", action="store_true", help="Execute inside the reviewed account container.")
    args = parser.parse_args()
    if not args.run:
        print(json.dumps({"suite": "marketing-live-smoke", "mode": "plan_only", "network_or_db_operations": 0,
                          "accounts": 2, "marketing_records": 4, "publishing_required": "disabled",
                          "billing": "GET only", "cleanup": "exact synthetic IDs, verified transaction",
                          "credentials": "memory only"}, separators=(",", ":")))
        return 0
    smoke = None
    failed = None
    cleanup_failed = None
    old_handlers = {}
    try:
        for signum in (signal.SIGINT, signal.SIGTERM):
            old_handlers[signum] = signal.signal(signum, interrupt)
        smoke = Smoke(*load_account_modules())
        smoke.preflight()
        smoke.register_and_login()
        smoke.exercise()
    except SmokeFailure as error:
        failed = {"check": error.code, **({"http_status": error.http_status} if error.http_status is not None else {})}
    except BaseException:
        failed = {"check": "internal_failure"}
    finally:
        # Give exact cleanup a chance even if the operator interrupts once.
        for signum in old_handlers:
            signal.signal(signum, signal.SIG_IGN)
        if smoke is not None:
            try:
                smoke.cleanup()
            except SmokeFailure as error:
                cleanup_failed = error.code
            except BaseException:
                cleanup_failed = "cleanup_database_or_runtime_failure"
        for signum, previous in old_handlers.items():
            signal.signal(signum, previous)
    summary = {"suite": "marketing-live-smoke", "status": "passed" if failed is None and cleanup_failed is None else "failed",
               "checks_passed": smoke.checks if smoke else [], "http_requests": smoke.requests if smoke else 0,
               "cleanup": smoke.cleanup_result if smoke else {"status": "not_needed"},
               "passwords_or_tokens_written": False}
    if failed:
        summary["failure"] = failed
        if smoke and smoke.last_request:
            summary["failure"]["last_request"] = smoke.last_request
    if cleanup_failed:
        summary["cleanup"] = {"status": "pending" if cleanup_failed == "cleanup_pending_ambiguous_mutation" else "failed",
                              "reason": cleanup_failed}
        if smoke and smoke.journal_written:
            summary["cleanup"]["recovery_manifest"] = str(smoke.journal)
    print(json.dumps(summary, separators=(",", ":")))
    return 0 if summary["status"] == "passed" else 1


if __name__ == "__main__":
    sys.exit(main())
