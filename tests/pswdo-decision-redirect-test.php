<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function decision_redirect_assert_same(int $expected, int $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$case}: expected reopen ID {$expected}, got {$actual}.");
    }
}

foreach (['view_request', 'reject_request', 'mark_invalid', 'mark_invalid_with_sms'] as $action) {
    decision_redirect_assert_same(0, pswdo_request_reopen_id_after_action(42, $action, 'success'), "Successful {$action}");
    decision_redirect_assert_same(42, pswdo_request_reopen_id_after_action(42, $action, 'error'), "Failed {$action}");
}

decision_redirect_assert_same(42, pswdo_request_reopen_id_after_action(42, 'document_check', 'success'), 'Document review');
decision_redirect_assert_same(0, pswdo_request_reopen_id_after_action(0, 'document_check', 'success'), 'Missing request ID');

echo "PSWDO decision redirect test passed.\n";
