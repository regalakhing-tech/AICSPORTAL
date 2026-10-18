import unittest

import cv2
import numpy as np

from ai_processor.tamper_detection.local_visual import analyze_image_forensics
from ai_processor.tamper_detection.overlay import detect_digital_text_overlay

PAPER_WARM = (205, 190, 238)   # BGR: pinkish paper under warm light
INK_PHOTO = (75, 62, 98)       # BGR: dark grey ink with the same warm cast
BODY = [
    'I HEREBY CERTIFY that the above statements are complete and true',
    'in every detail, and that in the consequence to the disease or',
    'disability above specified the patient was ill and the bedside',
    'watcher was unable to go on duty on account of illness from',
    'April 11 - 15, 2021 inclusive, and that her claim is meritorious.',
    'The applicant was confined at the house/hospital from April 11',
    'Nature of Disease: Hypertensive Heart Disease, not specified',
]


def _photographed_page(extra=None, heading=False, pen=False, seed=3):
    """A 'phone photo' of a form: warm paper, warm ink, blur and noise."""
    image = np.zeros((1100, 900, 3), np.uint8)
    image[:] = PAPER_WARM
    if heading:
        cv2.putText(image, 'MEDICAL CERTIFICATE', (170, 90), cv2.FONT_HERSHEY_DUPLEX, 1.5, INK_PHOTO, 4, cv2.LINE_AA)
    for index, line in enumerate(BODY * 2):
        cv2.putText(image, line, (40, 170 + index * 52), cv2.FONT_HERSHEY_SIMPLEX, 0.72, INK_PHOTO, 2, cv2.LINE_AA)
    cv2.putText(image, '(Date)', (420, 960), cv2.FONT_HERSHEY_SIMPLEX, 0.72, INK_PHOTO, 2, cv2.LINE_AA)
    if pen:
        points = np.array([[430, 1030], [470, 1000], [520, 1045], [570, 995], [640, 1040], [700, 1005]], np.int32)
        cv2.polylines(image, [points], False, (150, 70, 30), 3, cv2.LINE_AA)  # blue ballpoint
    image = cv2.GaussianBlur(image, (0, 0), 0.9)
    noise = np.random.default_rng(seed).normal(0, 4, image.shape)
    image = np.clip(image.astype(np.float32) + noise, 0, 255).astype(np.uint8)
    if extra:
        extra(image)  # drawn after the "camera": crisp, pure colour, no noise
    return image


def _paste_date(image):
    cv2.putText(image, 'September 24, 2026', (520, 960), cv2.FONT_HERSHEY_DUPLEX, 0.8, (0, 0, 0), 2, cv2.LINE_AA)


def _overlaps(box, region):
    y1, x1, y2, x2 = box
    ry1, rx1, ry2, rx2 = region
    return not (x2 < rx1 or rx2 < x1 or y2 < ry1 or ry2 < y1)


class DigitalTextOverlayTests(unittest.TestCase):
    def test_pasted_pure_black_date_on_photo_is_found(self):
        indicators = detect_digital_text_overlay(_photographed_page(extra=_paste_date), page_number=1)
        self.assertEqual(len(indicators), 1)
        indicator = indicators[0]
        self.assertEqual(indicator['type'], 'digital_text_overlay')
        self.assertEqual(indicator['confidence_band'], 'high')
        self.assertEqual(indicator['page'], 1)
        date_region = [round(930 * 1000 / 1100), round(510 * 1000 / 900), round(975 * 1000 / 1100), round(820 * 1000 / 900)]
        self.assertTrue(_overlaps(indicator['box_2d'], date_region), indicator['box_2d'])
        self.assertIn('darker', indicator['reason'])

    def test_clean_photo_has_no_overlay(self):
        self.assertEqual(detect_digital_text_overlay(_photographed_page(heading=True, pen=True), page_number=1), [])

    def test_clean_scan_with_all_black_text_has_no_overlay(self):
        image = np.full((1100, 900, 3), 255, np.uint8)
        for index, line in enumerate(BODY * 2):
            cv2.putText(image, line, (40, 170 + index * 52), cv2.FONT_HERSHEY_SIMPLEX, 0.72, (0, 0, 0), 2, cv2.LINE_AA)
        _paste_date(image)
        self.assertEqual(detect_digital_text_overlay(image, page_number=1), [])

    def test_dark_background_pattern_outside_text_lines_is_ignored(self):
        def cloth_and_date(image):
            for x in range(40, 860, 60):  # dark red ornaments on the cloth under the paper
                cv2.putText(image, 'OO@@', (x, 1085), cv2.FONT_HERSHEY_DUPLEX, 0.6, (20, 10, 120), 2)
            _paste_date(image)
        page = _photographed_page(extra=cloth_and_date)
        text_lines = [[40, 150 + index * 52, 860, 180 + index * 52] for index in range(len(BODY) * 2)] + [[410, 935, 830, 970]]
        indicators = detect_digital_text_overlay(page, page_number=1, text_boxes=text_lines)
        self.assertEqual(len(indicators), 1)
        self.assertLess(indicators[0]['box_2d'][2], 960)

    def test_page_without_confident_text_lines_is_not_judged(self):
        self.assertEqual(detect_digital_text_overlay(_photographed_page(extra=_paste_date), page_number=1, text_boxes=[]), [])

    def test_screenshot_with_black_heading_and_grey_text_is_not_judged(self):
        image = np.full((1100, 900, 3), 250, np.uint8)  # noise-free screen background
        cv2.putText(image, 'Welcome, Juanita Alorro!', (60, 110), cv2.FONT_HERSHEY_DUPLEX, 1.3, (10, 10, 10), 3, cv2.LINE_AA)
        for index, line in enumerate(BODY * 2):
            cv2.putText(image, line, (40, 190 + index * 52), cv2.FONT_HERSHEY_SIMPLEX, 0.72, (120, 110, 105), 2, cv2.LINE_AA)
        self.assertEqual(detect_digital_text_overlay(image, page_number=1), [])

    def test_too_little_text_is_not_judged(self):
        image = np.full((400, 400, 3), PAPER_WARM, np.uint8)
        _paste_date(image)
        self.assertEqual(detect_digital_text_overlay(image, page_number=1), [])

    def test_image_forensics_reports_the_overlay_first(self):
        result = analyze_image_forensics([_photographed_page(extra=_paste_date)], {'pages': [{'lines': [{'text': 'September 24, 2026 date line', 'box': [410, 930, 830, 975], 'confidence': 95}] + [{'text': line, 'box': [40, 150 + index * 52, 860, 180 + index * 52], 'confidence': 90} for index, line in enumerate(BODY * 2)]}]})
        self.assertEqual(result['decision'], 'review')
        self.assertEqual(result['indicators'][0]['type'], 'digital_text_overlay')


if __name__ == '__main__':
    unittest.main()


class OverlayPersistenceTests(unittest.TestCase):
    def test_overlay_indicator_survives_aggregation_and_storage(self):
        from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence
        from ai_processor.tamper_detection.postprocessing import attach_combined_finding
        overlay = {'type': 'digital_text_overlay', 'page': 1, 'box_2d': [802, 538, 823, 773],
                   'confidence_band': 'high', 'reason': 'Flat digital black text.'}
        clear = {'status': 'completed', 'decision': 'clear', 'indicators': []}
        local = [{'status': 'completed', 'family': 'local_visual', 'decision': 'review', 'indicators': [overlay]}]
        combined = aggregate_tamper_evidence({'status': 'unavailable'}, clear, clear, {}, local_results=local)
        saved = attach_combined_finding({}, combined)['tamper_detection']
        self.assertIn('digital_text_overlay', [item['type'] for item in saved['indicators']])
