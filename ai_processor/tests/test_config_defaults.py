import importlib
import os
import unittest


class ConfigDefaultsTest(unittest.TestCase):
    def test_optional_adcd_defaults_to_disabled(self):
        previous = os.environ.pop("AICS_ADCD_ENABLED", None)
        try:
            import ai_processor.config as config

            reloaded = importlib.reload(config)
            self.assertFalse(reloaded.SETTINGS.adcd_enabled)
        finally:
            if previous is not None:
                os.environ["AICS_ADCD_ENABLED"] = previous
            importlib.reload(config)


if __name__ == "__main__":
    unittest.main()
