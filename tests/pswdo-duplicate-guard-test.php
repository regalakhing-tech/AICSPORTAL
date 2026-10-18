<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' failed. Expected: ' . var_export($expected, true) . '; Actual: ' . var_export($actual, true)
        );
    }
}

function assert_true(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label . ' failed.');
    }
}

$localCandidates = pswdo_duplicate_contact_candidates('0917-123-4567');
assert_true(in_array('09171234567', $localCandidates, true), 'Local number normalization includes 0917 format');
assert_true(in_array('639171234567', $localCandidates, true), 'Local number normalization includes 639 format');

$intlCandidates = pswdo_duplicate_contact_candidates('+63 917 123 4567');
assert_true(in_array('639171234567', $intlCandidates, true), 'International number keeps 639 format');
assert_true(in_array('09171234567', $intlCandidates, true), 'International number converts to 0917 format');

assert_same(false, pswdo_should_block_duplicate_approval(['duplicate_flag' => true]), 'Duplicate flag remains a PSWDO review warning');
assert_same(false, pswdo_should_block_duplicate_approval(['duplicate_flag' => false]), 'False duplicate flag does not block approval');
assert_same(false, pswdo_should_block_duplicate_approval([]), 'Missing duplicate flag does not block approval');

$messageWithRefs = pswdo_build_duplicate_block_message([
    ' AICS-2026-AAAAAA ',
    '',
    'AICS-2026-BBBBBB',
    'AICS-2026-AAAAAA',
]);
assert_true(str_contains($messageWithRefs, 'AICS-2026-AAAAAA'), 'Duplicate message includes first reference');
assert_true(str_contains($messageWithRefs, 'AICS-2026-BBBBBB'), 'Duplicate message includes second reference');
assert_true(str_contains($messageWithRefs, 'Please review'), 'Duplicate message includes review wording');

$messageWithoutRefs = pswdo_build_duplicate_block_message([]);
assert_true(!str_contains($messageWithoutRefs, 'Matching request(s):'), 'No-reference message omits matching list prefix');

echo "All PSWDO duplicate guard tests passed.\n";
