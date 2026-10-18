<?php
declare(strict_types=1);

$routes = [
    'overview' => 'overview-dashboard.php',
    'release' => 'release-scheduling.php',
    'requests' => 'request-management.php',
    'prescreening' => 'prescreening-queue.php',
    'map' => 'map-analytics.php',
    'reports' => 'reports.php',
    'report_for_release' => 'report-for-release.php',
    'report_released' => 'report-released.php',
    'all_releases' => 'municipal-releases.php',
];

$page = isset($_GET['page']) ? (string) $_GET['page'] : 'overview';
if ($page === 'workflow') {
    $page = 'overview';
}
if (!array_key_exists($page, $routes)) {
    $page = 'overview';
}

$target = 'php_files/' . $routes[$page];
$query = $_GET;
unset($query['page']);
if (count($query) > 0) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit;
