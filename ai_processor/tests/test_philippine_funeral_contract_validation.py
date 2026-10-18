"""Synthetic OCR-only regression coverage for the Funeral Contract slot."""
import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate


FUNERAL_CONTRACT = '''Sample Punerarya Funeral Parlor
FUNERAL SERVICE CONTRACT
Name of Deceased: TEST DECEASED 001
Contracting Party: TEST RELATIVE 002
Funeral Package: Casket, Embalming, Chapel Service, Hearse
Total Contract Price: PHP 25000.00
Payment Terms: Installment
Authorized Representative: Test Funeral Director
Contract Number: TEST-CONTRACT-001
'''
MEMORIAL_CONTRACT = '''Sample Memorial Chapel
CONTRACT FOR FUNERAL SERVICES
Name of Decedent: TEST DECEASED 001
Service Package: Coffin, Viewing, Funeral Coach, Interment Assistance
Down Payment: PHP 5000.00
Client Signature: TEST RELATIVE 002
'''


class PhilippineFuneralContractValidationTests(unittest.TestCase):
    def assert_match(self, text):
        result = classify_document(text, 'funeral_contract')
        self.assertEqual(result['detected_type'], 'funeral_contract')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['document_subtype'], 'funeral_service_contract')
        self.assertTrue(result['funeral_evidence']['funeral_establishment'])
        self.assertTrue(result['funeral_evidence']['contract_marker'])
        self.assertTrue(result['funeral_evidence']['deceased_identity'])
        self.assertTrue(result['funeral_evidence']['service_package'])
        return result

    def test_funeral_parlor_and_memorial_chapel_contracts_match(self):
        parlor = self.assert_match(FUNERAL_CONTRACT)
        chapel = self.assert_match(MEMORIAL_CONTRACT)
        self.assertTrue(parlor['funeral_evidence']['financial_terms'])
        self.assertTrue(parlor['funeral_evidence']['authorization'])
        self.assertTrue(chapel['funeral_evidence']['authorization'])

    def test_contracting_party_is_structural_not_a_deceased_or_applicant_requirement(self):
        fields = extract_document_fields('funeral_contract', FUNERAL_CONTRACT.replace('TEST DECEASED 001', 'JUAN DELA CRUZ'))['fields']
        quality = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}
        result = evaluate('funeral_contract', 'funeral_contract', 90, quality, fields, 'Juan Dela Cruz', [], application_context={'full_name': 'Juan Dela Cruz', 'burial_identity_source': 'beneficiary_snapshot'})
        self.assertEqual(result['findings']['checks']['name_match']['status'], 'match')
        self.assertNotIn('TEST RELATIVE 002', str(result['findings']['consistency_check']))

    def test_related_funeral_documents_and_sparse_text_need_staff_confirmation(self):
        for text in (
            'Sample Funeral Home Statement of Account Name of Deceased TEST Balance Due PHP 10000',
            'Sample Funeral Home Certificate of Balance Deceased Name TEST Remaining Balance PHP 10000',
            'Sample Funeral Home Promissory Note Deceased Name TEST Promise to Pay PHP 10000',
            'Burial Permit Name of Deceased TEST',
            'Transfer Permit Cadaver Name of Deceased TEST',
            'Sample Funeral Home Official Receipt Amount Paid PHP 1000',
            'Memorial Pre-Need Plan Client TEST',
            'Funeral Amount PHP 1000',
            '',
        ):
            self.assertEqual(classify_document(text, 'funeral_contract')['decision'], 'uncertain')

    def test_death_and_medical_certificates_remain_wrong_slot(self):
        death = 'Certificate of Death Name of Deceased TEST Sex Male Date of Death August 1 2026 Place of Death Sample Civil Registrar Registry Number TEST'
        medical = 'Sample Hospital Medical Certificate Patient Name TEST This is to certify Diagnosis TEST Attending Physician Dr Test'
        self.assertEqual(classify_document(death, 'funeral_contract')['decision'], 'wrong_slot')
        self.assertEqual(classify_document(medical, 'funeral_contract')['decision'], 'wrong_slot')

    def test_sensitive_contract_values_are_not_evidence_or_extracted(self):
        result = self.assert_match(FUNERAL_CONTRACT)
        fields = extract_document_fields('funeral_contract', FUNERAL_CONTRACT)['fields']
        self.assertNotIn('25000.00', str(result['funeral_evidence']))
        self.assertNotIn('TEST-CONTRACT-001', str(result['funeral_evidence']))
        self.assertNotIn('amount', fields)
        self.assertNotIn('reference_number', fields)


if __name__ == '__main__':
    unittest.main()
