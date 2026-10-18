<?php
declare(strict_types=1);

/**
 * Private ADCD artifact access helpers.  They intentionally deal only in
 * logical identifiers and metadata, never a client supplied file location.
 */
function aics_ai_artifact_root(): string
{
    $configured = trim((string) getenv('AICS_AI_REVIEW_ARTIFACT_ROOT'));
    return $configured !== '' ? $configured : 'C:\\xampp\\aics_private\\ai_review_artifacts';
}

/** @return array{review_id:int,page:int,type:string}|null */
function aics_ai_artifact_request(array $query): ?array
{
    $allowed = ['review_id' => true, 'page' => true, 'type' => true];
    if (count($query) !== count($allowed)) return null;
    foreach ($query as $key => $value) {
        if (!isset($allowed[$key]) || !is_string($value)) return null;
    }
    $review = $query['review_id'] ?? '';
    $page = $query['page'] ?? '';
    $type = $query['type'] ?? '';
    if (!preg_match('/^[1-9][0-9]*$/D', $review) || !preg_match('/^[1-9][0-9]*$/D', $page)) return null;
    if (!in_array($type, ['signal', 'threshold', 'original'], true)) return null;
    $reviewId = filter_var($review, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $pageNumber = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($reviewId === false || $pageNumber === false) return null;
    return ['review_id' => $reviewId, 'page' => $pageNumber, 'type' => $type];
}

function aics_ai_artifact_available(string $findingsJson, int $pageNumber, string $type, string $supplementJson = ''): bool
{
    $findings = json_decode($findingsJson, true);
    $native = is_array($findings) && is_array($findings['tamper_detection'] ?? null) ? $findings['tamper_detection'] : null;
    $supplement = json_decode($supplementJson, true);
    $tamper = is_array($native) && ($native['status'] ?? '') === 'completed'
        ? $native
        : (is_array($supplement) ? $supplement : $native);
    if (!is_array($tamper) || ($tamper['status'] ?? null) !== 'completed') return false;
    if ($type === 'original') {
        foreach ((array) ($tamper['original_pages'] ?? []) as $page) {
            if (is_array($page) && (int) ($page['page'] ?? 0) === $pageNumber && ($page['available'] ?? false) === true) return true;
        }
        return false;
    }
    $pages = is_array($tamper['pages'] ?? null) ? $tamper['pages'] : [];
    foreach ($pages as $page) {
        if (!is_array($page) || (int) ($page['page'] ?? 0) !== $pageNumber) continue;
        return (($page['artifacts'][$type]['available'] ?? false) === true);
    }
    return false;
}

/** @return string|null Canonical regular PNG path within the configured root. */
function aics_ai_artifact_file(string $root, int $reviewId, int $pageNumber, string $type): ?string
{
    $fileName = $type === 'signal' ? 'signal-overlay.png' : ($type === 'threshold' ? 'threshold-overlay.png' : ($type === 'original' ? 'original-page.png' : ''));
    if ($fileName === '') return null;
    $rootPath = realpath($root);
    if ($rootPath === false || !is_dir($rootPath)) return null;
    $candidate = $rootPath . DIRECTORY_SEPARATOR . 'review_' . $reviewId . DIRECTORY_SEPARATOR . 'page_' . $pageNumber . DIRECTORY_SEPARATOR . $fileName;
    $resolved = realpath($candidate);
    $prefix = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';
    $normalized = $resolved === false ? '' : str_replace('\\', '/', $resolved);
    if ($resolved === false || !is_file($resolved) || !str_starts_with($normalized, $prefix)) return null;
    $handle = @fopen($resolved, 'rb');
    if ($handle === false) return null;
    $signature = fread($handle, 8);
    fclose($handle);
    return $signature === "\x89PNG\r\n\x1a\n" ? $resolved : null;
}
