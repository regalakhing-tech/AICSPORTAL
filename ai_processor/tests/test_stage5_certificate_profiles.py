"""Synthetic safety regressions for the former-rebel and crisis certificates."""
import unittest
from unittest.mock import patch

import numpy as np
import pytesseract

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.ocr_engine import retry_philid_field_rois, resolve_roi_profile


ECLIP = '''ENHANCED COMPREHENSIVE LOCAL INTEGRATION PROGRAM
FORMER REBEL ELIGIBILITY CERTIFICATION
Beneficiary: JUAN DELA CRUZ
Committee Chair: MARIA SANTOS
Certifying Officer: PEDRO REYES
Reference No: ECLIP-123
Narrative: SYNTHETIC SECURITY NARRATIVE ONLY'''

CRISIS = '''CERTIFICATE OF PERSON IN CRISIS SITUATION
MSWDO SOCIAL WELFARE CERTIFICATION
Beneficiary: ANA REYES
Social Worker: MARIA SANTOS
Approved By: PEDRO CRUZ
Household Income: PHP 5,000
Assessment Notes: SYNTHETIC PRIVATE NOTE ONLY'''


class Stage5CertificateProfileTests(unittest.TestCase):
    def test_complete_textual_profile_resolution_matrix_is_high_confidence_only(self):
        matrix = (
            ('valid_id', 'national_id', 'valid_id'),
            ('med_cert', 'medical_certificate', 'medical_certificate'),
            ('med_cert', 'medical_abstract', 'medical_abstract'),
            ('billing_statement', 'billing_statement', 'billing_statement'),
            ('billing_statement', 'prescription', 'prescription'),
            ('death_cert', 'psa_death_certificate', 'death_certificate'),
            ('funeral_contract', 'funeral_service_contract', 'funeral_contract'),
            ('report_police_med', 'police_report', 'police_report'),
            ('report_police_med', 'medical_report', 'medical_report'),
            ('school_assessment', 'school_assessment', 'school_assessment'),
            ('registration_form', 'deped_basic_education_enrollment_form', 'registration_form'),
            ('mdrrmo_cert', 'damage_certification', 'drrm_damage_certification'),
            ('mdrrmo_cert', 'displacement_certification', 'drrm_displacement_certification'),
            ('eclip_cert', '', 'eclip_certification'),
            ('crisis_situation_cert', '', 'person_in_crisis_certification'),
        )
        for expected, subtype, profile in matrix:
            classification = {'detected_type': expected, 'document_subtype': subtype, 'confidence_band': 'high'}
            self.assertEqual(resolve_roi_profile(expected, classification), profile)
            self.assertIsNone(resolve_roi_profile(expected, {**classification, 'confidence_band': 'moderate'}))

    def test_classifier_families_resolve_to_distinct_safe_profiles(self):
        eclip = classify_document(ECLIP, 'eclip_cert')
        crisis = classify_document(CRISIS, 'crisis_situation_cert')
        self.assertEqual(eclip['decision'], 'match')
        self.assertEqual(crisis['decision'], 'match')
        self.assertEqual(resolve_roi_profile('eclip_cert', eclip), 'eclip_certification')
        self.assertEqual(resolve_roi_profile('crisis_situation_cert', crisis), 'person_in_crisis_certification')
        self.assertIsNone(resolve_roi_profile('eclip_cert', {**eclip, 'confidence_band': 'moderate'}))
        self.assertIsNone(resolve_roi_profile('crisis_situation_cert', {**crisis, 'detected_type': 'eclip_cert'}))

    def test_beneficiary_labels_never_select_committee_or_staff(self):
        eclip = extract_document_fields('eclip_cert', ECLIP)['fields']
        crisis = extract_document_fields('crisis_situation_cert', CRISIS)['fields']
        self.assertEqual(eclip['client_name']['normalized'], 'JUAN DELA CRUZ')
        self.assertEqual(crisis['client_name']['normalized'], 'ANA REYES')
        self.assertNotIn('MARIA SANTOS', str(eclip))
        self.assertNotIn('PEDRO REYES', str(eclip))
        self.assertNotIn('MARIA SANTOS', str(crisis))
        self.assertNotIn('PEDRO CRUZ', str(crisis))

    def test_certification_sentence_is_profile_scoped_and_precedes_signature_block(self):
        eclip = extract_document_fields('eclip_cert', '''ECLIP CERTIFICATION
This is to certify that JUAN DELA CRUZ is the beneficiary for this certification.
Authorized Officer: MARIA SANTOS''')['fields']
        crisis = extract_document_fields('crisis_situation_cert', '''CERTIFICATE OF PERSON IN CRISIS SITUATION
This is to certify that ANA REYES is a person in crisis situation.
Issuing Official: PEDRO CRUZ''')['fields']
        self.assertEqual(eclip['client_name']['normalized'], 'JUAN DELA CRUZ')
        self.assertEqual(crisis['client_name']['normalized'], 'ANA REYES')

    def test_stage5_metadata_excludes_references_narratives_and_staff(self):
        for kind, text, prohibited in (
            ('eclip_cert', ECLIP, ('ECLIP-123', 'SECURITY NARRATIVE', 'MARIA SANTOS', 'PEDRO REYES')),
            ('crisis_situation_cert', CRISIS, ('PHP 5,000', 'PRIVATE NOTE', 'MARIA SANTOS', 'PEDRO CRUZ')),
        ):
            fields = extract_document_fields(kind, text)['fields']
            self.assertEqual(set(fields), {'client_name'})
            for value in prohibited:
                self.assertNotIn(value, str(fields))

    def test_displacement_role_is_not_certifying_or_barangay_officer(self):
        fields = extract_document_fields('mdrrmo_cert', '''MDRRMO CERTIFICATION
Displaced Person: ANA REYES
Certifying Officer: MARIA SANTOS
Barangay Official: PEDRO CRUZ''')['fields']
        # The former profile uses Name of Affected Person; the stage-4 role
        # assertion proves the explicit displaced-person label too.
        self.assertEqual(fields['client_name']['normalized'], 'ANA REYES')
        self.assertNotIn('MARIA SANTOS', str(fields['client_name']))
        self.assertNotIn('PEDRO CRUZ', str(fields['client_name']))

    def test_global_identity_garbage_is_never_reliable(self):
        for kind, text, key in (
            ('eclip_cert', 'Beneficiary: et ne', 'client_name'),
            ('crisis_situation_cert', 'Client Name: /Acdress oe', 'client_name'),
            ('mdrrmo_cert', 'Name of Affected Person: 9! Xx', 'client_name'),
            ('med_cert', 'Patient Name: r -', 'patient_name'),
        ):
            field = extract_document_fields(kind, text)['fields'][key]
            self.assertNotEqual(field['status'], 'extracted')
            self.assertIsNone(field['value'])

    def test_stage5_roi_is_bounded_and_only_used_for_weak_identity(self):
        metadata = {'pages': [{'page_number': 1, 'lines': [
            {'text': 'Beneficiary', 'box': [10, 10, 90, 22], 'confidence': 95},
        ]}]}
        image = np.zeros((120, 300, 3), dtype=np.uint8)
        with patch('ai_processor.ocr_engine._configure_tesseract'), patch.object(
            pytesseract, 'image_to_data', return_value={'text': ['JUAN', 'DELA', 'CRUZ'], 'conf': ['96', '95', '94']}
        ) as ocr:
            result = retry_philid_field_rois([image], metadata, 'unused', {'client_name'}, profile_key='eclip_certification')
        self.assertEqual(ocr.call_count, 2)
        self.assertEqual(result['roi_retry_calls'], 2)
        self.assertEqual(result['roi_values']['client_name']['value'], 'JUAN DELA CRUZ')
        self.assertNotIn('roi_retry_calls', retry_philid_field_rois([image], metadata, 'unused', set(), profile_key='eclip_certification'))

    def test_cross_profile_certificate_words_do_not_change_canonical_profile(self):
        eclip = classify_document(ECLIP + '\nstudent narrative word only', 'eclip_cert')
        crisis = classify_document(CRISIS + '\npatient narrative word only', 'crisis_situation_cert')
        drrm = {'detected_type': 'mdrrmo_cert', 'document_subtype': 'damage_certification', 'confidence_band': 'high'}
        self.assertEqual(resolve_roi_profile('eclip_cert', eclip), 'eclip_certification')
        self.assertEqual(resolve_roi_profile('crisis_situation_cert', crisis), 'person_in_crisis_certification')
        self.assertEqual(resolve_roi_profile('mdrrmo_cert', drrm), 'drrm_damage_certification')
