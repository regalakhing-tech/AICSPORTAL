<?php
declare(strict_types=1);

require_once __DIR__ . '/aics-chatbot-lib.php';

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedAddresses = ['127.0.0.1', '::1'];
if (!in_array($remoteAddress, $allowedAddresses, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Local access only.';
    exit;
}

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

if (!isset($_SESSION['aics_local_setup_token'])) {
    $_SESSION['aics_local_setup_token'] = bin2hex(random_bytes(32));
}

$success = false;
$error = '';
$keyPath = aics_chatbot_local_key_path();
$configured = aics_chatbot_resolve_api_key('', $keyPath) !== '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    $apiKey = is_string($_POST['api_key'] ?? null) ? trim($_POST['api_key']) : '';

    if (!hash_equals($_SESSION['aics_local_setup_token'], $token)) {
        $error = 'The setup session expired. Refresh the page and try again.';
    } elseif (preg_match('/^gsk_[A-Za-z0-9]+$/', $apiKey) !== 1) {
        $error = 'Enter a valid Groq API key.';
    } else {
        try {
            $directory = dirname($keyPath);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Private directory could not be created.');
            }

            $temporaryPath = tempnam($directory, 'groq-');
            if ($temporaryPath === false) {
                throw new RuntimeException('Temporary key file could not be created.');
            }
            if (file_put_contents($temporaryPath, $apiKey . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Key file could not be written.');
            }
            @chmod($temporaryPath, 0600);
            if (is_file($keyPath) && !unlink($keyPath)) {
                throw new RuntimeException('Existing local key could not be replaced.');
            }
            if (!rename($temporaryPath, $keyPath)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Local key could not be activated.');
            }

            $apiKey = '';
            $configured = true;
            $success = true;
            $_SESSION['aics_local_setup_token'] = bin2hex(random_bytes(32));
        } catch (Throwable) {
            $error = 'The key could not be saved. Check that XAMPP has permission to write to C:\\xampp.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Local AICS Groq Setup</title>
    <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
    <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; color: #0f172a; background: #f0f9ff; font: 15px/1.55 Arial, sans-serif; }
        main { width: min(460px, 100%); padding: 30px; border: 1px solid #bae6fd; border-radius: 22px; background: #fff; box-shadow: 0 22px 60px rgba(3, 105, 161, .14); }
        .eyebrow { margin: 0 0 8px; color: #0284c7; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 0 0 10px; font-size: 25px; }
        p { color: #52637a; }
        label { display: block; margin: 22px 0 7px; font-weight: 700; }
        input { width: 100%; padding: 13px 14px; border: 1px solid #94a3b8; border-radius: 12px; font: inherit; }
        input:focus { outline: 3px solid rgba(14, 165, 233, .2); border-color: #0284c7; }
        button, a.button { display: inline-flex; justify-content: center; width: 100%; margin-top: 14px; padding: 13px 18px; border: 0; border-radius: 12px; color: #fff; background: #0284c7; font: 700 15px/1 Arial, sans-serif; text-decoration: none; cursor: pointer; }
        .notice { padding: 11px 13px; border-radius: 11px; font-size: 13px; }
        .success { color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error { color: #9f1239; background: #fff1f2; border: 1px solid #fecdd3; }
        .status { color: <?= $configured ? '#166534' : '#9f1239' ?>; font-weight: 700; }
        small { display: block; margin-top: 18px; color: #64748b; }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">Local XAMPP only</p>
    <h1>Connect AICS Guide to Groq</h1>
    <p>The key is stored outside the website folder and is never sent to browser JavaScript.</p>
    <p>Current status: <span class="status"><?= $configured ? 'Configured' : 'Not configured' ?></span></p>

    <?php if ($success): ?>
        <p class="notice success">Key saved. The AICS chatbot is ready for a live test.</p>
        <a class="button" href="../../landingpage.php#ask-aics">Open AICS Guide</a>
    <?php else: ?>
        <?php if ($error !== ''): ?>
            <p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="token" value="<?= htmlspecialchars($_SESSION['aics_local_setup_token'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="api-key">Groq API key</label>
            <input id="api-key" name="api_key" type="password" required autofocus spellcheck="false" autocomplete="off">
            <button type="submit">Save key locally</button>
        </form>
    <?php endif; ?>

    <small>For presentation use on this laptop only. Rotate the key after the presentation.</small>
</main>
</body>
</html>
