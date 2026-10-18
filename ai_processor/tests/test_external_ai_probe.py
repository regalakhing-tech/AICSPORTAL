import unittest
from dataclasses import replace

from ai_processor.config import SETTINGS


class ExternalAiProbeTests(unittest.TestCase):
    def test_configuration_payload_contains_only_safe_state(self):
        from ai_processor.external_ai.probe import configuration_payload

        settings = replace(
            SETTINGS,
            external_ai_enabled=True,
            external_ai_endpoint='https://example.invalid/v1/chat/completions',
            external_ai_model='test-model',
            external_ai_api_key='replacement-test-key',
            external_ai_image_enabled=False,
        )

        payload = configuration_payload(settings)

        self.assertEqual(payload, {'status': 'configured', 'image_enabled': False})
        self.assertNotIn('replacement-test-key', repr(payload))
        self.assertNotIn('example.invalid', repr(payload))
        self.assertNotIn('test-model', repr(payload))


if __name__ == '__main__':
    unittest.main()
