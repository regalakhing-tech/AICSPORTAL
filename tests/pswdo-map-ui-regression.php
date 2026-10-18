<?php
declare(strict_types=1);

function map_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$page = (string) file_get_contents(__DIR__ . '/../Pswdo/php_files/map-analytics.php');
$script = (string) file_get_contents(__DIR__ . '/../Pswdo/js files/map-analytics.js');
$styles = (string) file_get_contents(__DIR__ . '/../Pswdo/css files/pswdo-pages.css');

foreach (['map-hero-bar', 'map-workspace', 'map-insights-panel', 'map-floating-controls', 'map-choropleth-legend'] as $requiredElement) {
    map_ui_assert(str_contains($page, $requiredElement), "The redesigned map is missing {$requiredElement}.");
}

map_ui_assert(str_contains($script, 'function concentrationFillExpression()'), 'The persistent choropleth color expression is missing.');
foreach (['#dcfce7', '#fde68a', '#fdba74'] as $color) {
    map_ui_assert(str_contains($script, $color), "The zoom-safe choropleth is missing {$color}.");
}
map_ui_assert(!str_contains($script, '#f87171'), 'The choropleth still has a separate very-high color.');
map_ui_assert(!str_contains($page, 'Very high') && !str_contains($page, 'is-very-high'), 'The legend still exposes a Very high category.');
map_ui_assert(str_contains($script, 'aicsConcentrationCategory(applicationCount)') && !str_contains($script, 'return ratio >= .9'), 'Municipality and barangay colors must use the fixed concentration bands.');
foreach (['Low (1-250)', 'Medium (251-500)', 'High (501+)'] as $range) {
    map_ui_assert(str_contains($page, $range), "The map legend must show $range.");
}
map_ui_assert(
    substr_count($script, "'fill-color': concentrationFillExpression()") >= 2,
    'Municipality and barangay polygons must both retain concentration colors.'
);
map_ui_assert(str_contains($script, "'fill-opacity': concentrationFillOpacityExpression()"), 'The persistent polygon opacity expression is missing.');
map_ui_assert(str_contains($script, 'legend.hidden = !densityEnabled;'), 'The heat legend must hide when its overlay is disabled.');
map_ui_assert(str_contains($script, 'padding: isMobile ? 24 : 36'), 'Province fitting must use balanced padding in the map-first workspace.');
map_ui_assert(str_contains($styles, 'grid-template-columns: minmax(0, 1fr) 320px'), 'The map-first workspace and insight panel layout is missing.');
map_ui_assert(str_contains($styles, '.map-choropleth-legend'), 'The permanent concentration legend is not styled.');
map_ui_assert(str_contains($script, 'badge.className = `map-popup-concentration is-${meta.key}`'), 'The hover popup concentration badge is missing.');
map_ui_assert(str_contains($styles, '.map-popup-concentration'), 'The hover popup concentration badge is not styled.');

echo "PSWDO map UI regression passed.\n";
