<?php
declare(strict_types=1);

require_once __DIR__ . '/php_files/pswdo-common.php';

pswdo_start_session();

if (isset($_SESSION['aics_account_id']) && (string) ($_SESSION['aics_role_code'] ?? '') === 'pswdo') {
    header('Location: dashboard.php?page=overview', true, 302);
    exit;
}

header('Location: login.php', true, 302);
exit;
