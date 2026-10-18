import copy
import unittest

from ai_processor.gemini_scanner.prompt import CHECK_KEYS
from ai_processor.gemini_scanner.verdict import apply_verdict, as_visual_result

BENEFICIARY = {'full_name': 'Nicasio Eimar Bustillo', 'date_of_birth': '1960-03-04'}
CLEAR_TAMPER = {'status': 'completed', 'decision': 'clear', 'indicators': []}
REVIEW_TAMPER = {'status': 'completed', 'decision': 'review', 'review_basis': 'corroborated_visual_evidence',
                 'label': 'Suspected alteration — staff review required', 'indicators': []}


def _local(status='needs_staff_review'):
    return {
        'status': status, 'detected_document_type': 'registration_form', 'confidence_score': 35,
        'extracted_fields': {'patient_name': {'value': None}},
        'findings': {
            'reasons': ['The document contains insufficient reliable evidence.'],
            'checks': {'document_type': {'status': 'review', 'expected': 'med_cert', 'detected': 'registration_form',
                                         'classification': {'decision': 'uncertain', 'detected_type': 'registration_form'}},
                       'required_fields': {'status': 'review', 'missing': ['patient_name']}},
            'assessments': {
                'document_match': {'state': 'review', 'label': 'Confirm document type', 'explanation': 'x'},
                'readability': {'state': 'pass', 'label': 'Readable', 'explanation': 'low OCR quality'},
                'applicant_match': {'state': 'review', 'label': 'Confirm beneficiary details', 'explanation': 'x'},
                'alteration_signal': {'state': 'pass', 'label': 'No corroborated alteration signal', 'explanation': 'x'},
            },
        },
    }


def _scan(**overrides):
    scan = {
        'document_type': 'med_cert', 'document_title': 'MEDICAL CERTIFICATE', 'type_confidence': 93,
        'primary_person': {'name': 'Nicasio E. Bustillo', 'role': 'patient', 'birth_date': '1960-03-04',
                           'sex': 'Male', 'address': None},
        'beneficiary_name_found': True, 'document_date': '2026-09-20', 'issuer': 'Antique Provincial Hospital',
        'checks': {key: {'state': 'pass', 'reason': f'{key} ok', 'missing': []} for key in CHECK_KEYS},
        'indicators': [], 'sample_or_specimen': {'detected': False, 'reason': ''},
        'summary': 'Medical certificate for the beneficiary.',
    }
    for key, value in overrides.items():
        if key == 'checks':
            scan['checks'].update(value)
        else:
            scan[key] = value
    return scan


def _gemini(scan):
    return {'status': 'completed', 'provider': 'google_gemini', 'model': 'gemini-2.5-flash',
            'elapsed_ms': 4200, 'cache_hit': False, 'page_count': 1, 'scan': scan}


def _run(scan=None, tamper=CLEAR_TAMPER, local=None, expected='med_cert', gemini=None):
    return apply_verdict(local or _local(), expected, gemini or _gemini(scan or _scan()), tamper, BENEFICIARY)


class GeminiVerdictTests(unittest.TestCase):
    def test_all_checks_pass_is_likely_valid(self):
        result = _run()
        self.assertEqual(result['status'], 'likely_valid')
        verdict = result['findings']['verdict']
        self.assertEqual(verdict['source'], 'gemini')
        self.assertEqual(verdict['tone'], 'success')
        self.assertEqual(result['detected_document_type'], 'med_cert')
        self.assertEqual(result['confidence_score'], 93)
        self.assertEqual(result['model_service'], 'gemini_vision_primary')
        checks = result['findings']['thesis_checks']
        self.assertEqual(list(checks), list(CHECK_KEYS))
        self.assertTrue(all(item['state'] == 'pass' for item in checks.values()))
        self.assertEqual(result['findings']['checks']['document_type']['classification']['decision'], 'match')

    def test_review_205_death_certificate_in_medical_slot(self):
        scan = _scan(document_type='death_cert', document_title='CERTIFICATE OF DEATH', type_confidence=96,
                     primary_person={'name': 'Jennifer Angeles Ramos', 'role': 'deceased', 'birth_date': '2001-11-20',
                                     'sex': 'Female', 'address': None},
                     beneficiary_name_found=False,
                     sample_or_specimen={'detected': True, 'reason': 'A website watermark is printed on the image.'})
        result = _run(scan)
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['detected_document_type'], 'death_cert')
        verdict = result['findings']['verdict']
        self.assertEqual(verdict['headline'], 'Wrong document uploaded')
        self.assertIn('Death Certificate', verdict['reason'])
        self.assertIn('Medical Abstract or Medical Certificate', verdict['reason'])
        self.assertEqual(verdict['tone'], 'danger')
        checks = result['findings']['thesis_checks']
        self.assertEqual(checks['consistent']['state'], 'fail')
        self.assertEqual(checks['untampered']['state'], 'review')
        self.assertIn('watermark', checks['untampered']['reason'])
        self.assertEqual(result['findings']['checks']['document_type']['classification']['decision'], 'wrong_slot')
        self.assertEqual(result['findings']['assessments']['document_match']['state'], 'fail')

    def test_sample_note_is_not_repeated_when_gemini_already_explains_it(self):
        scan = _scan(checks={'untampered': {'state': 'review', 'reason': 'A website watermark is printed at the bottom.', 'missing': []}},
                     sample_or_specimen={'detected': True, 'reason': 'The archive watermark shows this is a sample image.'})
        reason = _run(scan)['findings']['thesis_checks']['untampered']['reason']
        self.assertEqual(reason, 'A website watermark is printed at the bottom.')

    def test_sample_note_is_added_to_an_unrelated_tampering_reason(self):
        scan = _scan(checks={'untampered': {'state': 'review', 'reason': 'The amount digits look pasted.', 'missing': []}},
                     sample_or_specimen={'detected': True, 'reason': 'It looks like a specimen form.'})
        reason = _run(scan)['findings']['thesis_checks']['untampered']['reason']
        self.assertIn('pasted', reason)
        self.assertIn('specimen', reason)

    def test_name_mismatch_on_correct_document_is_flagged(self):
        scan = _scan(primary_person={'name': 'Maria Clara Reyes', 'role': 'patient', 'birth_date': None,
                                     'sex': None, 'address': None}, beneficiary_name_found=False)
        result = _run(scan)
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['verdict']['headline'], 'Name does not match the application')
        rows = {row['field']: row for row in result['findings']['field_comparison']}
        self.assertEqual(rows['name']['state'], 'fail')
        self.assertEqual(rows['name']['document'], 'Maria Clara Reyes')
        self.assertEqual(rows['name']['application'], 'Nicasio Eimar Bustillo')
        self.assertEqual(rows['name']['label'], 'Patient name')

    def test_beneficiary_elsewhere_on_document_is_review_not_fail(self):
        scan = _scan(primary_person={'name': 'Rosa Bustillo', 'role': 'patient', 'birth_date': None,
                                     'sex': None, 'address': None}, beneficiary_name_found=True)
        result = _run(scan)
        self.assertEqual(result['findings']['thesis_checks']['consistent']['state'], 'review')
        self.assertEqual(result['status'], 'needs_staff_review')

    def test_birth_date_conflict_fails_consistency(self):
        scan = _scan(primary_person={'name': 'Nicasio Eimar Bustillo', 'role': 'patient', 'birth_date': '1961-03-04',
                                     'sex': None, 'address': None})
        result = _run(scan)
        rows = {row['field']: row for row in result['findings']['field_comparison']}
        self.assertEqual(rows['birth_date']['state'], 'fail')
        self.assertEqual(result['status'], 'flagged_inconsistency')

    def test_unreadable_document_needs_reupload(self):
        result = _run(_scan(checks={'readable': {'state': 'fail', 'reason': 'The photo is too blurry.', 'missing': []}}))
        self.assertEqual(result['status'], 'needs_reupload')
        self.assertEqual(result['findings']['verdict']['headline'], 'Document is not readable')

    def test_incomplete_document_needs_reupload(self):
        result = _run(_scan(checks={'complete': {'state': 'fail', 'reason': 'The bottom half is cut off.',
                                                 'missing': ['physician signature']}}))
        self.assertEqual(result['status'], 'needs_reupload')
        self.assertIn('physician signature', result['findings']['verdict']['action'])

    def test_uncorroborated_tamper_fail_is_only_review(self):
        result = _run(_scan(checks={'untampered': {'state': 'fail', 'reason': 'The date digits look pasted.', 'missing': []}}))
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'review')
        self.assertEqual(result['status'], 'needs_staff_review')

    def test_corroborated_tamper_fail_is_flagged(self):
        result = _run(_scan(checks={'untampered': {'state': 'fail', 'reason': 'The date digits look pasted.', 'missing': []}}),
                      tamper=REVIEW_TAMPER)
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'fail')
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['verdict']['headline'], 'Possible document changes found')

    def test_local_forensic_review_alone_makes_untampered_review(self):
        result = _run(tamper=REVIEW_TAMPER)
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'review')
        self.assertEqual(result['status'], 'needs_staff_review')

    def test_deterministic_date_conflict_stays_flagged(self):
        chronology = {'status': 'completed', 'decision': 'review', 'review_basis': 'deterministic_chronology_conflict',
                      'label': 'Document date inconsistency — staff review required', 'indicators': []}
        result = _run(local=_local(status='flagged_inconsistency'), tamper=chronology)
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['thesis_checks']['consistent']['state'], 'fail')
        self.assertEqual(result['findings']['verdict']['headline'], 'Details do not agree')

    def test_ocr_based_local_flag_is_replaced_by_gemini_reading(self):
        result = _run(local=_local(status='flagged_inconsistency'))
        self.assertEqual(result['status'], 'likely_valid')

    def test_low_confidence_other_type_is_review(self):
        result = _run(_scan(document_type='billing_statement', type_confidence=55))
        self.assertEqual(result['findings']['thesis_checks']['consistent']['state'], 'review')
        self.assertEqual(result['status'], 'needs_staff_review')

    def test_medical_report_is_accepted_for_police_or_medical_report_slot(self):
        scan = _scan(primary_person={'name': 'Nicasio Bustillo', 'role': 'subject', 'birth_date': None,
                                     'sex': None, 'address': None})
        result = _run(scan, expected='report_police_med')
        self.assertEqual(result['findings']['thesis_checks']['consistent']['state'], 'pass')

    def test_burial_valid_id_of_claimant_is_not_a_name_conflict(self):
        scan = _scan(document_type='valid_id', document_title='PHILIPPINE IDENTIFICATION CARD',
                     primary_person={'name': 'Pedro Santos', 'role': 'cardholder', 'birth_date': '1980-01-01',
                                     'sex': 'Male', 'address': None}, beneficiary_name_found=False)
        result = apply_verdict(_local(), 'valid_id', _gemini(scan), CLEAR_TAMPER,
                               {**BENEFICIARY, 'assistance_types': ['burial']})
        rows = {row['field']: row for row in result['findings']['field_comparison']}
        self.assertEqual(rows['name']['state'], 'review')
        self.assertEqual(rows['birth_date']['state'], 'not_compared')
        self.assertEqual(result['status'], 'needs_staff_review')

    def test_gemini_unavailable_falls_back_to_local_checks(self):
        local = _local()
        result = _run(local=copy.deepcopy(local), gemini={'status': 'unavailable', 'error_code': 'GEMINI_NOT_CONFIGURED'})
        self.assertEqual(result['status'], local['status'])
        self.assertEqual(result['detected_document_type'], 'registration_form')
        verdict = result['findings']['verdict']
        self.assertEqual(verdict['source'], 'local')
        checks = result['findings']['thesis_checks']
        self.assertEqual(list(checks), list(CHECK_KEYS))
        self.assertEqual(checks['readable']['state'], 'pass')
        self.assertNotIn('low', checks['readable']['reason'].lower())
        self.assertEqual(checks['consistent']['state'], 'review')
        self.assertEqual(checks['complete']['state'], 'review')
        self.assertEqual(checks['unaltered']['state'], 'unavailable')
        self.assertNotIn('gemini_scan', result['findings'])

    def test_scan_summary_is_stored_without_unbounded_data(self):
        result = _run()
        stored = result['findings']['gemini_scan']
        self.assertEqual(stored['model'], 'gemini-2.5-flash')
        self.assertEqual(stored['document_title'], 'MEDICAL CERTIFICATE')
        self.assertNotIn('primary_person', stored)


OVERLAY = {'type': 'digital_text_overlay', 'page': 1, 'box_2d': [802, 538, 823, 773], 'confidence_band': 'high',
           'source': 'local_visual', 'reason': 'This text is flat digital black and much darker than the rest.'}
DATE_CONFLICT = {'type': 'date_context_conflict', 'page': 1, 'box_2d': [805, 481, 819, 766], 'confidence_band': 'high',
                 'source': 'chronology', 'reason': 'The signature date conflicts with event dates.'}


def _tamper(indicators, chronology=False, design_app=False):
    combined = {'status': 'completed', 'decision': 'review' if chronology else 'clear',
                'review_basis': 'deterministic_chronology_conflict' if chronology else 'none',
                'indicators': indicators,
                'auxiliary': {'file': {'editing_software': 'Canva' if design_app else None, 'design_app': design_app}}}
    if chronology:
        combined['date_conflict'] = {'document_date': '2026-09-24', 'conflicting_years': [2021]}
    return combined


class EditedTextVerdictTests(unittest.TestCase):
    def test_pasted_conflicting_date_from_canva_is_flagged_as_edited_date(self):
        result = _run(tamper=_tamper([DATE_CONFLICT, OVERLAY], chronology=True, design_app=True))
        checks, verdict = result['findings']['thesis_checks'], result['findings']['verdict']
        self.assertEqual(checks['untampered']['state'], 'fail')
        self.assertIn('Canva', checks['untampered']['reason'])
        self.assertEqual(verdict['headline'], 'Possible edited date')
        self.assertIn('September 24, 2026', verdict['reason'])
        self.assertIn('2021', verdict['reason'])
        self.assertEqual(result['status'], 'flagged_inconsistency')

    def test_pasted_text_alone_needs_review_only(self):
        result = _run(tamper=_tamper([OVERLAY]))
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'review')
        self.assertEqual(result['status'], 'needs_staff_review')

    def test_pasted_text_confirmed_by_ai_reading_is_fail(self):
        scan = _scan(checks={'untampered': {'state': 'review', 'reason': 'The date uses a different font.', 'missing': []}})
        result = _run(scan, tamper=_tamper([OVERLAY]))
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'fail')
        self.assertEqual(result['findings']['verdict']['headline'], 'Possible document changes found')

    def test_canva_export_alone_is_only_mentioned(self):
        result = _run(tamper=_tamper([], design_app=True))
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'pass')
        self.assertEqual(result['status'], 'likely_valid')

    def test_local_fallback_also_flags_edited_date(self):
        result = _run(tamper=_tamper([DATE_CONFLICT, OVERLAY], chronology=True, design_app=True),
                      gemini={'status': 'unavailable', 'error_code': 'GEMINI_TIMEOUT'})
        self.assertEqual(result['findings']['thesis_checks']['untampered']['state'], 'fail')
        self.assertEqual(result['findings']['verdict']['headline'], 'Possible edited date')
        self.assertEqual(result['status'], 'flagged_inconsistency')


class VisualAdapterTests(unittest.TestCase):
    def test_indicators_convert_to_aggregator_shape(self):
        scan = _scan(checks={'untampered': {'state': 'review', 'reason': 'x', 'missing': []}}, indicators=[
            {'type': 'pasted_region_boundary', 'check': 'untampered', 'page': 1, 'box_2d': [1, 2, 30, 40],
             'confidence_band': 'high', 'reason': 'Hard edge around the amount.'},
            {'type': 'handwritten_correction', 'check': 'unaltered', 'page': 1, 'box_2d': None,
             'confidence_band': 'moderate', 'reason': 'Overwritten digit.'},
        ])
        visual = as_visual_result(_gemini(scan))
        self.assertEqual(visual['payload_mode'], 'image')
        tamper = visual['assessment']['tamper_assessment']
        self.assertEqual(tamper['state'], 'review_signal')
        self.assertEqual(len(tamper['indicators']), 1)
        self.assertEqual(tamper['indicators'][0]['type'], 'pasted_region_boundary')

    def test_new_gemini_indicator_types_survive_aggregation_and_persistence(self):
        from ai_processor.tamper_detection.aggregation import aggregate_tamper_evidence
        from ai_processor.tamper_detection.postprocessing import attach_combined_finding
        scan = _scan(indicators=[
            {'type': 'sample_or_watermark', 'check': 'untampered', 'page': 1, 'box_2d': [880, 20, 915, 400],
             'confidence_band': 'high', 'reason': 'Website address printed below the form.'},
            {'type': 'handwritten_correction', 'check': 'unaltered', 'page': 1, 'box_2d': [100, 100, 150, 300],
             'confidence_band': 'moderate', 'reason': 'Digit written over a typed value.'},
        ])
        clear = {'status': 'completed', 'decision': 'clear', 'indicators': []}
        combined = aggregate_tamper_evidence(as_visual_result(_gemini(scan)), clear, clear, {}, local_results=[])
        saved = attach_combined_finding({}, combined)['tamper_detection']
        self.assertEqual({item['type'] for item in saved['indicators']}, {'sample_or_watermark', 'handwritten_correction'})

    def test_whole_image_finding_without_a_box_gets_a_document_box(self):
        scan = _scan(checks={'untampered': {'state': 'review', 'reason': 'The card is shown on a display stand.', 'missing': []}},
                     sample_or_specimen={'detected': True, 'reason': 'A sample card on a stand.'}, indicators=[])
        indicators = as_visual_result(_gemini(scan))['assessment']['tamper_assessment']['indicators']
        self.assertEqual(len(indicators), 1)
        self.assertEqual(indicators[0]['type'], 'sample_or_watermark')
        self.assertEqual(indicators[0]['confidence_band'], 'moderate')
        self.assertEqual(indicators[0]['reason'], 'The card is shown on a display stand.')

    def test_unavailable_scan_gives_unavailable_visual(self):
        self.assertEqual(as_visual_result({'status': 'unavailable'})['status'], 'unavailable')


if __name__ == '__main__':
    unittest.main()
