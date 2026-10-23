<?php
declare(strict_types=1);

require_once __DIR__ . '/aics-chatbot-lib.php';

/**
 * Server-side request limits for the AICS Guide. Counts live in a locked JSON
 * file on the private volume, so clearing cookies or signing in again does not
 * reset them. Keys are stored as hashes, never as raw addresses or account IDs.
 */
function aics_chatbot_rate_limit_dir(): string
{
    $private = trim((string) getenv('AICS_PRIVATE_ROOT'));
    $root = $private !== '' ? $private : 'C:\\xampp\\aics_private';
    return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'chatbot-rate';
}

/**
 * Record one request against every key, or none when any key is at its limit.
 * Returns false when the request must be refused, including when the count
 * file cannot be used (fail closed, so the AI key is never left unprotected).
 *
 * @param array<string,int> $limits key => most requests allowed per window
 */
function aics_chatbot_rate_limit_take(string $dir, string $bucket, array $limits, int $now, int $window = 600): bool
{
    if ($limits === [] || preg_match('/^[a-z]+$/', $bucket) !== 1) return false;
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
        $handle = @fopen($dir . DIRECTORY_SEPARATOR . $bucket . '.json', 'c+');
    } catch (Throwable) {
        return false;
    }
    if ($handle === false) return false;
    try {
        if (!flock($handle, LOCK_EX)) return false;
        $counts = json_decode((string) stream_get_contents($handle), true);
        $counts = is_array($counts) ? $counts : [];
        // Drop requests older than the window, and keys with none left.
        foreach ($counts as $hash => $times) {
            $recent = is_array($times) ? array_values(array_filter($times, static fn ($time): bool => is_int($time) && $time > $now - $window)) : [];
            if ($recent === []) unset($counts[$hash]);
            else $counts[$hash] = $recent;
        }
        $hashes = [];
        foreach ($limits as $key => $limit) {
            $hash = substr(hash('sha256', 'aics-chat|' . $key), 0, 32);
            if (count($counts[$hash] ?? []) >= max(0, (int) $limit)) return false;
            $hashes[] = $hash;
        }
        foreach ($hashes as $hash) $counts[$hash][] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($counts));
        fflush($handle);
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** The request body, or one byte more than the cap when it is too large (never the whole of a huge body). */
function aics_chatbot_read_body(): string
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > AICS_CHATBOT_MAX_BODY_BYTES) {
        return str_repeat(' ', AICS_CHATBOT_MAX_BODY_BYTES + 1);
    }
    return (string) file_get_contents('php://input', false, null, 0, AICS_CHATBOT_MAX_BODY_BYTES + 1);
}
