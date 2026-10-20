<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-common.php';

aics_start_session();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
}

session_destroy();

// Remove documents and pages this browser kept while signed in (shared computers).
header('Clear-Site-Data: "cache"');

aics_redirect_with_message('../login.php', 'logged_out');
