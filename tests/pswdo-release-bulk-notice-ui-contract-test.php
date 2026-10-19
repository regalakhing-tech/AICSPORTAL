<?php
declare(strict_types=1);

function release_notice_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/release-scheduling.php');
$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
release_notice_assert(is_string($page), 'Release Scheduling page could not be read.');
release_notice_assert(is_string($script), 'PSWDO page script could not be read.');
release_notice_assert(str_contains($page, 'id="open-bulk-schedule-btn"'), 'The Schedule for All action must remain available.');
release_notice_assert(str_contains($page, 'class="btn-solid secondary js-open-mass-sms-modal"'), 'The bulk notice action is missing.');
release_notice_assert(str_contains($page, 'Notify Scheduled Applicants'), 'The bulk notice action needs the clear user-facing label.');
release_notice_assert(str_contains($page, 'data-ready-count="<?= $massNoticeTargetCount ?>"'), 'The notice action must use the eligible-recipient count.');
release_notice_assert(str_contains($page, 'data-notice-ready="<?= $massNoticeTargetCount > 0 ? \'1\' : \'0\' ?>"'), 'The notice action must expose whether recipients are ready.');
release_notice_assert(str_contains($page, 'aria-disabled="<?= $massNoticeTargetCount > 0 ? \'false\' : \'true\' ?>"'), 'The notice action needs an accessible unavailable state.');
release_notice_assert(!str_contains($page, '$massNoticeTargetCount === 0 ? \'disabled'), 'The notice action must remain clickable so it can explain what is missing.');
release_notice_assert(str_contains($script, 'Schedule applicants with valid contact numbers before sending notices.'), 'Unavailable notice clicks must explain the required setup.');
release_notice_assert(!str_contains($page, 'Mass Notice + MSWDO Simulation'), 'Prototype terminology must not appear in the restored action.');
release_notice_assert(str_contains($page, "if (\$itemHasSchedule && \$itemReleaseStatus !== 'released')"), 'Every scheduled case must expose the Release action.');
release_notice_assert(str_contains($page, 'class="btn-solid release-complete-btn js-open-mark-released"'), 'The Release action must open the completion popup.');
release_notice_assert(str_contains($page, 'id="mark-released-modal"'), 'The Release completion popup is missing.');
release_notice_assert(str_contains($page, 'id="mark-released-amount"'), 'The Release completion popup keeps an amount field for schedules saved without one.');

echo "PSWDO release bulk notice UI contract test passed.\n";
