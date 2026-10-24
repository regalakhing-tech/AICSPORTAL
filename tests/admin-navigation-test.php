<?php
declare(strict_types=1);

// Rendering Admin navigation must not require a live database or expose an AI tab.
putenv('AICS_HTTP_TEST_MODE=1');
putenv('AICS_TEST_DB_HOST=127.0.0.1');
putenv('AICS_TEST_DB_PORT=1');
putenv('AICS_TEST_DB_NAME=aics_http_regression');
putenv('AICS_TEST_DB_USER=root');
require_once __DIR__ . '/../ADMIN/phpfiles/admin-layout.php';

function navigation_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$paths = ['dashboard_url' => '/ADMIN/dashboard.php', 'css_url' => '/ADMIN/cssfiles/admin-pages.css', 'js_url' => '/ADMIN/jsfiles/admin-pages.js', 'logo_url' => '/asset/logo.png', 'logout_url' => '/ADMIN/phpfiles/auth-logout.php'];
$sections = ['overview' => 'Overview', 'logs' => 'Security Logs', 'users' => 'User Management', 'backup' => 'Backup & Maintenance'];
foreach ($sections as $activePage => $label) {
    ob_start();
    admin_render_page_start('Admin navigation test', $activePage, 'Test Admin', 'TA', $paths);
    admin_render_page_end($paths);
    $html = (string) ob_get_clean();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//nav[@aria-label="Administration"]//a');
    $actual = [];
    foreach ($links as $link) $actual[] = trim($link->textContent);
    navigation_assert($actual === array_values($sections), 'Admin must show only Overview, Security Logs, User Management, and Backup & Maintenance.');
    $active = $xpath->query('//nav[@aria-label="Administration"]//a[@aria-current="page"]');
    navigation_assert($active->length === 1 && trim($active->item(0)->textContent) === $label, 'The current Admin section must stay highlighted.');
    navigation_assert($active->item(0)->getAttribute('href') === '/ADMIN/dashboard.php?page=' . $activePage, 'The active section must keep its correct route.');
}
echo "Admin navigation checks passed.\n";
