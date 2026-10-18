import unittest

from ai_processor.document_profiles import get_profile
from ai_processor.ocr_engine import OcrResult, select_ocr_evidence


MED_CERT_TEXT = '''MEDICAL CERTIFICATE
Sample District Hospital
Patient Name: ANA REYES
Diagnosis: information present
Attending Physician: SAMPLE DOCTOR'''


class OcrCandidateConsensusTests(unittest.TestCase):
    def test_high_raw_confidence_without_anchors_loses_to_supported_candidate(self):
        noise = OcrResult('original', 'random unrelated text', 93, {}, [])
        supported = OcrResult('clahe', MED_CERT_TEXT, 76, {}, [])
        selected = select_ocr_evidence([noise, supported], get_profile('med_cert'))
        self.assertEqual(selected.candidate_name, 'clahe')
        self.assertGreater(selected.score.anchor_coverage, 0)

    def test_conflicting_identity_values_become_uncertain(self):
        first = OcrResult('original', 'Philippine Identification Card\nFull Name: ANA REYES', 88, {}, [])
        second = OcrResult('clahe', 'Philippine Identification Card\nFull Name: ANNA REYES', 86, {}, [])
        selected = select_ocr_evidence([first, second], get_profile('valid_id'))
        self.assertEqual(selected.fields['name']['status'], 'uncertain')
        self.assertIsNone(selected.fields['name'].get('value'))

    def test_agreeing_normalized_identity_keeps_provenance(self):
        first = OcrResult('original', 'Philippine Identification Card\nFull Name: ANA M. REYES', 88, {}, [])
        second = OcrResult('clahe', 'Philippine Identification Card\nFull Name: ANA M REYES', 84, {}, [])
        selected = select_ocr_evidence([first, second], get_profile('valid_id'))
        self.assertEqual(selected.fields['name']['status'], 'extracted')
        self.assertGreaterEqual(len(selected.fields['name']['provenance']), 2)


if __name__ == '__main__':
    unittest.main()
