import unittest

from ai_processor.document_profiles import get_profile, supported_document_types


EXPECTED = {
    'valid_id', 'med_cert', 'billing_statement', 'death_cert',
    'funeral_contract', 'report_police_med', 'school_assessment',
    'registration_form', 'school_id', 'mdrrmo_cert', 'eclip_cert',
    'crisis_situation_cert', 'damaged_house_photo',
}


class DocumentProfileTests(unittest.TestCase):
    def test_every_supported_type_has_one_profile(self):
        self.assertEqual(set(supported_document_types()), EXPECTED)
        for code in EXPECTED:
            profile = get_profile(code)
            self.assertIsNotNone(profile)
            self.assertEqual(profile.code, code)
            self.assertGreaterEqual(profile.validation_gate.min_precision, .90)

    def test_medical_profiles_use_stricter_gate(self):
        for code in ('med_cert', 'billing_statement'):
            gate = get_profile(code).validation_gate
            self.assertEqual(gate.min_cases, 30)
            self.assertEqual(gate.min_precision, .95)
            self.assertEqual(gate.max_false_reupload_rate, .02)

    def test_photo_evidence_never_claims_ocr_identity_fields(self):
        profile = get_profile('damaged_house_photo')
        self.assertEqual(profile.extractable_fields, ())
        self.assertEqual(profile.required_fields, ())
        self.assertIsNone(profile.identity_field)

    def test_unknown_profile_fails_closed(self):
        self.assertIsNone(get_profile('not_supported'))


if __name__ == '__main__':
    unittest.main()
