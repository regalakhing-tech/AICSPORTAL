import copy
import unittest

from ai_processor.tamper_detection.postprocessing import attach_combined_finding, attach_persisted_finding, completed, unavailable


BASE = {
    'assessments': {
        'document_match': {'state': 'pass', 'label': 'Document type matches', 'explanation': 'Local check.'},
        'readability': {'state': 'pass', 'label': 'Readable', 'explanation': 'Local check.'},
        'applicant_match': {'state': 'pass', 'label': 'Applicant details match', 'explanation': 'Local check.'},
        'alteration_signal': {'state': 'unavailable', 'label': 'Pending', 'explanation': 'Pending.'},
    }
}


class TamperIndependenceTests(unittest.TestCase):
    def test_review_signal_changes_only_alteration_assessment(self):
        original = copy.deepcopy(BASE)
        signal = completed([{'page': 1, 'output_width': 512, 'output_height': 512,
                             'tampered_pixel_ratio': .02, 'max_tamper_probability': .91,
                             'mean_tamper_probability': .2, 'processing_time_seconds': 1.0}])
        attached = attach_persisted_finding(original, signal)
        self.assertEqual(attached['assessments']['document_match']['state'], 'pass')
        self.assertEqual(attached['assessments']['applicant_match']['state'], 'pass')
        self.assertEqual(attached['assessments']['alteration_signal']['state'], 'review')
        self.assertIn('not proof', attached['assessments']['alteration_signal']['explanation'].lower())

    def test_unavailable_signal_preserves_other_assessments(self):
        attached = attach_persisted_finding(copy.deepcopy(BASE), unavailable())
        self.assertEqual(attached['assessments']['document_match']['state'], 'pass')
        self.assertEqual(attached['assessments']['alteration_signal']['state'], 'unavailable')

    def test_combined_review_uses_neutral_staff_review_wording(self):
        combined = {'status': 'completed', 'integration_version': 2, 'decision': 'review',
                    'label': 'Suspected alteration — staff review required',
                    'confidence_band': 'moderate', 'evidence_families': ['gemini_visual', 'local_regional'],
                    'indicators': [], 'providers': {}}
        attached = attach_combined_finding(copy.deepcopy(BASE), combined)
        assessment = attached['assessments']['alteration_signal']
        self.assertEqual(assessment['state'], 'review')
        self.assertEqual(assessment['label'], 'Suspected alteration — staff review required')
        self.assertNotIn('fraud', assessment['explanation'].lower())
        self.assertNotIn('forg', assessment['explanation'].lower())


if __name__ == '__main__':
    unittest.main()
