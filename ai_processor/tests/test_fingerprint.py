"""Fingerprints the PHP duplicate check compares (includes/duplicate-detection.php)."""
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock

import cv2
import numpy as np

from ai_processor.database import save_fingerprint
from ai_processor.fingerprint import dhash_distance, document_fingerprint, file_sha256, image_dhash

PHP_SAME_PHOTO_MAX_DISTANCE = 24


def _form(seed: int):
    """A photo-like page: printed lines plus camera noise."""
    rng = np.random.default_rng(seed)
    page = np.full((1200, 900, 3), 235, dtype=np.uint8)
    for row in range(80, 1150, 45):
        width = int(rng.integers(200, 800))
        cv2.rectangle(page, (50, row), (50 + width, row + 12), (40, 40, 40), -1)
    return np.clip(page + rng.normal(0, 6, page.shape), 0, 255).astype(np.uint8)


def _resaved(image, max_edge=960, quality=55):
    scale = max_edge / max(image.shape[:2])
    small = cv2.resize(image, (round(image.shape[1] * scale), round(image.shape[0] * scale)), interpolation=cv2.INTER_AREA)
    _, data = cv2.imencode('.jpg', small, [cv2.IMWRITE_JPEG_QUALITY, quality])
    return cv2.imdecode(data, cv2.IMREAD_COLOR)


class FingerprintTests(unittest.TestCase):
    def test_hash_is_64_hex_characters(self):
        value = image_dhash(_form(1))
        self.assertRegex(value, r'^[0-9a-f]{64}$')
        self.assertIsNone(image_dhash(np.zeros((0, 0, 3), dtype=np.uint8)))

    def test_resaved_copy_stays_within_the_php_threshold(self):
        original = _form(1)
        self.assertLessEqual(dhash_distance(image_dhash(original), image_dhash(_resaved(original))), PHP_SAME_PHOTO_MAX_DISTANCE)

    def test_different_document_is_far_apart(self):
        self.assertGreater(dhash_distance(image_dhash(_form(1)), image_dhash(_form(2))), PHP_SAME_PHOTO_MAX_DISTANCE * 2)

    def test_document_fingerprint_never_raises(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'doc.jpg'
            path.write_bytes(b'bytes')
            fingerprint = document_fingerprint(path, [_form(3)])
            self.assertEqual(fingerprint['sha256'], file_sha256(path))
            self.assertIn('dhash', fingerprint)
            self.assertEqual(document_fingerprint(Path(folder) / 'missing.jpg', []), {})

    def test_save_is_optional_when_the_columns_are_missing(self):
        connection = MagicMock()
        connection.cursor.return_value.__enter__.return_value.execute.side_effect = RuntimeError('Unknown column')
        self.assertFalse(save_fingerprint(connection, 5, {'sha256': 'a' * 64}))
        connection.rollback.assert_called_once()
        self.assertFalse(save_fingerprint(connection, None, {'sha256': 'a' * 64}))


if __name__ == '__main__':
    unittest.main()
