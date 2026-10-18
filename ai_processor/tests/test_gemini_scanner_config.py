import tempfile
import unittest
import os
import subprocess
import sys
from pathlib import Path

from ai_processor.config import Settings, resolve_gemini_api_key
from ai_processor.private_env import load_private_environment


class GeminiKeyLoadingTests(unittest.TestCase):
    def _load(self, text: str) -> dict:
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'aics-ai.env'
            path.write_text(text, encoding='utf-8')
            environ: dict[str, str] = {}
            load_private_environment(path, environ)
            return environ

    def test_gemini_key_aliases_are_allowlisted(self):
        for name in ('AICS_GEMINI_API_KEY', 'AICS_GEMINI_IMAGE_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'):
            with self.subTest(name=name):
                self.assertEqual(self._load(f'{name}=synthetic-value\n').get(name), 'synthetic-value')

    def test_gemini_model_and_switch_are_allowlisted(self):
        environ = self._load('AICS_GEMINI_MODEL=gemini-x\nAICS_GEMINI_IMAGE_ENABLED=0\nUNRELATED=1\n')
        self.assertEqual(environ.get('AICS_GEMINI_MODEL'), 'gemini-x')
        self.assertEqual(environ.get('AICS_GEMINI_IMAGE_ENABLED'), '0')
        self.assertNotIn('UNRELATED', environ)

    def test_other_gemini_key_spellings_are_loaded_and_resolved(self):
        environ = self._load('GEMINI_KEY=k1\nAICS_GOOGLE_AI_KEY=k2\nGEMINI_MODEL_NAME=x\n')
        self.assertEqual(environ.get('GEMINI_KEY'), 'k1')
        self.assertEqual(environ.get('AICS_GOOGLE_AI_KEY'), 'k2')
        self.assertNotIn('GEMINI_MODEL_NAME', environ)
        self.assertEqual(resolve_gemini_api_key({'GEMINI_KEY': 'k1'}), 'k1')

    def test_first_non_empty_alias_wins(self):
        self.assertEqual(resolve_gemini_api_key({'GEMINI_API_KEY': 'b', 'AICS_GEMINI_API_KEY': ' '}), 'b')
        self.assertEqual(resolve_gemini_api_key({'AICS_GEMINI_API_KEY': 'a', 'GOOGLE_API_KEY': 'c'}), 'a')
        self.assertEqual(resolve_gemini_api_key({}), '')

    def test_settings_expose_scanner_defaults(self):
        settings = Settings()
        self.assertTrue(settings.gemini_model)
        self.assertGreaterEqual(settings.gemini_timeout_seconds, 10)
        self.assertIsInstance(settings.gemini_fallback_models, tuple)

    def test_scanner_uses_supported_defaults_without_private_model_overrides(self):
        with tempfile.TemporaryDirectory() as folder:
            environment = os.environ.copy()
            environment['AICS_EXTERNAL_AI_ENV_FILE'] = str(Path(folder) / 'missing.env')
            environment.pop('AICS_GEMINI_MODEL', None)
            environment.pop('AICS_GEMINI_FALLBACK_MODELS', None)
            output = subprocess.check_output(
                [sys.executable, '-c', 'from ai_processor.config import SETTINGS; print(SETTINGS.gemini_model, *SETTINGS.gemini_fallback_models)'],
                env=environment, text=True,
            )
        self.assertEqual(output.strip(), 'gemini-3.5-flash-lite gemini-3.1-flash-lite')


if __name__ == '__main__':
    unittest.main()
