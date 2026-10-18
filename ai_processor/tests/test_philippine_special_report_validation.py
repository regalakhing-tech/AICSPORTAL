"""Synthetic OCR-only coverage for the Special Cases Police/Medical Report slot."""
import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate


PNP_REPORT = '''Philippine National Police Sample Municipal Police Station
INCIDENT RECORD FORM
Blotter Entry Number: TEST-BLOTTER-001
Type of Incident: SYNTHETIC INCIDENT
Date Time of Incident: August 1 2026 10:00
Place of Incident: Sample Barangay
Victim Name: TEST BENEFICIARY 001
Reporting Person: TEST REPRESENTATIVE 002
Facts of the Incident: SENSITIVE SYNTHETIC NARRATIVE
Recorded By: PSSg Test Officer
'''
MEDICAL_REPORT = '''Sample Private Medical Center
MEDICAL EXAMINATION REPORT
Name of Examinee: TEST BENEFICIARY 001
Date Examined: August 1 2026
Physical Examination: SYNTHETIC EXAMINATION DETAIL
Clinical Findings: SYNTHETIC FINDING DETAIL
Assessment: SYNTHETIC ASSESSMENT
Examining Physician: Dr. Test Doctor
'''
MEDICO_LEGAL = '''Sample Provincial Hospital
MEDICO-LEGAL REPORT
Patient Name: TEST BENEFICIARY 001
Physical Findings: SYNTHETIC SENSITIVE DETAIL
Impression: SYNTHETIC ASSESSMENT
Medical Officer: Dr. Test Doctor
PRC License No. TEST-LICENSE-ONLY
'''


class PhilippineSpecialReportValidationTests(unittest.TestCase):
    def assert_match(self, text, subtype):
        result = classify_document(text, 'report_police_med')
        self.assertEqual(result['detected_type'], 'report_police_med')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['document_subtype'], subtype)
        return result

    def test_pnp_incident_report_and_city_police_variant_match(self):
        report = self.assert_match(PNP_REPORT, 'police_report')
        city = self.assert_match(PNP_REPORT.replace('Sample Municipal Police Station', 'Sample City Police Station').replace('INCIDENT RECORD FORM', 'POLICE REPORT'), 'police_report')
        self.assertTrue(report['special_report_evidence']['police_issuer'])
        self.assertTrue(report['special_report_evidence']['incident_fields'])
        self.assertTrue(city['special_report_evidence']['officer_certification'])

    def test_medical_and_medico_legal_reports_match_same_requirement_group(self):
        medical = self.assert_match(MEDICAL_REPORT, 'medical_report')
        medico = self.assert_match(MEDICO_LEGAL, 'medical_report')
        self.assertTrue(medical['special_report_evidence']['examination_findings'])
        self.assertTrue(medical['special_report_evidence']['assessment'])
        self.assertIn('Physician license field detected', medico['special_report_evidence']['physician'])

    def test_related_documents_and_sparse_markers_need_staff_confirmation(self):
        for text in (
            'Philippine National Police Police Clearance Full Name TEST Valid Until 2030',
            'Barangay Blotter Victim TEST Incident',
            'PNP incident',
            'Sample Hospital Laboratory Result Patient Name TEST Findings TEST Pathologist',
            'Hospital injury',
            '',
        ):
            self.assertEqual(classify_document(text, 'report_police_med')['decision'], 'uncertain')
        self.assertEqual(classify_document('Sample Hospital Medical Certificate Patient Name TEST This is to certify Diagnosis TEST Attending Physician Dr Test', 'report_police_med')['decision'], 'wrong_slot')

    def test_known_unrelated_documents_remain_wrong_slot(self):
        valid_id = 'Philippine Statistics Authority PhilSys National ID Full Name TEST Date of Birth January 2 1990 Address Sample PCN TEST-FORMAT'
        billing = 'Sample Hospital Billing Statement Patient Name TEST Account Number TEST Total Charges Amount Due Balance Billing Office'
        death = 'Certificate of Death Name of Deceased TEST Sex Male Date of Death August 1 2026 Place of Death Sample Civil Registrar Registry Number TEST'
        funeral = 'Funeral Contract Funeral Home Name of Deceased TEST Casket Embalming Hearse Service Agreement'
        for text in (valid_id, billing, death, funeral):
            self.assertEqual(classify_document(text, 'report_police_med')['decision'], 'wrong_slot')

    def test_sensitive_case_and_clinical_values_are_not_classifier_evidence(self):
        police = self.assert_match(PNP_REPORT, 'police_report')
        medical = self.assert_match(MEDICAL_REPORT, 'medical_report')
        self.assertNotIn('TEST-BLOTTER-001', str(police['special_report_evidence']))
        self.assertNotIn('SENSITIVE SYNTHETIC NARRATIVE', str(police['special_report_evidence']))
        self.assertNotIn('SYNTHETIC FINDING DETAIL', str(medical['special_report_evidence']))
        self.assertNotIn('TEST-LICENSE-ONLY', str(self.assert_match(MEDICO_LEGAL, 'medical_report')['special_report_evidence']))

    def test_person_served_comparison_uses_victim_or_patient_not_reporting_person(self):
        police_fields = extract_document_fields('report_police_med', PNP_REPORT.replace('TEST BENEFICIARY 001', 'JUAN DELA CRUZ'))['fields']
        medical_fields = extract_document_fields('report_police_med', MEDICAL_REPORT.replace('TEST BENEFICIARY 001', 'JUAN DELA CRUZ'))['fields']
        quality = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}
        context = {'full_name': 'Juan Dela Cruz', 'special_case_identity_source': 'beneficiary_snapshot'}
        police = evaluate('report_police_med', 'report_police_med', 90, quality, police_fields, 'Juan Dela Cruz', [], application_context=context)
        medical = evaluate('report_police_med', 'report_police_med', 90, quality, medical_fields, 'Juan Dela Cruz', [], application_context=context)
        self.assertEqual(police['findings']['checks']['name_match']['status'], 'match')
        self.assertEqual(medical['findings']['checks']['name_match']['status'], 'match')
        self.assertNotIn('TEST REPRESENTATIVE 002', str(police['findings']['consistency_check']))
        self.assertNotIn('report_number', police_fields)


if __name__ == '__main__':
    unittest.main()
