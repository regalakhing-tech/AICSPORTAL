<?php
declare(strict_types=1);

// The household section asks only one thing: the estimated monthly income of
// the whole family (all members combined), always required. Household members
// are no longer listed. Older requests keep their member lists.
require_once __DIR__ . '/../Applicants/php_files/auth-common.php';

function family_income_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$refused = static function (array $post): string {
    try {
        aics_family_total_income_from_post($post);
    } catch (RuntimeException $exception) {
        return $exception->getMessage();
    }
    return '';
};
$income = aics_income_options()[2];
family_income_assert(aics_family_total_income_from_post(['family_total_income' => $income]) === $income, 'The household total is saved.');
family_income_assert(str_contains($refused(['family_members_not_applicable' => '1']), 'total estimated monthly income'), 'The total is always required.');
family_income_assert(str_contains($refused([]), 'total estimated monthly income'), 'A household needs its total income.');
family_income_assert(str_contains($refused(['family_total_income' => '999']), 'total estimated monthly income'), 'Only listed income ranges are accepted.');

$root = __DIR__ . '/..';
$submit = (string) file_get_contents($root . '/Applicants/php_files/submit-request.php');
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
$save = (string) file_get_contents($root . '/Applicants/php_files/save-request.php');
$css = (string) file_get_contents($root . '/Applicants/css files/applicant-pages.css');

// Form: only the household total; no member table and no "Not Applicable".
family_income_assert(!str_contains($submit, 'family_name[]') && !str_contains($submit, 'family-add-row-btn') && !str_contains($submit, 'family_members_not_applicable'), 'Household members are no longer listed.');
$household = substr($submit, (int) strpos($submit, '<span>Household Income'), 3000);
family_income_assert(str_contains($household, 'name="family_total_income" id="family-total-income" required') && str_contains($household, 'Total Estimated Monthly Family Income'), 'One required total income field for the household.');
family_income_assert(str_contains($household, 'Enter one total only'), 'The label explains it is the combined income of the whole family.');
family_income_assert(!str_contains($js, 'validateFamilyComposition()') && !str_contains($submit, 'id="modal-confirm-family"'), 'No member check or member summary remains.');
family_income_assert(str_contains($js, 'assignValue("family_total_income", values.family_total_income);'), 'Editing a request restores the total.');
family_income_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/draft-lib.php'), "'family_total_income',"), 'Drafts keep the total.');

// Saving.
family_income_assert(str_contains($save, "'family_total_income' => aics_family_total_income_from_post(\$post)"), 'Submissions validate the total.');
family_income_assert(str_contains($save, 'family_total_income = ?'), 'Submissions save the total.');
family_income_assert(!str_contains($save, 'Select a valid estimated monthly income for each household member.') && !str_contains($save, 'Family Composition is required'), 'Members are no longer required.');
family_income_assert(str_contains($save, 'family_composition_json = COALESCE(?, family_composition_json)'), 'Editing an older request keeps its member list.');
family_income_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/auth-common.php'), 'ADD COLUMN IF NOT EXISTS family_total_income VARCHAR(40)'), 'Older databases get the column.');
family_income_assert(str_contains((string) file_get_contents($root . '/database/AICSdb.sql'), '`family_total_income` VARCHAR(40)'), 'New databases have the column.');

// PSWDO and printouts.
family_income_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/request-management.php'), 'Total Estimated Monthly Family Income'), 'PSWDO sees the household total.');
family_income_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/print-gis.php'), 'Total Estimated Monthly Family Income'), 'The printed GIS shows the household total.');
family_income_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/request-export.php'), 'Total Estimated Monthly Family Income'), 'The applicant\'s copy shows the household total.');
echo "Family total income checks passed.\n";
