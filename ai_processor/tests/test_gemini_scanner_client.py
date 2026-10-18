import io
import json
import tempfile
import unittest
from dataclasses import replace
from email.message import Message
from pathlib import Path
from urllib.error import HTTPError, URLError

import numpy as np

from ai_processor.config import SETTINGS
from ai_processor.external_ai.state import ExternalAiStateStore
from ai_processor.gemini_scanner.client import GeminiScannerClient
from ai_processor.gemini_scanner.prompt import CHECK_KEYS
from ai_processor.gemini_scanner.service import scan_document

SECRET = 'synthetic-secret-key-123'


def _scan_json():
    return {
        'document_title': 'CERTIFICATE OF DEATH', 'document_type': 'death_cert', 'type_confidence': 95,
        'primary_person': {'name': 'Jennifer Angeles Ramos', 'role': 'deceased', 'birth_date': None,
                           'sex': 'Female', 'address': None},
        'beneficiary_name_found': False, 'document_date': '2008-06-28', 'issuer': 'Civil Registrar',
        'checks': {key: {'state': 'pass', 'reason': 'Looks fine.'} for key in CHECK_KEYS},
        'indicators': [], 'sample_or_specimen': {'detected': False, 'reason': ''},
        'summary': 'A death certificate.',
    }


class _Response:
    def __init__(self, payload: bytes):
        self.payload = payload

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return False

    def read(self, limit=-1):
        return self.payload


class FakeOpener:
    """Returns queued outcomes; records every request."""

    def __init__(self, *outcomes):
        self.outcomes = list(outcomes)
        self.requests = []

    def __call__(self, request, timeout):
        self.requests.append((request, timeout))
        outcome = self.outcomes.pop(0)
        if isinstance(outcome, Exception):
            raise outcome
        return _Response(outcome)


def _ok(scan=None):
    envelope = {'candidates': [{'content': {'parts': [{'text': json.dumps(scan or _scan_json())}]},
                                'finishReason': 'STOP'}]}
    return json.dumps(envelope).encode('utf-8')


def _http(code):
    headers = Message()
    headers['Content-Type'] = 'application/json'
    return HTTPError('https://example.invalid', code, 'error', headers, io.BytesIO(b'{}'))


def _settings(**overrides):
    base = dict(gemini_image_enabled=True, gemini_image_api_key=SECRET, gemini_model='gemini-2.5-flash',
                gemini_fallback_models=('gemini-2.5-flash-lite',), gemini_timeout_seconds=20)
    base.update(overrides)
    return replace(SETTINGS, **base)


class GeminiClientTests(unittest.TestCase):
    def test_request_uses_header_key_inline_images_and_schema(self):
        opener = FakeOpener(_ok())
        client = GeminiScannerClient(_settings(), opener=opener)
        result = client.scan('{"task":"x"}', ['data:image/jpeg;base64,QUJD'], page_count=1)
        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['scan']['document_type'], 'death_cert')
        request, _ = opener.requests[0]
        self.assertIn('/models/gemini-2.5-flash:generateContent', request.full_url)
        self.assertNotIn(SECRET, request.full_url)
        self.assertEqual(request.get_header('X-goog-api-key'), SECRET)
        body = json.loads(request.data)
        parts = body['contents'][0]['parts']
        self.assertEqual(parts[0]['text'], '{"task":"x"}')
        self.assertEqual(parts[1]['inline_data'], {'mime_type': 'image/jpeg', 'data': 'QUJD'})
        self.assertEqual(body['generationConfig']['responseMimeType'], 'application/json')
        self.assertEqual(body['generationConfig']['responseSchema']['type'], 'OBJECT')
        self.assertNotIn(SECRET, json.dumps(result))

    def test_schema_property_named_type_is_converted_as_a_schema(self):
        opener = FakeOpener(_ok())
        GeminiScannerClient(_settings(), opener=opener).scan('{}', ['data:image/jpeg;base64,QUJD'], 1)
        schema = json.loads(opener.requests[0][0].data)['generationConfig']['responseSchema']
        indicator = schema['properties']['indicators']['items']['properties']['type']
        self.assertEqual(indicator['type'], 'STRING')
        self.assertIn('pasted_region_boundary', indicator['enum'])
        self.assertEqual(schema['properties']['document_date'], {'type': 'STRING', 'nullable': True})
        self.assertNotIn('additionalProperties', json.dumps(schema))
        # Gemini rejects maxItems here; contract.normalize_scan bounds list sizes instead.
        self.assertNotIn('maxItems', json.dumps(schema))

    def test_custom_instruction_schema_and_normalizer(self):
        envelope = json.dumps({'candidates': [{'content': {'parts': [{'text': json.dumps({'answer': 'ok'})}]}}]}).encode('utf-8')
        opener = FakeOpener(envelope)
        schema = {'type': 'object', 'properties': {'answer': {'type': 'string'}}, 'required': ['answer']}
        result = GeminiScannerClient(_settings(), opener=opener).scan(
            '{}', ['data:image/jpeg;base64,QUJD'], 1, instruction='Read the ID.', schema=schema,
            normalizer=lambda raw, page_count: {'answer': raw['answer'].upper(), 'pages': page_count})
        body = json.loads(opener.requests[0][0].data)
        self.assertEqual(body['systemInstruction']['parts'][0]['text'], 'Read the ID.')
        self.assertEqual(body['generationConfig']['responseSchema']['properties']['answer']['type'], 'STRING')
        self.assertEqual(result['scan'], {'answer': 'OK', 'pages': 1})

    def test_falls_back_to_next_model_on_rate_limit(self):
        opener = FakeOpener(_http(429), _ok())
        result = GeminiScannerClient(_settings(), opener=opener).scan('{}', ['data:image/jpeg;base64,QUJD'], 1)
        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['model'], 'gemini-2.5-flash-lite')
        self.assertEqual(result['attempts'], 2)

    def test_retries_transient_connection_failure_on_last_model(self):
        opener = FakeOpener(TimeoutError(), URLError('temporary outage'), _ok())
        result = GeminiScannerClient(_settings(), opener=opener).scan('{}', ['data:image/jpeg;base64,QUJD'], 1)
        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['model'], 'gemini-2.5-flash-lite')
        self.assertEqual(result['attempts'], 3)

    def test_error_codes(self):
        cases = [(_http(403), 'GEMINI_AUTH_FAILED'), (_http(400), 'GEMINI_BAD_REQUEST'),
                 (URLError('down'), 'GEMINI_UNAVAILABLE'), (TimeoutError(), 'GEMINI_TIMEOUT'),
                 (b'not json', 'GEMINI_INVALID_RESPONSE')]
        for outcome, code in cases:
            with self.subTest(code=code):
                opener = FakeOpener(outcome, outcome, outcome)
                result = GeminiScannerClient(_settings(), opener=opener).scan('{}', ['data:image/jpeg;base64,QUJD'], 1)
                self.assertEqual(result['status'], 'unavailable')
                self.assertEqual(result['error_code'], code)

    def test_auth_failure_does_not_retry_other_models(self):
        opener = FakeOpener(_http(401), _ok())
        result = GeminiScannerClient(_settings(), opener=opener).scan('{}', ['data:image/jpeg;base64,QUJD'], 1)
        self.assertEqual(result['error_code'], 'GEMINI_AUTH_FAILED')
        self.assertEqual(len(opener.requests), 1)

    def test_blocked_prompt_is_reported(self):
        blocked = json.dumps({'promptFeedback': {'blockReason': 'SAFETY'}}).encode('utf-8')
        opener = FakeOpener(blocked, blocked)
        result = GeminiScannerClient(_settings(), opener=opener).scan('{}', ['data:image/jpeg;base64,QUJD'], 1)
        self.assertEqual(result['error_code'], 'GEMINI_BLOCKED')


class GeminiServiceTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        root = Path(self.folder.name)
        self.document = root / 'upload.jpg'
        self.document.write_bytes(b'synthetic document bytes')
        self.store = ExternalAiStateStore(root / 'state')
        self.images = [np.full((60, 80, 3), 255, dtype=np.uint8)]
        self.beneficiary = {'full_name': 'Nicasio Eimar Bustillo'}

    def tearDown(self):
        self.folder.cleanup()

    def _run(self, settings, opener):
        client = GeminiScannerClient(settings, opener=opener)
        return scan_document(settings, self.document, self.images, 'med_cert', self.beneficiary,
                             client=client, state_store=self.store)

    def test_not_configured_without_key(self):
        result = self._run(_settings(gemini_image_api_key=''), FakeOpener())
        self.assertEqual((result['status'], result['error_code']), ('unavailable', 'GEMINI_NOT_CONFIGURED'))

    def test_disabled_switch(self):
        result = self._run(_settings(gemini_image_enabled=False), FakeOpener())
        self.assertEqual(result['error_code'], 'GEMINI_NOT_CONFIGURED')

    def test_completed_scan_is_cached(self):
        opener = FakeOpener(_ok())
        first = self._run(_settings(), opener)
        second = self._run(_settings(), FakeOpener())
        self.assertEqual(first['status'], 'completed')
        self.assertFalse(first['cache_hit'])
        self.assertTrue(second['cache_hit'])
        self.assertEqual(second['scan']['document_type'], 'death_cert')
        self.assertEqual(first['page_count'], 1)

    def test_different_slot_is_not_served_from_cache(self):
        self._run(_settings(), FakeOpener(_ok()))
        client = GeminiScannerClient(_settings(), opener=FakeOpener(_ok()))
        result = scan_document(_settings(), self.document, self.images, 'valid_id', self.beneficiary,
                               client=client, state_store=self.store)
        self.assertFalse(result['cache_hit'])

    def test_open_circuit_skips_the_call(self):
        for _ in range(3):
            self.store.record_transient_failure()
        opener = FakeOpener()
        result = self._run(_settings(), opener)
        self.assertEqual(result['error_code'], 'GEMINI_CIRCUIT_OPEN')
        self.assertEqual(opener.requests, [])

    def test_no_images(self):
        client = GeminiScannerClient(_settings(), opener=FakeOpener())
        result = scan_document(_settings(), self.document, [], 'med_cert', self.beneficiary,
                               client=client, state_store=self.store)
        self.assertEqual(result['error_code'], 'GEMINI_NO_IMAGES')


if __name__ == '__main__':
    unittest.main()
