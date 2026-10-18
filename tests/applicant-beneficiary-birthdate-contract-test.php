<?php
declare(strict_types=1);

$page = file_get_contents(__DIR__ . '/../Applicants/php_files/submit-request.php');
if (!is_string($page)) {
    throw new RuntimeException('Cannot read the application form.');
}

foreach (['beneficiary-birth-month', 'beneficiary-birth-day', 'beneficiary-birth-year'] as $id) {
    if (!preg_match('/<input\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*\binputmode="numeric"[^>]*>/s', $page)) {
        throw new RuntimeException("The {$id} typing field is missing.");
    }
}

if (!preg_match('/<input\b[^>]*\bname="beneficiary_birth_date"[^>]*\bid="beneficiary-birth-date"[^>]*>/s', $page)) {
    throw new RuntimeException('The ISO birthdate field used by draft save and submission is missing.');
}

if (!str_contains($page, 'beneficiary-birthdate.js')) {
    throw new RuntimeException('The application does not load its beneficiary birthdate controller.');
}

echo "Beneficiary birthdate markup contract passed.\n";
