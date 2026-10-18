"""Synthetic OCR-only regression coverage for the Philippine Death Certificate slot."""
import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate


FORM_103 = '''Republic of the Philippines
Office of the Municipal Civil Registrar
MUNICIPAL FORM NO. 103
CERTIFICATE OF DEATH
Name of Deceased: TEST DECEASED 001
Sex: Male
Date of Death: August 1, 2026
Place of Death: Sample Provincial Hospital, Antique
Cause of Death: SYNTHETIC SENSITIVE DETAIL
Registry Number: TEST-REGISTRY-001
Informant: TEST INFORMANT
'''
LOCAL_CIVIL = '''Office of the City Civil Registrar
DEATH CERTIFICATE
Deceased Name: TEST DECEASED 001
Age at Time of Death: 70
Date of Death: August 1, 2026
Place of Death: Sample City
Date of Registration: August 2, 2026
Civil Registrar: Test Officer
'''
PSA_STYLE = '''Philippine Statistics Authority
CERTIFICATE OF DEATH
Name of the Deceased: TEST DECEASED 001
Date of Birth: January 2, 1950
Date of Death: August 1, 2026
Place of Death: Sample Municipality
Medical Certification: Attending Physician
'''


class PhilippineDeathCertificateValidationTests(unittest.TestCase):
    def assert_match(self, text, subtype):
        result = classify_document(text, 'death_cert')
        self.assertEqual(result['detected_type'], 'death_cert')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['document_subtype'], subtype)
        self.assertTrue(result['death_evidence']['document_marker'])
        self.assertTrue(result['death_evidence']['deceased_identity'])
        self.assertTrue(result['death_evidence']['death_event'])
        return result

    def test_municipal_local_and_psa_variants_match(self):
        municipal = self.assert_match(FORM_103, 'municipal_form_103')
        local = self.assert_match(LOCAL_CIVIL, 'civil_registry_death_certificate')
        psa = self.assert_match(PSA_STYLE, 'psa_death_certificate')
        self.assertIn('Municipal Form No. 103 marker detected', municipal['death_evidence']['civil_registry'])
        self.assertTrue(local['death_evidence']['registration_informant'])
        self.assertTrue(psa['death_evidence']['civil_registry'])

    def test_psa_is_not_required_and_medical_certification_can_complete_structure(self):
        no_psa = PSA_STYLE.replace('Philippine Statistics Authority\n', '')
        result = self.assert_match(no_psa, 'death_certificate')
        self.assertIn('Medical certification section detected', result['death_evidence']['medical_certification'])

    def test_death_related_records_and_sparse_text_need_staff_confirmation(self):
        for text in (
            'Burial Permit Name of Deceased TEST DECEASED Date of Death August 1 2026',
            'Facts of death appear in our Register of Deaths Name of Deceased TEST Date of Death August 1 2026',
            'cause of death',
            'Republic of the Philippines Certificate',
            '',
        ):
            self.assertEqual(classify_document(text, 'death_cert')['decision'], 'uncertain')

    def test_medical_certificate_and_funeral_contract_do_not_match_death_slot(self):
        medical = 'Sample Hospital Medical Certificate Patient Name TEST This is to certify Diagnosis TEST Attending Physician Dr Test death mentioned'
        funeral = 'Funeral Contract Funeral Home Service Agreement Deceased Name TEST Casket Interment Charges'
        self.assertEqual(classify_document(medical, 'death_cert')['decision'], 'wrong_slot')
        self.assertEqual(classify_document(funeral, 'death_cert')['decision'], 'wrong_slot')

    def test_birth_certificate_and_valid_id_are_wrong_slot(self):
        birth = 'Republic of the Philippines Birth Certificate Name of Child TEST Date of Birth January 2 2020 Civil Registrar'
        valid_id = 'Philippine Statistics Authority PhilSys National ID Full Name TEST Date of Birth January 2 1990 Address Sample PCN TEST-FORMAT'
        self.assertEqual(classify_document(birth, 'death_cert')['decision'], 'wrong_slot')
        self.assertEqual(classify_document(valid_id, 'death_cert')['decision'], 'wrong_slot')

    def test_sensitive_cause_and_registry_values_are_not_evidence_or_extracted(self):
        result = self.assert_match(FORM_103, 'municipal_form_103')
        fields = extract_document_fields('death_cert', FORM_103)['fields']
        rendered = str(result['death_evidence'])
        self.assertNotIn('SYNTHETIC SENSITIVE DETAIL', rendered)
        self.assertNotIn('TEST-REGISTRY-001', rendered)
        self.assertNotIn('registry_number', fields)

    def test_burial_beneficiary_snapshot_is_the_existing_deceased_reference(self):
        fields = extract_document_fields('death_cert', FORM_103.replace('TEST DECEASED 001', 'JUAN DELA CRUZ'))['fields']
        quality = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}
        result = evaluate('death_cert', 'death_cert', 90, quality, fields, 'Juan Dela Cruz', [], application_context={'full_name': 'Juan Dela Cruz', 'burial_identity_source': 'beneficiary_snapshot'})
        self.assertEqual(result['findings']['checks']['name_match']['status'], 'match')
        self.assertNotIn('date_of_death', result['findings']['checks']['consistency_check']['comparisons'])


if __name__ == '__main__':
    unittest.main()
