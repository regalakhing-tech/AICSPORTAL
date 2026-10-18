import json, math, unittest
from ai_processor.database import finish
from ai_processor.tamper_detection.postprocessing import (
    attach_combined_finding, attach_persisted_finding, completed, unavailable,
)

PAGE = {'page': 1, 'output_width': 512, 'output_height': 512, 'tampered_pixel_ratio': .0000434, 'max_tamper_probability': .517117, 'mean_tamper_probability': .076887, 'processing_time_seconds': 20.86}

class Cursor:
    def __init__(self): self.args = None
    def __enter__(self): return self
    def __exit__(self, *_): return False
    def execute(self, _sql, args): self.args = args
class Connection:
    def __init__(self): self.current = Cursor(); self.committed = False
    def cursor(self): return self.current
    def commit(self): self.committed = True

class TamperPersistenceTests(unittest.TestCase):
    def completed(self): return completed([PAGE])
    def test_completed_result_is_canonical_and_numeric(self):
        finding = attach_persisted_finding({'summary': 'phase one', 'checks': {'quality': {}}}, self.completed())
        branch = finding['tamper_detection']; self.assertEqual(branch['status'], 'completed')
        self.assertIsInstance(branch['aggregate']['max_tamper_probability'], float); self.assertEqual(branch['pages'][0]['page'], 1)
        self.assertIn('summary', finding); self.assertNotIn('artifacts', json.dumps(branch)); self.assertNotIn('C:\\', json.dumps(branch))
    def test_unavailable_is_neutral(self):
        branch = attach_persisted_finding({'checks': {}}, unavailable('ADCD_SERVICE_UNAVAILABLE'))['tamper_detection']
        self.assertEqual(branch, {'model': 'ADCD-Net', 'integration_version': 1, 'status': 'unavailable', 'error_code': 'service_unavailable'})
    def test_malformed_is_safe(self):
        branch = attach_persisted_finding({'checks': {}}, {'providers': {'adcd_net': {'status': 'completed'}}})['tamper_detection']
        self.assertEqual(branch['error_code'], 'invalid_response')
    def test_repeated_update_preserves_phase_one_and_replaces_branch(self):
        first = attach_persisted_finding({'summary': 'keep', 'tampering': {'legacy': True}}, unavailable())
        second = attach_persisted_finding(first, self.completed())
        self.assertEqual(second['summary'], 'keep'); self.assertEqual(second['tamper_detection']['status'], 'completed'); self.assertNotIn('tampering', second)
    def test_finish_encodes_valid_json(self):
        connection = Connection(); findings = attach_persisted_finding({'summary': 'phase one'}, self.completed())
        finish(connection, 7, {'status': 'completed', 'extracted_fields': {}, 'findings': findings})
        stored = json.loads(connection.current.args[4]); self.assertEqual(stored['tamper_detection']['pages'][0]['page'], 1); self.assertTrue(connection.committed)
    def test_finish_rejects_nan(self):
        connection = Connection()
        with self.assertRaises(ValueError): finish(connection, 7, {'status': 'completed', 'findings': {'value': math.nan}})

    def test_combined_finding_whitelists_public_evidence(self):
        combined = {
            'status': 'completed', 'integration_version': 2, 'decision': 'review',
            'label': 'Suspected alteration — staff review required',
            'confidence_band': 'moderate',
            'evidence_families': ['gemini_visual', 'semantic_chronology'],
            'indicators': [{
                'type': 'typography_mismatch', 'page': 1,
                'box_2d': [700, 450, 850, 850], 'confidence_band': 'moderate',
                'reason': 'Localized typography differs from nearby peer text.',
                'source': 'gemini', 'private_path': 'C:\\secret\\mask.png',
            }],
            'providers': {
                'gemini': {'status': 'completed', 'secret': 'do-not-save'},
                'semantic_chronology': {'status': 'completed'},
                'local_regional': {'status': 'completed'},
                'adcd_net': {'status': 'completed', 'decision': 'clear'},
            },
        }
        branch = attach_combined_finding({'summary': 'keep'}, combined)['tamper_detection']
        encoded = json.dumps(branch)
        self.assertEqual(branch['decision'], 'review')
        self.assertNotIn('private_path', encoded)
        self.assertNotIn('secret', encoded)

    def test_deterministic_date_conflict_basis_and_label_are_persisted(self):
        combined = {
            'status': 'completed', 'integration_version': 2, 'decision': 'review',
            'review_basis': 'deterministic_chronology_conflict',
            'date_conflict': {'document_date': '2026-09-24', 'conflicting_years': [2021]},
            'label': 'Document date inconsistency — staff review required',
            'confidence_band': 'high', 'evidence_families': ['semantic_chronology'],
            'indicators': [], 'providers': {},
        }
        finding = attach_combined_finding({}, combined)
        branch = finding['tamper_detection']
        self.assertEqual(branch['review_basis'], 'deterministic_chronology_conflict')
        self.assertEqual(branch['date_conflict'], {
            'document_date': '2026-09-24', 'conflicting_years': [2021],
        })
        self.assertEqual(branch['label'], 'Document date inconsistency — staff review required')
        self.assertEqual(
            finding['assessments']['alteration_signal']['label'],
            'Document date inconsistency — staff review required',
        )

    def test_combined_finding_keeps_adcd_aggregate_and_pages(self):
        combined = {
            'status': 'completed', 'integration_version': 2, 'decision': 'clear',
            'label': 'No corroborated alteration signal', 'confidence_band': 'low',
            'evidence_families': ['adcd_net'], 'indicators': [],
            'providers': {'adcd_net': {'status': 'completed', 'decision': 'clear'}},
            'aggregate': {'tampered_pixel_ratio': .01, 'max_tamper_probability': .5,
                          'mean_tamper_probability': .1, 'processing_time_seconds': 1.2},
            'pages': [{**PAGE, 'artifacts': {'signal': {'available': True}, 'threshold': {'available': True, 'regions_found': False}, 'path': 'C:\\private.png'}}], 'threshold_id': 'adcd-review-v1',
        }
        branch = attach_combined_finding({}, combined)['tamper_detection']
        self.assertEqual(branch['aggregate']['tampered_pixel_ratio'], .01)
        self.assertEqual(branch['pages'][0]['page'], 1)
        self.assertEqual(branch['pages'][0]['artifacts'], {'signal': {'available': True}, 'threshold': {'available': True, 'regions_found': False}})

if __name__ == '__main__': unittest.main()
