<?php
declare(strict_types=1);

// A failed submission must leave nothing behind. MySQL commits the open
// transaction on any ALTER/CREATE TABLE, so no schema change may run between
// begin_transaction() and commit() in save-request.php; otherwise a later error
// (for example a missing draft file) leaves a half-saved request that starts the
// applicant's one-year cooldown although they saw "Submission failed".

function atomic_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = __DIR__ . '/..';
$sources = [];
foreach (['Applicants/php_files/save-request.php', 'Applicants/php_files/draft-lib.php', 'includes/applicant-id-verification.php'] as $file) {
    $sources[$file] = (string) file_get_contents($root . '/' . $file);
}
$save = $sources['Applicants/php_files/save-request.php'];

/** Body of a top-level function in any of the sources, or '' when not found there. */
function atomic_function_body(array $sources, string $name): string
{
    foreach ($sources as $source) {
        $start = strpos($source, "\nfunction {$name}(");
        if ($start === false) continue;
        $end = strpos($source, "\n}", $start);
        return substr($source, $start, $end === false ? null : $end - $start);
    }
    return '';
}

// From each begin_transaction() to every commit() of that transaction (the edit
// and create paths share one begin and each has its own commit).
$regions = 0;
$begin = strpos($save, '$connection->begin_transaction();');
atomic_assert($begin !== false, 'The submission must use a transaction.');
$nextBegin = strpos($save, '$connection->begin_transaction();', $begin + 1);
$offset = $begin;
while (($commit = strpos($save, '$connection->commit();', $offset)) !== false && ($nextBegin === false || $commit < $nextBegin)) {
    $region = substr($save, $begin, $commit - $begin);
    $regions++;
    atomic_assert(preg_match('/ALTER\s+TABLE|CREATE\s+TABLE/i', $region) !== 1, 'A schema change runs inside the submission transaction.');
    preg_match_all('/([a-z_][a-z0-9_]*)\s*\(\s*\$connection/i', $region, $calls);
    foreach (array_unique($calls[1]) as $function) {
        $body = atomic_function_body($sources, $function);
        if ($body === '') continue;
        atomic_assert(preg_match('/ALTER\s+TABLE|CREATE\s+TABLE|aics_ensure_[a-z_]*\(|ensure_tables_exist\(/i', $body) !== 1,
            "{$function}() changes the database schema inside the submission transaction.");
    }
    $offset = $commit + 1;
}
atomic_assert($regions >= 2, 'Expected the create and edit transactions.');
echo "Applicant submission atomicity checks passed ({$regions} transactions).\n";
