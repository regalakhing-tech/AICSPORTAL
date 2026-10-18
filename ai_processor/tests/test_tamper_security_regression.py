import json
import unittest

from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence
from ai_processor.tamper_detection.postprocessing import sanitize_combined_finding


BOX = [700, 450, 850, 850]


def _external(kind=None, reason='Inspect localized field rendering.'):
    indicators = [] if kind is None else [{
        'type': kind, 'page': 1, 'box_2d': BOX,
        'confidence_band': 'moderate', 'reason': reason,
    }]
    return {'status': 'completed', 'payload_mode': 'image', 'assessment': {
        'tamper_assessment': {'state': 'review_signal' if indicators else 'no_signal',
                              'confidence_band': 'moderate', 'summary': 'Advisory.',
                              'indicators': indicators}}}


def _local(family, kind=None):
    indicators = [] if kind is None else [{
        'type': kind, 'page': 1, 'box_2d': BOX, 'confidence_band': 'moderate',
        'reason': 'Independent local evidence.',
    }]
    return {'status': 'completed', 'family': family,
            'decision': 'review' if indicators else 'clear', 'indicators': indicators}


def _adcd(status='completed', review=False):
    if status != 'completed':
        return {'providers': {'adcd_net': {'status': 'unavailable', 'error': 'ADCD_SERVICE_UNAVAILABLE'}}}
    return {'providers': {'adcd_net': {
        'status': 'completed', 'decision': 'review' if review else 'clear',
        'tampered_pixel_ratio': .02 if review else 0.0,
        'max_tamper_probability': .91 if review else .2,
        'mean_tamper_probability': .2 if review else .05,
        'processing_time_seconds': 1.0, 'pages': [],
    }}}


def run_synthetic_case(case_name):
    if case_name == 'edited_date':
        parts = (_external('typography_mismatch'),
                 _local('semantic_chronology', 'date_context_conflict'),
                 _local('local_regional', 'typography_mismatch'), _adcd())
    elif case_name == 'edited_name':
        parts = (_external('pasted_region_boundary'), _local('semantic_chronology'),
                 _local('local_regional', 'typography_mismatch'), _adcd())
    elif case_name == 'clean_multifont':
        parts = (_external('typography_mismatch'), _local('semantic_chronology'),
                 _local('local_regional'), _adcd())
    else:
        raise ValueError(case_name)
    return sanitize_combined_finding(aggregate_tamper_evidence(*parts))


class TamperSecurityRegressionTests(unittest.TestCase):
    def test_changed_date_requires_two_independent_evidence_families(self):
        combined = run_synthetic_case('edited_date')
        self.assertEqual(combined['decision'], 'review')
        self.assertGreaterEqual(len(set(combined['evidence_families'])), 2)

    def test_clean_multifont_document_does_not_escalate(self):
        self.assertEqual(run_synthetic_case('clean_multifont')['decision'], 'clear')

    def test_persisted_data_excludes_sensitive_payloads(self):
        combined = run_synthetic_case('edited_name')
        combined['raw_response'] = 'data:image/jpeg;base64,secret'
        combined['api_key'] = 'secret'
        serialized = json.dumps(sanitize_combined_finding(combined))
        for forbidden in ('data:image', 'raw_response', 'api_key', 'C:\\xampp\\aics_private'):
            self.assertNotIn(forbidden, serialized)

    def test_unavailable_components_and_malformed_boxes_remain_neutral(self):
        external = {'status': 'unavailable', 'payload_mode': 'image'}
        malformed = _local('local_regional', 'typography_mismatch')
        malformed['indicators'][0]['box_2d'] = [900, 100, 800, 200]
        combined = aggregate_tamper_evidence(
            external, _local('semantic_chronology'), malformed, _adcd('unavailable'),
        )
        self.assertEqual(combined['decision'], 'clear')
        stored = sanitize_combined_finding(combined)
        self.assertNotIn('box_2d', stored['indicators'][0])

    def test_html_like_and_unicode_reason_is_plain_persisted_text(self):
        combined = aggregate_tamper_evidence(
            _external('pasted_region_boundary', '<script>alert(1)</script> — inspect'),
            _local('semantic_chronology'), _local('local_regional', 'typography_mismatch'), _adcd(),
        )
        stored = sanitize_combined_finding(combined)
        self.assertIn('<script>', stored['indicators'][0]['reason'])
        self.assertNotIn('raw_html', stored['indicators'][0])


if __name__ == '__main__':
    unittest.main()
