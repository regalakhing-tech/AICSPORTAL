<?php
declare(strict_types=1);
session_start();
$_SESSION = ['aics_account_id' => 42, 'aics_role_code' => 'applicat'];
$_SERVER['SCRIPT_NAME'] = '/AICS/AICS/Applicants/php_files/documents.php';
ob_start();
require __DIR__ . '/../chatbot/applicant-widget.php';
$html = ob_get_clean();
foreach (['data-mode="applicant"', '/AICS/AICS/Applicants/php_files/chat-assistant.php', 'My documents', 'Release schedule', 'role="log"', $_SESSION['aics_applicant_chat_csrf']] as $expected) {
    if (!str_contains($html, $expected)) throw new RuntimeException('Missing widget behavior: ' . $expected);
}
foreach (['overview-dashboard', 'my-profile', 'submit-request', 'documents', 'application-status', 'cancel-request', 'notifications'] as $page) {
    if (!str_contains(file_get_contents(__DIR__ . '/../Applicants/php_files/' . $page . '.php'), '/../../chatbot/applicant-widget.php')) throw new RuntimeException('Widget missing from ' . $page);
}
$_SESSION = [];
ob_start();
include __DIR__ . '/../chatbot/applicant-widget.php';
if (trim(ob_get_clean()) !== '') throw new RuntimeException('Anonymous user received applicant widget');
session_destroy();
echo "Applicant widget rendering and page integration passed.\n";
