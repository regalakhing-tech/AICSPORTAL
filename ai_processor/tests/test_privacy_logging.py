import inspect, unittest
import ai_processor.document_loader as loader
import ai_processor.ocr_engine as ocr_engine

class PrivacyLoggingTests(unittest.TestCase):
    def test_document_loader_does_not_log_raw_upload_paths_or_pdf_exception_text(self):
        source = inspect.getsource(loader)
        self.assertNotIn('raw=%r', source)
        self.assertNotIn('resolved=%s', source)
        self.assertNotIn('path=%s', source)
        self.assertNotIn('exception_message=%s', source)

    def test_ocr_diagnostics_do_not_log_private_machine_paths(self):
        source = inspect.getsource(ocr_engine)
        self.assertNotIn('raw_tesseract_path=%s', source)
        self.assertNotIn('resolved_tesseract_path=%s', source)

if __name__ == '__main__': unittest.main()
