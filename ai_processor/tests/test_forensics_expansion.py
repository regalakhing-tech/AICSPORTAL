"""Synthetic and privacy checks for optional document forensics."""
import tempfile
import unittest
import subprocess
import shutil
from unittest.mock import patch
from types import SimpleNamespace
from pathlib import Path

import cv2
import numpy as np
from PIL import Image

from ai_processor.tamper_detection.file_inspection import inspect_file
from ai_processor.tamper_detection.local_visual import inspect_qr, analyze_image_forensics
from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence
from ai_processor.tamper_detection.postprocessing import sanitize_combined_finding
from ai_processor.tamper_detection.artifacts import persist_review_pages


class FileInspectionTests(unittest.TestCase):
    def test_later_valid_pdf_signature_supersedes_normal_prior_revision(self):
        from ai_processor.tamper_detection.file_inspection import _signature_state
        raw = b'%PDF-1.4 /ByteRange [0 10 20 10] /ByteRange [0 30 40 10]'
        with patch('ai_processor.tamper_detection.file_inspection._verify_signature',
                   side_effect=[{'state': 'changed_after_signing'}, {'state': 'intact'}]):
            self.assertEqual(_signature_state(raw)['state'], 'intact')

    def test_image_metadata_is_bounded_and_does_not_flag_editor_name_alone(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'document.jpg'
            image = Image.new('RGB', (100, 100), 'white')
            exif = image.getexif()
            exif[305] = 'Adobe Photoshop'
            image.save(target, exif=exif)
            result = inspect_file(target)
        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['decision'], 'clear')
        self.assertEqual(result['facts']['editing_software'], 'Adobe Photoshop')

    def test_unsigned_pdf_does_not_claim_signature_validity(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'document.pdf'
            target.write_bytes(b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF')
            result = inspect_file(target)
        self.assertEqual(result['facts']['pdf_signature']['state'], 'unsigned')

    def test_signature_byte_range_without_crypto_library_is_unavailable(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'signed.pdf'
            target.write_bytes(b'%PDF-1.4 /ByteRange [0 10 30 20] /Contents <ABCD> %%EOF')
            result = inspect_file(target)
        self.assertIn(result['facts']['pdf_signature']['state'], {'unavailable', 'invalid_structure'})
        self.assertNotEqual(result['facts']['pdf_signature']['state'], 'intact')

    def test_pdf_signature_verifies_bytes_and_detects_appended_change(self):
        openssl = shutil.which('openssl') or r'C:\xampp\apache\bin\openssl.exe'
        if not Path(openssl).is_file():
            self.skipTest('OpenSSL executable unavailable')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            key, cert, data, signature = [root / name for name in ('key.pem', 'cert.pem', 'signed.bin', 'signature.der')]
            subprocess.run([openssl, 'req', '-config', r'C:\xampp\php\extras\openssl\openssl.cnf',
                            '-x509', '-newkey', 'rsa:2048', '-nodes',
                            '-keyout', str(key), '-out', str(cert), '-days', '1', '-subj', '/CN=Test Signer'],
                           check=True, capture_output=True)
            prefix = b'%PDF-1.4\n1 0 obj<</Type /Sig /ByteRange [0000000000 0000000000 0000000000 0000000000] /Contents <'
            suffix = b'>>endobj\n%%EOF'
            raw = prefix + b'0' * 8192 + suffix
            start, end = len(prefix), len(prefix) + 8192
            numbers = b'%010d %010d %010d %010d' % (0, start, end, len(raw) - end)
            raw = raw.replace(b'0000000000 0000000000 0000000000 0000000000', numbers, 1)
            data.write_bytes(raw[:start] + raw[end:])
            subprocess.run([openssl, 'cms', '-sign', '-binary', '-in', str(data),
                            '-signer', str(cert), '-inkey', str(key), '-outform', 'DER',
                            '-out', str(signature)], check=True, capture_output=True)
            hex_signature = signature.read_bytes().hex().encode('ascii')
            self.assertLess(len(hex_signature), 8192)
            target = root / 'signed.pdf'
            target.write_bytes(raw[:start] + hex_signature.ljust(8192, b'0') + raw[end:])
            signature_result = inspect_file(target)['facts']['pdf_signature']
            self.assertEqual(signature_result['state'], 'intact', signature_result)
            target.write_bytes(target.read_bytes() + b'\nadditional revision')
            self.assertEqual(inspect_file(target)['facts']['pdf_signature']['state'], 'changed_after_signing')
            corrupted = bytearray(target.read_bytes()[:-20])
            corrupted[20] ^= 1
            target.write_bytes(corrupted)
            self.assertEqual(inspect_file(target)['facts']['pdf_signature']['state'], 'invalid_signature')

    def test_invalid_signature_requires_staff_review_without_claiming_forgery(self):
        metadata = {'status': 'completed', 'family': 'metadata', 'decision': 'review',
                    'indicators': [{'type': 'invalid_pdf_signature', 'confidence_band': 'high',
                                    'reason': 'The signed bytes failed cryptographic verification.'}],
                    'facts': {'pdf_signature': {'state': 'invalid_signature'}}}
        clear = {'status': 'completed', 'decision': 'clear', 'indicators': []}
        result = aggregate_tamper_evidence({}, clear, clear, {}, local_results=[metadata])
        self.assertEqual(result['decision'], 'review')
        self.assertEqual(result['review_basis'], 'cryptographic_signature_problem')


class ImageForensicsTests(unittest.TestCase):
    def test_original_page_is_stored_in_private_review_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            settings = SimpleNamespace(ai_review_artifact_root=directory)
            page = np.full((100, 150, 3), 240, dtype=np.uint8)
            pages = persist_review_pages([page], 14, settings)
            self.assertEqual(pages, [{'page': 1, 'available': True}])
            self.assertTrue((Path(directory) / 'review_14' / 'page_1' / 'original-page.png').is_file())

    def test_qr_content_is_decoded_and_bounded(self):
        if not hasattr(cv2, 'QRCodeEncoder_create'):
            self.skipTest('OpenCV QR encoder unavailable')
        content = 'https://example.invalid/check?id=1234'
        qr = cv2.QRCodeEncoder_create().encode(content)
        page = cv2.resize(qr, (360, 360), interpolation=cv2.INTER_NEAREST)
        result = inspect_qr([cv2.cvtColor(page, cv2.COLOR_GRAY2BGR)])
        self.assertEqual(result['status'], 'completed')
        self.assertIn('example.invalid', result['facts'][0]['content_preview'])
        self.assertNotIn('id=1234', result['facts'][0]['content_preview'])
        self.assertEqual(result['facts'][0]['verification'], 'not_authenticated')

    def test_qr_path_and_non_url_payload_are_not_persisted(self):
        from ai_processor.tamper_detection.local_visual import _qr_preview
        self.assertNotIn('secret-person', _qr_preview('https://example.invalid/secret-person?id=12'))
        self.assertNotIn('patient', _qr_preview('patient Jane Doe, number 123'))

    def test_clean_blank_page_has_no_alteration_indicators(self):
        page = np.full((600, 800, 3), 245, dtype=np.uint8)
        result = analyze_image_forensics([page], {'pages': [{'lines': []}]})
        self.assertEqual(result['decision'], 'clear')
        self.assertEqual(result['indicators'], [])

    def test_copy_move_cluster_has_valid_destination_box(self):
        rng = np.random.default_rng(103)
        page = np.full((650, 850, 3), 240, dtype=np.uint8)
        patch = rng.integers(25, 220, (140, 160, 3), dtype=np.uint8)
        page[90:230, 80:240] = patch
        page[350:490, 500:660] = patch
        result = analyze_image_forensics([page], {'pages': [{'lines': []}]})
        cloned = [item for item in result['indicators'] if item['type'] == 'copy_move_region']
        self.assertTrue(cloned)
        self.assertTrue(all(0 <= value <= 1000 for value in cloned[0]['box_2d']))

    def test_one_local_copy_signal_stays_advisory(self):
        indicator = {'type': 'copy_move_region', 'page': 1, 'box_2d': [100, 100, 200, 200],
                     'confidence_band': 'moderate', 'reason': 'Review this region.'}
        local = {'status': 'completed', 'decision': 'review', 'indicators': [indicator], 'family': 'local_visual'}
        clear = {'status': 'completed', 'decision': 'clear', 'indicators': []}
        result = aggregate_tamper_evidence({}, clear, clear, {}, local_results=[local])
        self.assertEqual(result['decision'], 'clear')
        self.assertIn('local_visual', result['providers'])

    def test_one_high_confidence_copied_area_requests_staff_review(self):
        # Camera-noise copies at high confidence raised no false alarm on the real uploads
        # (tests/edited-documents/README.md), so one is enough to ask staff to look.
        indicator = {'type': 'copy_move_region', 'page': 1, 'box_2d': [100, 100, 200, 200],
                     'confidence_band': 'high', 'reason': 'The same pixels appear in two places.'}
        local = {'status': 'completed', 'decision': 'review', 'indicators': [indicator], 'family': 'local_visual'}
        clear = {'status': 'completed', 'decision': 'clear', 'indicators': []}
        result = aggregate_tamper_evidence({}, clear, clear, {}, local_results=[local])
        self.assertEqual(result['decision'], 'review')
        self.assertEqual(result['review_basis'], 'local_copied_region')
        self.assertEqual(sanitize_combined_finding(result)['review_basis'], 'local_copied_region')
        repeated = dict(indicator, type='repeated_visual_region')
        result = aggregate_tamper_evidence({}, clear, clear, {}, local_results=[dict(local, indicators=[repeated])])
        self.assertEqual(result['decision'], 'clear')

    def test_adcd_and_unrelated_local_signal_do_not_force_review(self):
        regional = {'status': 'completed', 'decision': 'review', 'indicators': [
            {'type': 'typography_mismatch', 'page': 1, 'box_2d': [100, 100, 200, 200],
             'confidence_band': 'moderate'}]}
        adcd = {'providers': {'adcd_net': {'status': 'completed', 'decision': 'review', 'pages': []}}}
        result = aggregate_tamper_evidence({}, {'status': 'completed', 'decision': 'clear', 'indicators': []},
                                           regional, adcd)
        self.assertEqual(result['decision'], 'clear')

    def test_overlapping_independent_local_signals_request_staff_review(self):
        visual = {'type': 'copy_move_region', 'page': 1, 'box_2d': [100, 100, 220, 250],
                  'confidence_band': 'moderate', 'reason': 'Review this region.'}
        typography = {'type': 'typography_mismatch', 'page': 1, 'box_2d': [105, 105, 220, 240],
                      'confidence_band': 'moderate', 'reason': 'Text differs from peers.'}
        local = {'status': 'completed', 'decision': 'review', 'indicators': [visual], 'family': 'local_visual'}
        regional = {'status': 'completed', 'decision': 'review', 'indicators': [typography]}
        result = aggregate_tamper_evidence({}, {'status': 'completed', 'decision': 'clear', 'indicators': []},
                                           regional, {}, local_results=[local])
        self.assertEqual(result['decision'], 'review')
        self.assertEqual(result['review_basis'], 'corroborated_visual_evidence')

    def test_safe_persistence_retains_local_boxes_and_redacted_qr(self):
        result = sanitize_combined_finding({
            'status': 'completed', 'decision': 'clear',
            'indicators': [{'type': 'copy_move_region', 'page': 1, 'box_2d': [100, 100, 200, 200],
                            'source_box_2d': [300, 300, 400, 400], 'source': 'local_visual',
                            'reason': 'Review region.'}],
            'providers': {'local_visual': {'status': 'completed'}, 'qr': {'status': 'completed'}},
            'original_pages': [{'page': 1, 'available': True}],
            'auxiliary': {'qr': [{'page': 1, 'box_2d': [10, 20, 30, 40],
                                  'content_preview': 'https://example.invalid/check?[parameters redacted]',
                                  'content_sha256': 'a' * 64, 'verification': 'not_authenticated'}]},
        })
        self.assertEqual(result['indicators'][0]['source_box_2d'], [300, 300, 400, 400])
        self.assertEqual(result['auxiliary']['qr'][0]['verification'], 'not_authenticated')
        self.assertEqual(result['providers']['local_visual']['status'], 'completed')
        self.assertEqual(result['original_pages'], [{'page': 1, 'available': True}])

    def test_persistence_redacts_untrusted_qr_preview_again(self):
        result = sanitize_combined_finding({
            'status': 'completed', 'auxiliary': {'qr': [
                {'page': 1, 'content_preview': 'https://example.invalid/patient/Jane?id=123',
                 'content_sha256': 'a' * 64},
                {'page': 1, 'content_preview': 'patient Jane Doe', 'content_sha256': 'b' * 64},
            ]},
        })
        previews = [item['content_preview'] for item in result['auxiliary']['qr']]
        self.assertTrue(all('Jane' not in preview and '123' not in preview for preview in previews))

    def test_covered_sensitive_field_is_localized(self):
        rng = np.random.default_rng(17)
        paper = rng.normal(230, 7, (480, 700, 3)).clip(0, 255).astype(np.uint8)
        paper[170:215, 260:530] = 250
        metadata = {'pages': [{'lines': [{'text': 'Date Issued: September 24, 2026',
                                          'box': [250, 164, 540, 221]}]}]}
        result = analyze_image_forensics([paper], metadata)
        self.assertTrue(any(item['type'] in {'erasure_or_cover_region', 'pasted_region_boundary'}
                            for item in result['indicators']))

    def test_pasted_textured_region_has_a_local_boundary_box(self):
        rng = np.random.default_rng(831)
        paper = rng.normal(216, 9, (500, 740, 3)).clip(0, 255).astype(np.uint8)
        paper[150:230, 220:560] = rng.normal(242, 7, (80, 340, 3)).clip(0, 255).astype(np.uint8)
        metadata = {'pages': [{'lines': [{'text': 'Issue Date: September 24, 2026',
                                          'box': [220, 150, 560, 230]}]}]}
        result = analyze_image_forensics([paper], metadata)
        self.assertTrue(any(item['type'] == 'pasted_region_boundary' and item.get('box_2d')
                            for item in result['indicators']))

    def test_resampled_sensitive_field_is_advisory(self):
        rng = np.random.default_rng(482)
        paper = rng.normal(225, 12, (500, 740, 3)).clip(0, 255).astype(np.uint8)
        paper[150:230, 220:560] = cv2.GaussianBlur(paper[150:230, 220:560], (11, 11), 4)
        metadata = {'pages': [{'lines': [{'text': 'Issue Date: September 24, 2026',
                                          'box': [220, 150, 560, 230]}]}]}
        result = analyze_image_forensics([paper], metadata)
        self.assertTrue(any(item['type'] == 'compression_or_resampling_difference'
                            for item in result['indicators']))

    def test_repeated_signature_area_is_labeled_as_repeated_visual(self):
        rng = np.random.default_rng(42)
        paper = np.full((650, 850, 3), 240, dtype=np.uint8)
        stamp = rng.integers(30, 220, (150, 165, 3), dtype=np.uint8)
        paper[90:240, 80:245] = stamp
        paper[350:500, 500:665] = stamp
        metadata = {'pages': [{'lines': [{'text': 'Signature of physician', 'box': [75, 80, 255, 250]},
                                          {'text': 'Signature of patient', 'box': [490, 340, 675, 510]}]}]}
        result = analyze_image_forensics([paper], metadata)
        self.assertTrue(any(item['type'] == 'repeated_visual_region' for item in result['indicators']))

    def test_repeated_signature_on_two_pages_references_source_page(self):
        rng = np.random.default_rng(991)
        source = np.full((500, 700, 3), 242, dtype=np.uint8)
        destination = source.copy()
        mark = rng.integers(20, 230, (110, 140, 3), dtype=np.uint8)
        source[100:210, 110:250] = mark
        destination[300:410, 430:570] = mark
        metadata = {'pages': [
            {'lines': [{'text': 'Signature', 'box': [100, 90, 260, 220]}]},
            {'lines': [{'text': 'Signature', 'box': [420, 290, 580, 420]}]},
        ]}
        result = analyze_image_forensics([source, destination], metadata)
        self.assertTrue(any(item['type'] == 'repeated_visual_region' and item.get('source_page') == 1
                            and item.get('page') == 2 for item in result['indicators']))


if __name__ == '__main__':
    unittest.main()
