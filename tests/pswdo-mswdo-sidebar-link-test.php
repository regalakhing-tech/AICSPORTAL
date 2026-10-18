<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

function mswdo_sidebar_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

mswdo_sidebar_assert(
    function_exists('pswdo_mswdo_nav_link'),
    'The shared MSWDO sidebar link renderer is missing.'
);

$link = pswdo_mswdo_nav_link('/AICS/Pswdo/dashboard.php');
$activeLink = pswdo_mswdo_nav_link('/AICS/Pswdo/dashboard.php', true);

mswdo_sidebar_assert(
    $link === '<a class="nav-link" href="/AICS/Pswdo/dashboard.php?page=all_releases"><i class="fas fa-envelope"></i> MSWDO</a>',
    'The MSWDO sidebar link must use the email icon, label, and all-releases destination.'
);
mswdo_sidebar_assert(
    str_contains($activeLink, 'class="nav-link active"'),
    'The shared MSWDO sidebar link must support its active state.'
);

echo "PSWDO MSWDO sidebar link test passed.\n";
