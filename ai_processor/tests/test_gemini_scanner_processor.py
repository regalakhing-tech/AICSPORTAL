import unittest
from contextlib import ExitStack
from dataclasses import replace
from pathlib import Path
from unittest.mock import MagicMock, patch

import numpy as np

from ai_processor.gemini_scanner.prompt import CHECK_KEYS
from ai_processor.processor import process_one
from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence

JOB = {
    'id': 205, 'document_id': 89, 'request_id': 5, 'file_path': 'AICS/Applicants/uploads/TEST/med.jpg',
    'expected_document_type': 'med_cert', 'beneficiary_complete_name': 'Nicasio Eimar Bustillo',
    'beneficiary_birth_date': '1960-03-04',
}
LOCAL_RESULT = {
    'status': 'needs_staff_review', 'detected_document_type': 'registration_form', 'confidence_score': 35,
    'extracted_fields': {}, 'findings': {'reasons': ['Local OCR was uncertain.'], 'assessments': {}},
}
DEATH_CERT_SCAN = {
    'status': 'completed', 'provider': 'google_gemini', 'model': 'gemini-2.5-flash', 'elapsed_ms': 3000,
    'cache_hit': False, 'page_count': 1,
    'scan': {
        'document_type': 'death_cert', 'document_title': 'CERTIFICATE OF DEATH', 'type_confidence': 96,
        'primary_person': {'name': 'Jennifer Angeles Ramos', 'role': 'deceased', 'birth_date': None,
                           'sex': None, 'address': None},
        'beneficiary_name_found': False, 'document_date': '2008-06-28', 'issuer': None,
        'checks': {key: {'state': 'pass', 'reason': 'ok', 'missing': []} for key in CHECK_KEYS},
        'indicators': [], 'sample_or_specimen': {'detected': False, 'reason': ''}, 'summary': 'Death certificate.',
    },
}


class GeminiProcessorTests(unittest.TestCase):
    def _run(self, gemini_result, job=None):
        stubs = {
            'connect': MagicMock(), 'claim_document': MagicMock(return_value=dict(job or JOB)),
            'resolve_path': MagicMock(return_value=Path('med.jpg')),
            'load_images': MagicMock(return_value=[np.full((40, 40, 3), 255, dtype=np.uint8)]),
            'extract_document_text': MagicMock(return_value=('text', 40.0, {'ocr_boxes_by_page': []})),
            'classify_document': MagicMock(return_value={'decision': 'uncertain', 'detected_type': 'registration_form',
                                                         'confidence_band': 'moderate'}),
            'classify_ocr_candidates': MagicMock(side_effect=lambda text, expected, meta, primary: primary),
            'extract': MagicMock(return_value={}),
            'resolve_safe_roi_fields': MagicMock(return_value=(None, set(), {})),
            'analyze_document_quality': MagicMock(return_value={'decision': 'acceptable'}),
            'prior_fields': MagicMock(return_value=[]),
            'evaluate': MagicMock(return_value={**LOCAL_RESULT, 'findings': dict(LOCAL_RESULT['findings'])}),
            'update_stage': MagicMock(), 'finish': MagicMock(),
            'scan_document': (MagicMock(side_effect=gemini_result) if isinstance(gemini_result, Exception)
                              else MagicMock(return_value=gemini_result)),
            'review_document': MagicMock(return_value={'status': 'not_needed', 'payload_mode': 'text', 'elapsed_ms': 0}),
            'analyze_chronology': MagicMock(return_value={'status': 'completed', 'decision': 'clear', 'indicators': []}),
            'analyze_regional_inconsistency': MagicMock(return_value={'status': 'completed', 'decision': 'clear', 'indicators': []}),
            'inspect_file': MagicMock(return_value={'status': 'completed', 'family': 'metadata', 'decision': 'clear', 'indicators': [], 'facts': {}}),
            'inspect_qr': MagicMock(return_value={'status': 'completed', 'family': 'qr', 'decision': 'clear', 'indicators': [], 'facts': []}),
            'analyze_image_forensics': MagicMock(return_value={'status': 'completed', 'family': 'local_visual', 'decision': 'clear', 'indicators': []}),
            'analyze_tampering': MagicMock(return_value={'providers': {'adcd_net': {'status': 'unavailable', 'pages': []}}}),
            'persist_private_artifacts': MagicMock(side_effect=lambda result, *_: result),
            'persist_review_pages': MagicMock(return_value=[]),
            'adcd_service_ready': MagicMock(return_value=False),
        }
        with ExitStack() as stack:
            for name, stub in stubs.items():
                stack.enter_context(patch(f'ai_processor.processor.{name}', stub))
            aggregate = stack.enter_context(patch('ai_processor.processor.aggregate_tamper_evidence',
                                                  wraps=aggregate_tamper_evidence))
            self.assertTrue(process_one(document_id=89))
        saved = stubs['finish'].call_args.args[2]
        return saved, stubs, aggregate

    def test_gemini_scan_drives_the_saved_result_and_skips_text_provider(self):
        saved, stubs, aggregate = self._run(DEATH_CERT_SCAN)
        stubs['review_document'].assert_not_called()
        scan_args = stubs['scan_document'].call_args.args
        self.assertEqual(scan_args[3], 'med_cert')
        self.assertEqual(scan_args[4]['full_name'], 'Nicasio Eimar Bustillo')
        self.assertEqual(aggregate.call_args.args[0]['payload_mode'], 'image')
        self.assertEqual(saved['status'], 'flagged_inconsistency')
        self.assertEqual(saved['detected_document_type'], 'death_cert')
        self.assertEqual(saved['model_service'], 'gemini_vision_primary')
        findings = saved['findings']
        self.assertEqual(findings['verdict']['headline'], 'Wrong document uploaded')
        self.assertEqual(list(findings['thesis_checks']), list(CHECK_KEYS))
        self.assertIn('gemini_scan', findings['performance']['stage_ms'])
        self.assertEqual(findings['tamper_detection']['providers']['gemini']['status'], 'completed')

    def test_gemini_unavailable_uses_text_provider_and_local_verdict(self):
        saved, stubs, _ = self._run({'status': 'unavailable', 'error_code': 'GEMINI_NOT_CONFIGURED'})
        stubs['review_document'].assert_called_once()
        self.assertEqual(saved['status'], 'needs_staff_review')
        self.assertEqual(saved['findings']['verdict']['source'], 'local')
        self.assertIn('GEMINI_NOT_CONFIGURED', saved['findings']['verdict']['notice'])

    def test_scanner_exception_never_fails_the_review(self):
        saved, stubs, _ = self._run(RuntimeError('boom'))
        stubs['review_document'].assert_called_once()
        self.assertEqual(saved['findings']['verdict']['source'], 'local')
        self.assertIn('GEMINI_INTERNAL_ERROR', saved['findings']['verdict']['notice'])

    def test_worker_routes_valid_id_to_applicant_and_legacy_to_beneficiary(self):
        from ai_processor.config import SETTINGS

        job = {**JOB, 'expected_document_type': 'valid_id',
               'applicant_first_name': 'Juan', 'applicant_middle_name': '',
               'applicant_last_name': 'Dela Cruz', 'applicant_birth_date': '1980-01-02'}
        for mode, expected_name in (('applicant', 'Juan Dela Cruz'), ('legacy', 'Nicasio Eimar Bustillo')):
            with self.subTest(mode=mode), patch('ai_processor.processor.SETTINGS',
                                                replace(SETTINGS, valid_id_owner_mode=mode)):
                _, stubs, _ = self._run(DEATH_CERT_SCAN, job)
            self.assertEqual(stubs['evaluate'].call_args.args[5], expected_name)
            self.assertEqual(stubs['scan_document'].call_args.args[4]['full_name'], expected_name)


if __name__ == '__main__':
    unittest.main()
