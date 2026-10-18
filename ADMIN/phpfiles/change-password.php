<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-common.php';

admin_set_security_headers();
admin_require_login();

$paths = admin_paths();
$accountId = (int) ($_SESSION['aics_account_id'] ?? 0);
$displayName = admin_current_display_name();
$initials = admin_initials($displayName);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        admin_require_valid_csrf();
    } catch (RuntimeException $exception) {
        $error = 'Invalid request. Please reload the page and try again.';
    }

    if ($error === '') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $strengthError = admin_validate_password_strength($newPassword);

        if ($newPassword !== $confirmPassword) {
            $error = 'The new password and confirmation do not match.';
        } elseif ($strengthError !== null) {
            $error = $strengthError;
        } elseif ($currentPassword === '') {
            $error = 'Please enter your current password.';
        } else {
            try {
                $connection = aics_db_connect();

                $stmt = $connection->prepare('SELECT password_hash FROM aics_accounts WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $accountId);
                $stmt->execute();
                $row = $stmt->get_result()?->fetch_assoc();
                $stmt->close();

                if (!is_array($row) || !password_verify($currentPassword, (string) ($row['password_hash'] ?? ''))) {
                    $error = 'Your current password is incorrect.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $update = $connection->prepare(
                        'UPDATE aics_accounts SET password_hash = ?, force_password_change = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                    );
                    $update->bind_param('si', $newHash, $accountId);
                    $update->execute();
                    $update->close();

                    admin_log_event(
                        $connection,
                        'password_changed',
                        'success',
                        'Admin password changed successfully.',
                        $accountId,
                        $accountId
                    );

                    $connection->close();

                    $success = 'Password changed successfully. Redirecting to dashboard...';
                    // Redirect after a short flash by using meta refresh below.
                }
            } catch (Throwable $exception) {
                $error = 'Could not update the password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Change Password &mdash; AICS Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= admin_h($paths['css_url']) ?>">
  <?php if ($success !== ''): ?>
    <meta http-equiv="refresh" content="2;url=<?= admin_h($paths['dashboard_url']) ?>">
  <?php endif; ?>
  <style>
    body {
      font-family: 'Inter', sans-serif;
      margin: 0;
      min-height: 100vh;
      background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 50%, #0284c7 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .pw-card {
      background: #ffffff;
      width: 100%;
      max-width: 480px;
      border-radius: 14px;
      box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18);
      overflow: hidden;
    }
    .pw-card header {
      padding: 22px 28px 18px;
      background: linear-gradient(135deg, #1d4ed8, #0284c7);
      color: #ffffff;
    }
    .pw-card header h1 { margin: 0; font-size: 18px; font-weight: 800; }
    .pw-card header p { margin: 4px 0 0; font-size: 12.5px; opacity: 0.92; }
    .pw-card .body { padding: 22px 28px 26px; }
    .pw-card label { display: block; margin-bottom: 14px; font-size: 12.5px; font-weight: 600; color: #334155; }
    .pw-card input[type="password"] {
      width: 100%;
      padding: 10px 12px;
      margin-top: 4px;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      font-size: 14px;
      font-family: inherit;
    }
    .pw-card input[type="password"]:focus { outline: 2px solid #1d4ed8; border-color: #1d4ed8; }
    .pw-card small { color: #64748b; font-size: 11.5px; }
    .pw-card .actions {
      display: flex;
      gap: 10px;
      margin-top: 16px;
    }
    .pw-card button {
      flex: 1;
      padding: 11px 18px;
      border: none;
      border-radius: 8px;
      font-family: inherit;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
    }
    .pw-card button.primary { background: #1d4ed8; color: #ffffff; }
    .pw-card button.primary:hover { background: #1e40af; }
    .pw-alert {
      padding: 12px 14px;
      border-radius: 8px;
      margin-bottom: 14px;
      font-size: 12.5px;
      display: flex;
      gap: 8px;
      align-items: flex-start;
    }
    .pw-alert.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .pw-alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .pw-rules {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 10px 14px;
      margin-bottom: 16px;
      font-size: 11.5px;
      color: #475569;
    }
    .pw-rules strong { color: #0f172a; }
    .pw-rules ul { margin: 4px 0 0 18px; padding: 0; }
  </style>
</head>
<body>
  <div class="pw-card">
    <header>
      <h1><i class="fas fa-key"></i> Change Your Password</h1>
      <p>Welcome, <?= admin_h($displayName) ?>. For security, please set a new password before continuing.</p>
    </header>
    <div class="body">
      <?php if ($error !== ''): ?>
        <div class="pw-alert error">
          <i class="fas fa-triangle-exclamation" style="font-size:14px;margin-top:1px;"></i>
          <span><?= admin_h($error) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($success !== ''): ?>
        <div class="pw-alert success">
          <i class="fas fa-circle-check" style="font-size:14px;margin-top:1px;"></i>
          <span><?= admin_h($success) ?></span>
        </div>
      <?php endif; ?>

      <div class="pw-rules">
        <strong>Password requirements:</strong>
        <ul>
          <li>At least 8 characters</li>
          <li>Includes uppercase &amp; lowercase letters</li>
          <li>Includes at least one number</li>
          <li>Includes at least one special character</li>
        </ul>
      </div>

      <form method="post" action="">
        <?= admin_csrf_input() ?>
        <label>Current password
          <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>New password
          <input type="password" name="new_password" required autocomplete="new-password">
        </label>
        <label>Confirm new password
          <input type="password" name="confirm_password" required autocomplete="new-password">
        </label>
        <div class="actions">
          <button type="submit" class="primary"><i class="fas fa-shield-halved"></i> Change Password</button>
        </div>
      </form>
    </div>
  </div>
</body>
</html>
