<?php
declare(strict_types=1);
require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';
$component = __DIR__ . '/../Pswdo/php_files/release-document-progress.php';
if (is_file($component)) require_once $component;
function release_progress_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
release_progress_assert(function_exists('pswdo_release_document_progress'), 'Release file progress must account for each uploaded file.');
$required = ['valid_id', 'med_cert', 'billing_statement'];
$complete = pswdo_release_document_progress($required, [...$required, 'valid_id_back'], 4);
release_progress_assert($complete['requirements_label'] === '4 / 4 files' && $complete['requirements_complete'], 'Four complete uploads must display 4 / 4.');
$missing = pswdo_release_document_progress($required, ['valid_id', 'valid_id_back', 'med_cert'], 3);
release_progress_assert($missing['requirements_label'] === '3 / 4 files' && !$missing['requirements_complete'], 'An extra ID image must not conceal a missing medical document.');
$unknown = pswdo_release_document_progress([], ['legacy_file'], 1);
release_progress_assert(!$unknown['requirements_complete'], 'Unknown requirements must not be marked complete.');
$empty = pswdo_release_document_progress([], [], 0);
release_progress_assert($empty['requirements_label'] === '0 / 0 files' && !$empty['requirements_complete'], 'An empty document list must remain neutral.');
foreach ([$complete, $missing, $empty] as $item) {
    ob_start(); pswdo_render_release_document_progress($item); $html = ob_get_clean();
    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML($html); libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    release_progress_assert(str_contains(preg_replace('/\s+/', ' ', $dom->textContent), $item['requirements_label']), 'The badge must expose the full count as readable text.');
    release_progress_assert($xpath->query('//i[@aria-hidden="true"]')->length === 1, 'The document icon must be decorative.');
    release_progress_assert(!str_contains($html, 'role="button"') && !str_contains($html, 'tabindex='), 'A file count must not pretend to be an interactive control.');
    release_progress_assert(str_contains($html, $item['requirements_complete'] ? 'is-complete' : ($item['missing_total'] > 0 ? 'is-incomplete' : 'is-neutral')), 'The badge must show its real completeness state.');
}
echo "PSWDO release document badge checks passed.\n";
