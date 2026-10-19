<?php
declare(strict_types=1);

// The beneficiary category "Family Head" is now "Solo Parent" (solo_parent),
// the code PSWDO filters, release groups and labels already use.
function solo_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$submit = (string) file_get_contents($root . '/Applicants/php_files/submit-request.php');
$save = (string) file_get_contents($root . '/Applicants/php_files/save-request.php');
$gis = (string) file_get_contents($root . '/Pswdo/php_files/print-gis.php');
$blank = (string) file_get_contents($root . '/Applicants/blank-gis.php');

solo_assert(str_contains($submit, '<input type="radio" name="client_category" value="solo_parent">') && str_contains($submit, '</i> Solo Parent</span>'), 'The form offers Solo Parent.');
solo_assert(!str_contains($submit, 'value="family_head"') && !str_contains($submit, 'Family Head'), 'The form no longer offers Family Head.');
solo_assert(!str_contains($submit, 'e.g. Solo parent,'), 'Solo parent is no longer an "Others" example.');
solo_assert((bool) preg_match("/\\\$allowedBeneficiaryCategories = \\[[^\\]]*'solo_parent'/", $save), 'Submissions accept solo_parent.');
solo_assert(str_contains($gis, "\$catChecked('solo_parent')") && str_contains($gis, ' Solo Parent</div>') && !str_contains($gis, ' Family Head</div>'), 'The printed GIS shows Solo Parent.');
solo_assert(str_contains($blank, ' Solo Parent</div>') && !str_contains($blank, ' Family Head</div>'), 'The blank GIS shows Solo Parent.');
echo "Solo Parent category checks passed.\n";
