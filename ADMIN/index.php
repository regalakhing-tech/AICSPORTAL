<?php
declare(strict_types=1);

require_once __DIR__ . '/phpfiles/admin-common.php';

admin_set_security_headers(false);
admin_start_session();

if (admin_is_logged_in()) {
    admin_redirect('dashboard.php', ['page' => 'overview']);
}

admin_redirect('login.php');
