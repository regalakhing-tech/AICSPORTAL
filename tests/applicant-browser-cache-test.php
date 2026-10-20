<?php
declare(strict_types=1);

// Applicant pages keep static files and their own uploaded documents in the
// browser, so switching tabs does not download them again. Documents stay
// private to that browser and are cleared on logout; PSWDO viewing is unchanged.

function cache_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$helper = (string) file_get_contents("{$root}/includes/secure-document-response.php");
cache_assert(str_contains($helper, '?int $privateCacheSeconds = null'), 'Callers choose document caching; the default stays no-store.');
cache_assert(str_contains($helper, "header('Cache-Control: private, no-store, no-cache, must-revalidate');"), 'Without a cache time documents are never stored.');
cache_assert(str_contains($helper, "'Cache-Control: private, '"), 'Cached documents are private to the browser.');
cache_assert(str_contains($helper, "header('ETag: ' . \$etag);") && str_contains($helper, 'http_response_code(304);'), 'An unchanged document is answered with 304 instead of the file.');
$check = strpos($helper, 'http_response_code(304);');
cache_assert($check !== false && $check < strpos($helper, 'readfile('), 'The 304 answer comes before the file is read.');

$applicantView = (string) file_get_contents("{$root}/Applicants/php_files/document-view.php");
cache_assert(str_contains($applicantView, 'aics_document_requirement_label((string) $document[\'document_type\']), 14400)'), 'Submitted documents (a new address per upload) are kept for a sign-in session.');
cache_assert(str_contains((string) file_get_contents("{$root}/Applicants/php_files/draft-document-view.php"), "(string) \$document['document_type']), 0)"), 'Draft documents are revalidated every time.');
cache_assert(str_contains((string) file_get_contents("{$root}/Applicants/php_files/profile-id-photo.php"), ", 0);"), 'Sign-up ID photos (same address after a new scan) are revalidated every time.');
$pswdoView = (string) file_get_contents("{$root}/Pswdo/php_files/document-view.php");
cache_assert(!preg_match('/aics_serve_document_file\([^;]*,\s*\d+\)\s*;/', $pswdoView), 'PSWDO document viewing is unchanged (never stored).');

cache_assert(str_contains((string) file_get_contents("{$root}/Applicants/php_files/auth-logout.php"), "header('Clear-Site-Data: \"cache\"');"), 'Logging out clears cached documents from the browser.');

$apache = (string) file_get_contents("{$root}/deploy/apache.conf");
cache_assert(str_contains($apache, 'Header set Cache-Control "public, max-age=31536000, immutable" "expr=%{QUERY_STRING} =~ /(^|&)v=/"'), 'Versioned CSS/JS are kept for a year.');
cache_assert(str_contains($apache, 'Header set Cache-Control "public, max-age=604800"'), 'Logos, images and fonts are kept for 7 days.');

// A long cache is only safe when the version changes with the file.
foreach (['Applicants/login.php', 'chatbot/applicant-widget.php', 'deploy/site/landingpage.php'] as $file) {
    $text = (string) file_get_contents("{$root}/{$file}");
    cache_assert(!preg_match('/\.(css|js)\?v=[A-Za-z0-9._-]+"/', $text), "{$file} versions its CSS/JS by file time, not a hand-typed value.");
}

echo "applicant browser cache test passed\n";
