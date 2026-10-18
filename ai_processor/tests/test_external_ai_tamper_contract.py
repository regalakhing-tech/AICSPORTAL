import json
import unittest
from dataclasses import replace

from ai_processor.config import SETTINGS
from ai_processor.external_ai.contracts import ExternalAiContractError, normalize_assessment
from ai_processor.external_ai.client import ExternalAiClient


def assessment(tamper=None):
    return {
        'schema_version': 'external-ai-assessment-v2',
        'state': 'review',
        'confidence_band': 'moderate',
        'document_type': 'med_cert',
        'reasons': ['ALTERATION_REVIEW_SIGNAL'],
        'fields': {'document_date': {'value': 'September 24, 2026', 'state': 'review'}},
        'document_quality': {'state': 'usable', 'reason': 'Readable.'},
        'signature_review': {'state': 'visible', 'reason': 'Visible.'},
        'visual_integrity': {'state': 'review_needed', 'reason': 'Inspect the date area.'},
        'tamper_assessment': tamper or {
            'state': 'review_signal', 'confidence_band': 'high',
            'summary': 'Date area needs staff inspection.',
            'indicators': [{
                'type': 'typography_mismatch', 'page': 1,
                'box_2d': [780, 520, 835, 790], 'confidence_band': 'moderate',
                'reason': 'Date weight and edge sharpness differ from nearby filled fields.',
            }],
        },
    }


class ExternalAiTamperContractTests(unittest.TestCase):
    def test_image_response_keeps_valid_localized_indicator(self):
        result = normalize_assessment(assessment(), payload_mode='image', page_count=1)
        self.assertEqual(result['tamper_assessment']['indicators'][0]['box_2d'], [780, 520, 835, 790])

    def test_text_response_cannot_claim_visual_tampering(self):
        result = normalize_assessment(assessment(), payload_mode='text', page_count=0)
        self.assertEqual(result['tamper_assessment']['state'], 'unavailable')
        self.assertEqual(result['tamper_assessment']['indicators'], [])

    def test_invalid_page_or_box_is_rejected(self):
        payload = assessment({
            'state': 'review_signal', 'confidence_band': 'high', 'summary': 'Review.',
            'indicators': [{'type': 'pasted_region_boundary', 'page': 2,
                            'box_2d': [900, 100, 800, 1200],
                            'confidence_band': 'high', 'reason': 'Invalid region.'}],
        })
        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment(payload, payload_mode='image', page_count=1)

    def test_forgery_conclusion_key_is_rejected_recursively(self):
        payload = assessment()
        payload['tamper_assessment']['indicators'][0]['forged'] = True
        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment(payload, payload_mode='image', page_count=1)

    def test_accusatory_tamper_text_is_rejected(self):
        payload = assessment()
        payload['tamper_assessment']['summary'] = 'This is definitely forged.'
        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment(payload, payload_mode='image', page_count=1)

    def test_accusatory_visual_integrity_text_is_rejected(self):
        payload = assessment()
        payload['visual_integrity']['reason'] = 'This document is fake.'
        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment(payload, payload_mode='image', page_count=1)

    def test_conclusive_tampered_statement_is_rejected(self):
        payload = assessment()
        payload['tamper_assessment']['summary'] = 'Document was tampered.'
        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment(payload, payload_mode='image', page_count=1)

    def test_unknown_keys_and_reason_codes_are_rejected(self):
        payload = assessment()
        payload['raw_response'] = 'unsafe'
        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment(payload, payload_mode='image', page_count=1)
        payload = assessment()
        payload['reasons'] = ['MADE_UP_REASON']
        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment(payload, payload_mode='image', page_count=1)

    def test_gemini_schema_and_instruction_require_bounded_peer_comparison(self):
        settings = replace(
            SETTINGS,
            external_ai_enabled=True,
            external_ai_endpoint='https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
            external_ai_model='gemini-2.5-flash',
            external_ai_api_key='test-key',
            external_ai_image_enabled=True,
        )
        body = json.loads(ExternalAiClient(settings)._request_body(
            {'fields': {'document_date': {'value': None}}}, ['data:image/jpeg;base64,AA==']
        ))
        schema = body['generationConfig']['responseSchema']
        instruction = body['systemInstruction']['parts'][0]['text'].lower()
        self.assertIn('tamper_assessment', schema['required'])
        self.assertIn('external-ai-assessment-v2', body['systemInstruction']['parts'][0]['text'])
        self.assertEqual(schema['properties']['tamper_assessment']['properties']['indicators']['maxItems'], 12)
        self.assertIn('nearby peer text', instruction)
        self.assertIn('font difference alone', instruction)
        self.assertIn('never conclude forgery', instruction)


if __name__ == '__main__':
    unittest.main()
