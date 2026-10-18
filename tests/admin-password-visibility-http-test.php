<?php
declare(strict_types=1);

$url = rtrim((string) (getenv('AICS_ADMIN_BASE_URL') ?: 'http://localhost/AICS/AICS/ADMIN'), '/') . '/login.php';
$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
$body = file_get_contents($url, false, $context);
$headers = $http_response_header ?? [];

if (!is_string($body) || !str_contains((string) ($headers[0] ?? ''), '200')) {
    throw new RuntimeException('Admin login page did not load over HTTP.');
}

$csp = '';
foreach ($headers as $header) {
    if (stripos($header, 'Content-Security-Policy:') === 0) {
        $csp = trim(substr($header, strlen('Content-Security-Policy:')));
        break;
    }
}

if (!preg_match("/script-src[^;]*'self'/", $csp)) {
    throw new RuntimeException('Admin login does not allow same-origin scripts.');
}

preg_match_all('/<script\b([^>]*)>/i', $body, $matches);
if ($matches[1] === []) {
    throw new RuntimeException('Admin login has no script to control password visibility.');
}

foreach ($matches[1] as $attributes) {
    if (!preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attributes, $source)) {
        throw new RuntimeException('Admin login includes inline JavaScript blocked by its Content Security Policy.');
    }

    $scriptUrl = rtrim(dirname($url), '/') . '/' . ltrim($source[1], '/');
    $scriptBody = file_get_contents($scriptUrl, false, $context);
    $scriptHeaders = $http_response_header ?? [];
    if (!is_string($scriptBody) || !str_contains((string) ($scriptHeaders[0] ?? ''), '200')) {
        throw new RuntimeException('Admin login script could not be loaded: ' . $source[1]);
    }
}

echo "Admin login password visibility script is loadable under its CSP.\n";
