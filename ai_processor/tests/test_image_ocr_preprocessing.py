import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image

from ai_processor.document_classifier import classify_document
from ai_processor.document_loader import load_images
from ai_processor.field_extractor import extract_document_fields
from ai_processor.ocr_engine import (
    _base_ocr_candidates, _candidate_score, _document_region, extract_document_text,
    resolve_roi_profile, resolve_safe_roi_fields,
)


class ImageOcrPreprocessingTests(unittest.TestCase):
    def test_large_image_keeps_original_and_bounds_enhanced_ocr_passes(self):
        image = np.full((3968, 2976, 3), 255, dtype=np.uint8)
        original, clahe, sharpened = _base_ocr_candidates(image)
        self.assertEqual(original[0].shape[:2], image.shape[:2])
        self.assertEqual(original[1], 1.0)
        for candidate in (clahe, sharpened):
            self.assertLessEqual(max(candidate[0].shape[:2]), 2400)
            self.assertLess(candidate[1], 1.0)

    def test_ocr_uses_remaining_candidate_after_tesseract_timeout(self):
        image = np.zeros((20, 30, 3), dtype=np.uint8)
        crop = {'applied': False}
        candidates = [
            (image, 1.0, '--psm 6', (0, 0), 'original', None, crop),
            (image, 1.0, '--psm 11', (0, 0), 'clahe', None, crop),
        ]
        with patch('ai_processor.ocr_engine._configure_tesseract'), \
             patch('ai_processor.ocr_engine._ocr_candidates', return_value=candidates), \
             patch('pytesseract.image_to_data', side_effect=[RuntimeError('Tesseract process timeout'), {'text': []}]):
            text, confidence, metadata = extract_document_text([image], 'unused', 45)
        self.assertEqual(text, '')
        self.assertEqual(confidence, 0)
        self.assertEqual(metadata['pages'][0]['selected_candidate'], 'clahe')

    def test_direct_jpeg_and_rgba_png_normalize_to_bgr(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            Image.new('RGB', (80, 40), 'white').save(root / 'card.jpg')
            Image.new('RGBA', (80, 40), (255, 255, 255, 120)).save(root / 'card.png')
            for filename in ('card.jpg', 'card.png'):
                image = load_images(root / filename, 1)[0]
                self.assertEqual(image.shape, (40, 80, 3))
                self.assertEqual(image.dtype, np.uint8)

    def test_exif_orientation_is_applied_without_changing_source(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / 'rotated.jpg'
            source = Image.new('RGB', (80, 40), 'white')
            exif = Image.Exif(); exif[274] = 6
            source.save(file, exif=exif)
            image = load_images(file, 1)[0]
            self.assertEqual(image.shape[:2], (80, 40))

    def test_philid_bilingual_structure_is_evidence_not_a_threshold_change(self):
        text = '''REPUBLIKA NG PILIPINAS
PAMBANSANG PAGKAKAKILANLAN
Philippine Identification Card
Apelyido: SAMPLE
Mga Pangalan: TEST PERSON
Gitnang Apelyido: USER
Petsa ng Kapanganakan: September 12, 2000
Tirahan: Sample Barangay, Antique'''
        result = classify_document(text, 'valid_id')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['detected_type'], 'valid_id')

    def test_philid_fields_are_composed_and_garbage_is_suppressed(self):
        text = '''Apelyido: SAMPLE
Mga Pangalan: TEST PERSON
Gitnang Apelyido: USER
Petsa ng Kapanganakan: September 12, 2000
Tirahan: Sample Barangay, Antique'''
        fields = extract_document_fields('valid_id', text)['fields']
        self.assertEqual(fields['name']['status'], 'extracted')
        self.assertEqual(fields['birth_date']['normalized'], '2000-09-12')
        self.assertEqual(fields['address']['status'], 'extracted')
        garbage = extract_document_fields('valid_id', 'Full Name: S C\nAddress: Et Thes')['fields']
        self.assertEqual(garbage['name']['status'], 'uncertain')
        self.assertEqual(garbage['address']['status'], 'uncertain')
        damaged_label = extract_document_fields('valid_id', 'Full Name: TEST PERSON\nAddress: /Acdress oe aimee ett S| BY')['fields']
        self.assertEqual(damaged_label['address']['status'], 'uncertain')
        long_garbage = extract_document_fields('valid_id', 'Full Name: TEST PERSON\nAddress: cer -| PMOLAUION @ PANT Ele ArT CHU Se by Mey tor op ike ra mf 4 2a Lieve HS LOWCLIGDS We Re Wek cera nomen tee ee diva Design oy LOWE A ae STA Wat ae carer and many unrelated security notice words from the next field')['fields']['address']
        self.assertEqual(long_garbage['status'], 'uncertain')
        self.assertFalse(long_garbage['reliable'])
        self.assertIsNone(long_garbage['value'])
        good_address = extract_document_fields('valid_id', 'Full Name: JUAN MIGUEL DELA CRUZ\nAddress: PUROK 2, BRGY. CALALA, HAMTIC, ANTIQUE')['fields']['address']
        self.assertEqual(good_address['status'], 'extracted')
        self.assertTrue(good_address['reliable'])

    def test_phone_frame_document_region_is_cropped_without_replacing_source(self):
        image = np.full((1200, 900, 3), 120, dtype=np.uint8)
        image[150:1050, 100:800] = 245
        import cv2
        cv2.rectangle(image, (100, 150), (799, 1049), (20, 20, 20), 4)
        region, offset, diagnostic = _document_region(image)
        self.assertTrue(diagnostic['applied'])
        self.assertGreater(offset[0], 0)
        self.assertLess(region.shape[0] * region.shape[1], image.shape[0] * image.shape[1])

    def test_marker_rich_candidate_scores_above_garbage(self):
        self.assertGreater(
            _candidate_score('REPUBLIKA NG PILIPINAS PAMBANSANG PAGKAKAKILANLAN Mga Pangalan Tirahan', [75]),
            _candidate_score('S C Et Thes', [75]),
        )

    def test_philid_uses_label_regions_without_promoting_structure_to_values(self):
        metadata = {'pages': [{'lines': [
            {'text': 'Apelyido', 'box': [10, 10, 90, 22], 'confidence': 96}, {'text': 'DELA CRUZ', 'box': [10, 26, 130, 38], 'confidence': 94},
            {'text': 'Mga Pangalan', 'box': [10, 45, 120, 57], 'confidence': 95}, {'text': 'JUAN MIGUEL', 'box': [10, 61, 150, 73], 'confidence': 93},
            {'text': 'Gitnang Apelyido', 'box': [10, 80, 150, 92], 'confidence': 95}, {'text': 'SANTOS', 'box': [10, 96, 90, 108], 'confidence': 92},
            {'text': 'Petsa ng Kapanganakan', 'box': [10, 115, 190, 127], 'confidence': 95}, {'text': 'JANUARY 02, 1990', 'box': [10, 131, 180, 143], 'confidence': 94},
            {'text': 'Tirahan', 'box': [10, 150, 80, 162], 'confidence': 95}, {'text': 'POBLACION 4, HAMTIC, ANTIQUE', 'box': [10, 166, 260, 178], 'confidence': 93},
        ]}]}
        fields = extract_document_fields('valid_id', 'PhilID structure only', metadata)['fields']
        self.assertEqual(fields['name']['normalized'], 'JUAN MIGUEL SANTOS DELA CRUZ')
        self.assertEqual(fields['birth_date']['normalized'], '1990-01-02')
        self.assertEqual(fields['address']['normalized'], 'poblacion 4 hamtic antique')

    def test_roi_profile_resolution_is_subtype_safe_for_medical_and_billing(self):
        self.assertEqual(resolve_roi_profile('med_cert', {'detected_type': 'med_cert', 'document_subtype': 'medical_certificate', 'confidence_band': 'high'}), 'medical_certificate')
        self.assertEqual(resolve_roi_profile('med_cert', {'detected_type': 'med_cert', 'document_subtype': 'medical_abstract', 'confidence_band': 'high'}), 'medical_abstract')
        self.assertEqual(resolve_roi_profile('billing_statement', {'detected_type': 'billing_statement', 'document_subtype': 'billing_statement', 'confidence_band': 'high'}), 'billing_statement')
        self.assertEqual(resolve_roi_profile('billing_statement', {'detected_type': 'billing_statement', 'document_subtype': 'prescription', 'confidence_band': 'high'}), 'prescription')
        self.assertIsNone(resolve_roi_profile('med_cert', {'detected_type': 'med_cert', 'document_subtype': 'unknown', 'confidence_band': 'high'}))
        self.assertIsNone(resolve_roi_profile('med_cert', {'detected_type': 'med_cert', 'document_subtype': 'medical_certificate', 'confidence_band': 'moderate'}))

    def test_complete_safe_field_can_use_one_bounded_candidate_without_fragment_merging(self):
        metadata = {'field_candidates': [
            {'candidate': 'original', 'text': 'Given Names: ANA\nLast Name: REYES', 'mean_confidence': 92, 'pages': []},
            {'candidate': 'clahe', 'text': 'Given Names: ANA\nLast Name: R', 'mean_confidence': 95, 'pages': []},
            {'candidate': 'sharpened', 'text': 'Given Names: ANA\nLast Name: R', 'mean_confidence': 94, 'pages': []},
        ]}
        fields = extract_document_fields('valid_id', 'Given Names: ANA\nLast Name: R', metadata)['fields']
        self.assertEqual(fields['name']['normalized'], 'ANA REYES')
        self.assertEqual(fields['name']['field_candidate'], 'original')
        # A separate candidate with a spurious one-character fragment must not
        # displace a coherent full name.
        metadata['field_candidates'][0]['text'] = 'Given Names: ANA\nLast Name: I REYES'
        fields = extract_document_fields('valid_id', 'Given Names: ANA\nLast Name: R', metadata)['fields']
        self.assertEqual(fields['name']['status'], 'uncertain')

    def test_low_classification_valid_id_requires_independent_family_and_anchor_evidence_for_roi(self):
        metadata = {'pages': [], 'field_candidates': [{
            'candidate': 'original',
            'text': 'PHILIPPINE IDENTIFICATION CARD\nREPUBLIC OF THE PHILIPPINES\nPHILSYS\nGiven Names: ANA',
            'pages': [{'lines': [{'text': 'Given Names: ANA', 'box': [10, 20, 180, 40], 'confidence': 92}]}],
        }]}
        profile, fields, diagnostic = resolve_safe_roi_fields('valid_id', {'confidence_band': 'low'}, metadata, {'name', 'birth_date'})
        self.assertEqual(profile, 'valid_id')
        self.assertEqual(fields, {'name'})
        self.assertEqual(diagnostic['mode'], 'valid_id_anchor_fallback')
        no_profile, no_fields, _ = resolve_safe_roi_fields('valid_id', {'confidence_band': 'low'}, {'pages': [], 'field_candidates': []}, {'name'})
        self.assertIsNone(no_profile)
        self.assertEqual(no_fields, set())

    def test_patient_identity_anchors_do_not_use_physician_or_guarantor(self):
        medical = extract_document_fields('med_cert', 'Medical Certificate\nPhysician: MARIA SANTOS\nPatient Name: JUAN DELA CRUZ')['fields']
        billing = extract_document_fields('billing_statement', 'Billing Statement\nGuarantor: MARIA SANTOS\nPatient: JUAN DELA CRUZ')['fields']
        self.assertEqual(medical['patient_name']['normalized'], 'JUAN DELA CRUZ')
        self.assertEqual(billing['patient_name']['normalized'], 'JUAN DELA CRUZ')

    def test_medical_prose_after_patient_word_is_not_a_person_name(self):
        text = ('MEDICAL CERTIFICATE\nI hereby waive all rights pertaining to professional confidences '
                'between physician and patient, and the physician accompanying this form.')

        patient = extract_document_fields('med_cert', text)['fields']['patient_name']

        self.assertEqual(patient['status'], 'uncertain')
        self.assertFalse(patient['reliable'])
        self.assertIsNone(patient['value'])

        roi_debris = extract_document_fields('med_cert', text, {
            'roi_values': {'patient_name': {'value': 'FE herein. trop )', 'confidence': 41.7}},
        })['fields']['patient_name']
        self.assertEqual(roi_debris['status'], 'uncertain')
        self.assertIsNone(roi_debris['value'])

    def test_burial_and_report_roles_do_not_select_other_people(self):
        death = extract_document_fields('death_cert', 'Certificate of Death\nInformant: MARIA DELA CRUZ\nName of Deceased: JUAN DELA CRUZ\nPhysician: PEDRO SANTOS')['fields']
        funeral = extract_document_fields('funeral_contract', 'Funeral Contract\nContracting Party: MARIA DELA CRUZ\nDeceased: JUAN DELA CRUZ\nFuneral Director: PEDRO SANTOS')['fields']
        report = extract_document_fields('report_police_med', 'Police Report\nReporting Person: PEDRO REYES\nVictim: ANA REYES\nOfficer: JUAN CRUZ')['fields']
        self.assertEqual(death['deceased_name']['normalized'], 'JUAN DELA CRUZ')
        self.assertEqual(funeral['deceased_name']['normalized'], 'JUAN DELA CRUZ')
        self.assertEqual(report['subject_name']['normalized'], 'ANA REYES')

    def test_medical_report_student_and_drrm_roles_remain_semantic(self):
        medical_report = extract_document_fields('report_police_med', 'Medical Report\nExamining Physician: MARIA SANTOS\nName of Examinee: ANA REYES\nRequested By: PEDRO CRUZ')['fields']
        school = extract_document_fields('school_assessment', 'School Assessment Form\nStudent Name: MARIA SANTOS\nGuardian: ELENA SANTOS\nRegistrar: JUAN CRUZ')['fields']
        registration = extract_document_fields('registration_form', 'Registration Form\nLearner Name: MARIA SANTOS\nGuardian: ELENA SANTOS\nEmergency Contact: PEDRO SANTOS')['fields']
        drrm = extract_document_fields('mdrrmo_cert', 'MDRRMO Certification\nName of Affected Person: ANA REYES\nCertified By: JUAN CRUZ')['fields']
        self.assertEqual(medical_report['subject_name']['normalized'], 'ANA REYES')
        self.assertEqual(school['client_name']['normalized'], 'MARIA SANTOS')
        self.assertEqual(registration['client_name']['normalized'], 'MARIA SANTOS')
        self.assertEqual(drrm['client_name']['normalized'], 'ANA REYES')
