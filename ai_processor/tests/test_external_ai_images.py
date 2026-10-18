import base64
import unittest

import numpy as np


class ExternalAiImageTests(unittest.TestCase):
    def test_prepared_image_is_bounded_jpeg(self):
        from ai_processor.external_ai.images import prepare_image_data_url

        image = np.zeros((2400, 3200, 3), dtype=np.uint8)
        data_url = prepare_image_data_url(image)
        encoded = data_url.split(',', 1)[1]

        self.assertTrue(data_url.startswith('data:image/jpeg;base64,'))
        self.assertLessEqual(len(base64.b64decode(encoded)), 1_500_000)

    def test_empty_image_is_rejected(self):
        from ai_processor.external_ai.images import ExternalAiImageError, prepare_image_data_url

        with self.assertRaisesRegex(ExternalAiImageError, 'EXTERNAL_AI_IMAGE_PREPARATION_FAILED'):
            prepare_image_data_url(np.zeros((0, 0, 3), dtype=np.uint8))


if __name__ == '__main__':
    unittest.main()
