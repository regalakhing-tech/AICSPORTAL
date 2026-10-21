"""Printed totals are checked by the system's own arithmetic, not by the AI reading."""
import unittest

from ai_processor.gemini_scanner.contract import normalize_scan
from ai_processor.gemini_scanner.prompt import CHECK_KEYS, PROMPT_VERSION, response_schema
from ai_processor.gemini_scanner.verdict import _amount, apply_verdict

CLEAR = {'status': 'completed', 'decision': 'clear', 'indicators': []}


def _scan(totals):
    return {
        'document_type': 'billing_statement', 'document_title': 'BILLING STATEMENT', 'type_confidence': 95,
        'primary_person': {'name': 'Nicasio Eimar Bustillo', 'role': 'patient', 'birth_date': None, 'sex': None, 'address': None},
        'beneficiary_name_found': True, 'document_date': '2023-03-18', 'issuer': 'Magdalena Clinic',
        'checks': {key: {'state': 'pass', 'reason': f'{key} ok', 'missing': []} for key in CHECK_KEYS},
        'indicators': [], 'sample_or_specimen': {'detected': False, 'reason': ''},
        'totals': totals, 'summary': 'Billing statement.',
    }


def _run(totals):
    gemini = {'status': 'completed', 'model': 'gemini-test', 'scan': _scan(totals)}
    local = {'status': 'likely_valid', 'detected_document_type': 'billing_statement', 'findings': {'reasons': []}}
    return apply_verdict(local, 'billing_statement', gemini, CLEAR, {'full_name': 'Nicasio Eimar Bustillo'})


CANVA_BILL = {'label': 'Amount', 'stated_total': 'P 1, 950.00',
              'line_amounts': ['P 50.00', 'P 140.00', 'P 600.00', 'P 1, 190.00']}
ORIGINAL_BILL = {'label': 'Amount', 'stated_total': 'P 1,950.00',
                 'line_amounts': ['P 50.00', 'P 140.00', 'P 600.00', 'P 1,160.00']}


class AmountParsingTests(unittest.TestCase):
    def test_printed_amounts(self):
        self.assertEqual(_amount('P 1, 190.00'), 1190.0)
        self.assertEqual(_amount('₱ 28,430.00'), 28430.0)
        self.assertEqual(_amount(',3,500.00'), 3500.0)
        self.assertEqual(_amount('(500.00)'), -500.0)
        self.assertIsNone(_amount('1,19'))
        self.assertIsNone(_amount('PR3031'))


class TotalsVerdictTests(unittest.TestCase):
    def test_total_that_adds_up_passes(self):
        result = _run([ORIGINAL_BILL])
        self.assertEqual(result['findings']['thesis_checks']['consistent']['state'], 'pass')
        self.assertEqual(result['status'], 'likely_valid')

    def test_one_wrong_total_needs_review(self):
        result = _run([CANVA_BILL])
        consistent = result['findings']['thesis_checks']['consistent']
        self.assertEqual(consistent['state'], 'review')
        self.assertIn('₱1,980.00', consistent['reason'])
        self.assertIn('₱1,950.00', consistent['reason'])
        self.assertEqual(result['status'], 'needs_staff_review')

    def test_two_wrong_totals_are_flagged(self):
        actual = {'label': 'Actual', 'stated_total': '96,664.30',
                  'line_amounts': ['28,430.00', '4,755.00', '465.00', '2,000.00', '1,800.00', '10,031.50',
                                   '38,312.40', '70.00', '779.40', '2,985.00', '6,640.00', '996.80']}
        discount = {'label': 'Discount', 'stated_total': '19,332.86',
                    'line_amounts': ['5,686.00', '951.00', '93.00', '400.00', '360.00', '2,006.30',
                                     '7,662.48', '14.00', '58.88', '597.00', '1,328.00', '199.20']}
        result = _run([actual, discount])
        self.assertEqual(result['findings']['thesis_checks']['consistent']['state'], 'fail')
        self.assertEqual(result['status'], 'flagged_inconsistency')
        self.assertEqual(result['findings']['verdict']['headline'], 'Totals do not add up')

    def test_gap_equal_to_one_line_is_treated_as_a_reading_slip(self):
        skipped = {'label': 'Amount', 'stated_total': '1,950.00', 'line_amounts': ['50.00', '140.00', '600.00', '1,160.00', '600.00']}
        self.assertEqual(_run([skipped])['findings']['thesis_checks']['consistent']['state'], 'pass')

    def test_balance_after_cash_advance_adds_up(self):
        # Funeral contract: total cost less cash advance is the balance.
        balance = {'label': 'Balance', 'stated_total': 'P 23,000.00', 'line_amounts': ['P 65,000.00', 'P 42,000.00']}
        cost = {'label': 'Total cost', 'stated_total': 'P 65,000.00',
                'line_amounts': ['45,000.00', '8,000.00', '4,000.00', '8,000.00']}
        result = _run([cost, balance])
        self.assertEqual(result['findings']['thesis_checks']['consistent']['state'], 'pass')

    def test_total_copied_into_its_own_lines_is_ignored(self):
        repeated = {**ORIGINAL_BILL, 'line_amounts': [*ORIGINAL_BILL['line_amounts'], 'P 1,950.00']}
        self.assertEqual(_run([repeated])['findings']['thesis_checks']['consistent']['state'], 'pass')


class TotalsContractTests(unittest.TestCase):
    def test_schema_and_normalizer_carry_totals(self):
        self.assertEqual(PROMPT_VERSION, 'gemini-document-scanner-v4')
        self.assertIn('totals', response_schema()['required'])
        raw = _scan([CANVA_BILL, {'label': 'x', 'stated_total': '', 'line_amounts': ['1']}, 'junk'])
        scan = normalize_scan(raw, 1)
        self.assertEqual(scan['totals'], [CANVA_BILL])
        self.assertEqual(normalize_scan({**raw, 'totals': None}, 1)['totals'], [])


if __name__ == '__main__':
    unittest.main()
