<?php
declare(strict_types=1);

// HTTP fixture mode may use only the explicit AICS_TEST_DB_* values. This
// mapping keeps credentials out of source while preventing an accidental
// fallback to the normal development database.
$aicsHttpTestMode = getenv('AICS_HTTP_TEST_MODE') === '1';
if (!defined('AICS_DB_HOST')) {
    define('AICS_DB_HOST', $aicsHttpTestMode ? (getenv('AICS_TEST_DB_HOST') ?: '') : (getenv('AICS_DB_HOST') ?: 'localhost'));
}

if (!defined('AICS_DB_USER')) {
    define('AICS_DB_USER', $aicsHttpTestMode ? (getenv('AICS_TEST_DB_USER') ?: '') : (getenv('AICS_DB_USER') ?: 'root'));
}

if (!defined('AICS_DB_PASS')) {
    define('AICS_DB_PASS', $aicsHttpTestMode ? (string) (getenv('AICS_TEST_DB_PASSWORD') ?: '') : (string) (getenv('AICS_DB_PASS') ?: ''));
}

if (!defined('AICS_DB_NAME')) {
    define('AICS_DB_NAME', $aicsHttpTestMode ? (getenv('AICS_TEST_DB_NAME') ?: '') : (getenv('AICS_DB_NAME') ?: 'AICSdb'));
}

if (!defined('AICS_DB_PORT')) {
    define('AICS_DB_PORT', (int) ($aicsHttpTestMode ? (getenv('AICS_TEST_DB_PORT') ?: 0) : (getenv('AICS_DB_PORT') ?: 3306)));
}

// Server-only PhilSMS configuration. The environment variable is preferred;
// under XAMPP the git-ignored config/secrets.local.php supplies the token
// shared by applicant OTP and PSWDO notices.
$aicsLocalSecrets = is_file(__DIR__ . '/secrets.local.php') ? (array) require __DIR__ . '/secrets.local.php' : [];
if (!defined('AICS_PHILSMS_API_TOKEN')) {
    define('AICS_PHILSMS_API_TOKEN', trim((string) (getenv('PHILSMS_API_TOKEN') ?: ($aicsLocalSecrets['PHILSMS_API_TOKEN'] ?? ''))));
}
if (!defined('AICS_PHILSMS_SENDER_ID')) {
    define('AICS_PHILSMS_SENDER_ID', trim((string) (getenv('PHILSMS_SENDER_ID') ?: 'PhilSMS')));
}
if (!defined('AICS_PHILSMS_TOKEN_SOURCE')) {
    $philsmsEnvironmentToken = getenv('PHILSMS_API_TOKEN');
    define(
        'AICS_PHILSMS_TOKEN_SOURCE',
        is_string($philsmsEnvironmentToken) && trim($philsmsEnvironmentToken) !== ''
            ? 'environment variable PHILSMS_API_TOKEN'
            : 'config/secrets.local.php'
    );
}

// MSWDO notices are deliberately simulation-only during the prototype phase.
// `live` is reserved for a future provider implementation; it never sends mail
// from this configuration file.
if (!defined('AICS_MSWDO_NOTIFICATION_MODE')) {
    define('AICS_MSWDO_NOTIFICATION_MODE', strtolower(trim((string) (getenv('AICS_MSWDO_NOTIFICATION_MODE') ?: 'simulate'))));
}

/**
 * Returns the bare PhilSMS token for use after the fixed `Bearer ` prefix is
 * added to the Authorization header. This tolerates common local .env/config
 * mistakes without ever returning the token to a browser or log.
 */
function aics_philsms_api_token(): string
{
    $metadata = aics_philsms_api_token_metadata();
    if ($metadata['token'] === '') {
        throw new RuntimeException(
            'PhilSMS is not configured. Set PHILSMS_API_TOKEN in the Apache environment and restart Apache.'
        );
    }

    return $metadata['token'];
}

/** @return array{token:string,source:string,length:int,whitespace_removed:bool,bearer_prefix_removed:bool,quotes_removed:bool} */
function aics_philsms_api_token_metadata(): array
{
    $rawToken = (string) AICS_PHILSMS_API_TOKEN;
    $token = trim($rawToken);
    $whitespaceRemoved = $token !== $rawToken;
    $hadBearerPrefix = preg_match('/^Bearer\s+/i', $token) === 1;
    $token = preg_replace('/^Bearer\s+/i', '', $token) ?? '';
    $token = trim($token);
    $quotesRemoved = false;
    if (strlen($token) >= 2 && (($token[0] === '"' && $token[-1] === '"') || ($token[0] === "'" && $token[-1] === "'"))) {
        $token = trim(substr($token, 1, -1));
        $quotesRemoved = true;
    }
    return [
        'token' => $token,
        'source' => AICS_PHILSMS_TOKEN_SOURCE,
        'length' => strlen($token),
        'whitespace_removed' => $whitespaceRemoved,
        'bearer_prefix_removed' => $hadBearerPrefix,
        'quotes_removed' => $quotesRemoved,
    ];
}

/**
 * @throws RuntimeException
 */
function aics_db_connect(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $testMode = getenv('AICS_HTTP_TEST_MODE') === '1';
    $hosts = array_values(array_unique(array_filter([
        $testMode ? '' : trim((string) getenv('AICS_DB_HOST')),
        trim(AICS_DB_HOST),
        'localhost',
        '127.0.0.1',
    ])));

    $testMode = getenv('AICS_HTTP_TEST_MODE') === '1';
    $databaseNames = array_values(array_unique(array_filter([
        $testMode ? '' : trim((string) getenv('AICS_DB_NAME')),
        trim(AICS_DB_NAME),
    ])));
    // The isolated Apache regression alias must never silently fall back to
    // the development database when its temporary database is unavailable.
    if (!$testMode) {
        $databaseNames = array_values(array_unique(array_merge($databaseNames, ['AICSdb', 'aicsdb'])));
    }
    if ($testMode && $databaseNames === []) {
        throw new RuntimeException('Isolated HTTP test database is not configured.');
    }

    $attemptErrors = [];

    foreach ($hosts as $host) {
        foreach ($databaseNames as $databaseName) {
            try {
                $connection = new mysqli(
                    $host,
                    AICS_DB_USER,
                    AICS_DB_PASS,
                    $databaseName,
                    AICS_DB_PORT
                );
                $connection->set_charset('utf8mb4');
                return $connection;
            } catch (mysqli_sql_exception $exception) {
                $attemptErrors[] = sprintf('%s/%s: %s', $host, $databaseName, $exception->getMessage());
            }
        }
    }

    $detail = $attemptErrors !== [] ? ' Tried ' . implode(' | ', $attemptErrors) : '';
    throw new RuntimeException('Database connection failed.' . $detail);
}
