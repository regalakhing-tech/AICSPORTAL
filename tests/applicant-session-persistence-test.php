<?php
declare(strict_types=1);

// Sign-in sessions must survive a deploy. PHP kept them in the container's
// temporary folder, so every deploy signed everyone out while their pages
// stayed open: saved ID photos showed as broken images and autosave failed
// silently. Sessions now live on the persistent volume, and the applicant form
// says plainly when the sign-in has ended.
function session_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$ini = (string) file_get_contents($root . '/deploy/php.ini');
$start = (string) file_get_contents($root . '/deploy/start.sh');
$apache = (string) file_get_contents($root . '/deploy/apache.conf');
$js = (string) file_get_contents($root . '/Applicants/js files/applicant-pages.js');
$css = (string) file_get_contents($root . '/Applicants/css files/applicant-pages.css');

session_assert((bool) preg_match('/^session\.save_path = "\/var\/www\/html\/AICS\/AICS\/Applicants\/uploads\/\.private\/sessions"$/m', $ini), 'Sessions are stored on the persistent volume.');
session_assert((bool) preg_match('/^session\.gc_maxlifetime = (\d+)$/m', $ini, $lifetime) && (int) $lifetime[1] >= 3600, 'Idle sessions last at least an hour.');
session_assert(str_contains($start, '"$DATA/sessions"') && str_contains($start, 'chmod 700 "$DATA/sessions"'), 'The container creates a private sessions folder.');
session_assert(str_contains($apache, 'Applicants/uploads') && str_contains($apache, 'Require all denied'), 'The sessions folder is never served to the web.');

session_assert(str_contains($js, 'function showAicsSignedOutNotice()'), 'The form can say the sign-in has ended.');
$draftSave = substr($js, (int) strpos($js, 'async function saveDraft('), 1500);
session_assert(str_contains($draftSave, 'response.status === 401') && str_contains($draftSave, 'showAicsSignedOutNotice();'), 'A refused autosave shows the signed-out notice.');
$badge = substr($js, (int) strpos($js, 'const refreshAicsNotificationBadge'), 600);
session_assert(str_contains($badge, 'response.status === 401') && str_contains($badge, 'showAicsSignedOutNotice();'), 'A refused notifications check shows the signed-out notice.');
session_assert(str_contains($js, 'Saved photo could not load. Refresh the page to see it.'), 'A saved ID photo that cannot load says so instead of a broken image.');
session_assert(str_contains($css, '.aics-signed-out-notice {'), 'The notice is styled.');
echo "Applicant session persistence checks passed.\n";
