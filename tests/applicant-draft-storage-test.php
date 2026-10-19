<?php
declare(strict_types=1);

// Draft uploads must survive a redeploy: on Railway the temp folder is wiped
// with the container, while AICS_PRIVATE_ROOT is on the persistent volume.
require_once __DIR__ . '/../Applicants/php_files/draft-lib.php';

function draft_storage_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$volume = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-volume-test';
putenv('AICS_PRIVATE_ROOT=' . $volume . DIRECTORY_SEPARATOR);
draft_storage_assert(aics_draft_upload_root() === $volume . DIRECTORY_SEPARATOR . 'draft_uploads', 'Draft uploads go to the private storage folder when one is configured.');
putenv('AICS_PRIVATE_ROOT');
draft_storage_assert(aics_draft_upload_root() === rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'aics-draft-uploads', 'Without private storage, drafts stay in the temp folder.');
echo "Applicant draft storage checks passed.\n";
