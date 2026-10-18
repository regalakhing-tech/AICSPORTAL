<?php
declare(strict_types=1);

$routes = [
    'overview' => 'overview-dashboard.php',
    'users' => 'user-management.php',
    'logs' => 'security-logs.php',
    'backup' => 'backup-maintenance.php',
];

$page = isset($_GET['page']) ? (string) $_GET['page'] : 'overview';
if (!array_key_exists($page, $routes)) {
    $page = 'overview';
}

$target = 'phpfiles/' . $routes[$page];
$query = $_GET;
unset($query['page']);
if (count($query) > 0) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit;
