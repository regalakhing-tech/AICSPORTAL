"""The focused "is this image AI-made?" check and how it reaches the verdict."""
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace

import numpy as np

from ai_processor.gemini_scanner.ai_generation import check_ai_generation, normalize_check
from ai_processor.gemini_scanner.prompt import CHECK_KEYS
from ai_processor.gemini_scanner.verdict import apply_verdict
from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence, ai_image_assessment
from ai_processor.tamper_detection.postprocessing import attach_combined_finding

CLEAR = {'status': 'completed', 'decision': 'clear', 'indicators': []}
SIGNS = [
    {'type': 'garbled_text', 'page': 1, 'box_2d': [40, 80, 70, 400], 'description': 'The heading reads "DEPARTMENT OF ACCOUNT".'},
    {'type': 'malformed_mark', 'page': 1, 'box_2d': [20, 20, 120, 140], 'description': 'The seal ring text is unreadable.'},
]


def _ai_check(verdict='ai_generated', confidence=90, signs=SIGNS):
    raw = {'verdict': verdict, 'confidence': confidence, 'signs': signs, 'real_capture_evidence': [], 'summary': 'x'}
    return {'status': 'completed', 'model': 'gemini-test', 'check': normalize_check(raw, 1)}


def _metadata(facts=None):
    return {'status': 'completed', 'family': 'metadata', 'decision': 'clear', 'indicators': [], 'facts': facts or {}}


def _combined(ai_check, facts=None):
    return aggregate_tamper_evidence({'status': 'unavailable'}, CLEAR, CLEAR, {},
                                     local_results=[_metadata(facts)], ai_check=ai_check)


def _scan():
    return {
        'document_type': 'billing_statement', 'document_title': 'STATEMENT OF ACCOUNT', 'type_confidence': 95,
        'primary_person': {'name': 'Juan Dela Cruz', 'role': 'patient', 'birth_date': None, 'sex': None, 'address': None},
        'beneficiary_name_found': True, 'document_date': '2026-10-02', 'issuer': 'Hospital',
        'checks': {key: {'state': 'pass', 'reason': f'{key} ok', 'missing': []} for key in CHECK_KEYS},
        'indicators': [], 'sample_or_specimen': {'detected': False, 'reason': ''}, 'totals': [], 'summary': 'Bill.',
    }


def _verdict(combined):
    local = {'status': 'likely_valid', 'detected_document_type': 'billing_statement', 'findings': {'reasons': []}}
    return apply_verdict(local, 'billing_statement', {'status': 'completed', 'model': 'm', 'scan': _scan()},
                         combined, {'full_name': 'Juan Dela Cruz'})


class NormalizeTests(unittest.TestCase):
    def test_unknown_sign_types_and_verdicts_are_dropped(self):
        check = normalize_check({'verdict': 'definitely', 'confidence': 300,
                                 'signs': [{'type': 'bogus'}, SIGNS[0]], 'real_capture_evidence': 'x'}, 1)
        self.assertEqual(check['verdict'], 'uncertain')
        self.assertEqual(check['confidence'], 100)
        self.assertEqual(check['sign_kinds'], ['garbled_text'])
        self.assertEqual(check['real_capture_evidence'], [])

    def test_accusatory_wording_is_replaced(self):
        check = normalize_check({'verdict': 'ai_generated', 'confidence': 80, 'signs': [
            {'type': 'garbled_text', 'page': 1, 'box_2d': [1, 1, 9, 9], 'description': 'This is a fake bill.'}]}, 1)
        self.assertNotIn('fake', check['signs'][0]['description'].lower())


class AssessmentTests(unittest.TestCase):
    def test_confident_answer_with_two_kinds_of_signs_fails(self):
        self.assertEqual(ai_image_assessment(_ai_check(), {})['state'], 'fail')

    def test_one_kind_of_sign_only_asks_for_review(self):
        self.assertEqual(ai_image_assessment(_ai_check(signs=SIGNS[:1]), {})['state'], 'review')

    def test_uncertain_with_a_sign_asks_for_review(self):
        self.assertEqual(ai_image_assessment(_ai_check('uncertain', 60, SIGNS[:1]), {})['state'], 'review')

    def test_real_capture_and_low_confidence_raise_nothing(self):
        self.assertIsNone(ai_image_assessment(_ai_check('real_capture', 95, []), {}))
        self.assertIsNone(ai_image_assessment(_ai_check('ai_generated', 40), {}))
        self.assertIsNone(ai_image_assessment({'status': 'unavailable'}, {}))

    def test_phone_camera_details_cap_it_at_review(self):
        result = ai_image_assessment(_ai_check(), {'camera': 'Xiaomi 23108RN04Y', 'date_taken': '2026:10:02 14:41:00'})
        self.assertEqual(result['state'], 'review')
        self.assertIn('Xiaomi', result['reason'])


class DecisionTests(unittest.TestCase):
    def test_ai_made_image_is_flagged_with_its_own_headline(self):
        combined = _combined(_ai_check())
        self.assertEqual(combined['review_basis'], 'ai_generated_image')
        self.assertIn('gemini_ai_check', combined['evidence_families'])
        self.assertTrue(all(item['source'] == 'gemini' for item in combined['indicators']))
        result = _verdict(combined)
        untampered = result['findings']['thesis_checks']['untampered']
        self.assertEqual(untampered['state'], 'fail')
        self.assertIn('DEPARTMENT OF ACCOUNT', untampered['reason'])
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['verdict']['headline'], 'Possibly AI-generated image')

    def test_weaker_answer_needs_staff_review(self):
        result = _verdict(_combined(_ai_check(signs=SIGNS[:1])))
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'review')
        self.assertEqual(result['status'], 'needs_staff_review')
        self.assertEqual(result['findings']['verdict']['headline'], 'Possibly AI-generated image')

    def test_visual_signs_are_not_mistaken_for_a_content_credential(self):
        verdict = _verdict(_combined(_ai_check(signs=SIGNS[:1])))['findings']['verdict']
        self.assertNotEqual(verdict['headline'], 'Made with generative AI')

    def test_real_photo_stays_clean(self):
        result = _verdict(_combined(_ai_check('real_capture', 92, [])))
        self.assertEqual(result['status'], 'likely_valid')

    def test_saved_finding_keeps_the_summary(self):
        saved = attach_combined_finding({}, _combined(_ai_check()))['tamper_detection']
        self.assertEqual(saved['review_basis'], 'ai_generated_image')
        self.assertEqual(saved['ai_generation']['state'], 'fail')
        self.assertIn('AI-generated', saved['label'])
        self.assertTrue(any(item['type'] == 'ai_generated_content' for item in saved['indicators']))


class _Client:
    def __init__(self):
        self.calls = 0

    def scan(self, request_text, urls, page_count, *, instruction=None, schema=None, normalizer=None):
        self.calls += 1
        raw = {'verdict': 'real_capture', 'confidence': 88, 'signs': [], 'real_capture_evidence': ['sensor noise'], 'summary': 'ok'}
        return {'status': 'completed', 'model': 'gemini-test', 'elapsed_ms': 5, 'scan': normalizer(raw, page_count)}


class ServiceTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        self.document = self.root / 'bill.jpg'
        self.document.write_bytes(b'not really a jpeg, only hashed')
        self.images = [np.full((60, 80, 3), 200, dtype=np.uint8)]
        self.settings = SimpleNamespace(gemini_image_enabled=True, gemini_image_api_key='test', gemini_ai_check_enabled=True,
                                        gemini_ai_check_model='gemini-test', gemini_ai_check_fallback_models=(),
                                        gemini_model='lite', gemini_fallback_models=(), gemini_timeout_seconds=45,
                                        external_ai_state_root=str(self.root / 'state'))

    def test_result_is_cached(self):
        client = _Client()
        first = check_ai_generation(self.settings, self.document, self.images, client=client)
        second = check_ai_generation(self.settings, self.document, self.images, client=client)
        self.assertEqual(first['check']['verdict'], 'real_capture')
        self.assertTrue(second['cache_hit'])
        self.assertEqual(client.calls, 1)

    def test_disabled_or_missing_file_never_raises(self):
        self.settings.gemini_ai_check_enabled = False
        self.assertEqual(check_ai_generation(self.settings, self.document, self.images)['error_code'], 'AI_CHECK_DISABLED')
        self.settings.gemini_ai_check_enabled = True
        missing = check_ai_generation(self.settings, self.root / 'gone.jpg', self.images, client=_Client())
        self.assertEqual(missing['status'], 'unavailable')


if __name__ == '__main__':
    unittest.main()
