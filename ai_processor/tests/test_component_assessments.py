import unittest

from ai_processor.verification import evaluate


QUALITY = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 120}


def field(value=None, status='extracted', reliable=True):
    return {'value': value, 'normalized': value, 'status': status, 'reliable': reliable}


class ComponentAssessmentTests(unittest.TestCase):
    def test_uncertain_name_never_returns_likely_valid(self):
        fields = {'name': field(None, 'uncertain', False)}
        result = evaluate('valid_id', 'valid_id', 90, QUALITY, fields, 'Ana Reyes', [],
                          classification={'decision': 'match'}, profile_calibrated=True)
        self.assertEqual(result['status'], 'needs_staff_review')
        self.assertEqual(result['findings']['assessments']['applicant_match']['state'], 'review')

    def test_reliable_wrong_slot_is_flagged_for_staff(self):
        result = evaluate('valid_id', 'death_cert', 95, QUALITY, {}, 'Ana Reyes', [],
                          classification={'decision': 'wrong_slot', 'reason': 'Strong wrong-slot evidence.'})
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['assessments']['document_match']['state'], 'fail')

    def test_address_difference_alone_is_not_identity_failure(self):
        fields = {'name': field('ANA REYES'), 'address': field('HAMTIC ANTIQUE')}
        result = evaluate('valid_id', 'valid_id', 92, QUALITY, fields, 'Ana Reyes', [],
                          classification={'decision': 'match'},
                          application_context={'full_name': 'Ana Reyes', 'address': 'CEBU CITY'},
                          profile_calibrated=False)
        self.assertNotEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['assessments']['applicant_match']['state'], 'review')

    def test_uncalibrated_profile_fails_closed_to_staff_review(self):
        fields = {'name': field('ANA REYES')}
        result = evaluate('valid_id', 'valid_id', 92, QUALITY, fields, 'Ana Reyes', [],
                          classification={'decision': 'match'}, profile_calibrated=False)
        self.assertEqual(result['status'], 'needs_staff_review')
        self.assertEqual(set(result['findings']['assessments']), {
            'document_match', 'readability', 'applicant_match', 'alteration_signal'
        })
        self.assertNotIn('confidence', result['findings'])


if __name__ == '__main__':
    unittest.main()
