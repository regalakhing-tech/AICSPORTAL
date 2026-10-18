<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-data-lib.php';

$documents = [
    ['code' => 'valid_id', 'document_id' => 10, 'label' => 'Valid ID'],
    ['code' => 'med_cert', 'document_id' => 20, 'label' => 'Medical Certificate'],
    ['code' => 'billing_statement', 'document_id' => 30, 'label' => 'Billing Statement'],
    ['code' => 'valid_id_back', 'document_id' => 11, 'label' => 'Back of Applicant ID'],
];
$grouped = pswdo_group_id_photos($documents);
if (count($grouped) !== 3 || array_column($grouped, 'code') !== ['valid_id', 'med_cert', 'billing_statement']) {
    throw new RuntimeException('Back of ID must not become a fourth document row.');
}
if (($grouped[0]['back_photo']['document_id'] ?? null) !== 11) {
    throw new RuntimeException('Back of ID must remain accessible inside Valid ID.');
}
$withoutBack = pswdo_group_id_photos(array_slice($documents, 0, 3));
if (count($withoutBack) !== 3 || isset($withoutBack[0]['back_photo'])) {
    throw new RuntimeException('Requests without an ID back must keep their required rows.');
}

echo "PSWDO ID photo grouping passed.\n";
