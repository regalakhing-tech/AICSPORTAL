import unittest
import cv2
import numpy as np

from ai_processor.tamper_detection.regional import analyze_regional_inconsistency


def fixture(edited=False):
    image = np.full((500, 800, 3), 238, dtype=np.uint8)
    rows = []
    for index, text in enumerate(('Date Admitted: April 11, 2021', 'Date Issued: April 15, 2021', 'Date Discharged: April 16, 2021')):
        y = 100 + index * 100
        scale = 0.72
        thickness = 1
        if edited and index == 1:
            cv2.rectangle(image, (40, y - 38), (420, y + 14), (255, 255, 255), -1)
            scale = 1.15
            thickness = 3
        cv2.putText(image, text, (50, y), cv2.FONT_HERSHEY_SIMPLEX, scale, (35, 35, 35), thickness, cv2.LINE_AA)
        height = 62 if edited and index == 1 else 38
        rows.append({'text': text, 'box': [40, y - height, 430, y + 16], 'confidence': 95.0})
    return image, {'pages': [{'page_number': 1, 'lines': rows}]}


class TamperRegionalTests(unittest.TestCase):
    def test_size_and_stroke_outliers_in_date_region_need_review(self):
        image, metadata = fixture(edited=True)
        result = analyze_regional_inconsistency([image], metadata)
        self.assertEqual(result['decision'], 'review')
        self.assertGreaterEqual(len(result['indicators'][0]['metrics']), 2)
        self.assertEqual(result['indicators'][0]['page'], 1)

    def test_clean_mixed_field_text_is_not_flagged(self):
        image, metadata = fixture(edited=False)
        result = analyze_regional_inconsistency([image], metadata)
        self.assertEqual(result['decision'], 'clear')

    def test_color_and_spacing_outliers_are_detected_together(self):
        image, metadata = fixture(edited=False)
        line = metadata['pages'][0]['lines'][1]
        box = line['box']
        image[box[1]:box[3], box[0]:box[2]] = 238
        cv2.putText(image, 'Date Issued:   April   15,   2021', (50, 200),
                    cv2.FONT_HERSHEY_SIMPLEX, .72, (180, 45, 20), 1, cv2.LINE_AA)
        result = analyze_regional_inconsistency([image], metadata)
        self.assertEqual(result['decision'], 'review')
        self.assertIn('ink_chroma', result['indicators'][0]['metrics'])


if __name__ == '__main__':
    unittest.main()
