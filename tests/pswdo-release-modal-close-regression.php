<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

function release_modal_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

release_modal_assert(
    function_exists('pswdo_should_reopen_release_schedule_modal'),
    'The release redirect policy is missing.'
);
release_modal_assert(
    pswdo_should_reopen_release_schedule_modal('save_release_schedule') === false,
    'Save Schedule & Notify must return to the queue with the modal closed.'
);
release_modal_assert(
    pswdo_should_reopen_release_schedule_modal('update_release_status') === true,
    'Existing non-save release actions must keep their current reopen behavior.'
);

echo "PSWDO release modal close regression passed.\n";
