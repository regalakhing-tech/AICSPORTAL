<?php
declare(strict_types=1);

function landingpage_order_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = __DIR__ . '/../../landingpage.html';
$html = file_get_contents($path);
landingpage_order_assert(is_string($html), 'Could not read landingpage.html.');

$document = new DOMDocument();
libxml_use_internal_errors(true);
$document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
libxml_clear_errors();
$xpath = new DOMXPath($document);

$actualOrder = [];
foreach ($xpath->query('//*[@id="category-tab-buttons"]/*[@data-tab]') ?: [] as $button) {
    $actualOrder[] = $button->attributes?->getNamedItem('data-tab')?->nodeValue;
}
$expectedOrder = ['medical', 'burial', 'disaster', 'educational', 'inflation', 'special'];
landingpage_order_assert($actualOrder === $expectedOrder, 'Assistance tabs are not in the required left-to-right order.');

$boldProcess = $xpath->query('//*[@id="how-it-works"]//*[self::strong or self::b][normalize-space(.)="4-step process"]');
landingpage_order_assert($boldProcess !== false && $boldProcess->length === 1, 'The “4-step process” phrase is not bold.');

echo "Landing-page assistance order and workflow emphasis test passed.\n";
