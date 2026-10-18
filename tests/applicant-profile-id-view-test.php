<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/applicant-id-verification.php';

function profile_id_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$originalRoot = getenv('AICS_PRIVATE_ROOT');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-profile-id-' . bin2hex(random_bytes(8));
$ownerDir = $root . DIRECTORY_SEPARATOR . 'applicant_ids' . DIRECTORY_SEPARATOR . '7';
$otherDir = $root . DIRECTORY_SEPARATOR . 'applicant_ids' . DIRECTORY_SEPARATOR . '8';
mkdir($ownerDir, 0700, true);
mkdir($otherDir, 0700, true);
$front = $ownerDir . DIRECTORY_SEPARATOR . 'front.png';
$back = $ownerDir . DIRECTORY_SEPARATOR . 'back.png';
$otherFront = $otherDir . DIRECTORY_SEPARATOR . 'front.png';
file_put_contents($front, 'front');
file_put_contents($back, 'back');
file_put_contents($otherFront, 'other');
putenv('AICS_PRIVATE_ROOT=' . $root);

try {
    $record = ['front_path' => $front, 'back_path' => $back];
    profile_id_assert(aics_id_photo_path_for_account(7, $record, 'front') === realpath($front),
        'The account owner should see the saved front ID image.');
    profile_id_assert(aics_id_photo_path_for_account(7, $record, 'back') === realpath($back),
        'The account owner should see the saved back ID image.');
    profile_id_assert(aics_id_photo_path_for_account(8, $record, 'front') === null,
        'A different account must not see these images.');
    profile_id_assert(aics_id_photo_path_for_account(7, ['front_path' => $otherFront], 'front') === null,
        'A path to another account must be rejected even if stored in the row.');
    profile_id_assert(aics_id_photo_path_for_account(7, $record, '../front') === null,
        'Only front and back image requests are valid.');
    profile_id_assert(aics_id_photo_path_for_account(7, ['front_path' => $ownerDir . '/missing.png'], 'front') === null,
        'Missing photos must not be advertised as available.');
    profile_id_assert(aics_id_resolved_root_matches_account(7, realpath($ownerDir), realpath(dirname($ownerDir))),
        'The real account folder must remain valid.');
    profile_id_assert(!aics_id_resolved_root_matches_account(9, realpath($otherDir), realpath(dirname($otherDir))),
        'A linked folder resolving to a different account number must be rejected.');
    $linkedDir = $root . DIRECTORY_SEPARATOR . 'applicant_ids' . DIRECTORY_SEPARATOR . '9';
    if (@symlink($otherDir, $linkedDir)) {
        try {
            profile_id_assert(aics_id_photo_path_for_account(9, ['front_path' => $otherFront], 'front') === null,
                'A linked account folder must not expose another account photo.');
        } finally {
            unlink($linkedDir);
        }
    }
} finally {
    if ($originalRoot === false) putenv('AICS_PRIVATE_ROOT');
    else putenv('AICS_PRIVATE_ROOT=' . $originalRoot);
    unlink($front);
    unlink($back);
    unlink($otherFront);
    rmdir($ownerDir);
    rmdir($otherDir);
    rmdir(dirname($ownerDir));
    rmdir($root);
}

echo "Applicant profile ID view test passed.\n";
