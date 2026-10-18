import unittest
import numpy as np
from unittest.mock import Mock, patch
from pathlib import Path
from tempfile import TemporaryDirectory
from ai_processor.document_classifier import classify, classify_document
from ai_processor.document_loader import resolve_path, load_images
from ai_processor import processor
from ai_processor.matching import name_match, normalize_date
from ai_processor.verification import evaluate
from ai_processor.field_extractor import extract
from ai_processor.image_quality import analyze_document_quality

class CoreTests(unittest.TestCase):
    def test_classifier(self):
        self.assertEqual(classify('MEDICAL CERTIFICATE Patient Diagnosis Physician Hospital')[0], 'med_cert')
        self.assertEqual(classify('CERTIFICATE OF DEATH Name of Deceased TEST Sex Male Date of Death August 1 2026 Place of Death Sample Civil Registrar Registry Number TEST')[0], 'death_cert')
    def test_expected_slot_classification_is_conservative(self):
        self.assertEqual(classify_document('MEDICAL CERTIFICATE Patient Diagnosis Physician Hospital', 'med_cert')['decision'], 'match')
        self.assertEqual(classify_document('Sample Hospital Billing Statement Patient Name TEST Account Number TEST Total Charges Amount Due Balance Billing Office', 'med_cert')['decision'], 'wrong_slot')
        self.assertEqual(classify_document('Hospital patient name', 'med_cert')['decision'], 'uncertain')
        self.assertEqual(classify_document('PRESCRIPTION Patient Name JUAN DELA CRUZ Generic Drug tablet 500 mg Take twice daily Attending Physician Dr Test', 'billing_statement')['decision'], 'match')
        self.assertEqual(classify_document('FUNERAL CONTRACT Funeral Home Name of Deceased TEST Casket Embalming Hearse Service Agreement', 'death_cert')['decision'], 'wrong_slot')
        self.assertEqual(classify_document('certificate attendance', 'coe_mswdo')['decision'], 'uncertain')
    def test_all_document_families_and_photo_role(self):
        cases = [
            ('school_assessment', 'Sample High School SCHOOL ASSESSMENT FORM Student Name TEST Grade Level School Year Tuition Fee Total Assessment Registrar'),
            ('registration_form', 'Sample National High School BASIC EDUCATION ENROLLMENT FORM Learner Name TEST School Year Grade Level to Enroll With LRN Returning Parent Guardian Registrar'),
            ('mdrrmo_cert', 'Municipal Disaster Risk Reduction and Management Office CERTIFICATION Name of Affected Person Residential House Typhoon Partially Damaged Based on Field Validation MDRRMO Officer'),
            ('eclip_cert', 'Enhanced Comprehensive Local Integration Program Former Rebel Eligibility Certification'),
            ('crisis_situation_cert', 'Certificate of Person in Crisis Situation MSWDO Social Welfare Certification'),
        ]
        for expected, text in cases:
            self.assertEqual(classify_document(text, expected)['decision'], 'match')
        photo = evaluate('damaged_house_photo', None, 0, {'decision':'acceptable','blank':False,'blurred':False}, {}, 'Test', [], classification={'decision':'not_applicable'})
        self.assertEqual(photo['status'], 'needs_staff_review')
        self.assertEqual(photo['findings']['checks']['content_verification']['status'], 'manual_review_required')
    def test_valid_id_classifier_diagnostic_fixtures(self):
        live_synthetic = (
            'SAMPLE ID - FOR SYSTEM TESTING ONLY TEST IDENTIFICATION CARD Name JUAN DELA CRUZ '
            'Date of Birth January 2 1950 Address San Jose de Buenavista Antique Sex Male '
            'ID Number TEST-000001 Valid Until December 31 2030 SAMPLE - NOT A REAL GOVERNMENT ID'
        )
        live_result = classify_document(live_synthetic, 'valid_id')
        self.assertGreaterEqual(live_result['scores']['valid_id'], 7)
        self.assertEqual(live_result['confidence_band'], 'high')
        self.assertEqual(live_result['decision'], 'uncertain')

        fixture_path = Path(__file__).parent / 'fixtures' / 'classifier_valid_id_ocr.txt'
        compliant_result = classify_document(fixture_path.read_text(encoding='utf-8'), 'valid_id')
        self.assertEqual(compliant_result['detected_type'], 'valid_id')
        self.assertEqual(compliant_result['document_subtype'], 'other_id')
        self.assertEqual(compliant_result['decision'], 'uncertain')
        self.assertGreaterEqual(compliant_result['scores']['valid_id'], 7)
        self.assertTrue(all(score == 0 for family, score in compliant_result['scores'].items() if family != 'valid_id'))

        weak_result = classify_document('Name Juan Dela Cruz Date 2026 Number 12345', 'valid_id')
        self.assertIsNone(weak_result['detected_type'])
        self.assertEqual(weak_result['confidence_band'], 'low')
        self.assertEqual(weak_result['decision'], 'uncertain')
    def test_structured_quality_decisions(self):
        blank=np.full((400,400,3),255,dtype=np.uint8)
        sharp=np.random.default_rng(7).integers(40,220,(500,500,3),dtype=np.uint8)
        tiny=np.zeros((80,80,3),dtype=np.uint8)
        self.assertEqual(analyze_document_quality([blank],30,'',0)['decision'],'reupload')
        self.assertEqual(analyze_document_quality([sharp],30,'Readable document content here',80)['decision'],'acceptable')
        self.assertEqual(analyze_document_quality([tiny],30,'some text',40)['decision'],'reupload')
        multi=analyze_document_quality([sharp,blank],30,'Readable document content here',80)
        self.assertIn(multi['decision'],('acceptable','staff_review'))
    def test_quality_status_precedence(self):
        classification=classify_document('MEDICAL CERTIFICATE Patient Diagnosis Physician Hospital','med_cert')
        blank={'decision':'reupload','reason':'The uploaded document appears blank or contains too little readable content. Please upload a clearer copy.','blank':True,'blurred':False,'blur_variance':0}
        severe={'decision':'reupload','reason':'The uploaded document is severely blurred and cannot be reliably read. Please upload a clearer copy.','blank':False,'blurred':True,'blur_variance':1}
        moderate={'decision':'staff_review','reason':'The document may be difficult to read reliably. Staff review is recommended.','blank':False,'blurred':True,'blur_variance':20}
        acceptable={'decision':'acceptable','reason':'Document quality appears acceptable for verification.','blank':False,'blurred':False,'blur_variance':100}
        fields={'patient_name':{'value':'JULIE SANTOS','confidence':.9},'document_date':{'value':'2026-08-01'},'diagnosis_present':{'value':True}}
        self.assertEqual(evaluate('med_cert',classification['detected_type'],90,blank,fields,'Juan Dela Cruz',[],classification=classification)['status'],'needs_reupload')
        self.assertEqual(evaluate('med_cert',classification['detected_type'],90,severe,fields,'Juan Dela Cruz',[],classification=classification)['status'],'needs_reupload')
        self.assertEqual(evaluate('med_cert',classification['detected_type'],90,moderate,fields,'Juan Dela Cruz',[],classification=classification)['status'],'needs_staff_review')
        self.assertEqual(evaluate('med_cert',classification['detected_type'],90,acceptable,fields,'Juan Dela Cruz',[],classification=classification)['status'],'flagged_inconsistency')
    def test_all_medical_slots_carry_structured_classification_into_verification(self):
        cases = [
            ('valid_id', 'Philippine Statistics Authority PhilSys National ID Full Name JUAN DELA CRUZ Date of Birth January 2 1950 Address Antique PCN TEST-FORMAT-ONLY', {'name': {'value': 'JUAN DELA CRUZ'}}, 'completed'),
            ('med_cert', 'MEDICAL CERTIFICATE Patient JULIE SANTOS Diagnosis Physician Hospital', {'patient_name': {'value': 'JULIE SANTOS', 'confidence': .9}, 'document_date': {'value':'2026-08-01'}, 'diagnosis_present': {'value': True}}, 'flagged_inconsistency'),
            ('billing_statement', 'PRESCRIPTION Patient Name JUAN DELA CRUZ Generic Drug tablet 500 mg Take twice daily Attending Physician Dr Test', {'name': {'value': 'JUAN DELA CRUZ'}, 'amount': {'value':'500'}}, 'completed'),
        ]
        for expected, text, fields, status in cases:
            classification = classify_document(text, expected)
            result = evaluate(expected, classification['detected_type'], 90, {'blank':False,'blurred':False,'blur_variance':100}, fields, 'Juan Dela Cruz', [], classification=classification)
            self.assertIn('classification', result['findings']['checks']['document_type'])
            self.assertEqual(result['findings']['checks']['document_type']['classification']['expected_type'], expected)
            self.assertEqual(result['status'], status)
    def test_name_and_date(self):
        self.assertEqual(name_match('JUAN DELA CRUZ', 'Juan Dela Cruz'), 'match')
        self.assertEqual(name_match('DELA CRUZ, JUAN', 'Juan Dela Cruz'), 'match')
        self.assertEqual(name_match('JUAN D. DELA CRUZ', 'Juan Dela Cruz'), 'match')
        self.assertEqual(name_match('Juan Dela Cruz', 'Julie Dela Cruz'), 'mismatch')
        self.assertEqual(name_match('Juan Dela Cruz', 'Maria Dela Cruz'), 'mismatch')
        self.assertEqual(name_match('JUAN DELA CRUZ', 'JUAM DELA CRUZ'), 'likely_match')
        self.assertEqual(name_match('JUAN D. CRUZ', 'Juan Dela Cruz'), 'match')
        self.assertEqual(name_match('Cruz, Juan Dela', 'Juan Dela Cruz'), 'match')
        self.assertEqual(normalize_date('January 2, 1950'), '1950-01-02')
        self.assertIsNone(normalize_date('01/02/1950'))
    def test_medical_certificate_extracts_birth_and_document_dates_separately(self):
        fields = extract(
            'MEDICAL CERTIFICATE\nPatient Name: JUAN DELA CRUZ\n'
            'Date of Birth: January 2, 1960\nDate: August 21, 2026\n'
            'Diagnosis: Pneumonia\nPhysician: Dr. Maria Santos\nHospital: AICS Medical Center',
            'med_cert',
        )
        self.assertEqual(fields['patient_name']['value'], 'JUAN DELA CRUZ')
        self.assertEqual(fields['birth_date']['value'], '1960-01-02')
        self.assertEqual(fields['document_date']['value'], '2026-08-21')
        self.assertNotEqual(fields['birth_date']['value'], fields['document_date']['value'])
        self.assertEqual(fields['physician_name']['value'], 'Dr. Maria Santos')
        self.assertEqual(fields['hospital_or_clinic']['value'], 'AICS Medical Center')
        self.assertTrue(fields['diagnosis_present']['value'])

    def test_medical_certificate_hospital_clinic_combined_label_keeps_the_facility_value(self):
        fields = extract('MEDICAL CERTIFICATE\nHospital/Clinic: AICS Medical Center\nPhysician: Dr. Maria Santos', 'med_cert')
        self.assertEqual(fields['hospital_or_clinic']['value'], 'AICS Medical Center')
    def test_medical_urgency_requires_explicit_timing_evidence(self):
        fields = extract('MEDICAL CERTIFICATE\nDiagnosis: Pneumonia\nProcedure: scheduled surgery on August 30, 2026', 'med_cert')
        self.assertEqual(fields['urgency_evidence']['status'], 'extracted')
        self.assertIn('scheduled surgery', fields['urgency_evidence']['value'])
        diagnosis_only = extract('MEDICAL CERTIFICATE\nDiagnosis: Pneumonia', 'med_cert')
        self.assertEqual(diagnosis_only['urgency_evidence']['status'], 'not_present')
    def test_medical_certificate_birth_date_conflict_is_flagged(self):
        fields = {'patient_name': {'value': 'JUAN DELA CRUZ'}, 'birth_date': {'value': '1960-01-02'}, 'document_date': {'value': '2026-08-21'}, 'diagnosis_present': {'value': True}}
        result = evaluate('med_cert', 'med_cert', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [{'birth_date': {'value': '1950-01-02'}}], '1950-01-02')
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['checks']['birth_date_consistency']['status'], 'fail')
    def test_medical_certificate_matching_birth_date_has_no_conflict(self):
        fields = {'patient_name': {'value': 'JUAN DELA CRUZ'}, 'birth_date': {'value': '1950-01-02'}, 'document_date': {'value': '2026-08-21'}, 'diagnosis_present': {'value': True}}
        result = evaluate('med_cert', 'med_cert', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [{'birth_date': {'value': '1950-01-02'}}], '1950-01-02')
        self.assertNotIn('Possible birth-date inconsistency detected between Valid ID and Medical Certificate.', result['findings']['reasons'])
    def test_medical_certificate_name_conflict_cannot_be_likely_valid(self):
        fields = {'patient_name': {'value': 'JULIE DELA CRUZ'}, 'birth_date': {'value': '1950-01-02'}, 'document_date': {'value': '2026-08-21'}, 'diagnosis_present': {'value': True}}
        result = evaluate('med_cert', 'med_cert', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [{'_document_type': 'valid_id', 'name': {'value': 'JUAN DELA CRUZ'}}], '1950-01-02')
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['checks']['name_match']['status'], 'mismatch')
        self.assertTrue(any('Name inconsistency detected.' in reason for reason in result['findings']['reasons']))
    def test_low_confidence_name_mismatch_needs_staff_review(self):
        fields = {'patient_name': {'value': 'PEDRO SANTOS', 'confidence': .4}, 'document_date': {'value': '2026-08-21'}, 'diagnosis_present': {'value': True}}
        result = evaluate('med_cert', 'med_cert', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [])
        self.assertEqual(result['status'], 'needs_staff_review')
    def test_address_mismatch_is_staff_review_not_identity_flag(self):
        fields = {'name': {'value': 'JUAN DELA CRUZ', 'confidence': .9}, 'address': {'value': 'Different Barangay, Hamtic, Antique', 'confidence': .8}}
        result = evaluate('valid_id', 'valid_id', 90, {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [], application_context={'full_name': 'Juan Dela Cruz', 'address': 'Poblacion 4, San Jose de Buenavista, Antique'})
        self.assertEqual(result['status'], 'needs_staff_review')
        self.assertIn('Beneficiary address differs', ' '.join(result['findings']['reasons']))
    def test_uncertain_type_retains_reliable_identity_flag(self):
        fields = {'patient_name': {'value': 'PEDRO SANTOS', 'confidence': .9}, 'document_date': {'value': '2026-08-21'}, 'diagnosis_present': {'value': True}}
        classification = {'decision': 'uncertain', 'reason': 'Document type requires staff confirmation.'}
        result = evaluate('med_cert', 'med_cert', 55, {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [], classification=classification)
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['confidence_score'], 55)
        self.assertIn('Document type requires staff confirmation.', result['findings']['reasons'])
    def test_clean_billing_does_not_inherit_medical_name_conflict(self):
        fields = {'name': {'value': 'JUAN DELA CRUZ'}}
        prior = [
            {'_document_type': 'valid_id', 'name': {'value': 'JUAN DELA CRUZ'}},
            {'_document_type': 'med_cert', 'patient_name': {'value': 'JULIE DELA CRUZ'}},
        ]
        result = evaluate('billing_statement', 'billing_statement', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', prior)
        self.assertEqual(result['findings']['checks']['name_match']['status'], 'match')
        self.assertEqual(result['status'], 'completed')
        self.assertFalse(any('Name inconsistency detected.' in reason for reason in result['findings']['reasons']))
    def test_billing_name_conflict_is_flagged(self):
        fields = {'name': {'value': 'MARIA SANTOS'}, 'amount': {'value': '500.00'}}
        result = evaluate('billing_statement', 'billing_statement', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', [])
        self.assertEqual(result['findings']['checks']['name_match']['status'], 'mismatch')
        self.assertEqual(result['status'], 'flagged_inconsistency')
    def test_billing_without_name_does_not_inherit_sibling_conflict(self):
        result = evaluate('billing_statement', 'billing_statement', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, {}, 'Juan Dela Cruz', [{'_document_type': 'med_cert', 'patient_name': {'value': 'JULIE DELA CRUZ'}}])
        self.assertEqual(result['findings']['checks']['name_match']['status'], 'not_present')
        self.assertEqual(result['status'], 'needs_staff_review')
        self.assertFalse(any('Name inconsistency detected.' in reason for reason in result['findings']['reasons']))
    def test_consistent_documents_have_no_name_inconsistency_reason(self):
        fields = {'name': {'value': 'JUAN DELA CRUZ'}, 'amount': {'value': '500.00'}}
        prior = [{'_document_type': 'valid_id', 'name': {'value': 'JUAN DELA CRUZ'}}, {'_document_type': 'med_cert', 'patient_name': {'value': 'JUAN DELA CRUZ'}}]
        result = evaluate('billing_statement', 'billing_statement', 90, {'blank': False, 'blurred': False, 'blur_variance': 100}, fields, 'Juan Dela Cruz', prior)
        self.assertEqual(result['status'], 'completed')
        self.assertNotIn('Possible name inconsistency across uploaded documents.', result['findings']['reasons'])
    def test_valid_id_fields_on_same_line_stay_separate(self):
        fields = extract(
            'Full Name: JUAN DELA CRUZ Date of Birth: 01/02/1950 '
            'Expiration Date: 01/02/2030 ID Number: N01-23-456789',
            'valid_id',
        )
        self.assertEqual(fields['name']['value'], 'JUAN DELA CRUZ')
        self.assertEqual(fields['birth_date']['status'], 'uncertain')
        self.assertEqual(fields['valid_until']['status'], 'uncertain')
        self.assertNotIn('id_number', fields)
        self.assertNotIn('document_date', fields)
    def test_valid_id_fields_on_following_lines_stay_separate(self):
        fields = extract(
            'Name\nJUAN DELA CRUZ\nDOB\n01/02/1950\nValid Until\n01/02/2030\n',
            'valid_id',
        )
        self.assertEqual(fields['name']['value'], 'JUAN DELA CRUZ')
        self.assertEqual(fields['birth_date']['status'], 'uncertain')
        self.assertEqual(fields['valid_until']['status'], 'uncertain')
    def test_status_precedence(self):
        blank = evaluate('death_cert', 'valid_id', 90, {'blank':True,'blurred':False,'blur_variance':0}, {}, '', [])
        self.assertEqual(blank['status'], 'needs_reupload')
        wrong = evaluate('death_cert', 'valid_id', 90, {'blank':False,'blurred':False,'blur_variance':100}, {}, '', [])
        self.assertEqual(wrong['status'], 'flagged_inconsistency')
        clean = evaluate('valid_id', 'valid_id', 90, {'blank':False,'blurred':False,'blur_variance':100}, {}, '', [])
        self.assertEqual(clean['status'], 'needs_staff_review')
    def test_stored_upload_path_resolution(self):
        with TemporaryDirectory() as temp:
            project = Path(temp) / 'AICS'
            upload = project / 'Applicants' / 'uploads'
            target = upload / 'AICS-2026-241956' / 'file.pdf'
            target.parent.mkdir(parents=True); target.touch()
            self.assertEqual(resolve_path(str(project), str(upload), 'AICS/Applicants/uploads/AICS-2026-241956/file.pdf'), target.resolve())
            self.assertEqual(resolve_path(str(project), str(upload), 'Applicants/uploads/AICS-2026-241956/file.pdf'), target.resolve())
            self.assertEqual(resolve_path(str(project), str(upload), str(target.resolve())), target.resolve())
            with self.assertRaisesRegex(RuntimeError, 'AI_PATH_OUTSIDE_UPLOAD_ROOT'):
                resolve_path(str(project), str(upload), 'AICS/Applicants/uploads/../../../../Windows/system.ini')
            with self.assertRaisesRegex(RuntimeError, 'AI_DOCUMENT_FILE_NOT_FOUND'):
                resolve_path(str(project), str(upload), 'AICS/Applicants/uploads/AICS-2026-241956/missing.pdf')

    @patch('pdf2image.convert_from_path')
    def test_pdf_render_uses_normalized_aics_database_path(self, convert_from_path):
        with TemporaryDirectory() as temp:
            project = Path(temp) / 'AICS'
            upload = project / 'Applicants' / 'uploads'
            target = upload / 'AICS-2026-241956' / 'file.pdf'
            target.parent.mkdir(parents=True); target.write_bytes(b'%PDF-test')
            poppler = Path(temp) / 'poppler'; poppler.mkdir()
            (poppler / 'pdfinfo.exe').touch(); (poppler / 'pdftoppm.exe').touch()
            resolved = resolve_path(str(project), str(upload), 'AICS/Applicants/uploads/AICS-2026-241956/file.pdf', 20)
            convert_from_path.return_value = [np.zeros((2, 2, 3), dtype=np.uint8)]
            images = load_images(resolved, 3, str(poppler))
            self.assertEqual(len(images), 1)
            convert_from_path.assert_called_once_with(str(target.resolve()), first_page=1, last_page=3, poppler_path=str(poppler.resolve()), timeout=60)
    @patch('ai_processor.processor.update_stage')
    @patch('ai_processor.processor.fail')
    @patch('ai_processor.processor.resolve_path', side_effect=RuntimeError('AI_PDF_RENDER_FAILED'))
    @patch('ai_processor.processor.claim_one')
    @patch('ai_processor.processor.connect')
    def test_claimed_pdf_failure_is_finalized(self, connect, claim, resolve, fail, update_stage):
        db = Mock(); connect.return_value = db
        claim.return_value = {'id': 42, 'file_path': 'AICS/Applicants/uploads/r/file.pdf'}
        processor.process_one()
        fail.assert_called_once_with(db, 42, 'AI_PDF_RENDER_FAILED')
    @patch('ai_processor.processor.update_stage')
    @patch('ai_processor.processor.fail')
    @patch('ai_processor.processor.resolve_path', side_effect=ValueError('unexpected'))
    @patch('ai_processor.processor.claim_one')
    @patch('ai_processor.processor.connect')
    def test_claimed_unexpected_failure_is_finalized(self, connect, claim, resolve, fail, update_stage):
        db = Mock(); connect.return_value = db
        claim.return_value = {'id': 43, 'file_path': 'AICS/Applicants/uploads/r/file.pdf'}
        processor.process_one()
        fail.assert_called_once_with(db, 43, 'AI_PROCESSOR_ERROR')

if __name__ == '__main__': unittest.main()
