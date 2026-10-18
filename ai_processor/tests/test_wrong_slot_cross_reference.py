"""A wrong upload type must remain visible as an inconsistency, not disappear into image review."""
import unittest

from ai_processor.document_classifier import classify_document, classify_ocr_candidates
from ai_processor.external_ai.postprocessing import apply_external_recommendation, enforce_beneficiary_comparisons
from ai_processor.verification import evaluate


QUALITY = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 120}
PARTIAL_DEATH_CERT = (
    'CERTIFICATE OF DEATH OFFICE OF THE CIVIL REGISTRAR GENERAL '
    'NAME OF DECEASED Jennifer Angeles Ramos DATE OF DEATH June 28, 2008 '
    'PLACE OF DEATH Manila CAUSE OF DEATH'
)


class WrongSlotCrossReferenceTests(unittest.TestCase):
    def test_other_retained_ocr_reading_can_recover_wrong_document_type(self):
        metadata = {'field_candidates': [
            {'candidate': 'expected_profile', 'text': 'Name and date unclear'},
            {'candidate': 'full_page', 'text': PARTIAL_DEATH_CERT},
        ]}
        found = classify_ocr_candidates('Name and date unclear', 'valid_id', metadata)
        self.assertEqual(found['decision'], 'wrong_slot')
        self.assertEqual(found['detected_type'], 'death_cert')
        reviewed = evaluate('valid_id', found['detected_type'], 90,
                            {'decision': 'reupload', 'blank': False, 'reason': 'Low resolution.'},
                            {}, 'Different Beneficiary', [], classification=found)
        self.assertEqual(reviewed['status'], 'flagged_inconsistency')

    def test_conflicting_ocr_document_families_need_staff_review(self):
        metadata = {'field_candidates': [
            {'candidate': 'id_view', 'text': 'Philippine Statistics Authority PhilSys National ID Full Name Ana Reyes Date of Birth January 2 1990 Address Antique PCN 123'},
            {'candidate': 'death_view', 'text': PARTIAL_DEATH_CERT},
        ]}
        found = classify_ocr_candidates('Name and date unclear', 'valid_id', metadata)
        self.assertEqual(found['decision'], 'uncertain')

    def test_partial_death_certificate_is_wrong_in_id_and_medical_slots(self):
        for required in ('valid_id', 'med_cert'):
            with self.subTest(required=required):
                found = classify_document(PARTIAL_DEATH_CERT, required)
                self.assertEqual(found['detected_type'], 'death_cert')
                self.assertEqual(found['decision'], 'wrong_slot')

    def test_death_heading_alone_is_not_enough_to_flag_a_document(self):
        found = classify_document('CERTIFICATE OF DEATH', 'valid_id')
        self.assertEqual(found['decision'], 'uncertain')

    def test_reliable_wrong_slot_flags_inconsistency_and_names_both_types(self):
        classification = classify_document(PARTIAL_DEATH_CERT, 'valid_id')
        reviewed = evaluate('valid_id', classification['detected_type'], 90, QUALITY, {},
                            'Different Beneficiary', [], classification=classification)
        self.assertEqual(reviewed['status'], 'flagged_inconsistency')
        self.assertEqual(reviewed['findings']['assessments']['document_match']['state'], 'fail')
        self.assertIn('Valid ID', ' '.join(reviewed['findings']['reasons']))
        self.assertIn('Death Certificate', ' '.join(reviewed['findings']['reasons']))
        self.assertNotIn('Name inconsistency', ' '.join(reviewed['findings']['reasons']))

    def test_readability_reupload_does_not_hide_confirmed_wrong_type(self):
        classification = classify_document(PARTIAL_DEATH_CERT, 'valid_id')
        quality = {'decision': 'reupload', 'blank': False,
                   'reason': 'The document has low resolution.'}
        reviewed = evaluate('valid_id', classification['detected_type'], 90, quality, {},
                            'Different Beneficiary', [], classification=classification)
        self.assertEqual(reviewed['status'], 'flagged_inconsistency')
        self.assertEqual(reviewed['findings']['assessments']['document_match']['state'], 'fail')
        self.assertEqual(reviewed['findings']['assessments']['readability']['state'], 'fail')

    def test_blank_upload_does_not_claim_wrong_document_type(self):
        classification = classify_document(PARTIAL_DEATH_CERT, 'valid_id')
        quality = {'decision': 'reupload', 'blank': True,
                   'reason': 'The document is blank.'}
        reviewed = evaluate('valid_id', classification['detected_type'], 90, quality, {},
                            'Different Beneficiary', [], classification=classification)
        self.assertEqual(reviewed['status'], 'needs_reupload')

    def test_correct_id_with_reliable_other_cardholder_name_stays_flagged(self):
        fields = {'name': {'value': 'Jennifer Ramos', 'normalized': 'Jennifer Ramos',
                           'status': 'extracted', 'reliable': True}}
        reviewed = evaluate('valid_id', 'valid_id', 90, QUALITY, fields, 'Ana Reyes', [],
                            classification={'decision': 'match'}, profile_calibrated=True)
        self.assertEqual(reviewed['status'], 'flagged_inconsistency')
        self.assertEqual(reviewed['findings']['assessments']['applicant_match']['state'], 'fail')

    def test_external_recapture_cannot_erase_local_wrong_type_flag(self):
        local = evaluate('valid_id', 'death_cert', 90, QUALITY, {}, 'Ana Reyes', [],
                         classification={'decision': 'wrong_slot', 'reason': 'Death Certificate, not Valid ID.'})
        provider = {'status': 'completed', 'assessment': {
            'state': 'review', 'fields': {},
            'document_quality': {'state': 'recapture_required', 'reason': 'Some text is unclear.'},
        }}
        reviewed = apply_external_recommendation(local, provider)
        self.assertEqual(reviewed['status'], 'flagged_inconsistency')
        self.assertEqual(reviewed['findings']['assessments']['document_match']['state'], 'fail')
        self.assertEqual(reviewed['findings']['assessments']['readability']['state'], 'fail')

    def test_wrong_slot_deceased_name_is_not_compared_as_id_cardholder(self):
        local = evaluate('valid_id', 'death_cert', 90, QUALITY, {}, 'Ana Reyes', [],
                         classification={'decision': 'wrong_slot', 'reason': 'Death Certificate, not Valid ID.'})
        provider = {'status': 'completed', 'assessment': {
            'state': 'conflict', 'reasons': ['FIELD_CONFLICT'],
            'fields': {'name': {'value': 'Jennifer Ramos', 'state': 'conflict'},
                       'deceased_name': {'value': 'Jennifer Ramos', 'state': 'conflict'}},
            'document_quality': {'state': 'usable', 'reason': 'Readable.'},
        }}
        compared = enforce_beneficiary_comparisons(provider, {'full_name': 'Ana Reyes'},
                                                   document_type_matches=False)
        reviewed = apply_external_recommendation(local, compared)
        self.assertEqual(reviewed['status'], 'flagged_inconsistency')
        self.assertEqual(reviewed['findings']['assessments']['document_match']['state'], 'fail')
        self.assertNotEqual(reviewed['findings']['assessments']['applicant_match']['state'], 'fail')
        self.assertNotIn('FIELD_CONFLICT', compared['assessment']['reasons'])


if __name__ == '__main__':
    unittest.main()
