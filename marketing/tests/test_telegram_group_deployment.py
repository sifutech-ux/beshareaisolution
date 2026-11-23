"""Fail-closed deployment-template checks; no Docker or network required."""
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKER = ROOT / "deploy" / "telegram-group-worker.compose.yaml"


class TelegramGroupDeploymentTest(unittest.TestCase):
    def test_dedicated_worker_is_disabled_by_default(self):
        text = WORKER.read_text(encoding="utf-8")
        self.assertIn('marketing-telegram-worker:', text)
        self.assertIn('MARKETING_GROUP_WORKER_ENABLED: "0"', text)
        self.assertIn('MARKETING_GROUP_PUBLISH_ENABLED: "0"', text)
        self.assertIn('"/app/marketing_group_worker.py"', text)
        self.assertIn('"MARKETING_TELEGRAM_BOT_TOKEN"', text)
        self.assertNotIn('ports:', text)
        self.assertNotIn('privileged: true', text)
        self.assertNotIn('/var/run/docker.sock', text)

    def test_no_unrelated_secrets_appear_in_worker_allowlist(self):
        source = WORKER.read_text(encoding="utf-8")
        self.assertIn('read_only: true', source)
        self.assertIn('cap_drop: [ALL]', source)
        for unrelated in ("OPENAI_API_KEY", "STRIPE_SECRET_KEY",
                          "WHATSAPP_ACCESS_TOKEN", "MARKETING_META_APP_SECRET"):
            self.assertNotIn(unrelated, source)


if __name__ == "__main__":
    unittest.main()
