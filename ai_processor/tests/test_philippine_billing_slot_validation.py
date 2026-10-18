"""Synthetic OCR-only regression coverage for the Billing Statement/Prescription slot."""
import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate


GOVERNMENT_SOA = '''Republic of the Philippines Sample Provincial Hospital
Billing Office
STATEMENT OF ACCOUNT
Patient Name: TEST PATIENT 001
Account Number: TEST-ACCOUNT-001
Total Charges: PHP 12345.67
Outstanding Balance: PHP 6789.00
Prepared By: Test Billing Clerk
'''
PRIVATE_FINAL_BILL = '''Sample Private Medical Center
FINAL HOSPITAL BILL
Name of Patient: TEST PATIENT 001
Hospital Number: TEST-HOSPITAL-001
Net Amount Due: PHP 4567.00
Billing Department
'''
PRESCRIPTION = '''PRESCRIPTION
Patient Name: TEST PATIENT 001
Generic Drug: Testcillin tablet 500 mg
Sig: Take twice daily for 7 days
Attending Physician: Dr. Test Doctor
'''
CLINIC_PRESCRIPTION = '''Sample Community Clinic
Rx
Name: TEST PATIENT 001
Medicine: Testmox capsule 250 mg
Take every 8 hours
Doctor: Dr. Test Doctor
PRC License No. TEST-LICENSE-ONLY
'''


class PhilippineBillingSlotValidationTests(unittest.TestCase):
    def assert_match(self, text, subtype):
        result = classify_document(text, 'billing_statement')
        self.assertEqual(result['detected_type'], 'billing_statement')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['slot_result'], 'matched')
        self.assertEqual(result['document_subtype'], subtype)
        return result

    def test_statement_of_account_hospital_bill_and_private_bill_match(self):
        government = self.assert_match(GOVERNMENT_SOA, 'billing_statement')
        private = self.assert_match(PRIVATE_FINAL_BILL, 'billing_statement')
        temporary = self.assert_match(PRIVATE_FINAL_BILL.replace('FINAL HOSPITAL BILL', 'TEMPORARY HOSPITAL BILL'), 'billing_statement')
        self.assertTrue(government['billing_evidence']['billing_office'])
        self.assertTrue(private['billing_evidence']['financial_fields'])
        self.assertTrue(temporary['billing_evidence']['facility'])

    def test_prescriptions_match_without_requiring_a_facility(self):
        printed = self.assert_match(PRESCRIPTION, 'prescription')
        clinic = self.assert_match(CLINIC_PRESCRIPTION, 'prescription')
        self.assertTrue(printed['billing_evidence']['medicine_fields'])
        self.assertTrue(printed['billing_evidence']['dosage_or_directions'])
        self.assertIn('Physician license field detected', clinic['billing_evidence']['physician'])

    def test_related_receipts_sparse_markers_and_related_documents_need_staff_confirmation(self):
        for text in (
            'Sample Hospital Official Receipt Patient Name TEST Total Amount PHP 100 Cashier',
            'Pharmacy Receipt Medicine Test Drug Total Amount PHP 100',
            'Hospital Amount Due PHP 100',
            'Rx',
            'Treatment Protocol Patient Name TEST',
            '',
        ):
            result = classify_document(text, 'billing_statement')
            self.assertEqual(result['decision'], 'uncertain')

    def test_medical_certificate_and_valid_id_remain_wrong_slot(self):
        medical = 'Sample Hospital Medical Certificate Patient Name TEST This is to certify Diagnosis TEST Attending Physician Dr Test'
        valid_id = 'Philippine Statistics Authority PhilSys National ID Full Name TEST Date of Birth January 2 1990 Address Sample PCN TEST-FORMAT'
        self.assertEqual(classify_document(medical, 'billing_statement')['decision'], 'wrong_slot')
        self.assertEqual(classify_document(valid_id, 'billing_statement')['decision'], 'wrong_slot')

    def test_sensitive_values_are_not_in_billing_evidence(self):
        bill = self.assert_match(GOVERNMENT_SOA, 'billing_statement')
        prescription = self.assert_match(PRESCRIPTION, 'prescription')
        self.assertNotIn('TEST-ACCOUNT-001', str(bill['billing_evidence']))
        self.assertNotIn('6789.00', str(bill['billing_evidence']))
        self.assertNotIn('Testcillin', str(prescription['billing_evidence']))
        self.assertNotIn('TEST-LICENSE-ONLY', str(classify_document(CLINIC_PRESCRIPTION, 'billing_statement')['billing_evidence']))

    def test_patient_consistency_remains_functional_without_account_or_medicine_comparison(self):
        billing_fields = extract_document_fields('billing_statement', '''STATEMENT OF ACCOUNT
Patient Name: JUAN DELA CRUZ
Account Number: TEST-ACCOUNT-001
Amount Due: PHP 12345.67
''')['fields']
        prescription_fields = extract_document_fields('billing_statement', '''PRESCRIPTION
Patient Name: JUAN DELA CRUZ
Medicine: Testcillin 500 mg
Physician: Dr. Test Doctor
''')['fields']
        quality = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}
        billing = evaluate('billing_statement', 'billing_statement', 90, quality, billing_fields, 'Juan Dela Cruz', [])
        prescription = evaluate('billing_statement', 'billing_statement', 90, quality, prescription_fields, 'Juan Dela Cruz', [])
        self.assertEqual(billing['findings']['checks']['name_match']['status'], 'match')
        self.assertEqual(prescription['findings']['checks']['name_match']['status'], 'match')
        self.assertNotIn('TEST-ACCOUNT-001', str(billing['findings']['consistency_check']))
        self.assertNotIn('Testcillin', str(prescription['findings']['consistency_check']))


if __name__ == '__main__':
    unittest.main()
