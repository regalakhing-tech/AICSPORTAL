import unittest

from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence, apply_tamper_recommendation


BOX = [700, 450, 850, 850]


def external(indicator=None, confidence='moderate'):
    indicators = [] if indicator is None else [{
        'type': indicator, 'page': 1, 'box_2d': BOX,
        'confidence_band': confidence, 'reason': 'Inspect localized date area.',
    }]
    return {'status': 'completed', 'payload_mode': 'image', 'assessment': {
        'tamper_assessment': {'state': 'review_signal' if indicators else 'no_signal',
                              'confidence_band': confidence, 'summary': 'Advisory.',
                              'indicators': indicators}}}


def local(family, indicator=None):
    indicators = [] if indicator is None else [{
        'type': indicator, 'page': 1, 'box_2d': BOX,
        'confidence_band': 'moderate', 'reason': 'Independent local evidence.', 'metrics': ['height', 'sharpness'],
    }]
    return {'status': 'completed', 'family': family,
            'decision': 'review' if indicators else 'clear', 'indicators': indicators}


def adcd(review=False):
    return {'providers': {'adcd_net': {
        'status': 'completed', 'decision': 'review' if review else 'clear',
        'threshold_id': 'adcd-review-v1', 'pages': [{'page': 1, 'output_width': 512, 'output_height': 512,
        'tampered_pixel_ratio': .02 if review else 0.0, 'max_tamper_probability': .91 if review else .2,
        'mean_tamper_probability': .2 if review else .05, 'processing_time_seconds': 1.0}],
        'tampered_pixel_ratio': .02 if review else 0.0, 'max_tamper_probability': .91 if review else .2,
        'mean_tamper_probability': .2 if review else .05, 'processing_time_seconds': 1.0,
    }}}


class TamperAggregationTests(unittest.TestCase):
    def test_single_gemini_font_signal_does_not_escalate(self):
        result = aggregate_tamper_evidence(external('typography_mismatch'), local('semantic_chronology'), local('local_regional'), adcd())
        self.assertEqual(result['decision'], 'clear')

    def test_low_confidence_gemini_signal_cannot_corroborate(self):
        weak = external('pasted_region_boundary', confidence='low')
        result = aggregate_tamper_evidence(weak, local('semantic_chronology'),
                                           local('local_regional', 'typography_mismatch'), adcd())
        self.assertEqual(result['decision'], 'clear')

    def test_disjoint_date_and_visual_regions_do_not_corroborate(self):
        chronology = local('semantic_chronology', 'date_context_conflict')
        chronology['indicators'][0]['box_2d'] = [100, 100, 200, 300]
        visual = local('local_regional', 'typography_mismatch')
        visual['indicators'][0]['box_2d'] = [700, 450, 850, 850]
        result = aggregate_tamper_evidence(external(), chronology, visual, adcd())
        self.assertEqual(result['decision'], 'clear')

    def test_repeated_high_confidence_chronology_conflict_flags_inconsistency(self):
        chronology = local('semantic_chronology', 'date_context_conflict')
        chronology.update({
            'confidence_band': 'high', 'repeated_conflict': True,
            'date_conflict': {'document_date': '2026-09-24', 'conflicting_years': [2021]},
        })
        chronology['indicators'].append(dict(chronology['indicators'][0]))
        combined = aggregate_tamper_evidence(external(), chronology, local('local_regional'), adcd())
        self.assertEqual(combined['decision'], 'review')
        self.assertEqual(combined['review_basis'], 'deterministic_chronology_conflict')
        self.assertEqual(combined['date_conflict']['conflicting_years'], [2021])
        applied = apply_tamper_recommendation({'status': 'completed', 'findings': {}}, combined)
        self.assertEqual(applied['status'], 'flagged_inconsistency')
        preserved = apply_tamper_recommendation({'status': 'needs_reupload', 'findings': {}}, combined)
        self.assertEqual(preserved['status'], 'needs_reupload')

    def test_date_conflict_and_overlapping_local_visual_signal_escalate(self):
        result = aggregate_tamper_evidence(
            external('typography_mismatch'), local('semantic_chronology', 'date_context_conflict'),
            local('local_regional', 'typography_mismatch'), adcd(),
        )
        self.assertEqual(result['decision'], 'review')
        self.assertGreaterEqual(len(result['evidence_families']), 2)

    def test_uncalibrated_adcd_does_not_turn_unrelated_visual_signal_into_review(self):
        alone = aggregate_tamper_evidence(external(), local('semantic_chronology'), local('local_regional'), adcd(True))
        corroborated = aggregate_tamper_evidence(external('pasted_region_boundary'), local('semantic_chronology'), local('local_regional'), adcd(True))
        self.assertEqual(alone['decision'], 'clear')
        self.assertEqual(corroborated['decision'], 'clear')

    def test_review_signal_requires_staff_review_but_preserves_stronger_status(self):
        combined = aggregate_tamper_evidence(
            external('typography_mismatch'), local('semantic_chronology', 'date_context_conflict'),
            local('local_regional', 'typography_mismatch'), adcd(),
        )
        self.assertEqual(apply_tamper_recommendation({'status': 'completed', 'findings': {}}, combined)['status'], 'needs_staff_review')
        self.assertEqual(apply_tamper_recommendation({'status': 'needs_reupload', 'findings': {}}, combined)['status'], 'needs_reupload')


if __name__ == '__main__':
    unittest.main()
