<?php
declare(strict_types=1);

function aics_serve_document_file(string $filePath, string $allowedRoot, string $displayName): never
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

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($resolvedFile));
    header('Content-Disposition: inline; filename="' . addcslashes($safeName, '"\\') . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($resolvedFile);
    exit;
}
