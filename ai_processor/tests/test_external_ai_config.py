import os
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

from ai_processor.config import SETTINGS


class ExternalAiPrivateEnvironmentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name) / 'aics-ai.env'

    def tearDown(self):
        self.temp.cleanup()

    def test_allowed_names_load_without_overwriting_process_values(self):
        from ai_processor.private_env import load_private_environment

        self.path.write_text(
            'AICS_EXTERNAL_AI_ENABLED=1\n'
            'AICS_EXTERNAL_AI_MODEL=file-model\n'
            'AICS_EXTERNAL_AI_API_KEY=replacement-test-key\n'
            'UNRELATED_NAME=must-not-load\n',
            encoding='utf-8',
        )
        environment = {'AICS_EXTERNAL_AI_MODEL': 'process-model'}

        loaded = load_private_environment(self.path, environment)

        self.assertEqual(environment['AICS_EXTERNAL_AI_MODEL'], 'process-model')
        self.assertEqual(environment['AICS_EXTERNAL_AI_API_KEY'], 'replacement-test-key')
        self.assertNotIn('UNRELATED_NAME', environment)
        self.assertNotIn('UNRELATED_NAME', loaded)

    def test_malformed_and_nul_values_are_ignored(self):
        from ai_processor.private_env import load_private_environment

        self.path.write_bytes(
            b'AICS_EXTERNAL_AI_ENABLED=1\n'
            b'BROKEN_LINE\n'
            b'AICS_EXTERNAL_AI_API_KEY=unsafe\x00value\n'
        )
        environment = {}

        loaded = load_private_environment(self.path, environment)

        self.assertEqual(loaded, {'AICS_EXTERNAL_AI_ENABLED': '1'})
        self.assertNotIn('AICS_EXTERNAL_AI_API_KEY', environment)


class ExternalAiConfigurationHealthTests(unittest.TestCase):
    def test_disabled_provider_is_reported_without_requiring_a_key(self):
        from ai_processor.config import external_ai_configuration_health

        settings = replace(SETTINGS, external_ai_enabled=False, external_ai_api_key='')

        self.assertEqual(external_ai_configuration_health(settings), 'disabled')

    def test_enabled_valid_provider_is_configured(self):
        from ai_processor.config import external_ai_configuration_health

        settings = replace(
            SETTINGS,
            external_ai_enabled=True,
            external_ai_endpoint='https://example.invalid/v1/chat/completions',
            external_ai_model='test-model',
            external_ai_api_key='replacement-test-key',
            external_ai_timeout_seconds=12.0,
        )

        self.assertEqual(external_ai_configuration_health(settings), 'configured')

    def test_invalid_settings_return_privacy_safe_codes(self):
        from ai_processor.config import external_ai_configuration_health

        base = replace(
            SETTINGS,
            external_ai_enabled=True,
            external_ai_endpoint='https://example.invalid/v1/chat/completions',
            external_ai_model='test-model',
            external_ai_api_key='replacement-test-key',
            external_ai_timeout_seconds=12.0,
        )

        self.assertEqual(external_ai_configuration_health(replace(base, external_ai_api_key='')), 'missing_key')
        self.assertEqual(external_ai_configuration_health(replace(base, external_ai_endpoint='http://example.invalid')), 'invalid_endpoint')
        self.assertEqual(external_ai_configuration_health(replace(base, external_ai_timeout_seconds=13.0)), 'invalid_timeout')

    def test_redacted_configuration_never_returns_the_key(self):
        from ai_processor.config import redacted_external_ai_config

        settings = replace(
            SETTINGS,
            external_ai_enabled=True,
            external_ai_endpoint='https://example.invalid/v1/chat/completions',
            external_ai_model='test-model',
            external_ai_api_key='replacement-test-key',
            external_ai_timeout_seconds=12.0,
        )

        redacted = redacted_external_ai_config(settings)

        self.assertEqual(redacted['status'], 'configured')
        self.assertNotIn('replacement-test-key', repr(redacted))
        self.assertNotIn('api_key', redacted)


if __name__ == '__main__':
    unittest.main()
