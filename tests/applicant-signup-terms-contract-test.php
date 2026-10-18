<?php
declare(strict_types=1);

$page = new DOMDocument();
@$page->loadHTMLFile(__DIR__ . '/../Applicants/login.html');
$xpath = new DOMXPath($page);

function one(DOMXPath $xpath, string $id): DOMElement
{
    $nodes = $xpath->query('//*[@id="' . $id . '"]');
    if ($nodes === false || $nodes->length !== 1 || !($nodes->item(0) instanceof DOMElement)) {
        throw new RuntimeException("Missing or duplicated element: {$id}");
    }
    return $nodes->item(0);
}

try {
    $checkbox = one($xpath, 'register-terms');
    $modalCheckbox = one($xpath, 'register-terms-modal');
    $link = one($xpath, 'signup-terms-link');
    $modal = one($xpath, 'signup-terms-modal');
    one($xpath, 'signup-terms-close');
    $next = one($xpath, 'btn-goto-step-2');

    if ($checkbox->getAttribute('type') !== 'checkbox' || !$next->hasAttribute('disabled')) {
        throw new RuntimeException('Checkbox and initially disabled Next are required.');
    }
    if ($link->tagName !== 'a' || $link->getAttribute('href') !== '#signup-terms-modal') {
        throw new RuntimeException('Terms text must be a link to the dialog.');
    }
    if ($link->parentNode?->tagName === 'label') {
        throw new RuntimeException('Terms link must not be inside the checkbox label.');
    }
    if ($modal->getAttribute('role') !== 'dialog' || $modal->getAttribute('aria-modal') !== 'true') {
        throw new RuntimeException('Terms content must have dialog semantics.');
    }
    if ($modalCheckbox->getAttribute('type') !== 'checkbox' || $modalCheckbox->hasAttribute('name')) {
        throw new RuntimeException('Dialog consent must be a checkbox that does not duplicate form data.');
    }
    if ($xpath->query('//*[@id="signup-terms-modal"]//*[self::h3]')?->length !== 12) {
        throw new RuntimeException('All twelve supplied terms sections must appear.');
    }
    foreach (['PSWDO', 'personal information', 'application', 'SMS', 'approval', 'Republic Act No. 10173', 'Privacy Notice'] as $term) {
        if (stripos($modal->textContent, $term) === false) {
            throw new RuntimeException("Terms content missing: {$term}");
        }
    }
    echo "PASS signup terms markup and content\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
    exit(1);
}
