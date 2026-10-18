<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function flagged_row_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

flagged_row_assert(
    pswdo_request_has_flagged_inconsistency([
        ['uploaded' => true, 'ai_status' => 'completed'],
        ['uploaded' => true, 'ai_status' => 'flagged_inconsistency'],
    ]),
    'A request with one inconsistent document must receive the red warning row state.'
);
flagged_row_assert(
    !pswdo_request_has_flagged_inconsistency([
        ['uploaded' => true, 'ai_status' => 'needs_staff_review'],
        ['uploaded' => false, 'ai_status' => 'flagged_inconsistency'],
    ]),
    'Only an uploaded document with a flagged inconsistency may activate the red row state.'
);

echo "PSWDO flagged request row test passed.\n";
