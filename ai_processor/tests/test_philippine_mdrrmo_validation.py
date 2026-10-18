"""Synthetic OCR-only coverage for MDRRMO/LDRRMO certification validation."""
import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate


DAMAGE = '''REPUBLIC OF THE PHILIPPINES MUNICIPALITY OF SAMPLE
MUNICIPAL DISASTER RISK REDUCTION AND MANAGEMENT OFFICE
CERTIFICATION OF DAMAGE
Name of Affected Person: TEST BENEFICIARY 001
Residential House affected by Typhoon Sample. Partially Damaged.
Based on Field Validation. Certified By MDRRMO Officer.'''

DISPLACEMENT = '''CITY OF SAMPLE LOCAL DISASTER RISK REDUCTION AND MANAGEMENT OFFICE
THIS IS TO CERTIFY THAT
Affected Family: TEST FAMILY Flooding event. Temporarily Displaced.
Evacuation Center. Upon Inspection. Certified By LDRRMO Officer.'''


class PhilippineMdrrmoValidationTests(unittest.TestCase):
    def match(self, text):
        result = classify_document(text, 'mdrrmo_cert')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['detected_type'], 'mdrrmo_cert')
        self.assertTrue(result['mdrrmo_evidence']['drrmo_issuer'])
        self.assertTrue(result['mdrrmo_evidence']['certification_marker'])
        self.assertTrue(result['mdrrmo_evidence']['affected_person_or_property'])
        self.assertTrue(result['mdrrmo_evidence']['disaster_context'])
        return result

    def test_damage_variants_and_no_monetary_amount_match(self):
        partial = self.match(DAMAGE)
        self.assertEqual(partial['document_subtype'], 'house_damage_certification')
        self.assertTrue(partial['mdrrmo_evidence']['damage_status'])
        total = self.match(DAMAGE.replace('MDRRMO', 'CDRRMO').replace('Municipal Disaster Risk Reduction and Management Office', 'City Disaster Risk Reduction and Management Office').replace('Partially Damaged', 'Totally Damaged'))
        self.assertTrue(total['mdrrmo_evidence']['officer_authorization'])
        provincial = self.match(DAMAGE.replace('MDRRMO', 'PDRRMO').replace('Municipal Disaster Risk Reduction and Management Office', 'Provincial Disaster Risk Reduction and Management Office'))
        self.assertTrue(provincial['mdrrmo_evidence']['drrmo_issuer'])
        filipino = self.match(DAMAGE.replace('Partially Damaged', 'Bahagyang Nasira').replace('Typhoon', 'Bagyo'))
        self.assertTrue(filipino['mdrrmo_evidence']['damage_status'])

    def test_displacement_satisfies_the_same_canonical_requirement(self):
        result = self.match(DISPLACEMENT)
        self.assertEqual(result['document_subtype'], 'displacement_certification')
        self.assertTrue(result['mdrrmo_evidence']['displacement_status'])

    def test_related_documents_do_not_silently_match(self):
        for text in (
            'Office of the Punong Barangay BARANGAY CERTIFICATION Name of Resident Flood damaged house Typhoon',
            'BUREAU OF FIRE PROTECTION FIRE INCIDENT CERTIFICATION House damaged by fire',
            'Sangguniang Bayan RESOLUTION DECLARING A STATE OF CALAMITY due to flooding',
            'LGU INCIDENT REPORT Flood affected families response actions',
            'DAFAC Disaster Assistance Family Access Card Affected Household Flood',
            'Certificate of Residency Resident affected by typhoon',
            'disaster damage',
            '',
        ):
            self.assertEqual(classify_document(text, 'mdrrmo_cert')['decision'], 'uncertain')

    def test_safe_evidence_and_disaster_beneficiary_snapshot_consistency(self):
        sensitive = DAMAGE + ' Certificate Number: TEST-123 Address: SECRET SITIO Estimated Cost of Damage: PHP 99999'
        result = self.match(sensitive)
        self.assertNotIn('TEST-123', str(result['mdrrmo_evidence']))
        self.assertNotIn('99999', str(result['mdrrmo_evidence']))
        fields = extract_document_fields('mdrrmo_cert', DAMAGE.replace('TEST BENEFICIARY 001', 'Juan Dela Cruz'))['fields']
        quality = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}
        checked = evaluate('mdrrmo_cert', 'mdrrmo_cert', 90, quality, fields, 'Juan Dela Cruz', [], application_context={'full_name': 'Juan Dela Cruz', 'disaster_identity_source': 'beneficiary_snapshot'})
        self.assertEqual(checked['findings']['checks']['name_match']['status'], 'match')


if __name__ == '__main__':
    unittest.main()
