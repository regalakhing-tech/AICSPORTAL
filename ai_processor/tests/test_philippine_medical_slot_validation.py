"""Synthetic OCR-only regression coverage for the Medical Abstract/Certificate slot."""
import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate


GOVERNMENT_ABSTRACT = '''Republic of the Philippines Department of Health
Sample Provincial Hospital Medical Records Department
MEDICAL ABSTRACT
Patient Name: TEST PATIENT 001
Date of Birth: January 2, 1990
Admitting Diagnosis: TEST CONDITION ALPHA
Treatment: Synthetic treatment plan
Hospital Course: Synthetic clinical course
Attending Physician: Dr. Test Doctor
'''
PRIVATE_ABSTRACT = '''Sample Private Medical Center
CLINICAL ABSTRACT
Name of Patient: TEST PATIENT 001
Diagnosis: TEST CONDITION BETA
Management: Synthetic care plan
Prognosis: Stable for test only
Physician: Dr. Test Doctor
'''
MEDICAL_CERTIFICATE = '''Sample Community Clinic
MEDICAL CERTIFICATE
This is to certify that Patient Name: TEST PATIENT 001 was examined.
Medical Condition: TEST CONDITION GAMMA
Attending Physician: Dr. Test Doctor
'''
DIGITIZED_CERTIFICATE = '''Sample Private Medical Center
MEDICAL CERTIFICATION
This is to certify that Name of Patient: TEST PATIENT 001 was treated.
Findings: TEST CONDITION DELTA
Signed By: Dr. Test Doctor, Physician
PRC License No. TEST-LICENSE-ONLY
'''


class PhilippineMedicalSlotValidationTests(unittest.TestCase):
    def assert_match(self, text, subtype):
        result = classify_document(text, 'med_cert')
        self.assertEqual(result['detected_type'], 'med_cert')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['slot_result'], 'matched')
        self.assertEqual(result['document_subtype'], subtype)
        self.assertTrue(result['medical_evidence']['facility'])
        self.assertTrue(result['medical_evidence']['document_marker'])
        self.assertTrue(result['medical_evidence']['patient_identity'])
        self.assertTrue(result['medical_evidence']['physician'])
        return result

    def test_government_and_private_abstracts_match_without_doh_requirement(self):
        government = self.assert_match(GOVERNMENT_ABSTRACT, 'medical_abstract')
        private = self.assert_match(PRIVATE_ABSTRACT, 'medical_abstract')
        self.assertTrue(government['medical_evidence']['clinical_sections'])
        self.assertTrue(private['medical_evidence']['clinical_sections'])

    def test_certificate_and_digitized_certificate_match_same_requirement_group(self):
        certificate = self.assert_match(MEDICAL_CERTIFICATE, 'medical_certificate')
        digitized = self.assert_match(DIGITIZED_CERTIFICATE, 'medical_certificate')
        self.assertIn('Medical-condition certification language detected', certificate['medical_evidence']['clinical_sections'])
        self.assertIn('Physician license field detected', digitized['medical_evidence']['physician'])

    def test_clinical_summary_can_be_accepted_only_with_full_abstract_structure(self):
        summary = PRIVATE_ABSTRACT.replace('CLINICAL ABSTRACT', 'CLINICAL SUMMARY')
        self.assert_match(summary, 'medical_abstract')

    def test_ambiguous_medical_records_and_sparse_text_need_staff_confirmation(self):
        discharge = classify_document('Sample Hospital Discharge Summary Patient Name TEST PATIENT Diagnosis TEST Treatment TEST Attending Physician Dr Test', 'med_cert')
        self.assertEqual(discharge['decision'], 'uncertain')
        self.assertEqual(discharge['document_subtype'], 'medical_record_other')
        self.assertEqual(discharge['slot_result'], 'manual_review')
        for text in ('medical', 'Hospital patient name', 'Medical Certificate Patient Name TEST Attending Physician Dr Test'):
            self.assertEqual(classify_document(text, 'med_cert')['decision'], 'uncertain')

    def test_known_wrong_documents_remain_wrong_slot(self):
        cases = (
            'Sample Hospital Billing Statement Patient Name TEST Account Number TEST Total Charges Amount Due Balance Billing Office',
            'Prescription Patient Name TEST Generic Drug tablet 500 mg Take twice daily Attending Physician Dr Test',
            'Philippine Statistics Authority PhilSys National ID Full Name TEST Date of Birth January 2 1990 Address Sample PCN TEST-FORMAT',
            'Certificate of Death Name of Deceased TEST Sex Male Date of Death August 1 2026 Place of Death Sample Civil Registrar Registry Number TEST Informant',
        )
        for text in cases:
            self.assertEqual(classify_document(text, 'med_cert')['decision'], 'wrong_slot')

    def test_sensitive_clinical_values_are_not_persisted_in_medical_evidence(self):
        text = MEDICAL_CERTIFICATE + 'Diagnosis: RARE-SYNTHETIC-CONDITION-XYZ Medication: SECRET-TEST-MEDICINE'
        evidence = classify_document(text, 'med_cert')['medical_evidence']
        rendered = str(evidence)
        self.assertNotIn('RARE-SYNTHETIC-CONDITION-XYZ', rendered)
        self.assertNotIn('SECRET-TEST-MEDICINE', rendered)

    def test_patient_name_and_dob_consistency_remain_functional_without_clinical_comparison(self):
        fields = extract_document_fields('med_cert', '''Sample Community Clinic
MEDICAL CERTIFICATE
Patient Name: JUAN DELA CRUZ
Date of Birth: January 2, 1990
This is to certify that the patient was examined.
Diagnosis: SENSITIVE TEST DIAGNOSIS
Attending Physician: Dr. Test Doctor
''')['fields']
        result = evaluate('med_cert', 'med_cert', 90, {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [], '1990-01-02')
        self.assertEqual(result['findings']['checks']['name_match']['status'], 'match')
        self.assertNotIn('SENSITIVE TEST DIAGNOSIS', str(result['findings']['consistency_check']))
        missing_dob = extract_document_fields('med_cert', MEDICAL_CERTIFICATE)['fields']
        self.assertEqual(missing_dob['birth_date']['status'], 'not_present')


if __name__ == '__main__':
    unittest.main()
