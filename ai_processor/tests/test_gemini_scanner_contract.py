import json
import unittest

from ai_processor.gemini_scanner.catalog import DOCUMENT_CATALOG, document_label, identity_role
from ai_processor.gemini_scanner.contract import CHECK_KEYS, normalize_scan
from ai_processor.gemini_scanner.prompt import build_request_text, response_schema


def _raw(**overrides):
    raw = {
        'document_type': 'death_cert',
        'document_title': 'CERTIFICATE OF DEATH',
        'type_confidence': 96,
        'primary_person': {'name': 'Jennifer Angeles Ramos', 'role': 'deceased', 'birth_date': '2001-11-20',
                           'sex': 'Female', 'address': None},
        'beneficiary_name_found': False,
        'document_date': '2008-06-28',
        'issuer': 'Office of the Civil Registrar',
        'checks': {key: {'state': 'pass', 'reason': 'ok', 'missing': []} for key in CHECK_KEYS},
        'indicators': [],
        'sample_or_specimen': {'detected': True, 'reason': 'Website watermark commercial-archive.com is printed.'},
        'summary': 'A civil registry death certificate.',
    }
    raw.update(overrides)
    return raw


class CatalogTests(unittest.TestCase):
    def test_catalog_covers_every_required_slot(self):
        for code in ('valid_id', 'med_cert', 'billing_statement', 'death_cert', 'funeral_contract',
                     'report_police_med', 'school_assessment', 'registration_form', 'mdrrmo_cert',
                     'damaged_house_photo', 'crisis_situation_cert'):
            self.assertIn(code, DOCUMENT_CATALOG)
        self.assertEqual(document_label('death_cert'), 'Death Certificate')
        self.assertEqual(identity_role('med_cert'), 'patient')
        self.assertEqual(identity_role('damaged_house_photo'), None)
        self.assertEqual(document_label('medical_certificate'), 'Medical Abstract or Medical Certificate')

    def test_education_asks_for_a_school_id_and_an_enrollment_form(self):
        self.assertEqual(document_label('school_id'), 'School ID')
        self.assertEqual(document_label('registration_form'), 'Enrollment Form')
        self.assertIn('school id', DOCUMENT_CATALOG['school_id']['looks_like'].lower())
        self.assertIn('enrollment form', DOCUMENT_CATALOG['registration_form']['looks_like'].lower())
        self.assertEqual(identity_role('school_id'), 'learner')
        self.assertEqual(identity_role('registration_form'), 'learner')


class PromptTests(unittest.TestCase):
    def test_schema_enumerates_catalog_types_and_five_checks(self):
        schema = response_schema()
        types = schema['properties']['document_type']['enum']
        for code in DOCUMENT_CATALOG:
            self.assertIn(code, types)
        self.assertIn('other', types)
        self.assertIn('unknown', types)
        self.assertEqual(sorted(schema['properties']['checks']['required']), sorted(CHECK_KEYS))

    def test_request_text_names_the_required_slot_and_only_the_beneficiary_name(self):
        text = build_request_text('med_cert', {'full_name': 'Juan Dela Cruz', 'date_of_birth': '1990-01-01',
                                               'address': 'Secret Street'}, page_count=2)
        payload = json.loads(text)
        self.assertEqual(payload['required_document']['code'], 'med_cert')
        self.assertEqual(payload['beneficiary_full_name'], 'Juan Dela Cruz')
        self.assertEqual(payload['page_count'], 2)
        self.assertRegex(payload['today'], r'^\d{4}-\d{2}-\d{2}$')
        self.assertNotIn('Secret Street', text)
        self.assertNotIn('1990-01-01', text)


class ContractTests(unittest.TestCase):
    def test_valid_scan_is_normalized(self):
        scan = normalize_scan(_raw(), page_count=1)
        self.assertEqual(scan['document_type'], 'death_cert')
        self.assertEqual(scan['type_confidence'], 96)
        self.assertEqual(scan['primary_person']['role'], 'deceased')
        self.assertTrue(scan['sample_or_specimen']['detected'])
        self.assertEqual(set(scan['checks']), set(CHECK_KEYS))

    def test_unknown_enums_and_ranges_are_clamped(self):
        scan = normalize_scan(_raw(document_type='passport_x', type_confidence=250,
                                   checks={'readable': {'state': 'excellent', 'reason': 'x'}}), page_count=1)
        self.assertEqual(scan['document_type'], 'unknown')
        self.assertEqual(scan['type_confidence'], 100)
        self.assertEqual(scan['checks']['readable']['state'], 'review')
        self.assertEqual(scan['checks']['complete']['state'], 'unavailable')

    def test_indicator_boxes_and_pages_are_bounded(self):
        scan = normalize_scan(_raw(indicators=[
            {'type': 'pasted_region_boundary', 'check': 'untampered', 'page': 9, 'box_2d': [-5, 10, 1500, 400],
             'confidence_band': 'high', 'reason': 'Edges around the date differ.'},
            {'type': 'nonsense', 'check': 'untampered', 'page': 1, 'box_2d': [1, 2, 3, 4],
             'confidence_band': 'high', 'reason': 'x'},
            {'type': 'erasure_or_cover_region', 'check': 'unaltered', 'page': 1, 'box_2d': [500, 500, 400, 600],
             'confidence_band': 'moderate', 'reason': 'White-out over the amount.'},
        ]), page_count=1)
        self.assertEqual(len(scan['indicators']), 2)
        first, second = scan['indicators']
        self.assertEqual(first['page'], 1)
        self.assertEqual(first['box_2d'], [0, 10, 1000, 400])
        self.assertIsNone(second['box_2d'])

    def test_accusatory_language_is_replaced_with_neutral_text(self):
        checks = {key: {'state': 'pass', 'reason': 'ok'} for key in CHECK_KEYS}
        checks['untampered'] = {'state': 'fail', 'reason': 'This document is FORGED and fake.'}
        scan = normalize_scan(_raw(checks=checks, summary='Fraudulent certificate.'), page_count=1)
        text = json.dumps(scan).lower()
        for word in ('forged', 'fake', 'fraud'):
            self.assertNotIn(word, text)
        self.assertEqual(scan['checks']['untampered']['state'], 'fail')

    def test_long_text_is_truncated(self):
        scan = normalize_scan(_raw(summary='a' * 5000, document_title='b' * 500), page_count=1)
        self.assertLessEqual(len(scan['summary']), 400)
        self.assertLessEqual(len(scan['document_title']), 120)

    def test_non_object_input_raises(self):
        with self.assertRaises(ValueError):
            normalize_scan(['not', 'an', 'object'], page_count=1)


if __name__ == '__main__':
    unittest.main()
