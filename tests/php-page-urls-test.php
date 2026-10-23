<?php
declare(strict_types=1);

// The applicant login and the landing page are served as .php, not .html.
// No code links to the old .html addresses, and old links redirect permanently.

function page_urls_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
page_urls_assert(is_file("{$root}/Applicants/login.php") && !is_file("{$root}/Applicants/login.html"), 'The applicant login page is login.php.');
page_urls_assert(is_file("{$root}/deploy/site/landingpage.php") && !is_file("{$root}/deploy/site/landingpage.html"), 'The landing page is landingpage.php.');

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!preg_match('/\.(php|js|html|sh)$/', $path) || preg_match('#/(tests|docs|scratch|output|\.superpowers|\.agents|Applicants/uploads|includes/PHPMailer|vendor|node_modules)/#', $path)) continue;
    $text = (string) file_get_contents($path);
    page_urls_assert(!preg_match('/\b(login|landingpage)\.html\b/', $text), "{$path} still links to an .html page.");
}

$apache = (string) file_get_contents("{$root}/deploy/apache.conf");
page_urls_assert(str_contains($apache, 'RedirectMatch 302 ^/$ /AICS/landingpage.php'), 'The site root opens the landing page.');
page_urls_assert(str_contains($apache, 'RedirectMatch 301 ^/AICS/landingpage\.html$ /AICS/landingpage.php'), 'Old landing page links redirect.');
page_urls_assert(str_contains($apache, 'RedirectMatch 301 ^/AICS/AICS/Applicants/login\.html$ /AICS/AICS/Applicants/login.php'), 'Old login links redirect.');
page_urls_assert(
    str_contains($apache, 'RewriteCond %{HTTP_HOST} \.up\.railway\.app$ [NC]')
    && str_contains($apache, 'RewriteRule ^ https://aicsportal.dev%{REQUEST_URI} [R=308,L]')
    && strpos($apache, 'RewriteRule ^ https://aicsportal.dev') < strpos($apache, 'RedirectMatch 302 ^/$'),
    'The Railway address sends visitors to the same page on aicsportal.dev.'
);
$dockerfile = (string) file_get_contents("{$root}/Dockerfile");
page_urls_assert((bool) preg_match('/a2enmod [^\n]*\brewrite\b/', $dockerfile), 'The Docker image turns on mod_rewrite for the aicsportal.dev redirect.');
$htaccess = (string) file_get_contents("{$root}/Applicants/.htaccess");
page_urls_assert(str_contains($htaccess, '<Files "login.php">') && str_contains($htaccess, 'login\.html$ $1/Applicants/login.php'), 'The login page is not cached and old login links redirect.');

echo "php page urls test passed\n";
