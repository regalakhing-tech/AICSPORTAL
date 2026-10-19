<?php
declare(strict_types=1);

// The household gives one estimated monthly income for the whole family (all
// members combined), not one per member. It is saved with the request and
// shown to PSWDO and on the printed GIS.
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
family_income_assert(aics_family_total_income_from_post(['family_members_not_applicable' => '1']) === null, 'No household: no total.');
family_income_assert(str_contains($refused([]), 'total estimated monthly income'), 'A household needs its total income.');
family_income_assert(str_contains($refused(['family_total_income' => '999']), 'total estimated monthly income'), 'Only listed income ranges are accepted.');

$root = __DIR__ . '/..';
$submit = (string) file_get_contents($root . '/Applicants/php_files/submit-request.php');
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
$save = (string) file_get_contents($root . '/Applicants/php_files/save-request.php');
$css = (string) file_get_contents($root . '/Applicants/css files/applicant-pages.css');

// Form: no per-member income; one total inside the household controls.
family_income_assert(!str_contains($submit, 'family_estimated_income[]') && !str_contains($js, '"family_estimated_income[]"'), 'Household members no longer have their own income field.');
$entry = substr($submit, (int) strpos($submit, '<div id="family-entry-controls">'), 12000);
family_income_assert(str_contains($entry, 'name="family_total_income"') && str_contains($entry, 'Total Estimated Monthly Family Income'), 'One total income field for the household.');
family_income_assert(str_contains($entry, 'Enter one total only'), 'The label explains it is the combined income of all members.');
family_income_assert(str_contains($js, 'index += 5') && !str_contains($js, "'Educational Attainment', 'Estimated Monthly Income'"), 'Household rows have five fields.');
family_income_assert(str_contains($css, 'grid-template-columns: 1.45fr 0.55fr 1.05fr 1.1fr 1.2fr 0.72fr;'), 'The household grid has one column fewer.');
family_income_assert(str_contains($js, 'assignValue("family_total_income", values.family_total_income);'), 'Editing a request restores the total.');
family_income_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/draft-lib.php'), "'family_total_income',"), 'Drafts keep the total.');

// Saving.
family_income_assert(str_contains($save, "'family_total_income' => aics_family_total_income_from_post(\$post)"), 'Submissions validate the total.');
family_income_assert(str_contains($save, 'family_total_income = ?'), 'Submissions save the total.');
family_income_assert(!str_contains($save, 'Select a valid estimated monthly income for each household member.'), 'Members are no longer asked for their own income.');
family_income_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/auth-common.php'), 'ADD COLUMN IF NOT EXISTS family_total_income VARCHAR(40)'), 'Older databases get the column.');
family_income_assert(str_contains((string) file_get_contents($root . '/database/AICSdb.sql'), '`family_total_income` VARCHAR(40)'), 'New databases have the column.');

// PSWDO and printouts.
family_income_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/request-management.php'), 'Total Estimated Monthly Family Income'), 'PSWDO sees the household total.');
family_income_assert(str_contains((string) file_get_contents($root . '/Pswdo/php_files/print-gis.php'), 'Total Estimated Monthly Family Income'), 'The printed GIS shows the household total.');
family_income_assert(str_contains((string) file_get_contents($root . '/Applicants/php_files/request-export.php'), 'Total Estimated Monthly Family Income'), 'The applicant\'s copy shows the household total.');
echo "Family total income checks passed.\n";
