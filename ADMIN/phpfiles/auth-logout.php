<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';

admin_set_security_headers(false);
admin_start_session();

$actorId = (int) ($_SESSION['aics_account_id'] ?? 0);

if ($actorId > 0) {
    try {
        $connection = aics_db_connect();
        admin_ensure_schema($connection);
        admin_log_event($connection, 'logout', 'info', 'Admin user logged out.', $actorId, $actorId);
        $connection->close();
    } catch (Throwable) {
        // Best effort only.
    }
}

admin_destroy_session();
admin_redirect('../login.php', ['auth' => 'logged_out']);
