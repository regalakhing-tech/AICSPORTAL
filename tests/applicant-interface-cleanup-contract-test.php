<?php
declare(strict_types=1);

function applicant_cleanup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$documents = file_get_contents($root . '/Applicants/php_files/documents.php');
$profile = file_get_contents($root . '/Applicants/php_files/my-profile.php');

applicant_cleanup_assert(is_string($documents), 'Cannot read the Documents page.');
applicant_cleanup_assert(is_string($profile), 'Cannot read the My Profile page.');
applicant_cleanup_assert(
    !str_contains($documents, '<i class="fas fa-check"></i> Complete</span>'),
    'The completed-request badge must not appear on the Documents page.'
);
applicant_cleanup_assert(
    str_contains($documents, '<i class="fas fa-clock"></i> <?= (int) ($req[\'progress_pct\'] ?? 0) ?>%'),
    'Useful incomplete-request progress must remain visible.'
);
applicant_cleanup_assert(
    !str_contains($profile, 'Live portal synchronization'),
    'The synchronization badge must not appear on My Profile.'
);

echo "Applicant interface cleanup contract passed.\n";
