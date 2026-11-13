#!/usr/bin/env python3
"""Verify the restricted sandbox credential; optionally create disposable QA sessions.

Run on the BeShare VPS. Never accepts a live key, charges a card, or grants app access.
Only synthetic customer data is sent. No credential is printed or stored in results.
"""
import argparse
import json
import os
from pathlib import Path
import stat
import urllib.error
import urllib.parse
import urllib.request

API_VERSION = "2026-08-26.dahlia"
ORIGIN = "https://beshareaisolution.com"
PROBE_ID = "beshare-backend-sandbox-20261113-v1"
PRICES = {
    "copilot_early": ("price_1UbHgx0wYRAO6pPkfAGkFHH7", 2900, "month"),
    "copilot_standard": ("price_1UbHkS0wYRAO6pPkHbdCz420", 4900, "month"),
    "os_early": ("price_1UbHiX0wYRAO6pPkiSHtb0sl", 19900, "month"),
    "os_setup": ("price_1UbHiy0wYRAO6pPkVk0t3Pc2", 29900, None),
    "os_topup_500": ("price_1UbHjT0wYRAO6pPkQz9PkIPU", 4900, None),
}


class ProbeError(Exception):
    pass


def credential(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077:
        raise ProbeError("Credential must be a regular private file with mode 0600.")
    config = dict(line.split("=", 1) for line in path.read_text().splitlines() if "=" in line)
    key = config.get("BESHARE_STRIPE_SECRET_KEY", "")
    if config.get("BESHARE_STRIPE_MODE") != "test" or not key.startswith("rk_test_"):
        raise ProbeError("Only the restricted Stripe sandbox key is accepted.")
    if config.get("BESHARE_BILLING_ENABLED") != "false":
        raise ProbeError("This probe requires application billing to remain disabled.")
    return key


class Stripe:
    def __init__(self, key):
        self.key = key

    def call(self, path, values=None, operation=None):
        headers = {"Authorization": "Bearer " + self.key, "Stripe-Version": API_VERSION}
        if values is not None:
            if not operation:
                raise ProbeError("All writes need an idempotency key.")
            headers["Idempotency-Key"] = PROBE_ID + "-" + operation
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        request = urllib.request.Request(
            "https://api.stripe.com/v1/" + path,
            data=None if values is None else urllib.parse.urlencode(values).encode(),
            headers=headers,
        )
        try:
            with urllib.request.urlopen(request, timeout=25) as response:
                result = json.load(response)
        except urllib.error.HTTPError as error:
            try:
                code = json.load(error).get("error", {}).get("code", "stripe_error")
            except (ValueError, TypeError):
                code = "stripe_error"
            raise ProbeError(f"{path}: HTTP {error.code} ({code})") from None
        except (urllib.error.URLError, TimeoutError, ValueError):
            raise ProbeError(f"{path}: request failed; no response data logged") from None
        if result.get("livemode") is True:
            raise ProbeError("Unexpected live object: stopped.")
        return result


def verify_price(value, amount, interval):
    return (
        value.get("livemode") is False and value.get("active") is True
        and value.get("currency") == "myr" and value.get("unit_amount") == amount
        and (value.get("recurring") or {}).get("interval") == interval
        and (interval is None or value["recurring"].get("interval_count") == 1)
    )


def run(stripe, create_checkouts=False):
    result = {"mode": "sandbox", "api_version": API_VERSION, "prices": {}, "application_access": False}
    for name, (price, amount, interval) in PRICES.items():
        found = stripe.call("prices/" + price)
        if found.get("id") != price or not verify_price(found, amount, interval):
            raise ProbeError("Price validation failed: " + name)
        result["prices"][name] = {"id": price, "amount": amount, "verified": True}
    if not create_checkouts:
        return result
    customer = stripe.call("customers", {
        "email": "beshare-backend-qa@example.com", "name": "BeShare Backend Sandbox QA",
        "metadata[fixture]": PROBE_ID, "metadata[application_access]": "none",
    }, "customer")
    result["customer"] = customer["id"]
    result["checkouts"] = {}
    for product in ("copilot", "os"):
        values = {
            "mode": "subscription", "customer": customer["id"],
            "success_url": ORIGIN + "/?stripe_sandbox_qa=success",
            "cancel_url": ORIGIN + "/?stripe_sandbox_qa=cancelled",
            "client_reference_id": PROBE_ID + "-" + product,
            "metadata[fixture]": PROBE_ID, "metadata[application_access]": "none",
            "subscription_data[metadata][fixture]": PROBE_ID,
            "line_items[0][price]": PRICES[product + "_early"][0],
            "line_items[0][quantity]": 1,
            "custom_text[submit][message]": "Ujian sandbox BeShare sahaja. Tiada caj sebenar atau pengaktifan akaun aplikasi.",
        }
        if product == "copilot":
            values.update({
                "subscription_data[trial_period_days]": 7,
                "subscription_data[trial_settings][end_behavior][missing_payment_method]": "pause",
                "payment_method_collection": "if_required",
            })
        else:
            values.update({"line_items[1][price]": PRICES["os_setup"][0], "line_items[1][quantity]": 1})
        checkout = stripe.call("checkout/sessions", values, product + "-checkout")
        if checkout.get("livemode") is not False or not checkout["id"].startswith("cs_test_"):
            raise ProbeError("Sandbox checkout validation failed.")
        if product == "os" and checkout.get("amount_total") != 49800:
            raise ProbeError("AI OS initial total must be MYR498.")
        result["checkouts"][product] = {"id": checkout["id"], "url": checkout["url"], "total": checkout["amount_total"]}
    portal = stripe.call("billing_portal/sessions", {
        "customer": customer["id"], "configuration": "bpc_1UbHub0wYRAO6pPkrhJX2XWi",
        "return_url": ORIGIN + "/?stripe_sandbox_qa=portal",
    }, "portal")
    result["portal"] = {"id": portal["id"], "created": True}
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, default=Path("/etc/beshare/billing-sandbox.env"))
    parser.add_argument("--create-checkouts", action="store_true")
    parser.add_argument("--result", type=Path)
    args = parser.parse_args()
    try:
        result = run(Stripe(credential(args.config)), args.create_checkouts)
        if args.result:
            with os.fdopen(os.open(args.result, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), "w") as output:
                os.fchmod(output.fileno(), 0o600)
                json.dump(result, output, indent=2)
        print(json.dumps(result, indent=2))
    except (OSError, ProbeError) as error:
        print("FAIL: " + str(error))
        raise SystemExit(1)


if __name__ == "__main__":
    main()
