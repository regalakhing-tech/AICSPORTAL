<?php
declare(strict_types=1);

// Shared head and footer parts of the applicant pages: the phone layout (styled only
// inside a max-width: 768px media query, so desktop and tablet stay as they are) and
// the page language (English, Tagalog, Hiligaynon) chosen on My Profile.

function aics_app_shell_asset_version(string $relativePath): string
{
    $path = __DIR__ . '/../' . $relativePath;
    return (string) (is_file($path) ? filemtime($path) : time());
}

/** The signed-in applicant's saved language, read once per session. */
function aics_app_shell_language(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['aics_account_id'])) return '';
    if (!array_key_exists('aics_language', $_SESSION)) {
        $language = '';
        try {
            $connection = aics_db_connect();
            aics_ensure_auth_schema($connection);
            $language = aics_applicant_language($connection, (int) $_SESSION['aics_account_id']);
            $connection->close();
        } catch (Throwable $exception) {
            $language = '';
        }
        $_SESSION['aics_language'] = $language;
    }
    return aics_normalize_applicant_language((string) $_SESSION['aics_language']);
}

function aics_app_shell_head(): string
{
    $asset = static fn (string $path): string => htmlspecialchars('../' . $path . '?v=' . rawurlencode(aics_app_shell_asset_version($path)), ENT_QUOTES, 'UTF-8');
    $language = aics_app_shell_language();
    // Set the language before the page shows, so the content never flashes in English.
    // The saved account language wins; this device's last choice is used until one is saved.
    $boot = '(function(){var d=document.documentElement,l=' . json_encode($language) . ';'
        . 'try{if(!l){l=window.localStorage.getItem("aics-applicant-language")||"";}}catch(e){}'
        . 'if(l==="tl"||l==="hil"){d.setAttribute("data-aics-lang",l);d.classList.add("aics-i18n-pending");'
        . 'setTimeout(function(){d.classList.remove("aics-i18n-pending");},3000);}})();';
    return implode("\n", [
        '<link rel="stylesheet" href="' . $asset('css files/applicant-app.css') . '" />',
        '<script>' . $boot . '</script>',
        '<script src="' . $asset('js files/applicant-i18n-dict.js') . '" defer></script>',
        '<script src="' . $asset('js files/applicant-i18n.js') . '" defer></script>',
    ]) . "\n";
}

function aics_app_shell_script(): string
{
    $js = '../js files/applicant-app.js?v=' . rawurlencode(aics_app_shell_asset_version('js files/applicant-app.js'));
    // Pages live in Applicants/php_files, so the project root is three levels up from the script.
    $assetBase = rtrim(dirname(dirname(dirname(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''))))), '/') . '/asset';
    return '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '" data-asset-base="'
        . htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
}
