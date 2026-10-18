import io
import json
import unittest
from dataclasses import replace
from types import SimpleNamespace
from urllib.error import HTTPError

from ai_processor.config import SETTINGS


ASSESSMENT = {
    'schema_version': 'external-ai-assessment-v1',
    'state': 'review',
    'confidence_band': 'moderate',
    'document_type': 'valid_id',
    'reasons': ['FIELD_UNCERTAIN'],
    'fields': {},
    'document_quality': {'state': 'usable', 'reason': 'Readable image.'},
    'signature_review': {'state': 'visible', 'reason': 'Signature is visible.'},
    'visual_integrity': {'state': 'no_obvious_issue', 'reason': 'No obvious editing signal.'},
}


class FakeResponse:
    def __init__(self, payload, status=200):
        self.payload = payload
        self.status = status

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self, _limit=-1):
        return json.dumps(self.payload).encode('utf-8')


class SequenceOpener:
    def __init__(self, *outcomes):
        self.outcomes = list(outcomes)
        self.calls = []

    def __call__(self, request, timeout):
        self.calls.append((request, timeout))
        outcome = self.outcomes.pop(0)
        if isinstance(outcome, BaseException):
            raise outcome
        return outcome


def completion(assessment=ASSESSMENT):
    return FakeResponse({'choices': [{'message': {'content': json.dumps(assessment)}}]})


def gemini_completion(assessment=ASSESSMENT):
    return FakeResponse({'candidates': [{'content': {'parts': [{'text': json.dumps(assessment)}]}}]})


def settings():
    return replace(
        SETTINGS,
        external_ai_enabled=True,
        external_ai_endpoint='https://example.invalid/v1/chat/completions',
        external_ai_model='test-model',
        external_ai_api_key='replacement-test-key',
        external_ai_timeout_seconds=12.0,
    )


def gemini_settings():
    return replace(
        SETTINGS,
        external_ai_enabled=True,
        external_ai_endpoint='https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent',
        external_ai_model='gemini-3.5-flash-lite',
        external_ai_fallback_models=('gemini-2.5-flash', 'gemini-2.5-flash-lite'),
        external_ai_api_key='gemini-test-key',
        external_ai_timeout_seconds=12.0,
        external_ai_image_enabled=True,
    )


class ExternalAiClientTests(unittest.TestCase):
    def test_gemini_timeout_uses_the_next_configured_model(self):
        from ai_processor.external_ai.client import ExternalAiClient

        opener = SequenceOpener(TimeoutError(), gemini_completion())
        result = ExternalAiClient(gemini_settings(), opener=opener, sleeper=lambda _seconds: None).assess(
            {'expected_document_type': 'valid_id'}
        )

        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['model'], 'gemini-2.5-flash')
        self.assertEqual(result['attempts'], 2)
        self.assertIn('/models/gemini-3.5-flash-lite:generateContent', opener.calls[0][0].full_url)
        self.assertIn('/models/gemini-2.5-flash:generateContent', opener.calls[1][0].full_url)

    def test_gemini_tries_all_models_when_each_one_times_out(self):
        from ai_processor.external_ai.client import ExternalAiClient

        opener = SequenceOpener(TimeoutError(), TimeoutError(), TimeoutError())
        result = ExternalAiClient(gemini_settings(), opener=opener, sleeper=lambda _seconds: None).assess({})

        self.assertEqual(result['status'], 'unavailable')
        self.assertEqual(result['error_code'], 'EXTERNAL_AI_TIMEOUT')
        self.assertEqual(result['attempts'], 3)
        self.assertEqual(len(opener.calls), 3)

    def test_gemini_authentication_failure_does_not_use_fallback_models(self):
        from ai_processor.external_ai.client import ExternalAiClient

        error = HTTPError('https://example.invalid', 401, 'Unauthorized', {}, io.BytesIO(b''))
        opener = SequenceOpener(error, gemini_completion())
        result = ExternalAiClient(gemini_settings(), opener=opener, sleeper=lambda _seconds: None).assess({})

        self.assertEqual(result['error_code'], 'EXTERNAL_AI_AUTH_FAILED')
        self.assertEqual(len(opener.calls), 1)

    def test_groq_curl_transport_keeps_key_out_of_process_arguments(self):
        from ai_processor.external_ai.client import CurlOpener
        from urllib.request import Request

        calls = []
        def runner(args, **kwargs):
            calls.append((args, kwargs))
            return SimpleNamespace(
                returncode=0,
                stdout='{"choices":[{"message":{"content":"{}"}}]}\n__AICS_HTTP_STATUS__:200\n__AICS_CONTENT_TYPE__:application/json',
                stderr='',
            )

        request = Request(
            'https://api.groq.com/openai/v1/chat/completions',
            data=b'{"model":"test"}',
            headers={'Content-Type': 'application/json', 'Authorization': 'Bearer private-test-key'},
            method='POST',
        )
        with CurlOpener(runner=runner, executable='curl.exe')(request, timeout=4) as response:
            self.assertIn(b'choices', response.read())

        args, kwargs = calls[0]
        self.assertNotIn('private-test-key', repr(args))
        self.assertIn('private-test-key', kwargs['input'])
        self.assertEqual(kwargs['timeout'], 5)

    def test_successful_completion_returns_normalized_assessment(self):
        from ai_processor.external_ai.client import ExternalAiClient

        opener = SequenceOpener(completion())
        result = ExternalAiClient(settings(), opener=opener, sleeper=lambda _seconds: None).assess(
            {'expected_document_type': 'valid_id'}
        )

        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['assessment']['state'], 'review')
        self.assertEqual(result['payload_mode'], 'text')
        self.assertEqual(result['attempts'], 1)

    def test_authentication_failure_does_not_retry(self):
        from ai_processor.external_ai.client import ExternalAiClient

        error = HTTPError('https://example.invalid', 401, 'Unauthorized', {}, io.BytesIO(b''))
        opener = SequenceOpener(error)

        result = ExternalAiClient(settings(), opener=opener, sleeper=lambda _seconds: None).assess({})

        self.assertEqual(result['status'], 'unavailable')
        self.assertEqual(result['error_code'], 'EXTERNAL_AI_AUTH_FAILED')
        self.assertEqual(len(opener.calls), 1)

    def test_cloudflare_html_forbidden_is_reported_as_access_blocked(self):
        from ai_processor.external_ai.client import ExternalAiClient

        headers = {'Content-Type': 'text/html; charset=UTF-8', 'Server': 'cloudflare'}
        error = HTTPError(
            'https://example.invalid', 403, 'Forbidden', headers,
            io.BytesIO(b'<html><title>Just a moment...</title><p>Cloudflare challenge</p></html>'),
        )
        opener = SequenceOpener(error)

        result = ExternalAiClient(settings(), opener=opener, sleeper=lambda _seconds: None).assess({})

        self.assertEqual(result['status'], 'unavailable')
        self.assertEqual(result['error_code'], 'EXTERNAL_AI_ACCESS_BLOCKED')
        self.assertEqual(len(opener.calls), 1)

    def test_transient_timeout_retries_once(self):
        from ai_processor.external_ai.client import ExternalAiClient

        opener = SequenceOpener(TimeoutError(), completion())

        result = ExternalAiClient(settings(), opener=opener, sleeper=lambda _seconds: None).assess({})

        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['attempts'], 2)
        self.assertEqual(len(opener.calls), 2)

    def test_invalid_provider_json_is_unavailable_without_retry(self):
        from ai_processor.external_ai.client import ExternalAiClient

        opener = SequenceOpener(FakeResponse({'choices': [{'message': {'content': 'not json'}}]}))

        result = ExternalAiClient(settings(), opener=opener, sleeper=lambda _seconds: None).assess({})

        self.assertEqual(result['error_code'], 'EXTERNAL_AI_INVALID_RESPONSE')
        self.assertEqual(len(opener.calls), 1)

    def test_request_uses_configured_model_and_image_mode_only_when_supplied(self):
        from ai_processor.external_ai.client import ExternalAiClient

        opener = SequenceOpener(completion())
        result = ExternalAiClient(settings(), opener=opener, sleeper=lambda _seconds: None).assess(
            {'expected_document_type': 'valid_id'},
            image_data_url='data:image/jpeg;base64,AA==',
        )
        body = json.loads(opener.calls[0][0].data.decode('utf-8'))

        self.assertEqual(body['model'], 'test-model')
        self.assertEqual(result['payload_mode'], 'image')
        self.assertEqual(body['messages'][1]['content'][1]['type'], 'image_url')

    def test_groq_request_enforces_the_assessment_contract_with_strict_json_schema(self):
        from ai_processor.external_ai.client import ExternalAiClient

        groq_settings = replace(
            settings(),
            external_ai_endpoint='https://api.groq.com/openai/v1/chat/completions',
            external_ai_model='openai/gpt-oss-20b',
        )
        opener = SequenceOpener(completion())
        ExternalAiClient(groq_settings, opener=opener, sleeper=lambda _seconds: None).assess({
            'expected_document_type': 'valid_id',
            'fields': {'full_name': {'value': 'Sample Person'}},
        })

        body = json.loads(opener.calls[0][0].data.decode('utf-8'))
        response_format = body['response_format']
        self.assertEqual(response_format['type'], 'json_schema')
        self.assertTrue(response_format['json_schema']['strict'])
        schema = response_format['json_schema']['schema']
        self.assertFalse(schema['additionalProperties'])
        self.assertEqual(set(schema['required']), set(schema['properties']))
        fields_schema = schema['properties']['fields']
        self.assertIn('full_name', fields_schema['properties'])
        self.assertFalse(fields_schema['additionalProperties'])

    def test_gemini_request_uses_all_images_structured_json_and_api_key_header(self):
        from ai_processor.external_ai.client import ExternalAiClient

        opener = SequenceOpener(gemini_completion())
        result = ExternalAiClient(gemini_settings(), opener=opener, sleeper=lambda _seconds: None).assess(
            {
                'expected_document_type': 'valid_id',
                'fields': {'name': {'value': None}},
                'beneficiary': {'full_name': 'Sample Person'},
            },
            image_data_urls=[
                'data:image/jpeg;base64,AA==',
                'data:image/jpeg;base64,AQ==',
            ],
        )

        request = opener.calls[0][0]
        body = json.loads(request.data.decode('utf-8'))
        parts = body['contents'][0]['parts']
        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['provider'], 'google_gemini')
        self.assertEqual(result['payload_mode'], 'image')
        self.assertEqual(len([part for part in parts if 'inline_data' in part]), 2)
        self.assertEqual(body['generationConfig']['responseMimeType'], 'application/json')
        response_schema = body['generationConfig']['responseSchema']
        self.assertNotIn('additionalProperties', json.dumps(response_schema))
        value_schema = response_schema['properties']['fields']['properties']['name']['properties']['value']
        self.assertEqual(value_schema['type'], 'STRING')
        self.assertTrue(value_schema['nullable'])
        self.assertEqual(request.headers['X-goog-api-key'], 'gemini-test-key')
        self.assertNotIn('Authorization', request.headers)


if __name__ == '__main__':
    unittest.main()
