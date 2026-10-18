import unittest


CLEAR_RESULT = {
    'status': 'likely_valid',
    'findings': {
        'assessments': {
            'document_match': {'state': 'pass'},
            'readability': {'state': 'pass'},
            'applicant_match': {'state': 'pass'},
            'alteration_signal': {'state': 'unavailable'},
        },
        'consistency_check': {'comparisons': []},
    },
}
MATCH_CLASSIFICATION = {'decision': 'match', 'confidence_band': 'high'}
RELIABLE_FIELDS = {'name': {'status': 'extracted', 'normalized': 'TEST PERSON'}}


class ExternalAiInvocationPolicyTests(unittest.TestCase):
    def test_every_readable_document_invokes_external_provider(self):
        from ai_processor.external_ai.contracts import decide_invocation

        decision = decide_invocation(CLEAR_RESULT, MATCH_CLASSIFICATION, RELIABLE_FIELDS)

        self.assertTrue(decision.invoke)
        self.assertEqual(decision.reason, 'beneficiary_cross_reference')

    def test_uncertain_beneficiary_identity_invokes_external_provider(self):
        from ai_processor.external_ai.contracts import decide_invocation

        result = {'status': 'needs_staff_review', 'findings': {'assessments': {
            'document_match': {'state': 'pass'},
            'readability': {'state': 'pass'},
            'applicant_match': {'state': 'review'},
        }}}

        decision = decide_invocation(result, MATCH_CLASSIFICATION, RELIABLE_FIELDS)

        self.assertTrue(decision.invoke)
        self.assertEqual(decision.reason, 'beneficiary_match_uncertain')

    def test_unreadable_document_still_invokes_visual_quality_review(self):
        from ai_processor.external_ai.contracts import decide_invocation

        result = {'status': 'needs_reupload', 'findings': {'assessments': {
            'readability': {'state': 'fail'},
            'document_match': {'state': 'review'},
            'applicant_match': {'state': 'review'},
        }}}

        decision = decide_invocation(result, {'decision': 'uncertain'}, {})

        self.assertTrue(decision.invoke)
        self.assertEqual(decision.reason, 'document_quality_review')

    def test_staff_forced_diagnostic_invokes_for_readable_document(self):
        from ai_processor.external_ai.contracts import decide_invocation

        decision = decide_invocation(CLEAR_RESULT, MATCH_CLASSIFICATION, RELIABLE_FIELDS, force=True)

        self.assertTrue(decision.invoke)
        self.assertEqual(decision.reason, 'staff_requested_diagnostic')


class ExternalAiEvidenceTests(unittest.TestCase):
    def test_evidence_is_bounded_and_contains_only_beneficiary_snapshot(self):
        from ai_processor.external_ai.contracts import build_evidence

        result = {'status': 'needs_staff_review', 'findings': {
            'assessments': {'document_match': {'state': 'review'}},
            'secret_path': 'C:/private/document.png',
        }}
        evidence = build_evidence(
            'valid_id',
            'A' * 13000,
            {'name': {'status': 'extracted', 'normalized': 'TEST PERSON'}, '_internal': {'value': 'hidden'}},
            result,
            {
                'full_name': 'Test Person', 'date_of_birth': '2000-01-02',
                'age': 26, 'sex': 'female', 'address': 'Test Barangay, Antique',
                'assistance_types': ['medical'], 'database_id': 99,
                'applicant_name': 'Must Not Be Sent',
            },
        )

        self.assertEqual(evidence['schema_version'], 'external-ai-evidence-v1')
        self.assertEqual(len(evidence['ocr_text']), 12000)
        self.assertNotIn('_internal', evidence['fields'])
        self.assertNotIn('secret_path', repr(evidence))
        self.assertEqual(evidence['beneficiary']['full_name'], 'Test Person')
        self.assertEqual(evidence['beneficiary']['age'], 26)
        self.assertNotIn('application', evidence)
        self.assertNotIn('database_id', evidence['beneficiary'])
        self.assertNotIn('applicant_name', evidence['beneficiary'])


class ExternalAiAssessmentSchemaTests(unittest.TestCase):
    def test_valid_assessment_is_normalized(self):
        from ai_processor.external_ai.contracts import normalize_assessment

        normalized = normalize_assessment({
            'schema_version': 'external-ai-assessment-v1',
            'state': 'review',
            'confidence_band': 'moderate',
            'document_type': 'valid_id',
            'reasons': ['FIELD_UNCERTAIN'],
            'fields': {'name': {'value': 'Test Person', 'state': 'support'}},
            'document_quality': {'state': 'usable', 'reason': 'Readable image.'},
            'signature_review': {'state': 'visible', 'reason': 'Signature is visible.'},
            'visual_integrity': {'state': 'no_obvious_issue', 'reason': 'No obvious editing signal.'},
        })

        self.assertEqual(normalized['state'], 'review')
        self.assertEqual(normalized['fields']['name']['value'], 'Test Person')
        self.assertEqual(normalized['signature_review']['state'], 'visible')

    def test_invalid_or_decision_bearing_assessment_is_rejected(self):
        from ai_processor.external_ai.contracts import ExternalAiContractError, normalize_assessment

        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment({'decision': 'approve'})

        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment({
                'schema_version': 'external-ai-assessment-v1',
                'state': 'confirmed_duplicate',
                'confidence_band': 'high',
                'reasons': [],
                'fields': {},
            })

    def test_unsafe_reason_code_is_rejected(self):
        from ai_processor.external_ai.contracts import ExternalAiContractError, normalize_assessment

        with self.assertRaisesRegex(ExternalAiContractError, 'EXTERNAL_AI_INVALID_SCHEMA'):
            normalize_assessment({
                'schema_version': 'external-ai-assessment-v1',
                'state': 'review',
                'confidence_band': 'low',
                'reasons': ['contains private explanation'],
                'fields': {},
            })


if __name__ == '__main__':
    unittest.main()
