import unittest
from datetime import date

from ai_processor.consistency import build_consistency_check
from ai_processor.database import get_request_verification_context
from ai_processor.field_extractor import extract
from ai_processor.field_normalization import normalize_amount, normalize_date, normalize_sex
from ai_processor.verification import evaluate


QUALITY = {'decision': 'acceptable', 'blank': False, 'blurred': False, 'blur_variance': 100}
APPLICATION = {
    'full_name': 'Juan Dela Cruz', 'date_of_birth': '1950-01-02',
    'sex': 'male', 'address': 'Poblacion 4, San Jose de Buenavista, Antique',
}


class StructuredExtractionTests(unittest.TestCase):
    def test_request_snapshot_context_is_authoritative(self):
        context = get_request_verification_context({
            'beneficiary_complete_name': 'Juan Dela Cruz', 'beneficiary_birth_date': '1950-01-02',
            'beneficiary_age': 76,
            'gender': 'male', 'beneficiary_sitio_purok': 'Poblacion 4', 'beneficiary_barangay': 'San Jose',
            'beneficiary_municipality': 'San Jose de Buenavista', 'beneficiary_province': 'Antique',
            'assistance_types_json': '["burial"]',
        })
        self.assertEqual(context['full_name'], 'Juan Dela Cruz')
        self.assertEqual(context['age'], 76)
        self.assertEqual(context['burial_identity_source'], 'beneficiary_snapshot')
        self.assertIn('Antique', context['address'])

    def test_database_date_objects_are_json_safe_in_consistency(self):
        context = dict(APPLICATION, date_of_birth=date(1950, 1, 2))
        result = build_consistency_check('valid_id', extract('Full Name: Juan Dela Cruz\nDate of Birth: January 2, 1950', 'valid_id'), context)
        self.assertEqual(result['comparisons']['date_of_birth']['application'], '1950-01-02')

    def test_valid_id_extracts_identity_without_retaining_number(self):
        fields = extract('Full Name: JUAN DELA CRUZ\nDate of Birth: January 2, 1950\nSex: M\nID Number: TEST-000001', 'valid_id')
        self.assertEqual(fields['name']['normalized'], 'JUAN DELA CRUZ')
        self.assertEqual(fields['birth_date']['normalized'], '1950-01-02')
        self.assertEqual(fields['sex']['normalized'], 'male')
        self.assertNotIn('id_number', fields)

    def test_ambiguous_numeric_dob_is_uncertain(self):
        fields = extract('Name: Juan Dela Cruz\nDOB: 01/02/1950', 'valid_id')
        self.assertEqual(fields['birth_date']['status'], 'uncertain')
        consistency = build_consistency_check('valid_id', fields, APPLICATION)
        self.assertNotIn('date_of_birth', consistency['comparisons'])
        self.assertNotIn('date_of_birth', consistency['mismatched_fields'])

    def test_medical_context_separates_dob_and_document_date(self):
        fields = extract('Patient Name: Juan Dela Cruz\nDate of Birth: Jan 02 1950\nDate Issued: August 20, 2026\nSex: Male\nDiagnosis: Test', 'med_cert')
        self.assertEqual(fields['birth_date']['normalized'], '1950-01-02')
        self.assertEqual(fields['document_date']['normalized'], '2026-08-20')

    def test_medical_parenthesized_signature_date_is_extracted(self):
        fields = extract(
            'MEDICAL CERTIFICATE\nconfined from April 11 - 15, 2021 inclusive\n'
            '(Signature of Physician)\n(Date) September 24, 2026',
            'med_cert',
        )
        self.assertEqual(fields['document_date']['status'], 'extracted')
        self.assertEqual(fields['document_date']['normalized'], '2026-09-24')

    def test_medical_absent_dob_is_not_present(self):
        fields = extract('Patient Name: Juan Dela Cruz\nDate Issued: August 20, 2026\nDiagnosis: Test', 'med_cert')
        self.assertEqual(fields['birth_date']['status'], 'not_present')
        result = evaluate('med_cert', 'med_cert', 90, QUALITY, fields, APPLICATION['full_name'], [],
                          APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertNotEqual(result['status'], 'flagged_inconsistency')

    def test_prescription_extracts_patient_without_requiring_amount(self):
        fields = extract('PRESCRIPTION\nPatient Name: Juan Dela Cruz\nDate: August 20, 2026\nPhysician: Maria Santos\nRx dosage frequency', 'billing_statement')
        self.assertEqual(fields['document_subtype']['value'], 'prescription')
        self.assertEqual(fields['patient_name']['normalized'], 'JUAN DELA CRUZ')
        self.assertNotIn('amount', fields)
        result = evaluate('billing_statement', 'billing_statement', 90, QUALITY, fields, APPLICATION['full_name'], [],
                          APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(result['status'], 'completed')
        comparisons = result['findings']['consistency_check']['comparisons']
        self.assertEqual(set(comparisons), {'full_name'})

    def test_billing_compares_dob_only_when_present(self):
        matching = extract('BILLING STATEMENT\nPatient Name: Juan Dela Cruz\nDOB: January 2, 1950', 'billing_statement')
        mismatch = extract('BILLING STATEMENT\nPatient Name: Juan Dela Cruz\nDOB: January 2, 1960', 'billing_statement')
        self.assertEqual(build_consistency_check('billing_statement', matching, APPLICATION)['comparisons']['date_of_birth']['status'], 'match')
        self.assertEqual(build_consistency_check('billing_statement', mismatch, APPLICATION)['comparisons']['date_of_birth']['status'], 'mismatch')

    def test_medical_without_sex_omits_sex_comparison(self):
        fields = extract('MEDICAL CERTIFICATE\nPatient Name: Juan Dela Cruz\nDOB: January 2, 1950', 'med_cert')
        comparisons = build_consistency_check('med_cert', fields, APPLICATION)['comparisons']
        self.assertEqual(set(comparisons), {'full_name', 'date_of_birth'})

    def test_beneficiary_address_not_applicant_address_is_used(self):
        context = get_request_verification_context({'beneficiary_complete_name': 'Juan Dela Cruz', 'beneficiary_birth_date': '1950-01-02', 'gender': 'male', 'beneficiary_barangay': 'Poblacion', 'beneficiary_municipality': 'Hamtic', 'beneficiary_province': 'Antique', 'barangay': 'Atabay', 'municipality': 'San Jose de Buenavista', 'province': 'Antique'})
        self.assertEqual(context['address'], 'Poblacion, Hamtic, Antique')
        self.assertEqual(context['barangay'], 'Poblacion')
        self.assertEqual(context['municipality'], 'Hamtic')

    def test_billing_amount_is_not_retained_in_review_metadata(self):
        fields = extract('BILLING STATEMENT\nPatient Name: Juan Dela Cruz\nTotal Amount: PHP 12,345.67', 'billing_statement')
        self.assertNotIn('amount', fields)
        self.assertNotIn('reference_number', fields)
        result = evaluate('billing_statement', 'billing_statement', 90, QUALITY, fields, APPLICATION['full_name'], [],
                          APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(result['status'], 'completed')

    def test_billing_date_accepts_contextual_label_without_colon(self):
        fields = extract('BILLING STATEMENT\nPatient Name Juan Dela Cruz\nBilling Date August 21, 2026\nTotal Amount PHP 5,000.00', 'billing_statement')
        self.assertEqual(fields['document_date']['status'], 'extracted')
        self.assertEqual(fields['document_date']['normalized'], '2026-08-21')

    def test_document_families_have_specific_fields(self):
        death = extract('Deceased Name: Juan Dela Cruz\nDate of Death: August 1, 2026\nPlace of Death: Antique', 'death_cert')
        funeral = extract('Deceased Name: Juan Dela Cruz\nFuneral Home: Example Services\nContract Date: August 2, 2026', 'funeral_contract')
        report = extract('POLICE REPORT\nSubject Name: Juan Dela Cruz\nReport Number: P-1\nReport Date: August 3, 2026', 'report_police_med')
        crisis = extract('Client Name: Juan Dela Cruz\nIssuing Office: MSWDO\nCertificate Date: August 4, 2026', 'coe_mswdo')
        self.assertEqual(death['date_of_death']['normalized'], '2026-08-01')
        self.assertEqual(funeral['funeral_home']['value'], 'Example Services')
        self.assertEqual(report['report_subtype']['value'], 'police_report')
        self.assertEqual(crisis['issuing_organization']['value'], 'MSWDO')


class ConsistencyTests(unittest.TestCase):
    def _medical(self, name='Juan Dela Cruz', dob='January 2, 1950', sex='Male'):
        return extract(f'Patient Name: {name}\nDate of Birth: {dob}\nSex: {sex}\nDate Issued: August 20, 2026\nDiagnosis: Test', 'med_cert')

    def test_name_variations_and_contradiction(self):
        for value, expected in [('JUAN DELA CRUZ', 'match'), ('DELA CRUZ, JUAN', 'match'), ('JUAN D. DELA CRUZ', 'match')]:
            fields = {'patient_name': {'value': value, 'status': 'extracted', 'confidence': .8}}
            self.assertEqual(build_consistency_check('med_cert', fields, APPLICATION)['comparisons']['full_name']['status'], expected)
        fields = {'patient_name': {'value': 'Pedro Santos', 'status': 'extracted', 'confidence': .8}}
        self.assertEqual(build_consistency_check('med_cert', fields, APPLICATION)['comparisons']['full_name']['status'], 'mismatch')

    def test_dob_match_and_mismatch(self):
        matching = build_consistency_check('med_cert', self._medical(), APPLICATION)
        mismatch = build_consistency_check('med_cert', self._medical(dob='January 2, 1960'), APPLICATION)
        self.assertEqual(matching['comparisons']['date_of_birth']['status'], 'match')
        self.assertEqual(mismatch['comparisons']['date_of_birth']['status'], 'mismatch')

    def test_medical_dob_mismatch_flags_with_explanation(self):
        valid_id = {'_document_type': 'valid_id', '_quality_usable': True, '_type_match': True,
                    'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                    'birth_date': {'value': '1950-01-02', 'normalized': '1950-01-02', 'status': 'extracted', 'confidence': .8}}
        result = evaluate('med_cert', 'med_cert', 90, QUALITY, self._medical(dob='January 2, 1960'),
                          APPLICATION['full_name'], [valid_id], APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(result['status'], 'flagged_inconsistency')
        comparison = result['findings']['consistency_check']['comparisons']['date_of_birth']
        self.assertEqual(comparison['application'], '1950-01-02')
        self.assertEqual(comparison['valid_id'], '1950-01-02')
        self.assertTrue(any('Date of birth inconsistency detected.' in reason for reason in result['findings']['reasons']))
        self.assertTrue(any('Medical Certificate contains January 2, 1960' in reason for reason in result['findings']['reasons']))
        self.assertTrue(any('submitted beneficiary information and trusted Valid ID contain January 2, 1950' in reason for reason in result['findings']['reasons']))

    def test_different_name_with_correct_dob_flags(self):
        result = evaluate('med_cert', 'med_cert', 90, QUALITY, self._medical(name='Pedro Santos'),
                          APPLICATION['full_name'], [], APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['consistency_check']['comparisons']['full_name']['status'], 'mismatch')

    def test_sex_normalization_and_mismatch(self):
        self.assertEqual(normalize_sex('M'), normalize_sex('MALE'))
        matching = build_consistency_check('med_cert', self._medical(sex='M'), APPLICATION)
        mismatch = build_consistency_check('med_cert', self._medical(sex='Female'), APPLICATION)
        self.assertEqual(matching['comparisons']['sex']['status'], 'match')
        self.assertEqual(mismatch['comparisons']['sex']['status'], 'mismatch')

    def test_address_with_same_barangay_and_municipality_ignores_street(self):
        context = dict(APPLICATION, address='Magdalena, Anini-y, Antique', barangay='Magdalena', municipality='Anini-y')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'San Pedro Street, Magdalena, Anini-y, Antique', 'status': 'extracted', 'confidence': .8}}

        comparison = build_consistency_check('valid_id', fields, context)['comparisons']['address']

        self.assertEqual(comparison['status'], 'match')

    def test_street_only_address_is_uncertain_not_mismatch(self):
        context = dict(APPLICATION, address='Magdalena, Anini-y, Antique', barangay='Magdalena', municipality='Anini-y')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'SAN PEDRO STREET', 'status': 'extracted', 'confidence': .8}}

        comparison = build_consistency_check('valid_id', fields, context)['comparisons']['address']

        self.assertEqual(comparison['status'], 'uncertain')

    def test_street_with_matching_municipality_is_uncertain_not_mismatch(self):
        context = dict(APPLICATION, address='Magdalena, Anini-y, Antique', barangay='Magdalena', municipality='Anini-y', province='Antique')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'San Pedro Street, Anini-y, Antique', 'status': 'extracted', 'confidence': .8}}

        comparison = build_consistency_check('valid_id', fields, context)['comparisons']['address']

        self.assertEqual(comparison['status'], 'uncertain')

    def test_uncertain_address_requires_staff_review(self):
        context = dict(APPLICATION, address='Magdalena, Anini-y, Antique', barangay='Magdalena', municipality='Anini-y', province='Antique')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'SAN PEDRO STREET', 'status': 'extracted', 'confidence': .8}}

        result = evaluate('valid_id', 'valid_id', 90, QUALITY, fields, APPLICATION['full_name'], [],
                          application_context=context, classification={'decision': 'match'}, profile_calibrated=True)

        self.assertEqual(result['status'], 'needs_staff_review')

    def test_different_barangay_and_municipality_remain_mismatch(self):
        context = dict(APPLICATION, address='Magdalena, Anini-y, Antique', barangay='Magdalena', municipality='Anini-y')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'Poblacion, Hamtic, Antique', 'status': 'extracted', 'confidence': .8}}

        comparison = build_consistency_check('valid_id', fields, context)['comparisons']['address']

        self.assertEqual(comparison['status'], 'mismatch')

    def test_two_part_different_barangay_and_municipality_are_mismatch(self):
        context = dict(APPLICATION, address='Magdalena, Anini-y, Antique', barangay='Magdalena', municipality='Anini-y', province='Antique')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'Poblacion, Hamtic', 'status': 'extracted', 'confidence': .8}}

        comparison = build_consistency_check('valid_id', fields, context)['comparisons']['address']

        self.assertEqual(comparison['status'], 'mismatch')

    def test_undelimited_complete_different_address_is_mismatch(self):
        context = dict(APPLICATION, address='Magdalena, Anini-y, Antique', barangay='Magdalena', municipality='Anini-y', province='Antique')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'Poblacion Hamtic Antique', 'status': 'extracted', 'confidence': .8}}

        comparison = build_consistency_check('valid_id', fields, context)['comparisons']['address']

        self.assertEqual(comparison['status'], 'mismatch')

    def test_longer_locality_name_does_not_prefix_match(self):
        context = dict(APPLICATION, address='Poblacion, San Jose, Antique', barangay='Poblacion', municipality='San Jose', province='Antique')
        fields = {'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'address': {'value': 'Poblacion, San Jose del Monte, Bulacan', 'status': 'extracted', 'confidence': .8}}

        comparison = build_consistency_check('valid_id', fields, context)['comparisons']['address']

        self.assertEqual(comparison['status'], 'mismatch')

    def test_sibling_isolation(self):
        medical = evaluate('med_cert', 'med_cert', 90, QUALITY, self._medical(name='Pedro Santos'),
                           APPLICATION['full_name'], [], APPLICATION['date_of_birth'], application_context=APPLICATION)
        billing_fields = extract('Patient Name: Juan Dela Cruz\nTotal Amount: 500', 'billing_statement')
        prior_medical = {'_document_type': 'med_cert', **self._medical(name='Pedro Santos')}
        billing = evaluate('billing_statement', 'billing_statement', 90, QUALITY, billing_fields,
                           APPLICATION['full_name'], [prior_medical], APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(medical['status'], 'flagged_inconsistency')
        self.assertEqual(billing['status'], 'completed')

    def test_valid_id_disagreement_does_not_contaminate_medical(self):
        bad_id = {'_document_type': 'valid_id', '_quality_usable': True, '_type_match': True,
                  'name': {'value': 'Pedro Santos', 'status': 'extracted', 'confidence': .8},
                  'birth_date': {'value': '1960-01-02', 'normalized': '1960-01-02', 'status': 'extracted', 'confidence': .8}}
        medical = evaluate('med_cert', 'med_cert', 90, QUALITY, self._medical(), APPLICATION['full_name'],
                           [bad_id], APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(medical['status'], 'completed')
        self.assertFalse(medical['findings']['consistency_check']['trusted_valid_id_used'])

    def test_valid_id_dob_disagreement_disqualifies_secondary_reference(self):
        bad_id = {'_document_type': 'valid_id', '_quality_usable': True, '_type_match': True,
                  'name': {'value': 'Juan Dela Cruz', 'status': 'extracted', 'confidence': .8},
                  'birth_date': {'value': '1960-01-02', 'normalized': '1960-01-02', 'status': 'extracted', 'confidence': .8}}
        medical = evaluate('med_cert', 'med_cert', 90, QUALITY, self._medical(), APPLICATION['full_name'],
                           [bad_id], APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(medical['status'], 'completed')
        self.assertFalse(medical['findings']['consistency_check']['trusted_valid_id_used'])

    def test_quality_precedence_blocks_ocr_mismatch_flag(self):
        severe = {'decision': 'reupload', 'blank': False, 'blurred': True, 'blur_variance': 1, 'reason': 'Severely blurred.'}
        result = evaluate('med_cert', 'med_cert', 90, severe, self._medical(name='Pedro Santos'),
                          APPLICATION['full_name'], [], APPLICATION['date_of_birth'], application_context=APPLICATION)
        self.assertEqual(result['status'], 'needs_reupload')

    def test_normalization_dates(self):
        self.assertEqual(normalize_date('January 2, 1950'), '1950-01-02')
        self.assertEqual(normalize_date('Jan 02 1950'), '1950-01-02')
        self.assertEqual(normalize_date('1950-01-02'), '1950-01-02')
        self.assertIsNone(normalize_date('01/02/1950'))
        self.assertEqual(normalize_date('13/02/1950'), '1950-02-13')


if __name__ == '__main__':
    unittest.main()
