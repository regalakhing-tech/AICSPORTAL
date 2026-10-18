import tempfile
import unittest
from pathlib import Path


class ExternalAiStateTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        from ai_processor.external_ai.state import ExternalAiStateStore
        self.store = ExternalAiStateStore(Path(self.temp.name))

    def tearDown(self):
        self.temp.cleanup()

    def test_cache_round_trip_and_expiry(self):
        value = {'status': 'completed', 'assessment': {'state': 'support'}}
        self.store.put_cached('a' * 64, value, now=100, ttl_seconds=20)

        self.assertEqual(self.store.get_cached('a' * 64, now=119), value)
        self.assertIsNone(self.store.get_cached('a' * 64, now=120))

    def test_malformed_cache_is_a_miss(self):
        cache_file = Path(self.temp.name) / 'cache' / ('b' * 64 + '.json')
        cache_file.parent.mkdir(parents=True)
        cache_file.write_text('{broken', encoding='utf-8')

        self.assertIsNone(self.store.get_cached('b' * 64, now=1))

    def test_three_transient_failures_open_circuit_for_five_minutes(self):
        self.store.record_transient_failure(now=100)
        self.store.record_transient_failure(now=101)
        self.store.record_transient_failure(now=102)

        self.assertTrue(self.store.circuit_is_open(now=103))
        self.assertFalse(self.store.circuit_is_open(now=402))

    def test_success_resets_failure_count_and_circuit(self):
        for moment in (100, 101, 102):
            self.store.record_transient_failure(now=moment)

        self.store.record_success(now=103)

        self.assertFalse(self.store.circuit_is_open(now=104))

    def test_image_capability_is_bound_to_endpoint_and_model(self):
        self.store.mark_image_capability('https://example.invalid/v1/chat/completions', 'model-a', now=100)

        self.assertTrue(self.store.image_capability_verified('https://example.invalid/v1/chat/completions', 'model-a'))
        self.assertFalse(self.store.image_capability_verified('https://other.invalid/v1/chat/completions', 'model-a'))
        self.assertFalse(self.store.image_capability_verified('https://example.invalid/v1/chat/completions', 'model-b'))

    def test_cache_key_changes_with_document_model_prompt_and_mode(self):
        from ai_processor.external_ai.state import build_cache_key

        document = Path(self.temp.name) / 'document.bin'
        document.write_bytes(b'document-one')
        first = build_cache_key(document, 'model-a', 'prompt-v1', 'text')
        self.assertEqual(first, build_cache_key(document, 'model-a', 'prompt-v1', 'text'))
        self.assertNotEqual(first, build_cache_key(document, 'model-b', 'prompt-v1', 'text'))
        self.assertNotEqual(first, build_cache_key(document, 'model-a', 'prompt-v2', 'text'))
        self.assertNotEqual(first, build_cache_key(document, 'model-a', 'prompt-v1', 'image'))
        document.write_bytes(b'document-two')
        self.assertNotEqual(first, build_cache_key(document, 'model-a', 'prompt-v1', 'text'))


if __name__ == '__main__':
    unittest.main()
