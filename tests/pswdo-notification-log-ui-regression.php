<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function notification_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$partial = __DIR__ . '/../Pswdo/php_files/_notification-log-list.php';
notification_ui_assert(is_file($partial), 'The compact notification activity list renderer is missing.');

$notificationLogs = [
    [
        'reference_no' => 'AICS-2026-TEST01',
        'beneficiary_name' => 'Sample Beneficiary',
        'stage' => 'Release Schedule SMS',
        'message' => 'A deliberately long notification message that must be available in full details.',
        'sent_at_label' => 'September 17, 2026 10:08 PM',
        'sent_by' => 'PSWDO Personnel',
    ],
    [
        'reference_no' => 'AICS-2026-TEST02',
        'beneficiary_name' => '<script>alert("unsafe")</script>',
        'stage' => 'MSWDO Notification: Simulated',
        'message' => 'Municipality coordination preview.',
        'sent_at_label' => 'September 17, 2026 10:09 PM',
        'sent_by' => '',
    ],
];

for ($index = 3; $index <= 7; $index++) {
    $notificationLogs[] = [
        'reference_no' => 'AICS-2026-TEST0' . $index,
        'beneficiary_name' => 'Sample Beneficiary ' . $index,
        'stage' => 'For Screening SMS',
        'message' => 'Notification message ' . $index . '.',
        'sent_at_label' => 'September 17, 2026 10:0' . $index . ' PM',
        'sent_by' => 'PSWDO Personnel',
    ];
}
$allNotificationLogs = $notificationLogs;

ob_start();
require $partial;
$html = (string) ob_get_clean();

notification_ui_assert(substr_count($html, 'class="notification-activity-item') === 7, 'Every notification must render as one compact activity item.');
notification_ui_assert(str_contains($html, '<details'), 'Notification items must provide native expandable details.');
notification_ui_assert(str_contains($html, 'class="notification-message-preview"'), 'Each item needs a compact one-line message preview.');
notification_ui_assert(str_contains($html, 'class="notification-message-full"'), 'The complete message must remain available when expanded.');
notification_ui_assert(str_contains($html, 'View details'), 'Each item needs a clear details affordance.');
notification_ui_assert(str_contains($html, 'stage-scheduled'), 'Schedule notifications need their distinct visual status.');
notification_ui_assert(str_contains($html, 'notification-icon-mswdo'), 'MSWDO notifications need their distinct icon treatment.');
notification_ui_assert(!str_contains($html, '<script>alert'), 'Notification content must be HTML escaped.');
notification_ui_assert(!str_contains($html, '<table'), 'The oversized notification table must not return.');
notification_ui_assert(substr_count($html, 'data-notification-overflow') === 2, 'Only notifications after the latest five must start hidden.');
notification_ui_assert(substr_count($html, 'data-notification-overflow hidden') === 2, 'Remaining notifications must be hidden initially.');
notification_ui_assert(str_contains($html, 'data-notification-show-more'), 'A See More control is required when more than five notifications exist.');
notification_ui_assert(str_contains($html, 'See More Notifications'), 'The expansion control needs a clear label.');
notification_ui_assert(str_contains($html, '2 more'), 'The expansion control must state how many notifications remain.');

$document = new DOMDocument();
@$document->loadHTML($html);
$renderedItems = (new DOMXPath($document))->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' notification-activity-item ')]");
notification_ui_assert($renderedItems !== false && $renderedItems->length === 7, 'The rendered notification order could not be inspected.');
foreach ($renderedItems as $index => $renderedItem) {
    notification_ui_assert(
        $renderedItem instanceof DOMElement && $renderedItem->hasAttribute('hidden') === ($index >= 5),
        'Only notification items six and later may start hidden.'
    );
}

$notificationLogs = array_slice($notificationLogs, 0, 5);
ob_start();
require $partial;
$fiveItemHtml = (string) ob_get_clean();
notification_ui_assert(!str_contains($fiveItemHtml, 'data-notification-overflow'), 'Five notifications must all remain visible.');
notification_ui_assert(!str_contains($fiveItemHtml, 'data-notification-show-more'), 'See More must not render when there are no remaining notifications.');

$notificationLogs = array_slice($allNotificationLogs, 0, 6);
ob_start();
require $partial;
$sixItemHtml = (string) ob_get_clean();
notification_ui_assert(substr_count($sixItemHtml, 'data-notification-overflow hidden') === 1, 'Six notifications must hide exactly one item initially.');
notification_ui_assert(str_contains($sixItemHtml, '1 more'), 'Six notifications must report one remaining item.');

$notificationLogs = [];
ob_start();
require $partial;
$emptyHtml = (string) ob_get_clean();
notification_ui_assert(!str_contains($emptyHtml, 'data-notification-show-more'), 'An empty list must not render See More.');

echo "PSWDO notification log UI regression passed.\n";
