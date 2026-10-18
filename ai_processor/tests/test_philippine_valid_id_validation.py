"""Synthetic OCR-only tests for Philippine Valid Identification Card slot evidence."""
import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields


class PhilippineValidIdValidationTests(unittest.TestCase):
    def assert_recognized(self, subtype, text):
        result = classify_document(text, 'valid_id')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['detected_type'], 'valid_id')
        self.assertEqual(result['document_subtype'], subtype)
        self.assertEqual(result['slot_result'], 'matched')
        self.assertTrue(result['id_evidence']['issuer'])
        self.assertTrue(result['id_evidence']['document_marker'])
        self.assertTrue(result['id_evidence']['identity_fields'])
        self.assertTrue(result['id_evidence']['subtype_fields'])
        self.assertNotIn('TEST-12345', str(result['id_evidence']))

    def test_recognizes_supported_philippine_id_subtypes(self):
        self.assert_recognized('national_id', 'Philippine Statistics Authority PhilSys Philippine Identification National ID Full Name TEST APPLICANT Date of Birth January 2 1990 Address Sample Antique PCN TEST-12345')
        self.assert_recognized('drivers_license', "Land Transportation Office DOTr Driver's License Name TEST APPLICANT Date of Birth January 2 1990 Address Sample Antique License Number TEST-12345 DL Codes A")
        self.assert_recognized('philippine_passport', 'Republic of the Philippines Passport Full Name TEST APPLICANT Date of Birth January 2 1990 Nationality Filipino Passport Number TEST-12345 Date of Expiry January 2 2030')
        self.assert_recognized('prc_pic', 'Professional Regulation Commission PRC Professional Identification Card Name TEST APPLICANT Date of Birth January 2 1990 Profession Test Engineer Registration Number TEST-12345 Valid Until January 2 2030')

    def test_generic_id_is_staff_review_not_wrong_slot(self):
        result = classify_document('Identification Card Full Name TEST APPLICANT Date of Birth January 2 1990 Address Sample Antique ID Number TEST-12345 Valid Until January 2 2030', 'valid_id')
        self.assertEqual(result['detected_type'], 'valid_id')
        self.assertEqual(result['document_subtype'], 'other_id')
        self.assertEqual(result['slot_result'], 'manual_review')
        self.assertEqual(result['decision'], 'uncertain')

    def test_known_non_id_is_wrong_slot_and_weak_id_is_review(self):
        wrong = classify_document('Sample Hospital Billing Statement Patient Name TEST Account Number TEST Total Charges Amount Due Balance Billing Office', 'valid_id')
        self.assertEqual(wrong['decision'], 'wrong_slot')
        self.assertEqual(wrong['detected_type'], 'billing_statement')
        weak = classify_document('Name TEST APPLICANT Number TEST-12345', 'valid_id')
        self.assertEqual(weak['decision'], 'uncertain')
        self.assertEqual(weak['document_subtype'], 'unknown')

    def test_id_numbers_are_not_extracted_or_exposed_as_evidence(self):
        text = 'Philippine Statistics Authority PhilSys National ID Full Name TEST APPLICANT Date of Birth January 2 1990 Address Sample Antique PCN TEST-12345 ID Number SECRET-999'
        fields = extract_document_fields('valid_id', text)
        result = classify_document(text, 'valid_id')
        self.assertNotIn('id_number', fields)
        self.assertNotIn('SECRET-999', str(result['id_evidence']))


if __name__ == '__main__':
    unittest.main()
