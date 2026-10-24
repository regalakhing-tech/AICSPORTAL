<?php
declare(strict_types=1);

// Release confirmation and notices never ask for or display cash amounts.
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function amount_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$item = ['beneficiary_name' => 'Maria Santos', 'reference_no' => 'AICS-2026-123456', 'scheduled_date_label' => 'October 5, 2026', 'scheduled_time_label' => '9:00 AM', 'venue' => 'PSWDO Office', 'amount_given' => 5000.0];
amount_assert(str_contains(pswdo_build_release_schedule_sms_message($item), "Lugar: PSWDO Office\nPakidating") && !str_contains(pswdo_build_release_schedule_sms_message($item), '5,000'), 'The release SMS states an amount.');
amount_assert(pswdo_apply_release_schedule_sms_tokens("Lugar: {venue}\n{amount_line}Maraming salamat po.", $item) === "Lugar: PSWDO Office\nMaraming salamat po.", 'An older message still sends an amount placeholder.');

$page = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/release-scheduling.php');
amount_assert(preg_match('~<div[^>]+id="mark-released-modal".*?(?=<!-- Reschedule)~s', $page, $matches) === 1, 'The release confirmation dialog is missing.');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($matches[0]);
libxml_clear_errors();
$xpath = new DOMXPath($dom);
amount_assert($xpath->query('//input[not(@type="hidden")]')->length === 0, 'The release confirmation still asks for data entry.');
amount_assert($xpath->query('//form[@method="post" and @action="workflow-action.php"]')->length === 1, 'Confirmation must submit the release action.');
amount_assert($xpath->query('//input[@name="release_status" and @value="released"]')->length === 1, 'Confirmation must mark the assistance released.');
amount_assert($xpath->query('//input[@name="csrf_token"]')->length === 1, 'Release confirmation must keep its CSRF protection.');
amount_assert($xpath->query('//button[@type="submit"]')->length === 1 && $xpath->query('//button[@type="button"]')->length === 1, 'Confirmation must offer Confirm Release and Cancel.');
echo "PSWDO release amount removal checks passed.\n";
