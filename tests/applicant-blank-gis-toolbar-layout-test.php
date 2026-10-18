<?php
declare(strict_types=1);

function blank_gis_toolbar_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

ob_start();
require __DIR__ . '/../Applicants/blank-gis.php';
$html = (string) ob_get_clean();

$document = new DOMDocument();
@$document->loadHTML($html);
$xpath = new DOMXPath($document);
$controls = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' print-action-bar ')]/*");

blank_gis_toolbar_assert($controls !== false && $controls->length === 2, 'The GIS toolbar must contain exactly two actions.');
blank_gis_toolbar_assert($controls->item(0)?->getAttribute('id') === 'close-tab-link', 'Close Tab must be the left toolbar action.');
blank_gis_toolbar_assert($controls->item(1)?->getAttribute('id') === 'download-btn', 'Download GIS Form must be the right toolbar action.');

$styles = '';
foreach ($document->getElementsByTagName('style') as $style) {
    $styles .= $style->textContent;
}
blank_gis_toolbar_assert(
    preg_match('/\.print-action-bar\s*\{[^}]*justify-content\s*:\s*space-between/s', $styles) === 1,
    'The GIS toolbar must anchor its actions to opposite sides.'
);

echo "Applicant blank GIS toolbar layout test passed.\n";
