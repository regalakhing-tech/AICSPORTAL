<?php
declare(strict_types=1);

// Phone layout for the applicant pages. Everything it adds is styled only
// inside a max-width: 768px media query, so desktop and tablet stay as they are.

function aics_app_shell_asset_version(string $relativePath): string
{
    $path = __DIR__ . '/../' . $relativePath;
    return (string) (is_file($path) ? filemtime($path) : time());
}

function aics_app_shell_head(): string
{
    $css = '../css files/applicant-app.css?v=' . rawurlencode(aics_app_shell_asset_version('css files/applicant-app.css'));
    return '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
}

function aics_app_shell_script(): string
{
    $js = '../js files/applicant-app.js?v=' . rawurlencode(aics_app_shell_asset_version('js files/applicant-app.js'));
    // Pages live in Applicants/php_files, so the project root is three levels up from the script.
    $assetBase = rtrim(dirname(dirname(dirname(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''))))), '/') . '/asset';
    return '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '" data-asset-base="'
        . htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
}
