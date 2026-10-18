import unittest

import cv2
import numpy as np

from ai_processor.image_quality import analyze_document_quality
from ai_processor.verification import evaluate


OCR_TEXT = (
    'NATIONAL ID Full Name JUAN DELA CRUZ Date of Birth January 2 1950 '
    'Sex Male Address San Jose de Buenavista Antique ID Number TEST-000001 Valid Until 2030-12-31'
)
FIELDS = {
    'name': {'value': 'JUAN DELA CRUZ', 'status': 'extracted', 'confidence': .78, 'context': 'Full Name: JUAN DELA CRUZ'},
    'birth_date': {'value': '1950-01-02', 'normalized': '1950-01-02', 'status': 'extracted', 'confidence': .78, 'context': 'Date of Birth: January 2, 1950'},
    'sex': {'value': 'Male', 'normalized': 'male', 'status': 'extracted', 'confidence': .75, 'context': 'Sex: Male'},
    'address': {'value': 'San Jose de Buenavista, Antique', 'status': 'extracted', 'confidence': .68, 'context': 'Address: San Jose de Buenavista, Antique'},
    'id_number': {'value': 'TEST-000001', 'status': 'extracted', 'confidence': .74, 'context': 'ID Number: TEST-000001'},
    'valid_until': {'value': '2030-12-31', 'normalized': '2030-12-31', 'status': 'extracted', 'confidence': .78, 'context': 'Valid Until: 2030-12-31'},
}


def document_image(background=255, foreground=15):
    image = np.full((800, 1200, 3), background, dtype=np.uint8)
    lines = ('NATIONAL IDENTIFICATION CARD', 'Full Name: JUAN DELA CRUZ',
             'Date of Birth: January 2, 1950', 'Sex: Male',
             'Address: San Jose de Buenavista, Antique', 'ID Number: TEST-000001',
             'Valid Until: 2030-12-31')
    for index, line in enumerate(lines):
        cv2.putText(image, line, (70, 100 + index * 85), cv2.FONT_HERSHEY_SIMPLEX,
                    .9, (foreground, foreground, foreground), 2, cv2.LINE_AA)
    return image


class ExposureQualityRegressionTests(unittest.TestCase):
    def test_white_background_id_with_good_ocr_is_acceptable(self):
        quality = analyze_document_quality([document_image()], 30, OCR_TEXT, 86, FIELDS)
        self.assertGreater(quality['pages'][0]['mean_brightness'], 235)
        self.assertEqual(quality['decision'], 'acceptable')
        self.assertTrue(quality['strong_document_evidence'])

    def test_mild_overexposure_with_strong_ocr_is_not_reupload(self):
        quality = analyze_document_quality([document_image(250, 190)], 30, OCR_TEXT, 78, FIELDS)
        self.assertEqual(quality['decision'], 'acceptable')
        self.assertNotEqual(quality['severity'], 'severe')

    def test_moderate_exposure_with_borderline_ocr_needs_review(self):
        quality = analyze_document_quality([document_image(250, 190)], 30, 'ID Name Juan Cruz', 35,
                                           {'name': FIELDS['name']})
        self.assertEqual(quality['decision'], 'staff_review')
        self.assertEqual(quality['issue'], 'readability_concern')

    def test_true_overexposure_with_poor_ocr_needs_reupload(self):
        washed_out = np.full((800, 1200, 3), 254, dtype=np.uint8)
        quality = analyze_document_quality([washed_out], 30, '', 0, {})
        self.assertEqual(quality['decision'], 'reupload')
        self.assertEqual(quality['issue'], 'overexposure')
        self.assertIn('overexposed', quality['reason'])

    def test_true_underexposure_with_poor_ocr_needs_reupload(self):
        dark = np.full((800, 1200, 3), 5, dtype=np.uint8)
        quality = analyze_document_quality([dark], 30, '', 0, {})
        self.assertEqual(quality['decision'], 'reupload')
        self.assertEqual(quality['issue'], 'underexposure')
        self.assertIn('underexposed', quality['reason'])

    def test_severe_blur_with_accidental_characters_needs_reupload(self):
        gradient = np.tile(np.linspace(70, 130, 1200, dtype=np.uint8), (800, 1))
        image = np.dstack([gradient, gradient, gradient])
        quality = analyze_document_quality([image], 30, 'ID', 72, {})
        self.assertEqual(quality['decision'], 'reupload')
        self.assertEqual(quality['issue'], 'blur')

    def test_quality_block_has_no_invented_zero_confidence(self):
        quality = analyze_document_quality([np.full((800, 1200, 3), 254, dtype=np.uint8)], 30, '', 0, FIELDS)
        result = evaluate('valid_id', 'valid_id', 90, quality, FIELDS, 'Juan Dela Cruz', [])
        self.assertEqual(result['status'], 'needs_reupload')
        self.assertIsNone(result['confidence_score'])
        self.assertTrue(result['findings']['structured_extraction']['quality_blocked'])


if __name__ == '__main__':
    unittest.main()
