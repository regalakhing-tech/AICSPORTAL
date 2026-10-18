import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

import numpy as np

from ai_processor.config import SETTINGS


class FakeClient:
    def __init__(self, result):
        self.result = result
        self.calls = []

    def assess(self, evidence, image_data_url=None, image_data_urls=None):
        self.calls.append((evidence, image_data_urls if image_data_urls is not None else image_data_url))
        return dict(self.result)


class ExternalAiPostprocessingTests(unittest.TestCase):
    def test_conflict_without_readable_value_is_downgraded_to_review(self):
        from ai_processor.external_ai.postprocessing import enforce_beneficiary_comparisons

        provider = {'status': 'completed', 'assessment': {
            'state': 'conflict', 'reasons': ['FIELD_CONFLICT'],
            'fields': {'patient_name': {'value': None, 'state': 'conflict'}},
        }}

        checked = enforce_beneficiary_comparisons(provider, {'full_name': 'Nicasio Eimar Bustillo'})

        self.assertEqual(checked['assessment']['fields']['patient_name']['state'], 'review')
        self.assertEqual(checked['assessment']['state'], 'review')
        self.assertNotIn('FIELD_CONFLICT', checked['assessment']['reasons'])

    def test_deterministic_beneficiary_comparison_overrides_incorrect_model_states(self):
        from ai_processor.external_ai.postprocessing import enforce_beneficiary_comparisons

        provider = {'status': 'completed', 'assessment': {
            'state': 'review',
            'fields': {
                'name': {'value': 'MA. JOSEFINA M. DELA CRUZ', 'state': 'review'},
                'birth_date': {'value': '24 Feb 58', 'state': 'support'},
                'address': {'value': '662 Mendoza St., Lolomboy, Bocaue, Bulacan 3018', 'state': 'support'},
                'issuing_organization': {'value': 'Philippine Postal Corporation', 'state': 'support'},
            },
        }}

        checked = enforce_beneficiary_comparisons(provider, {
            'full_name': 'Cindy M Reyes', 'date_of_birth': '1995-06-19',
            'address': 'Purok 2, Concepcion, Belison, Antique',
        })

        fields = checked['assessment']['fields']
        self.assertEqual(fields['name']['state'], 'conflict')
        self.assertEqual(fields['birth_date']['state'], 'conflict')
        self.assertEqual(fields['address']['state'], 'conflict')
        self.assertEqual(fields['issuing_organization']['state'], 'support')
        self.assertEqual(checked['assessment']['state'], 'conflict')

    def test_external_beneficiary_conflict_flags_inconsistency_and_redacts_value(self):
        from ai_processor.external_ai.postprocessing import apply_external_recommendation, attach_external_assessment

        local = {'status': 'completed', 'findings': {'assessments': {}}}
        provider = {
            'status': 'completed', 'payload_mode': 'text', 'elapsed_ms': 12,
            'assessment': {
                'schema_version': 'external-ai-assessment-v1', 'state': 'conflict',
                'confidence_band': 'moderate', 'document_type': 'valid_id',
                'reasons': ['FIELD_CONFLICT'],
                'fields': {'name': {'value': 'Different Person', 'state': 'conflict'}},
                'document_quality': {'state': 'good', 'reason': 'Readable.'},
                'signature_review': {'state': 'visible', 'reason': 'Visible.'},
                'visual_integrity': {'state': 'no_obvious_issue', 'reason': 'No obvious issue.'},
            },
        }

        findings = attach_external_assessment(local['findings'], provider, 'applicant_match_uncertain')
        local['findings'] = findings
        result = apply_external_recommendation(local, provider)

        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['assessments']['applicant_match']['state'], 'fail')
        stored_field = findings['external_ai']['assessment']['fields']['name']
        self.assertIsNone(stored_field['value'])
        self.assertTrue(stored_field['difference_detected'])
        self.assertNotIn('raw_response', findings['external_ai'])

    def test_external_uncertainty_requires_staff_review(self):
        from ai_processor.external_ai.postprocessing import apply_external_recommendation

        provider = {'status': 'completed', 'assessment': {
            'state': 'review', 'fields': {'name': {'value': None, 'state': 'review'}},
            'document_quality': {'state': 'usable', 'reason': 'Readable with limitations.'},
        }}
        result = apply_external_recommendation({'status': 'completed', 'findings': {'assessments': {}}}, provider)

        self.assertEqual(result['status'], 'needs_staff_review')
        self.assertEqual(result['findings']['assessments']['applicant_match']['state'], 'review')

    def test_external_unreadable_result_requires_reupload(self):
        from ai_processor.external_ai.postprocessing import apply_external_recommendation

        provider = {'status': 'completed', 'assessment': {
            'state': 'review', 'fields': {},
            'document_quality': {'state': 'recapture_required', 'reason': 'Text cannot be read.'},
        }}
        result = apply_external_recommendation({'status': 'completed', 'findings': {'assessments': {}}}, provider)

        self.assertEqual(result['status'], 'needs_reupload')
        self.assertEqual(result['findings']['assessments']['readability']['state'], 'fail')
        self.assertEqual(result['findings']['assessments']['readability']['label'], 'Unreadable - replace document')

    def test_external_readable_support_keeps_non_escalated_status(self):
        from ai_processor.external_ai.postprocessing import apply_external_recommendation

        provider = {'status': 'completed', 'assessment': {
            'state': 'support', 'fields': {'name': {'value': 'Matching Person', 'state': 'support'}},
            'document_quality': {'state': 'good', 'reason': 'Clear and readable.'},
        }}
        result = apply_external_recommendation({'status': 'completed', 'findings': {'assessments': {}}}, provider)

        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['findings']['assessments']['readability']['label'], 'Readable')
        self.assertEqual(result['findings']['assessments']['applicant_match']['state'], 'pass')

    def test_tamper_only_provider_review_waits_for_independent_aggregation(self):
        from ai_processor.external_ai.postprocessing import apply_external_recommendation

        provider = {'status': 'completed', 'assessment': {
            'schema_version': 'external-ai-assessment-v2', 'state': 'review',
            'reasons': ['ALTERATION_REVIEW_SIGNAL'], 'fields': {},
            'document_quality': {'state': 'usable', 'reason': 'Readable.'},
        }}
        result = apply_external_recommendation(
            {'status': 'completed', 'findings': {'assessments': {}}}, provider,
        )
        self.assertEqual(result['status'], 'completed')


class ExternalAiServiceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.document = Path(self.temp.name) / 'document.jpg'
        self.document.write_bytes(b'synthetic-document')
        self.image = np.zeros((20, 30, 3), dtype=np.uint8)
        self.local_result = {'status': 'needs_staff_review', 'findings': {'assessments': {
            'readability': {'state': 'pass'},
            'document_match': {'state': 'review'},
            'applicant_match': {'state': 'review'},
        }}}

    def tearDown(self):
        self.temp.cleanup()

    def configured_settings(self):
        return replace(
            SETTINGS,
            external_ai_enabled=True,
            external_ai_endpoint='https://example.invalid/v1/chat/completions',
            external_ai_model='test-model',
            external_ai_api_key='replacement-test-key',
            external_ai_image_enabled=False,
            external_ai_state_root=str(Path(self.temp.name) / 'state'),
        )

    def test_gemini_instruction_requires_visible_identity_evidence_for_conflict(self):
        import json
        from ai_processor.external_ai.client import ExternalAiClient

        settings = replace(
            self.configured_settings(),
            external_ai_endpoint='https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent',
            external_ai_model='gemini-3.5-flash-lite',
        )
        client = ExternalAiClient(settings)

        body = json.loads(client._request_body({
            'fields': {'patient_name': {'value': ', and the', 'status': 'extracted'}},
            'beneficiary': {'full_name': 'Nicasio Eimar Bustillo'},
        }, []))
        instruction = body['systemInstruction']['parts'][0]['text'].lower()

        self.assertIn('do not mark an identity conflict solely from local ocr', instruction)
        self.assertIn('return the clearly readable conflicting value', instruction)
        self.assertIn('patient', instruction)
        self.assertIn('physician', instruction)
        self.assertIn('search the entire document for the supplied beneficiary name', instruction)
        self.assertIn('another related person', instruction)

    def test_disabled_provider_returns_not_needed_without_client_call(self):
        from ai_processor.external_ai.service import review_document

        client = FakeClient({'status': 'completed'})
        result = review_document(
            replace(self.configured_settings(), external_ai_enabled=False), self.document, [self.image],
            'valid_id', 'OCR', {}, self.local_result, {'decision': 'uncertain'}, {}, client=client,
        )

        self.assertEqual(result['status'], 'not_needed')
        self.assertEqual(result['reason'], 'provider_disabled')
        self.assertEqual(client.calls, [])

    def test_provider_timeout_returns_unavailable_without_raising(self):
        from ai_processor.external_ai.service import review_document

        client = FakeClient({'status': 'unavailable', 'error_code': 'EXTERNAL_AI_TIMEOUT', 'elapsed_ms': 12000, 'attempts': 2, 'payload_mode': 'text'})

        result = review_document(
            self.configured_settings(), self.document, [self.image], 'valid_id', 'OCR', {},
            self.local_result, {'decision': 'uncertain'}, {}, client=client,
        )

        self.assertEqual(result['status'], 'unavailable')
        self.assertEqual(result['error_code'], 'EXTERNAL_AI_TIMEOUT')

    def test_completed_result_is_cached_and_second_call_avoids_client(self):
        from ai_processor.external_ai.service import review_document

        completed = {
            'status': 'completed', 'provider': 'external_chat_completions', 'model': 'test-model',
            'payload_mode': 'text', 'elapsed_ms': 20, 'attempts': 1,
            'assessment': {
                'schema_version': 'external-ai-assessment-v1', 'state': 'support',
                'confidence_band': 'moderate', 'document_type': 'valid_id', 'reasons': [], 'fields': {},
            },
        }
        client = FakeClient(completed)
        arguments = (
            self.configured_settings(), self.document, [self.image], 'valid_id', 'OCR', {},
            self.local_result, {'decision': 'uncertain'}, {},
        )

        first = review_document(*arguments, client=client)
        second = review_document(*arguments, client=client)

        self.assertFalse(first['cache_hit'])
        self.assertTrue(second['cache_hit'])
        self.assertEqual(len(client.calls), 1)

    def test_old_prompt_cache_is_not_reused_for_identity_review(self):
        from ai_processor.external_ai.service import review_document
        from ai_processor.external_ai.state import ExternalAiStateStore, build_cache_key

        settings = self.configured_settings()
        store = ExternalAiStateStore(Path(settings.external_ai_state_root))
        old_key = build_cache_key(
            self.document, settings.external_ai_model, 'external-ai-assessment-v2',
            'text', settings.external_ai_endpoint,
        )
        store.put_cached(old_key, {'status': 'completed', 'assessment': {'state': 'conflict'}})
        client = FakeClient({'status': 'unavailable', 'error_code': 'EXTERNAL_AI_TIMEOUT', 'elapsed_ms': 1, 'attempts': 1})

        result = review_document(
            settings, self.document, [self.image], 'med_cert', 'OCR', {},
            self.local_result, {'decision': 'match'}, {'full_name': 'Nicasio Eimar Bustillo'},
            client=client, state_store=store,
        )

        self.assertEqual(result['status'], 'unavailable')
        self.assertEqual(len(client.calls), 1)

    def test_image_enabled_provider_receives_every_rendered_page(self):
        from ai_processor.external_ai.service import review_document

        completed = {
            'status': 'completed', 'provider': 'google_gemini', 'model': 'gemini-3.5-flash-lite',
            'payload_mode': 'image', 'elapsed_ms': 20, 'attempts': 1,
            'assessment': {
                'schema_version': 'external-ai-assessment-v1', 'state': 'support',
                'confidence_band': 'high', 'document_type': 'valid_id', 'reasons': [], 'fields': {},
                'document_quality': {'state': 'good', 'reason': 'Clear.'},
                'signature_review': {'state': 'visible', 'reason': 'Visible.'},
                'visual_integrity': {'state': 'no_obvious_issue', 'reason': 'No obvious issue.'},
            },
        }
        client = FakeClient(completed)
        settings = replace(
            self.configured_settings(),
            external_ai_endpoint='https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent',
            external_ai_model='gemini-3.5-flash-lite', external_ai_image_enabled=True,
        )
        images = [self.image, self.image.copy(), self.image.copy()]

        result = review_document(
            settings, self.document, images, 'valid_id', 'OCR', {}, self.local_result,
            {'decision': 'match'}, {'full_name': 'Beneficiary Person'}, client=client,
        )

        self.assertEqual(result['status'], 'completed')
        evidence, image_payloads = client.calls[0]
        self.assertEqual(evidence['beneficiary']['full_name'], 'Beneficiary Person')
        self.assertEqual(len(image_payloads), 3)

    def test_gemini_hostname_in_query_does_not_bypass_image_probe(self):
        from ai_processor.external_ai.service import review_document

        client = FakeClient({'status': 'unavailable', 'error_code': 'EXTERNAL_AI_TIMEOUT',
                             'elapsed_ms': 1, 'attempts': 1, 'payload_mode': 'text'})
        settings = replace(
            self.configured_settings(), external_ai_image_enabled=True,
            external_ai_endpoint='https://example.invalid/review?next=generativelanguage.googleapis.com',
        )
        review_document(settings, self.document, [self.image], 'valid_id', 'OCR', {},
                        self.local_result, {'decision': 'match'}, {}, client=client)
        _evidence, image_payloads = client.calls[0]
        self.assertEqual(image_payloads, [])


if __name__ == '__main__':
    unittest.main()
