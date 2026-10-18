<?php
declare(strict_types=1);

function viewer_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$page = file_get_contents(__DIR__ . '/../Pswdo/php_files/request-management.php');
$script = file_get_contents(__DIR__ . '/../Pswdo/js files/pswdo-pages.js');
$css = file_get_contents(__DIR__ . '/../Pswdo/css files/pswdo-pages.css');
viewer_assert(is_string($page) && is_string($script) && is_string($css), 'Viewer sources could not be read.');
viewer_assert(str_contains($page, 'js-open-ai-signal-viewer') && str_contains($page, 'count($viewablePages) > 0') && str_contains($page, '$tamperStatus === \'completed\''), 'Evidence button is not limited to completed results with an authorized page artifact.');
viewer_assert(str_contains($page, 'This earlier review does not include the current AI image-detail view.') && str_contains($page, 'Image review was completed, but the detailed image view is not available.'), 'Legacy and completed-without-artifact messages are missing.');
viewer_assert(!str_contains($page, 'Re-run AI Verification'), 'Completed results regained a re-run control.');
viewer_assert(str_contains($page, 'Marked areas on the document') && str_contains($page, 'id="ai-evidence-list"') && str_contains($script, 'tabsWrap.hidden') && str_contains($page, 'data-ai-view="original"') && str_contains($page, 'data-ai-view="signal"') && str_contains($page, 'data-ai-view="threshold"'), 'Viewer tabs are incomplete.');
viewer_assert(str_contains($page, 'document-view.php?request_id=') && str_contains($script, 'new URL("ai-artifact.php"'), 'Original and artifact sources are not separated.');
viewer_assert(str_contains($script, 'url.searchParams.set("review_id"') && str_contains($script, 'url.searchParams.set("page"') && str_contains($script, 'url.searchParams.set("type"'), 'Artifact requests do not use logical identifiers.');
viewer_assert(str_contains($script, 'No image region exceeded the current localization threshold.') && str_contains($script, 'regionsFound'), 'No-threshold-region behavior is missing.');
viewer_assert(str_contains($script, 'aiSignalViewer.index') && str_contains($script, 'Page ${page.page} of ${aiSignalViewer.pages.length}'), 'Multi-page alignment/navigation is missing.');
viewer_assert(str_contains($script, 'AI visualization could not be loaded.') && str_contains($script, 'Loading image view...'), 'Sanitized loading/error states are missing.');
foreach (['ai-ensure-current.php', 'reprocess_ai_review', 'processor.py', 'aics_launch_document_ai_processor'] as $forbidden) {
    $viewerBlock = substr($script, strpos($script, 'function artifactUrl'));
    viewer_assert(!str_contains($viewerBlock, $forbidden), 'Viewer path contains an AI processing action: ' . $forbidden);
}
foreach (['test_outputs', 'AICS-Development', 'aics_private', 'signal-overlay.png', 'threshold-overlay.png'] as $forbidden) {
    viewer_assert(!str_contains($page, $forbidden) && !str_contains($script, $forbidden), 'Viewer exposes private artifact detail: ' . $forbidden);
}
viewer_assert(str_contains($css, '.ai-signal-modal') && str_contains($css, '.ai-signal-image') && str_contains($css, 'max-width:100%'), 'Viewer presentation is incomplete.');
echo "PSWDO AI signal viewer contract test passed.\n";
