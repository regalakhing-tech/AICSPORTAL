import unittest

import cv2
import numpy as np

from ai_processor.ocr_engine import prepare_document_candidates


class DocumentPreprocessingTests(unittest.TestCase):
    @staticmethod
    def _page():
        image = np.full((700, 1000, 3), 246, dtype=np.uint8)
        cv2.rectangle(image, (35, 35), (965, 665), (25, 25, 25), 4)
        for y in range(120, 560, 55):
            cv2.rectangle(image, (120, y), (820, y + 12), (35, 35, 35), -1)
        return image

    def test_rotated_card_produces_upright_candidate(self):
        image = cv2.rotate(self._page(), cv2.ROTATE_90_CLOCKWISE)
        candidates = prepare_document_candidates(image)
        self.assertLessEqual(len(candidates), 5)
        self.assertTrue(any(abs(candidate.transform.rotation_degrees) == 90 for candidate in candidates))

    def test_every_candidate_maps_boxes_to_original_coordinates(self):
        image = self._page()
        for candidate in prepare_document_candidates(image):
            box = candidate.transform.to_original((10, 20, 100, 40))
            self.assertEqual(len(box), 4)
            self.assertGreaterEqual(min(box), 0)
            self.assertLessEqual(box[0], image.shape[1])
            self.assertLessEqual(box[2], image.shape[1])
            self.assertLessEqual(box[1], image.shape[0])
            self.assertLessEqual(box[3], image.shape[0])

    def test_candidates_are_bounded_include_clahe_and_do_not_mutate_source(self):
        image = self._page()
        original = image.copy()
        candidates = prepare_document_candidates(image)
        self.assertLessEqual(len(candidates), 5)
        self.assertIn('clahe', {candidate.name for candidate in candidates})
        self.assertTrue(np.array_equal(image, original))
        self.assertTrue(all(not np.shares_memory(candidate.image, image) for candidate in candidates))


if __name__ == '__main__':
    unittest.main()
