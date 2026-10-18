"""Valid ID identity belongs to the applicant in every assistance workflow."""
import json
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from ai_processor.consistency import build_consistency_check
from ai_processor.database import get_request_verification_context
from ai_processor.field_extractor import extract
from ai_processor.gemini_scanner.prompt import CHECK_KEYS, build_request_text
from ai_processor.gemini_scanner.verdict import apply_verdict


JOB = {
    'applicant_first_name': 'Juan', 'applicant_middle_name': '',
    'applicant_last_name': 'Dela Cruz', 'applicant_birth_date': '1980-01-02',
    'beneficiary_complete_name': 'Maria Dela Cruz', 'beneficiary_birth_date': '1990-03-04',
    'assistance_types_json': '["medical"]',
}


def scan(expected, name, readable='pass'):
    roles = {'valid_id': 'cardholder', 'med_cert': 'patient'}
    return {
        'document_type': expected, 'document_title': expected, 'type_confidence': 95,
        'primary_person': {'name': name, 'role': roles[expected], 'birth_date': None,
                           'sex': None, 'address': None},
        'beneficiary_name_found': False, 'document_date': None, 'issuer': None,
        'checks': {key: {'state': readable if key == 'readable' else 'pass',
                         'reason': 'checked', 'missing': []} for key in CHECK_KEYS},
        'indicators': [], 'sample_or_specimen': {'detected': False, 'reason': ''},
        'summary': 'Document reviewed.',
    }


def verdict(expected, name, context, readable='pass'):
    return apply_verdict(
        {'status': 'completed', 'findings': {'checks': {}, 'assessments': {}, 'reasons': []}},
        expected, {'status': 'completed', 'model': 'test', 'scan': scan(expected, name, readable)},
        {'decision': 'clear'}, context,
    )


class DocumentOwnershipTests(unittest.TestCase):
    def test_global_owner_rule_and_legacy_switch(self):
        from ai_processor.document_ownership import document_owner, select_reference_context
        context = get_request_verification_context(JOB)
        for assistance in ('medical', 'burial', 'special_cases', 'educational_basic',
                           'disaster_victims', 'former_rebels', 'high_inflation_affected', 'future'):
            request = {**context, 'assistance_types': [assistance]}
            self.assertEqual(document_owner('valid_id', 'applicant'), 'applicant')
            selected = select_reference_context('valid_id', request, 'applicant')
            self.assertEqual(selected['full_name'], 'Juan Dela Cruz')
            self.assertEqual(selected['reference_owner'], 'applicant')
            self.assertNotIn('Maria Dela Cruz', json.dumps(selected))
        self.assertEqual(document_owner('valid_id', 'legacy'), 'beneficiary')
        self.assertEqual(document_owner('valid_identification_card', 'applicant'), 'applicant')
        self.assertEqual(select_reference_context('valid_id', context, 'legacy')['full_name'], 'Maria Dela Cruz')
        self.assertEqual(document_owner('med_cert', 'applicant'), 'beneficiary')
        self.assertEqual(select_reference_context('med_cert', context, 'applicant')['full_name'], 'Maria Dela Cruz')

    def test_gemini_id_name_uses_applicant_and_beneficiary_documents_stay_beneficiary(self):
        from ai_processor.document_ownership import select_reference_context
        context = get_request_verification_context(JOB)
        applicant = select_reference_context('valid_id', context, 'applicant')
        beneficiary = select_reference_context('med_cert', context, 'applicant')
        self.assertEqual(verdict('valid_id', 'Juan Dela Cruz', applicant)['status'], 'likely_valid')
        self.assertNotEqual(verdict('valid_id', 'Maria Dela Cruz', applicant)['status'], 'likely_valid')
        self.assertEqual(verdict('med_cert', 'Maria Dela Cruz', beneficiary)['status'], 'likely_valid')
        self.assertNotEqual(verdict('med_cert', 'Juan Dela Cruz', beneficiary)['status'], 'likely_valid')
        self.assertEqual(verdict('valid_id', 'Juan Dela Cruz', applicant, 'fail')['status'], 'needs_reupload')
        wrong_slot = apply_verdict(
            {'status': 'completed', 'findings': {'checks': {}, 'assessments': {}, 'reasons': []}},
            'valid_id', {'status': 'completed', 'model': 'test', 'scan': scan('med_cert', 'Juan Dela Cruz')},
            {'decision': 'clear'}, applicant,
        )
        self.assertEqual(wrong_slot['status'], 'flagged_inconsistency')
        same_person = select_reference_context('valid_id', get_request_verification_context({
            **JOB, 'beneficiary_complete_name': 'Juan Dela Cruz',
        }), 'applicant')
        self.assertEqual(same_person['reference_owner'], 'applicant')
        self.assertEqual(verdict('valid_id', 'Juan Dela Cruz', same_person)['status'], 'likely_valid')

    def test_legacy_id_keeps_beneficiary_comparison_and_burial_exception(self):
        from ai_processor.document_ownership import select_reference_context
        context = get_request_verification_context({**JOB, 'assistance_types_json': '["burial"]'})
        selected = select_reference_context('valid_id', context, 'legacy')
        self.assertEqual(verdict('valid_id', 'Maria Dela Cruz', selected)['status'], 'likely_valid')
        self.assertEqual(verdict('valid_id', 'Juan Dela Cruz', selected)['status'], 'needs_staff_review')

    def test_prompt_never_sends_beneficiary_identity_for_applicant_id(self):
        from ai_processor.document_ownership import select_reference_context
        from ai_processor.gemini_scanner.prompt import APPLICANT_ID_INSTRUCTION, SYSTEM_INSTRUCTION
        context = get_request_verification_context(JOB)
        selected = select_reference_context('valid_id', context, 'applicant')
        payload = json.loads(build_request_text('valid_id', selected, 1))
        self.assertEqual(payload['applicant_full_name'], 'Juan Dela Cruz')
        self.assertNotIn('beneficiary_full_name', payload)
        self.assertIn('This Valid ID belongs to the applicant', APPLICANT_ID_INSTRUCTION)
        self.assertIn('supplied beneficiary name', SYSTEM_INSTRUCTION)
        legacy = json.loads(build_request_text('valid_id', context, 1))
        self.assertEqual(legacy['beneficiary_full_name'], 'Maria Dela Cruz')

    def test_local_consistency_uses_applicant_for_id_without_using_id_for_beneficiary(self):
        from ai_processor.document_ownership import select_reference_context
        context = get_request_verification_context(JOB)
        applicant = select_reference_context('valid_id', context, 'applicant')
        beneficiary = select_reference_context('med_cert', context, 'applicant')
        juan_id = extract('FULL NAME: JUAN DELA CRUZ', 'valid_id')
        maria_id = extract('FULL NAME: MARIA DELA CRUZ', 'valid_id')
        self.assertEqual(build_consistency_check('valid_id', juan_id, applicant)['comparisons']['full_name']['status'], 'match')
        self.assertEqual(build_consistency_check('valid_id', maria_id, applicant)['comparisons']['full_name']['status'], 'mismatch')
        sibling = {'_document_type': 'valid_id', '_quality_usable': True, '_type_match': True, **juan_id}
        medical = extract('MEDICAL CERTIFICATE\nPATIENT NAME: MARIA DELA CRUZ', 'med_cert')
        checked = build_consistency_check('med_cert', medical, beneficiary, [sibling])
        self.assertFalse(checked['trusted_valid_id_used'])
        no_name = build_consistency_check('valid_id', {}, applicant)
        self.assertEqual(no_name['comparisons']['full_name']['status'], 'not_present')

    def test_external_fallback_receives_only_applicant_identity_for_id(self):
        from ai_processor.document_ownership import select_reference_context
        from ai_processor.external_ai.client import ExternalAiClient
        from ai_processor.external_ai.contracts import build_evidence
        from ai_processor.external_ai.postprocessing import enforce_beneficiary_comparisons
        from ai_processor.config import SETTINGS

        context = select_reference_context('valid_id', get_request_verification_context(JOB), 'applicant')
        evidence = build_evidence('valid_id', '', {}, {'findings': {}}, context)
        self.assertEqual(evidence['applicant']['full_name'], 'Juan Dela Cruz')
        self.assertNotIn('beneficiary', evidence)
        body = json.loads(ExternalAiClient(SETTINGS)._request_body(evidence, []))
        self.assertIn('supplied applicant object', body['messages'][0]['content'])
        provider = {'status': 'completed', 'assessment': {'state': 'review', 'fields': {
            'name': {'value': 'Maria Dela Cruz', 'state': 'support'},
        }}}
        result = enforce_beneficiary_comparisons(provider, context)
        self.assertEqual(result['assessment']['fields']['name']['state'], 'conflict')

    def test_gemini_cache_separates_legacy_and_applicant_id_results(self):
        from ai_processor.document_ownership import select_reference_context
        from ai_processor.gemini_scanner.service import scan_document

        class Store:
            def __init__(self): self.values = {}
            def circuit_is_open(self): return False
            def get_cached(self, key): return self.values.get(key)
            def put_cached(self, key, value): self.values[key] = value
            def record_success(self): pass

        class Client:
            def __init__(self): self.calls = []
            def scan(self, request_text, images, count, **kwargs):
                self.calls.append((json.loads(request_text), kwargs))
                return {'status': 'completed', 'scan': {'document_type': 'valid_id'}}

        store, client = Store(), Client()
        settings = SimpleNamespace(gemini_image_enabled=True, gemini_image_api_key='test',
                                   gemini_model='test', gemini_fallback_models=(), external_ai_state_root='unused')
        context = get_request_verification_context({**JOB, 'beneficiary_complete_name': 'Juan Dela Cruz'})
        with tempfile.TemporaryDirectory() as directory:
            document = Path(directory) / 'id.png'
            document.write_bytes(b'synthetic id')
            with patch('ai_processor.gemini_scanner.service.prepare_image_data_url', return_value='data:image/png;base64,eA=='):
                legacy = scan_document(settings, document, [object()], 'valid_id',
                                       select_reference_context('valid_id', context, 'legacy'), client, store)
                applicant = scan_document(settings, document, [object()], 'valid_id',
                                          select_reference_context('valid_id', context, 'applicant'), client, store)
                cached = scan_document(settings, document, [object()], 'valid_id',
                                       select_reference_context('valid_id', context, 'applicant'), client, store)
        self.assertEqual(len(client.calls), 2)
        self.assertFalse(legacy['cache_hit'])
        self.assertFalse(applicant['cache_hit'])
        self.assertTrue(cached['cache_hit'])
        self.assertEqual(len(store.values), 2)
        self.assertEqual(client.calls[0][1], {})
        self.assertIn('This Valid ID belongs to the applicant', client.calls[1][1]['instruction'])

    def test_external_cache_separates_legacy_and_applicant_id_results(self):
        from ai_processor.document_ownership import select_reference_context
        from ai_processor.external_ai.service import review_document

        class Store:
            def __init__(self): self.values = {}
            def circuit_is_open(self): return False
            def image_capability_verified(self, *_): return False
            def get_cached(self, key): return self.values.get(key)
            def put_cached(self, key, value): self.values[key] = value
            def record_success(self): pass

        class Client:
            def __init__(self): self.calls = []
            def assess(self, evidence, image_data_urls=None):
                self.calls.append(evidence)
                return {'status': 'completed', 'assessment': {'state': 'support', 'fields': {}}}

        store, client = Store(), Client()
        settings = SimpleNamespace(external_ai_enabled=True, external_ai_api_key='test',
                                   external_ai_endpoint='https://api.groq.com/openai/v1/chat/completions',
                                   external_ai_model='test', external_ai_fallback_models=(),
                                   external_ai_timeout_seconds=12, external_ai_image_enabled=False,
                                   external_ai_state_root='unused')
        context = get_request_verification_context({**JOB, 'beneficiary_complete_name': 'Juan Dela Cruz'})
        with tempfile.TemporaryDirectory() as directory:
            document = Path(directory) / 'id.png'
            document.write_bytes(b'synthetic id')
            args = (settings, document, [], 'valid_id', '', {}, {'status': 'completed', 'findings': {}},
                    {'decision': 'match'})
            legacy = review_document(*args, select_reference_context('valid_id', context, 'legacy'),
                                     client=client, state_store=store)
            applicant = review_document(*args, select_reference_context('valid_id', context, 'applicant'),
                                        client=client, state_store=store)
            cached = review_document(*args, select_reference_context('valid_id', context, 'applicant'),
                                     client=client, state_store=store)
        self.assertFalse(legacy['cache_hit'])
        self.assertFalse(applicant['cache_hit'])
        self.assertTrue(cached['cache_hit'])
        self.assertEqual(len(client.calls), 2)
        self.assertIn('beneficiary', client.calls[0])
        self.assertIn('applicant', client.calls[1])


if __name__ == '__main__':
    unittest.main()
