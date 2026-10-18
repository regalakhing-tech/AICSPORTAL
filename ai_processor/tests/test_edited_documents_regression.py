"""Regression: local detectors on realistic generated forgeries (no network).

Uses tests/edited-documents/generate.py (Windows fonts). The full pipeline with
AICS AI is exercised by tests/edited-documents/run_tests.py.
"""
import importlib.util
import tempfile
import unittest
from pathlib import Path

from ai_processor.config import SETTINGS
from ai_processor.document_loader import load_images
from ai_processor.ocr_engine import extract_document_text
from ai_processor.tamper_detection.file_inspection import inspect_file
from ai_processor.tamper_detection.local_visual import analyze_image_forensics

GENERATOR = Path(__file__).resolve().parents[2] / 'tests' / 'edited-documents' / 'generate.py'


def _load_generator():
    spec = importlib.util.spec_from_file_location('edited_documents_generate', GENERATOR)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@unittest.skipUnless(Path(r'C:\Windows\Fonts\arial.ttf').is_file(), 'needs Windows fonts')
class EditedDocumentRegressionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.folder = tempfile.TemporaryDirectory()
        _load_generator().build_all(Path(cls.folder.name))
        cls.root = Path(cls.folder.name)
        cls.found = {}
        for name in ('clean-01-phone-photo.jpg', 'clean-03-cool-light-photo.jpg', 'edit-01-canva-typed-date.png',
                     'edit-02-pasted-beneficiary-name.jpg', 'edit-03-cloned-signature.jpg',
                     'id-clean-01-national-id.jpg', 'id-edit-02-drivers-birthdate-typed.jpg'):
            images = load_images(cls.root / name, 3, '', 60, 0)
            _, _, meta = extract_document_text(images, SETTINGS.tesseract_path, 45)
            cls.found[name] = {item['type'] for item in analyze_image_forensics(images, meta)['indicators']}

    @classmethod
    def tearDownClass(cls):
        cls.folder.cleanup()

    def test_canva_typed_date_is_found_and_canva_metadata_read(self):
        self.assertIn('digital_text_overlay', self.found['edit-01-canva-typed-date.png'])
        facts = inspect_file(self.root / 'edit-01-canva-typed-date.png')['facts']
        self.assertEqual(facts['editing_software'], 'Canva')

    def test_pasted_name_is_found(self):
        self.assertIn('digital_text_overlay', self.found['edit-02-pasted-beneficiary-name.jpg'])

    def test_cloned_signature_is_found(self):
        self.assertTrue({'copy_move_region', 'repeated_visual_region'} & self.found['edit-03-cloned-signature.jpg'])

    def test_typed_birth_date_on_id_photo_is_found(self):
        self.assertIn('digital_text_overlay', self.found['id-edit-02-drivers-birthdate-typed.jpg'])

    def test_clean_photos_have_no_typed_text_or_copy_findings(self):
        for name in ('clean-01-phone-photo.jpg', 'clean-03-cool-light-photo.jpg', 'id-clean-01-national-id.jpg'):
            with self.subTest(name=name):
                self.assertFalse({'digital_text_overlay', 'copy_move_region', 'repeated_visual_region'} & self.found[name])


if __name__ == '__main__':
    unittest.main()
