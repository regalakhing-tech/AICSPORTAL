import io
import json
import tempfile
import unittest
from contextlib import redirect_stdout
from dataclasses import replace
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image

from ai_processor import id_check
from ai_processor.config import SETTINGS


def _reading(**overrides):
    reading = {
        'front': {'document_detected': True, 'is_id': True, 'id_type': 'philsys_national_id',
                  'document_title': 'Philippine Identification Card', 'issuer': 'Philippine Statistics Authority',
                  'confidence': 'high', 'is_supported_id': True, 'philsys_logo_visible': False,
                  'readable': 'clear', 'last_name': 'DELA CRUZ',
                  'given_names': 'JUAN SANTOS', 'middle_name': None, 'birth_date': 'MARCH 12, 1975',
                  'expiry_date': '2029-03-12', 'expired': False},
        'back': {'is_back_of_id': True, 'same_card_as_front': 'yes',
                 'is_same_document_as_front': False, 'readable': 'clear'},
        'edited': {'state': 'pass', 'reason': 'No signs of editing.'},
        'sample_or_screenshot': {'detected': False, 'reason': ''},
    }
    for key, value in overrides.items():
        reading[key] = {**reading[key], **value}
    return reading


class FakeClient:
    def __init__(self, raw=None, status='completed'):
        self.raw, self.status, self.calls = raw, status, []

    def scan(self, request_text, image_data_urls, page_count, *, instruction=None, schema=None, normalizer=None):
        self.calls.append({'request': json.loads(request_text), 'images': len(image_data_urls),
                           'instruction': instruction, 'schema': schema})
        if self.status != 'completed':
            return {'status': 'unavailable', 'error_code': 'GEMINI_TIMEOUT'}
        return {'status': 'completed', 'model': 'test-model', 'elapsed_ms': 10, 'scan': normalizer(self.raw, page_count)}


class NormalizeTests(unittest.TestCase):
    def test_reading_is_bounded_and_dates_are_iso(self):
        raw = _reading(front={'id_type': 'made_up_type', 'last_name': 'X' * 500, 'birth_date': 'MARCH 12, 1975'})
        reading = id_check.normalize_reading(raw, 2)
        self.assertEqual(reading['front']['id_type'], 'unknown_id')
        self.assertEqual(len(reading['front']['last_name']), 80)
        self.assertEqual(reading['front']['birth_date'], '1975-03-12')

    def test_april_3_and_march_4_stay_distinct(self):
        for written, expected in (('April 3, 2005', '2005-04-03'), ('March 4, 2005', '2005-03-04')):
            with self.subTest(written=written):
                reading = id_check.normalize_reading(_reading(front={'birth_date': written}), 2)
                result = id_check.decide(reading, {'typed_on_text': False, 'design_app': None})
                self.assertEqual(result['birth_date'], expected)

    def test_non_object_is_rejected(self):
        with self.assertRaises(ValueError):
            id_check.normalize_reading([], 2)


class DecisionTests(unittest.TestCase):
    def _decide(self, raw, overlay=False, design_app=None):
        return id_check.decide(id_check.normalize_reading(raw, 2), {'typed_on_text': overlay, 'design_app': design_app})

    def test_clear_id_is_ok_with_name_parts(self):
        result = self._decide(_reading())
        self.assertEqual(result['decision'], 'ok')
        self.assertEqual(result['last_name'], 'DELA CRUZ')
        self.assertEqual(result['given_names'], 'JUAN SANTOS')
        self.assertEqual(result['birth_date'], '1975-03-12')
        self.assertEqual(result['expiration_date'], '2029-03-12')
        self.assertEqual(result['id_type_label'], 'Philippine National ID (PhilSys)')
        self.assertTrue(result['is_supported_id'])

    def test_philsys_title_and_logo_confirm_issuer_without_agency_text(self):
        for issuer in (None, 'Republic of the Philippines'):
            with self.subTest(issuer=issuer):
                result = self._decide(_reading(front={
                    'document_title': 'Pambansang Pagkakakilanlan / Philippine Identification Card',
                    'issuer': issuer, 'philsys_logo_visible': True, 'is_supported_id': False,
                }))
                self.assertEqual(result['decision'], 'ok')
                self.assertEqual(result['issuer'], 'Philippine Statistics Authority')

    def test_republic_heading_without_philsys_logo_is_insufficient(self):
        result = self._decide(_reading(front={
            'document_title': 'Philippine Identification Card',
            'issuer': 'Republic of the Philippines', 'philsys_logo_visible': False,
        }))
        self.assertEqual(result['decision'], 'unknown_id')

    def test_student_title_cannot_use_philsys_logo_exception(self):
        result = self._decide(_reading(front={
            'document_title': 'Student ID / Philippine Identification Card',
            'issuer': 'Republic of the Philippines', 'philsys_logo_visible': True,
        }))
        self.assertEqual(result['decision'], 'unsupported_id')

    def test_approved_government_ids(self):
        cases = [
            ('philsys_national_id', 'Philippine Statistics Authority'),
            ('philsys_national_id', 'PSA'),
            ('drivers_license', 'Land Transportation Office'),
            ('passport', 'Department of Foreign Affairs'),
            ('umid', 'Social Security System'),
            ('sss_id', 'Social Security System'),
            ('gsis_id', 'Government Service Insurance System'),
            ('philhealth_id', 'Philippine Health Insurance Corporation'),
            ('prc_id', 'Professional Regulation Commission'),
            ('pagibig_id', 'Pag-IBIG Fund'),
            ('owwa_id', 'Overseas Workers Welfare Administration'),
            ('dole_id', 'Department of Labor and Employment'),
            ('voters_id', 'Commission on Elections'),
            ('voters_certification', 'Commission on Elections'),
            ('postal_id', 'Philippine Postal Corporation'),
            ('nbi_clearance', 'National Bureau of Investigation'),
            ('four_ps_id', 'Department of Social Welfare and Development'),
            ('pwd_id', 'City Government of San Jose de Buenavista'),
            ('solo_parent_id', 'Municipal Government of Hamtic'),
            ('senior_citizen_id', 'Office of Senior Citizens Affairs'),
            ('city_municipal_id', 'City Government of San Jose de Buenavista'),
            ('barangay_id', 'Barangay Government of San Angel'),
            ('police_clearance', 'Philippine National Police'),
            ('other_government_id', 'Department of Agriculture'),
        ]
        for kind, issuer in cases:
            with self.subTest(kind=kind):
                title = 'Department of Agriculture Identification Card' if kind == 'other_government_id' else id_check.ID_TYPES[kind]
                result = self._decide(_reading(front={'id_type': kind, 'issuer': issuer, 'document_title': title}))
                self.assertEqual(result['decision'], 'ok')

    def test_unsupported_cards_and_documents_fail_even_with_matching_identity(self):
        kinds = ['school_id', 'student_id', 'university_id', 'college_id', 'senior_high_school_id',
                 'faculty_id', 'alumni_id', 'company_id', 'employee_id', 'membership_card',
                 'library_card', 'birth_certificate', 'medical_certificate', 'random_document',
                 'random_photo', 'unsupported_id', 'unknown_id']
        for kind in kinds:
            with self.subTest(kind=kind):
                result = self._decide(_reading(front={'id_type': kind, 'issuer': 'University of the Philippines',
                                                   'document_title': kind.replace('_', ' ').title()}))
                self.assertNotEqual(result['decision'], 'ok')
                self.assertFalse(result['is_supported_id'])
                self.assertIsNone(result['last_name'])
                self.assertIsNone(result['birth_date'])

    def test_public_and_private_school_cards_never_pass(self):
        for issuer in ('University of the Philippines', 'Example Private University'):
            with self.subTest(issuer=issuer):
                result = self._decide(_reading(front={'id_type': 'school_id', 'issuer': issuer,
                                                   'document_title': 'Student ID'}))
                self.assertEqual(result['id_type'], 'school_id')
                self.assertEqual(result['decision'], 'unsupported_id')

    def test_school_classification_wins_even_if_model_says_not_an_id(self):
        result = self._decide(_reading(front={'is_id': False, 'id_type': 'school_id',
                                                 'document_title': 'University of the Philippines Student ID',
                                                 'issuer': 'University of the Philippines'}))
        self.assertEqual(result['decision'], 'unsupported_id')
        self.assertIn('School or student', result['message'])

    def test_uncertain_or_unsubstantiated_government_type_fails(self):
        for front in ({'confidence': 'low'}, {'confidence': 'medium'}, {'issuer': None},
                      {'issuer': 'Republic of the Philippines'}, {'document_title': 'Student ID'},
                      {'id_type': 'unknown_id'}, {'id_type': 'other_government_id', 'issuer': 'Unknown office'},
                      {'id_type': 'other_government_id', 'issuer': 'Department of Agriculture',
                       'document_title': 'Other government ID'},
                      {'id_type': 'other_government_id', 'issuer': 'Department of Campus Affairs',
                       'document_title': 'Identification Card'}):
            with self.subTest(front=front):
                result = self._decide(_reading(front=front))
                self.assertNotEqual(result['decision'], 'ok')
                self.assertFalse(result['is_supported_id'])

    def test_non_id_papers_cannot_use_other_government_fallback(self):
        titles = ['Barangay Certificate', 'Birth Certificate', 'Marriage Certificate',
                  'Medical Certificate', 'Medical Abstract', 'Prescription', 'Billing Statement',
                  'School Assessment', 'Registration Form', 'Funeral Contract', 'Death Certificate',
                  'Police Report', 'MDRRMO Certification', 'Certificate of Eligibility',
                  'Certificate of Indigency', 'Certificate of Residency']
        for title in titles:
            with self.subTest(title=title):
                result = self._decide(_reading(front={'id_type': 'other_government_id',
                                                   'issuer': 'Barangay Government of San Angel',
                                                   'document_title': title}))
                self.assertEqual(result['decision'], 'unsupported_id')
                self.assertFalse(result['is_supported_id'])

    def test_approved_single_sided_documents_use_second_view_of_same_document(self):
        for kind, issuer in [('voters_certification', 'Commission on Elections'),
                             ('nbi_clearance', 'National Bureau of Investigation'),
                             ('police_clearance', 'Philippine National Police')]:
            with self.subTest(kind=kind):
                front = {'id_type': kind, 'document_title': id_check.ID_TYPES[kind], 'issuer': issuer}
                back = {'is_back_of_id': False, 'same_card_as_front': 'unsure',
                        'is_same_document_as_front': True}
                self.assertEqual(self._decide(_reading(front=front, back=back))['decision'], 'ok')
                back['is_same_document_as_front'] = False
                self.assertNotEqual(self._decide(_reading(front=front, back=back))['decision'], 'ok')

    def test_not_an_id_unreadable_or_wrong_back_asks_for_retake(self):
        for raw, words in ((_reading(front={'is_id': False, 'id_type': 'not_an_id'}), 'does not appear'),
                           (_reading(front={'readable': 'unreadable'}), 'clearly read'),
                           (_reading(front={'last_name': None}), 'clearly read'),
                           (_reading(back={'same_card_as_front': 'no'}), 'back of the same')):
            with self.subTest(words=words):
                result = self._decide(raw)
                self.assertEqual(result['decision'], 'retake')
                self.assertIn(words, result['message'])

    def test_typed_on_text_or_clear_edit_or_screenshot_is_edited(self):
        for kwargs in ({'overlay': True}, {'raw': _reading(edited={'state': 'fail', 'reason': 'Name pasted.'})},
                       {'raw': _reading(sample_or_screenshot={'detected': True, 'reason': 'Screenshot of a website.'})}):
            with self.subTest(kwargs=list(kwargs)):
                result = self._decide(kwargs.get('raw', _reading()), overlay=kwargs.get('overlay', False))
                self.assertEqual(result['decision'], 'edited')

    def test_uncertain_back_or_possible_edit_does_not_pass(self):
        for raw in (_reading(edited={'state': 'review', 'reason': 'Possible alteration.'}),
                    _reading(back={'same_card_as_front': 'unsure'}),
                    _reading(back={'readable': 'unreadable'})):
            with self.subTest(raw=raw):
                result = self._decide(raw)
                self.assertNotEqual(result['decision'], 'ok')
                self.assertFalse(result['is_supported_id'])


class CheckIdTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.front, self.back = Path(self.folder.name) / 'front.jpg', Path(self.folder.name) / 'back.jpg'
        for path in (self.front, self.back):
            Image.fromarray(np.full((300, 480, 3), 230, np.uint8)).save(path)
        self.settings = replace(SETTINGS, gemini_image_enabled=True, gemini_image_api_key='synthetic')

    def tearDown(self):
        self.folder.cleanup()

    def test_completed_check_sends_both_sides_and_no_personal_data_in_request(self):
        client = FakeClient(_reading())
        result = id_check.check_id(self.settings, self.front, self.back, client=client, local_checks=lambda paths: {'typed_on_text': False, 'design_app': None})
        self.assertEqual(result['status'], 'completed')
        self.assertEqual(result['decision'], 'ok')
        self.assertEqual(client.calls[0]['images'], 2)
        self.assertEqual(len(client.calls), 1)
        self.assertIn('last_name', json.dumps(client.calls[0]['schema']))
        self.assertIn('today', client.calls[0]['request'])
        self.assertNotIn('id_number', json.dumps(result))

    def test_unsupported_document_stops_before_identity_extraction(self):
        client = FakeClient(_reading(front={'id_type': 'school_id', 'document_title': 'Student ID',
                                            'issuer': 'University of the Philippines'}))
        result = id_check.check_id(self.settings, self.front, self.back, client=client,
                                   local_checks=lambda paths: {'typed_on_text': False, 'design_app': None})
        self.assertEqual(result['decision'], 'unsupported_id')
        self.assertEqual(len(client.calls), 1)
        self.assertIsNone(result['full_name'])
        self.assertIsNone(result['last_name'])
        self.assertIsNone(result['birth_date'])

    def test_philsys_visual_issuer_evidence_reaches_identity_stage(self):
        client = FakeClient(_reading(front={
            'document_title': 'Pambansang Pagkakakilanlan / Philippine Identification Card',
            'issuer': 'Republic of the Philippines', 'philsys_logo_visible': True,
            'is_supported_id': False,
        }))
        result = id_check.check_id(self.settings, self.front, self.back, client=client,
                                   local_checks=lambda paths: {'typed_on_text': False, 'design_app': None})
        self.assertEqual(result['decision'], 'ok')
        self.assertEqual(result['issuer'], 'Philippine Statistics Authority')
        self.assertEqual(len(client.calls), 1)
        self.assertIn('philsys_logo_visible', client.calls[0]['schema']['properties']['front']['properties'])

    def test_ai_unavailable_asks_to_try_again(self):
        result = id_check.check_id(self.settings, self.front, self.back, client=FakeClient(status='unavailable'),
                                   local_checks=lambda paths: {'typed_on_text': False, 'design_app': None})
        self.assertEqual((result['status'], result['decision']), ('unavailable', 'unavailable'))
        self.assertIn('try again', result['message'])
        self.assertNotIn('staff', result['message'])

    def test_not_configured_is_unavailable(self):
        result = id_check.check_id(replace(self.settings, gemini_image_api_key=''), self.front, self.back,
                                   local_checks=lambda paths: {'typed_on_text': False, 'design_app': None})
        self.assertEqual(result['decision'], 'unavailable')

    def test_large_photos_are_shrunk_for_local_checks(self):
        import cv2
        big = np.full((3200, 4200, 3), 240, np.uint8)
        small = id_check._shrink(big)
        self.assertEqual(max(small.shape[:2]), id_check.LOCAL_CHECK_MAX_EDGE)

    @unittest.skipUnless(Path(SETTINGS.tesseract_path or '').is_file(), 'needs Tesseract')
    def test_text_lines_are_found_on_a_sideways_card(self):
        import cv2
        card = np.full((500, 900, 3), 235, np.uint8)
        for index, text in enumerate(('REPUBLIKA NG PILIPINAS', 'PHILIPPINE IDENTIFICATION CARD', 'LAST NAME DELA CRUZ',
                                      'GIVEN NAMES JUAN SANTOS', 'DATE OF BIRTH MARCH 12 1975')):
            cv2.putText(card, text, (40, 70 + index * 80), cv2.FONT_HERSHEY_SIMPLEX, 1.1, (20, 20, 20), 2, cv2.LINE_AA)
        sideways = cv2.rotate(card, cv2.ROTATE_90_CLOCKWISE)
        image, boxes = id_check._upright_with_text_lines(sideways)
        self.assertGreaterEqual(len(boxes), 3)
        self.assertGreater(image.shape[1], image.shape[0])  # turned back to landscape

    def test_cli_prints_one_json_object(self):
        with patch.object(id_check, 'check_id', return_value={'status': 'completed', 'decision': 'ok'}):
            buffer = io.StringIO()
            with redirect_stdout(buffer):
                code = id_check.main(['--front', str(self.front), '--back', str(self.back)])
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(buffer.getvalue()), {'status': 'completed', 'decision': 'ok'})


if __name__ == '__main__':
    unittest.main()
