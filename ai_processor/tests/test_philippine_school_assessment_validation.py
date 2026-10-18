"""Synthetic OCR-only coverage for the School Assessment Form slot."""
import unittest
from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract_document_fields
from ai_processor.verification import evaluate

JHS = '''Sample National High School
SCHOOL ASSESSMENT FORM
Learner Name: TEST LEARNER 001
LRN: TEST-LRN-ONLY
School Year: 2026-2027
Grade Level: Grade 8
Assessment of Fees: Miscellaneous Fee, Activity Fee
Total Assessment: PHP 0.00
School Registrar
'''
SHS = '''Sample Private Academy
ENROLLMENT ASSESSMENT FORM
Student Name: TEST LEARNER 001
Student Number: TEST-STUDENT-ONLY
School Year: 2026-2027
Grade 11 HUMSS Track
Tuition Fee Miscellaneous Fees Voucher Discount Net Assessment Payment Schedule
Registrar Signature
'''

class PhilippineSchoolAssessmentValidationTests(unittest.TestCase):
    def match(self, text):
        result = classify_document(text, 'school_assessment')
        self.assertEqual(result['decision'], 'match'); self.assertEqual(result['detected_type'], 'school_assessment')
        self.assertTrue(result['school_evidence']['learner_identity']); self.assertTrue(result['school_evidence']['financial_assessment'])
        return result
    def test_private_jhs_shs_and_zero_fee_assessments_match(self):
        jhs=self.match(JHS); shs=self.match(SHS); enrol=self.match(SHS.replace('ENROLLMENT','ENROLMENT'))
        self.assertEqual(jhs['document_subtype'],'school_assessment'); self.assertEqual(shs['document_subtype'],'senior_high_school_assessment'); self.assertTrue(enrol['school_evidence']['shs_support'])
    def test_registration_academic_and_related_school_documents_do_not_match(self):
        self.assertEqual(classify_document('Sample High School BASIC EDUCATION ENROLLMENT FORM Learner Name TEST LRN Grade Level School Year Parent Guardian','school_assessment')['decision'],'wrong_slot')
        self.assertEqual(classify_document('Sample High School REPORT CARD SF9 Learner Name TEST Quarterly Grades Final Rating General Average','school_assessment')['decision'],'wrong_slot')
        for text in ('Sample School Statement of Account Student Name TEST Amount Due PHP 100','Certificate of Enrollment Student Name TEST Grade Level School Year Registrar','School Assessment',''):
            self.assertEqual(classify_document(text,'school_assessment')['decision'],'uncertain')
    def test_sensitive_values_not_in_evidence_and_beneficiary_snapshot_is_used(self):
        result=self.match(JHS); self.assertNotIn('TEST-LRN-ONLY',str(result['school_evidence'])); self.assertNotIn('0.00',str(result['school_evidence']))
        fields=extract_document_fields('school_assessment',JHS.replace('TEST LEARNER 001','JUAN DELA CRUZ'))['fields']; quality={'decision':'acceptable','blank':False,'blurred':False,'blur_variance':100}
        checked=evaluate('school_assessment','school_assessment',90,quality,fields,'Juan Dela Cruz',[],application_context={'full_name':'Juan Dela Cruz','educational_identity_source':'beneficiary_snapshot'})
        self.assertEqual(checked['findings']['checks']['name_match']['status'],'match')

if __name__ == '__main__': unittest.main()
