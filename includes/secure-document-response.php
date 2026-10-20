<?php
declare(strict_types=1);

/**
 * Sends a stored document after the caller has checked who may see it.
 * $privateCacheSeconds: null = never stored (default); 0 = kept in the viewer's own
 * browser but checked every time (an unchanged file is answered with 304); > 0 = kept
 * for that many seconds. Cached copies are private to the browser and cleared on logout.
 */
function aics_serve_document_file(string $filePath, string $allowedRoot, string $displayName, ?int $privateCacheSeconds = null): never
{
    $resolvedRoot = realpath($allowedRoot);
    $resolvedFile = realpath($filePath);
    if ($resolvedRoot === false || $resolvedFile === false || !is_file($resolvedFile)) {
        http_response_code(404);
        exit('Document not found.');
    }

    $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with(strtolower($resolvedFile), strtolower($rootPrefix))) {
        http_response_code(403);
        exit('Document access denied.');
    }

    $mimeType = (string) (new finfo(FILEINFO_MIME_TYPE))->file($resolvedFile);
    if (!in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        http_response_code(415);
        exit('Unsupported document type.');
    }

    $extension = match ($mimeType) {
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    };
    $safeName = preg_replace('/[^a-zA-Z0-9._ -]/', '_', trim($displayName)) ?: 'document';
    if (strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) !== $extension) $safeName .= '.' . $extension;

    header('X-Content-Type-Options: nosniff');
    if ($privateCacheSeconds === null) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    } else {
        $modified = (int) filemtime($resolvedFile);
        $etag = '"' . sha1($resolvedFile . '|' . (string) filesize($resolvedFile) . '|' . (string) $modified) . '"';
        header('Cache-Control: private, ' . ($privateCacheSeconds > 0 ? 'max-age=' . $privateCacheSeconds : 'no-cache'));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        $knownTags = array_map('trim', explode(',', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
        if (in_array($etag, $knownTags, true)) {
            http_response_code(304);
            exit;
        }
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($resolvedFile));
    header('Content-Disposition: inline; filename="' . addcslashes($safeName, '"\\') . '"');
    readfile($resolvedFile);
    exit;
}
