<?php
declare(strict_types=1);

require_once __DIR__ . '/php_files/auth-common.php';
require_once __DIR__ . '/php_files/application-access.php';

$routes = [
    'overview' => 'overview-dashboard.php',
    'profile' => 'my-profile.php',
    'submit' => 'submit-request.php',
    'documents' => 'documents.php',
    'status' => 'application-status.php',
    'cancel-request' => 'cancel-request.php',
    'notifications' => 'notifications.php',
];

$page = isset($_GET['page']) ? (string) $_GET['page'] : 'overview';
if (!array_key_exists($page, $routes)) {
    $page = 'overview';
}

if ($page === 'submit') {
    aics_require_applicat_login();
    aics_require_applicant_application_access('php_files/my-profile.php');
}

header('Location: php_files/' . $routes[$page], true, 302);
exit;
