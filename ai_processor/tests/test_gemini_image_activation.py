import unittest
from dataclasses import replace

from ai_processor.config import SETTINGS
from ai_processor.external_ai.visual_service import gemini_image_settings


class GeminiImageActivationTests(unittest.TestCase):
    def test_missing_dedicated_key_does_not_reuse_text_provider_key(self):
        configured = replace(SETTINGS, external_ai_endpoint='https://api.groq.com/openai/v1/chat/completions',
                             external_ai_api_key='text-provider-key', gemini_image_api_key='')
        self.assertIsNone(gemini_image_settings(configured))

    def test_dedicated_key_selects_gemini_image_endpoint(self):
        configured = replace(SETTINGS, gemini_image_api_key='test-key', gemini_image_enabled=True)
        visual = gemini_image_settings(configured)
        self.assertEqual(visual.external_ai_model, 'gemini-2.5-flash')
        self.assertTrue(visual.external_ai_image_enabled)
        self.assertIn('generativelanguage.googleapis.com', visual.external_ai_endpoint)
        self.assertNotEqual(visual.external_ai_state_root, configured.external_ai_state_root)


if __name__ == '__main__':
    unittest.main()
