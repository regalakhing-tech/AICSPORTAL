import json
import unittest
import numpy as np

from ai_processor.tamper_detection.chronology import analyze_chronology


def metadata(rows):
    return {'pages': [{'page_number': 1, 'lines': [
        {'text': text, 'box': box, 'confidence': 95.0} for text, box in rows
    ]}]}


class TamperChronologyTests(unittest.TestCase):
    def test_large_medical_date_gap_is_review_candidate(self):
        result = analyze_chronology('med_cert', metadata([
            ('confined from April 11 - 15, 2021 inclusive', [40, 600, 900, 650]),
            ('Date: September 24, 2026', [500, 1300, 900, 1360]),
        ]), {'document_date': {'status': 'extracted', 'normalized': '2026-09-24', 'page': 1}},
            [np.zeros((1600, 1000, 3), dtype=np.uint8)])
        self.assertEqual(result['decision'], 'review')
        self.assertEqual(result['indicators'][0]['type'], 'date_context_conflict')
        self.assertEqual(result['indicators'][0]['page'], 1)
        self.assertEqual(result['indicators'][0]['box_2d'], [812, 500, 850, 900])
        self.assertNotRegex(json.dumps(result).lower(), r'forg|fraud|fake|authentic')

    def test_repeated_medical_event_year_conflicting_with_signature_date_is_strong(self):
        result = analyze_chronology('med_cert', metadata([
            ('attending physician from April 11 - 15, 2021 inclusive', [40, 600, 900, 650]),
            ('confined at hospital from April 11 - 15, 2021 inclusive', [40, 900, 900, 950]),
            ('illness from April 11 - 15, 2021 inclusive', [40, 1100, 900, 1150]),
            ('(Date) September 24, 2026', [500, 1300, 900, 1360]),
        ]), {'document_date': {'status': 'extracted', 'normalized': '2026-09-24', 'page': 1}},
            [np.zeros((1600, 1000, 3), dtype=np.uint8)])
        self.assertEqual(result['decision'], 'review')
        self.assertEqual(result['confidence_band'], 'high')
        self.assertTrue(result['repeated_conflict'])
        self.assertEqual(result['date_conflict'], {
            'document_date': '2026-09-24',
            'conflicting_years': [2021],
        })

    def test_nearby_event_and_issue_dates_are_clear(self):
        result = analyze_chronology('med_cert', metadata([
            ('Examined on February 1, 2026', [40, 600, 500, 650]),
            ('Date Issued: February 3, 2026', [500, 1300, 900, 1360]),
        ]), {'document_date': {'status': 'extracted', 'normalized': '2026-02-03', 'page': 1}})
        self.assertEqual(result['decision'], 'clear')

    def test_birth_year_is_not_treated_as_document_timeline_conflict(self):
        result = analyze_chronology('med_cert', metadata([
            ('Date of Birth: January 5, 1980', [40, 500, 600, 550]),
            ('Date Issued: September 24, 2026', [500, 1300, 900, 1360]),
        ]), {'document_date': {'status': 'extracted', 'normalized': '2026-09-24', 'page': 1}},
            [np.zeros((1600, 1000, 3), dtype=np.uint8)])
        self.assertEqual(result['decision'], 'clear')

    def test_unlabeled_historical_year_in_prose_is_not_a_timeline_conflict(self):
        result = analyze_chronology('med_cert', metadata([
            ('The clinic building opened in 1998 and serves the community.', [40, 500, 900, 550]),
            ('Date Issued: September 24, 2026', [500, 1300, 900, 1360]),
        ]), {'document_date': {'status': 'extracted', 'normalized': '2026-09-24', 'page': 1}},
            [np.zeros((1600, 1000, 3), dtype=np.uint8)])
        self.assertEqual(result['decision'], 'clear')

    def test_unrelated_valid_until_year_is_not_a_medical_timeline_conflict(self):
        result = analyze_chronology('med_cert', metadata([
            ('License valid until 2030', [40, 500, 600, 550]),
            ('Date Issued: September 24, 2026', [500, 1300, 900, 1360]),
        ]), {'document_date': {'status': 'extracted', 'normalized': '2026-09-24', 'page': 1}},
            [np.zeros((1600, 1000, 3), dtype=np.uint8)])
        self.assertEqual(result['decision'], 'clear')


if __name__ == '__main__':
    unittest.main()
