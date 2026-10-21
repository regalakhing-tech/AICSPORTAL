"""Content credentials (C2PA / IPTC) that AI image tools embed in their output."""
import struct
import tempfile
import unittest
from pathlib import Path

from PIL import Image

from ai_processor.gemini_scanner.prompt import CHECK_KEYS
from ai_processor.gemini_scanner.verdict import apply_verdict
from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence
from ai_processor.tamper_detection.file_inspection import inspect_file
from ai_processor.tamper_detection.postprocessing import attach_combined_finding

# The parts of a Gemini image export the check relies on (claim generator,
# IPTC digital source type, SynthID action), as they appear in the JUMBF box.
GEMINI_MANIFEST = (b'jumb\x00jumdc2pa\x00c2pa.claim_generator_info.dnamex"Google C2PA Core Generator Library'
                   b'\x00c2pa.createdkdescriptionx Created by Google Generative AI.qdigitalSourceTypexF'
                   b'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia\x00'
                   b'c2pa.editedkdescriptionx(Applied imperceptible SynthID watermark.')
PHONE_MANIFEST = (b'jumb\x00jumdc2pa\x00c2pa.createdqdigitalSourceTypexF'
                  b'http://cv.iptc.org/newscodes/digitalsourcetype/computationalCapture\x00')


def _jpeg_with_app11(path: Path, payload: bytes) -> None:
    Image.new('RGB', (320, 240), 'white').save(path, 'JPEG')
    data = path.read_bytes()
    segment = b'\xff\xeb' + struct.pack('>H', len(payload) + 2) + payload
    path.write_bytes(data[:2] + segment + data[2:])


def _metadata_result(path: Path) -> dict:
    return inspect_file(path)


def _clear():
    return {'status': 'completed', 'decision': 'clear', 'indicators': []}


def _scan():
    return {
        'document_type': 'med_cert', 'document_title': 'MEDICAL CERTIFICATE', 'type_confidence': 94,
        'primary_person': {'name': 'Nicasio E. Bustillo', 'role': 'patient', 'birth_date': None, 'sex': None, 'address': None},
        'beneficiary_name_found': True, 'document_date': '2026-09-20', 'issuer': 'Antique Provincial Hospital',
        'checks': {key: {'state': 'pass', 'reason': f'{key} ok', 'missing': []} for key in CHECK_KEYS},
        'indicators': [], 'sample_or_specimen': {'detected': False, 'reason': ''}, 'summary': 'Medical certificate.',
    }


def _local():
    return {'status': 'likely_valid', 'detected_document_type': 'med_cert', 'findings': {'reasons': [], 'assessments': {}}}


class ProvenanceFileTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)

    def tearDown(self):
        self.folder.cleanup()

    def test_gemini_export_is_reported_as_ai_generated(self):
        path = self.root / 'bill.jpg'
        _jpeg_with_app11(path, GEMINI_MANIFEST)
        result = _metadata_result(path)
        self.assertEqual(result['decision'], 'review')
        provenance = result['facts']['ai_provenance']
        self.assertEqual(provenance, {'state': 'generated', 'tool': 'Google generative AI',
                                      'standard': 'C2PA', 'watermark': 'SynthID'})
        indicator = result['indicators'][0]
        self.assertEqual(indicator['type'], 'ai_generated_content')
        self.assertIn('Google generative AI', indicator['reason'])
        self.assertIn('SynthID', indicator['reason'])

    def test_phone_camera_content_credential_is_not_ai(self):
        path = self.root / 'photo.jpg'
        _jpeg_with_app11(path, PHONE_MANIFEST)
        result = _metadata_result(path)
        self.assertEqual(result['decision'], 'clear')
        self.assertNotIn('ai_provenance', result['facts'])

    def test_ai_edited_photo_in_png_xmp(self):
        from PIL import PngImagePlugin
        path = self.root / 'edited.png'
        info = PngImagePlugin.PngInfo()
        info.add_itxt('XML:com.adobe.xmp', '<x:xmpmeta><rdf:Description Iptc4xmpExt:DigitalSourceType='
                      '"http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia"'
                      ' xmp:CreatorTool="Adobe Firefly"/></x:xmpmeta>')
        Image.new('RGB', (200, 200), 'white').save(path, 'PNG', pnginfo=info)
        provenance = _metadata_result(path)['facts']['ai_provenance']
        self.assertEqual(provenance['state'], 'edited')
        self.assertEqual(provenance['tool'], 'Adobe Firefly')
        self.assertEqual(provenance['standard'], 'IPTC')

    def test_plain_photo_has_no_provenance(self):
        path = self.root / 'plain.jpg'
        Image.new('RGB', (200, 200), 'white').save(path, 'JPEG')
        result = _metadata_result(path)
        self.assertEqual(result['decision'], 'clear')
        self.assertNotIn('ai_provenance', result['facts'])


class ProvenanceDecisionTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        path = Path(folder.name) / 'bill.jpg'
        _jpeg_with_app11(path, GEMINI_MANIFEST)
        self.metadata = inspect_file(path)
        self.combined = aggregate_tamper_evidence({'status': 'unavailable'}, _clear(), _clear(), {},
                                                  local_results=[self.metadata])

    def test_aggregation_marks_generative_ai_basis(self):
        self.assertEqual(self.combined['decision'], 'review')
        self.assertEqual(self.combined['review_basis'], 'generative_ai_provenance')
        self.assertIn('file_provenance', self.combined['evidence_families'])
        self.assertEqual(self.combined['confidence_band'], 'high')
        self.assertTrue(any(item['type'] == 'ai_generated_content' and item['source'] == 'metadata'
                            for item in self.combined['indicators']))

    def test_saved_finding_keeps_the_provenance(self):
        saved = attach_combined_finding({}, self.combined)['tamper_detection']
        self.assertEqual(saved['review_basis'], 'generative_ai_provenance')
        self.assertIn('generative AI', saved['label'])
        self.assertEqual(saved['auxiliary']['file']['ai_provenance']['tool'], 'Google generative AI')
        self.assertTrue(any(item['type'] == 'ai_generated_content' for item in saved['indicators']))

    def test_verdict_fails_untampered_even_when_the_ai_reading_passed(self):
        gemini = {'status': 'completed', 'model': 'gemini-test', 'scan': _scan()}
        result = apply_verdict(_local(), 'med_cert', gemini, self.combined, {'full_name': 'Nicasio Eimar Bustillo'})
        checks = result['findings']['thesis_checks']
        self.assertEqual(checks['untampered']['state'], 'fail')
        self.assertIn('Google generative AI', checks['untampered']['reason'])
        self.assertEqual(checks['unaltered']['state'], 'pass')  # no invented local-forensics note
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['verdict']['headline'], 'Made with generative AI')

    def test_local_fallback_also_flags_it(self):
        result = apply_verdict(_local(), 'med_cert', {'status': 'unavailable', 'error_code': 'GEMINI_TIMEOUT'},
                               self.combined, {'full_name': 'Nicasio Eimar Bustillo'})
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'fail')
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['verdict']['headline'], 'Made with generative AI')


if __name__ == '__main__':
    unittest.main()
