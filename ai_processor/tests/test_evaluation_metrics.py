import json
import tempfile
import unittest
from pathlib import Path

from ai_processor.calibration import load_calibration_report, profile_is_calibrated
from ai_processor.document_profiles import get_profile
from ai_processor.evaluation import evaluate_manifest, evaluate_operational_records


FIXTURE_MANIFEST = Path(__file__).parent / 'fixtures' / 'validation' / 'manifest.json'


class EvaluationMetricsTests(unittest.TestCase):
    def test_metrics_are_separate_by_profile_and_component(self):
        report = evaluate_manifest(FIXTURE_MANIFEST)
        medical = report.profiles['med_cert']
        self.assertIsNotNone(medical.type_precision)
        self.assertIsNotNone(medical.false_reupload_rate)
        self.assertIsNotNone(medical.doctamper_precision)
        self.assertNotEqual(medical.type_precision, medical.doctamper_precision)

    def test_manifest_contains_no_absolute_paths_or_real_reference_numbers(self):
        raw = FIXTURE_MANIFEST.read_text(encoding='utf-8')
        self.assertNotRegex(raw, r'[A-Za-z]:\\')
        self.assertNotRegex(raw, r'AICS-20\d{2}-[A-F0-9]{6}')

    def test_missing_calibration_report_fails_closed(self):
        report = load_calibration_report(Path('missing-calibration.json'))
        self.assertFalse(profile_is_calibrated(get_profile('valid_id'), report))

    def test_reviewed_report_with_zero_false_reuploads_can_pass_gate(self):
        report = {'profiles': {'valid_id': {
            'case_count': 20, 'type_precision': .95,
            'false_reupload_rate': 0.0, 'calibrated': True,
        }}}
        self.assertTrue(profile_is_calibrated(get_profile('valid_id'), report))

    def test_absolute_fixture_path_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            manifest = Path(directory) / 'manifest.json'
            manifest.write_text(json.dumps({'schema_version': 1, 'cases': [{'path': r'C:\\private\\id.png', 'profile': 'valid_id'}]}), encoding='utf-8')
            with self.assertRaises(ValueError):
                evaluate_manifest(manifest)

    def test_operational_metrics_report_provider_reliability_and_staff_feedback(self):
        records = [
            {'external_invoked': True, 'cache_hit': False, 'external_status': 'completed', 'external_ms': 120},
            {'external_invoked': True, 'cache_hit': True, 'external_status': 'completed', 'external_ms': 30},
            {'external_invoked': True, 'cache_hit': False, 'external_status': 'unavailable', 'external_ms': 12000},
            {'external_invoked': False, 'cache_hit': False, 'external_status': 'not_needed', 'external_ms': 0},
        ]
        feedback = [
            {'agreement_state': 'agreed'},
            {'agreement_state': 'overridden'},
            {'agreement_state': 'not_applicable'},
        ]

        metrics = evaluate_operational_records(records, feedback)

        self.assertEqual(metrics['record_count'], 4)
        self.assertEqual(metrics['external_invocation_rate'], 0.75)
        self.assertEqual(metrics['external_cache_hit_rate'], 0.25)
        self.assertEqual(metrics['external_unavailable_rate'], 0.25)
        self.assertEqual(metrics['external_median_ms'], 120.0)
        self.assertEqual(metrics['external_p95_ms'], 10812.0)
        self.assertEqual(metrics['staff_agreement_rate'], 0.5)
        self.assertEqual(metrics['staff_override_rate'], 0.5)
        self.assertFalse(metrics['validated_model_accuracy'])

    def test_operational_metrics_are_safe_for_empty_inputs(self):
        metrics = evaluate_operational_records([], [])
        self.assertIsNone(metrics['external_invocation_rate'])
        self.assertIsNone(metrics['external_p95_ms'])
        self.assertIsNone(metrics['staff_agreement_rate'])
        self.assertFalse(metrics['validated_model_accuracy'])


if __name__ == '__main__':
    unittest.main()
