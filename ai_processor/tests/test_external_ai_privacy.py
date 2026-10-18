import io
import logging
import unittest

from ai_processor.tests.test_external_ai_client import SequenceOpener, completion, settings


class ExternalAiPrivacyTests(unittest.TestCase):
    def test_logs_do_not_include_key_evidence_or_image(self):
        from ai_processor.external_ai.client import ExternalAiClient

        stream = io.StringIO()
        handler = logging.StreamHandler(stream)
        logger = logging.getLogger('ai_processor.external_ai.client')
        logger.addHandler(handler)
        logger.setLevel(logging.INFO)
        try:
            ExternalAiClient(settings(), opener=SequenceOpener(completion()), sleeper=lambda _seconds: None).assess(
                {'ocr_text': 'PRIVATE OCR SAMPLE', 'applicant': 'Test Applicant'},
                image_data_url='data:image/jpeg;base64,PRIVATE_IMAGE_SAMPLE',
            )
        finally:
            logger.removeHandler(handler)

        output = stream.getvalue()
        self.assertNotIn('replacement-test-key', output)
        self.assertNotIn('PRIVATE OCR SAMPLE', output)
        self.assertNotIn('Test Applicant', output)
        self.assertNotIn('PRIVATE_IMAGE_SAMPLE', output)
        self.assertIn('status=completed', output)


if __name__ == '__main__':
    unittest.main()
