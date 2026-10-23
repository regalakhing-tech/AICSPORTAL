<?php
declare(strict_types=1);

// Replacing a draft document keeps its document_id, so its preview address must
// change after each upload; otherwise the browser shows the old photo from memory.
function draft_preview_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$js = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../Applicants/js files/applicant-pages.js'));

draft_preview_assert(str_contains($js, 'function draftDocumentPreviewUrl(info)'), 'There is no shared draft preview address builder.');
draft_preview_assert(substr_count($js, 'draft-document-view.php?document_id=') === 1, 'A draft preview address is still built outside draftDocumentPreviewUrl().');
draft_preview_assert(substr_count($js, 'draftDocumentPreviewUrl(draftInfo)') === 2, 'The ID photo tiles and the document cards do not both use draftDocumentPreviewUrl().');
draft_preview_assert((bool) preg_match('/window\.AICS_DRAFT_DOCUMENTS\[documentType\] = \{[^}]*revision: Date\.now\(\)/', $js), 'A replaced draft document does not get a new preview revision.');

// The builder itself: same document, new revision, new address.
preg_match('/function draftDocumentPreviewUrl\(info\) \{.*?\n\}/s', $js, $match);
draft_preview_assert(isset($match[0]), 'draftDocumentPreviewUrl() could not be read.');
$node = trim((string) shell_exec('node --version 2>&1'));
if (str_starts_with($node, 'v')) {
    $script = $match[0] . "\nconst a = draftDocumentPreviewUrl({ document_id: 'abc' });"
        . "\nconst b = draftDocumentPreviewUrl({ document_id: 'abc', revision: 1 });"
        . "\nconst c = draftDocumentPreviewUrl({ document_id: 'abc', revision: 2 });"
        . "\nconsole.log(JSON.stringify([a, b, c, draftDocumentPreviewUrl(null)]));";
    $file = tempnam(sys_get_temp_dir(), 'aics-preview-') . '.js';
    file_put_contents($file, $script);
    $urls = json_decode((string) shell_exec('node ' . escapeshellarg($file)), true);
    @unlink($file);
    draft_preview_assert(is_array($urls) && $urls[0] === 'draft-document-view.php?document_id=abc', 'A saved draft document without a revision does not keep its plain address.');
    draft_preview_assert($urls[1] !== $urls[2] && str_starts_with((string) $urls[2], 'draft-document-view.php?document_id=abc&'), 'A new revision does not give a new preview address.');
    draft_preview_assert($urls[3] === '', 'No draft document should give no address.');
}

echo "Applicant draft preview refresh test passed.\n";
