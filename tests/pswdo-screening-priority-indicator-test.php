<?php
declare(strict_types=1);

require_once __DIR__ . '/../Pswdo/php_files/pswdo-common.php';

putenv('SMART_PRIORITY_MEDICAL_ANALYSIS=true');

$cases = [
    [['client_categories_json' => '["youth"]', 'assistance_code' => 'medical', 'urgency_indicator' => 'URGENT', 'priority_level' => 'HIGH', 'priority_rank' => 7], 'STANDARD'],
    [['client_categories_json' => '["senior_citizen"]', 'assistance_code' => 'medical', 'urgency_indicator' => 'STANDARD'], 'HIGH'],
    [['client_categories_json' => '["pwd"]', 'assistance_code' => 'medical', 'urgency_indicator' => 'URGENT'], 'URGENT'],
    [['client_categories_json' => '["senior_citizen"]', 'assistance_code' => 'burial', 'urgency_indicator' => 'URGENT'], 'STANDARD'],
    [['client_categories_json' => '["pwd"]', 'assistance_code' => 'medical', 'urgency_indicator' => 'REQUIRES_STAFF_REVIEW'], 'HIGH'],
];

foreach ($cases as [$item, $expected]) {
    $indicator = pswdo_screening_priority_indicator($item);
    if (($indicator['label'] ?? null) !== $expected) {
        throw new RuntimeException("Screening priority should be {$expected} for " . json_encode($item));
    }
}

echo "Screening queue Smart Priority indicators passed.\n";
