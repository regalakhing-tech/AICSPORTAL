import unittest

from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate


DEPED = '''REPUBLIC OF THE PHILIPPINES DEPARTMENT OF EDUCATION SAMPLE NATIONAL HIGH SCHOOL
BASIC EDUCATION ENROLLMENT FORM
Learner Name: TEST LEARNER 001
School Year: 2026-2027 Grade Level to Enroll: Grade 7
With LRN? No LRN Returning / Balik-Aral Parent / Guardian
School Registrar Date Registered'''

PRIVATE_SHS = '''SAMPLE ACADEMY STUDENT REGISTRATION FORM
Student Name: TEST LEARNER 002 School Year: 2026-2027 Grade 12
Student Type: New Student Section: Orchid Semester: 1 Track: Academic Strand: STEM
Subjects Enrolled School Registrar Enrollment Date'''


SCHOOL_ID = '''SAMPLE NATIONAL HIGH SCHOOL
STUDENT ID
Name: TEST LEARNER 003
Student No. 2026-00123 LRN 123456789012
School Year 2026-2027 Grade 9'''


class PhilippineRegistrationFormValidationTests(unittest.TestCase):
    def test_school_id_matches_the_school_id_slot(self):
        result = classify_document(SCHOOL_ID, 'school_id')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['detected_type'], 'school_id')

    def test_school_id_fields_are_read_without_errors(self):
        fields = extract_document_fields('school_id', SCHOOL_ID, {})['fields']
        self.assertEqual(set(fields), {'client_name'})
        self.assertEqual(fields['client_name']['status'], 'extracted')

    def test_school_id_is_not_an_enrollment_form(self):
        result = classify_document(SCHOOL_ID, 'registration_form')
        self.assertEqual(result['decision'], 'wrong_slot')
        self.assertEqual(result['detected_type'], 'school_id')

    def test_enrollment_form_is_not_a_school_id(self):
        result = classify_document(DEPED, 'school_id')
        self.assertNotEqual(result['decision'], 'match')

    def match(self, text):
        result = classify_document(text, 'registration_form')
        self.assertEqual(result['decision'], 'match')
        self.assertEqual(result['detected_type'], 'registration_form')
        self.assertTrue(result['registration_evidence']['school_issuer'])
        self.assertTrue(result['registration_evidence']['registration_marker'])
        self.assertTrue(result['registration_evidence']['learner_identity'])
        self.assertTrue(result['registration_evidence']['school_year_or_grade'])
        self.assertTrue(result['registration_evidence']['registration_fields'])
        return result

    def test_deped_and_enhanced_basic_education_forms_match(self):
        result = self.match(DEPED)
        self.assertEqual(result['document_subtype'], 'deped_basic_education_enrollment_form')
        enhanced = self.match(DEPED.replace('BASIC EDUCATION ENROLLMENT FORM', 'ENHANCED BASIC EDUCATION ENROLLMENT FORM'))
        self.assertEqual(enhanced['display_type'], 'Enhanced Basic Education Enrollment Form')
        self.assertTrue(enhanced['registration_evidence']['parent_guardian_section'])

    def test_private_jhs_shs_and_enrolment_spelling_match_without_deped(self):
        result = self.match(PRIVATE_SHS)
        self.assertEqual(result['document_subtype'], 'private_school_registration_form')
        self.assertTrue(result['registration_evidence']['shs_track_strand'])
        self.assertTrue(result['registration_evidence']['subject_section_structure'])
        self.match(PRIVATE_SHS.replace('REGISTRATION FORM', 'ENROLMENT FORM').replace('Grade 12', 'Grade 8').replace('Track: Academic Strand: STEM', ''))

    def test_early_and_other_related_school_documents_need_confirmation(self):
        early = classify_document(DEPED.replace('BASIC EDUCATION ENROLLMENT FORM', 'BASIC EDUCATION EARLY REGISTRATION FORM'), 'registration_form')
        self.assertEqual(early['decision'], 'uncertain')
        self.assertEqual(early['document_subtype'], 'early_registration_form')
        for text in (
            'Sample High School CERTIFICATE OF ENROLLMENT This is to certify Student Name TEST School Year Grade 8 Registrar',
            'Sample School STUDENT INFORMATION SHEET Student Name TEST Parent Guardian Address',
            'Sample School APPLICATION FOR ADMISSION Applicant Desired Grade Entrance Examination',
            'Sample School enrollment',
            '',
        ):
            self.assertEqual(classify_document(text, 'registration_form')['decision'], 'uncertain')

    def test_assessment_and_report_card_are_wrong_slot(self):
        assessment = 'Sample High School SCHOOL ASSESSMENT FORM Student Name TEST Grade Level School Year Tuition Fee Total Assessment Registrar'
        report_card = 'Sample High School REPORT CARD SF9 Learner Name TEST School Year Grade 8 Quarterly Grade Final Rating General Average Attendance Record'
        self.assertEqual(classify_document(assessment, 'registration_form')['decision'], 'wrong_slot')
        self.assertEqual(classify_document(report_card, 'registration_form')['decision'], 'wrong_slot')

    def test_safe_evidence_and_educational_beneficiary_consistency(self):
        sensitive = DEPED + ' LRN: TEST-LRN-999 Student Number: 111 Parent Name: TEST PARENT Address: SAMPLE STREET 4Ps ID: ABC Religion: TEST'
        result = self.match(sensitive)
        self.assertNotIn('TEST-LRN-999', str(result['registration_evidence']))
        self.assertNotIn('TEST PARENT', str(result['registration_evidence']))
        fields = extract_document_fields('registration_form', DEPED.replace('TEST LEARNER 001', 'Juan Dela Cruz'))['fields']
        quality = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}
        checked = evaluate('registration_form', 'registration_form', 90, quality, fields, 'Juan Dela Cruz', [], application_context={'full_name': 'Juan Dela Cruz', 'educational_identity_source': 'beneficiary_snapshot'})
        self.assertEqual(checked['findings']['checks']['name_match']['status'], 'match')


if __name__ == '__main__':
    unittest.main()
