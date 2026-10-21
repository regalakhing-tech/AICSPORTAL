<?php
declare(strict_types=1);

// Dashboard cards keep their words inside the card on laptops (1024-1366 px)
// and phones. Before, the PSWDO counter labels could not wrap and were cut
// ("NEW APPLICATION REQ"), and the admin "Admin routine" tables stretched
// their card past the panel edge instead of scrolling inside it.
function fit_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** The declarations of the last rule whose selector list is exactly $selector. */
function fit_rule(string $css, string $selector): string
{
    $css = str_replace("\r\n", "\n", $css);
    preg_match_all('/(?:^|\})\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/m', $css, $matches);
    return (string) end($matches[1]);
}

$root = __DIR__ . '/..';
$pswdo = (string) file_get_contents($root . '/Pswdo/css files/pswdo-pages.css');
$label = fit_rule($pswdo, '.workflow-stage-label');
fit_assert($label !== '' && !str_contains($label, 'nowrap'), 'PSWDO counter labels may wrap to a second line.');
fit_assert(str_contains($label, 'overflow-wrap'), 'A long PSWDO counter label word breaks instead of leaving the card.');

$admin = (string) file_get_contents($root . '/ADMIN/cssfiles/admin-pages.css');
$panels = fit_rule($admin, ".dashboard-tab-panels,\n.dashboard-tab-panel.active");
fit_assert(str_contains($panels, 'grid-template-columns: minmax(0, 1fr)'), 'Admin tab panels are never wider than the dashboard.');
$children = fit_rule($admin, ".dashboard-tab-panels > *,\n.dashboard-tab-panel.active > *");
fit_assert(str_contains($children, 'min-width: 0'), 'Admin cards in a tab shrink so their tables scroll inside them.');

echo "Dashboard card text fit checks passed.\n";
