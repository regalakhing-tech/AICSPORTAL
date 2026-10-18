<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/geo-analytics.php';

header('Content-Type: application/json; charset=utf-8');
try {
    $stats = aics_historical_application_geo_stats(aics_db_connect());
    $municipalities = [];
    foreach ($stats['municipalities'] as $name => $data) {
        $municipalities[$name] = ['total' => (int) $data['total'], 'psgc_code' => (string) ($data['psgc_code'] ?? '')];
    }
    echo json_encode(['ok' => true, 'mode' => 'historical', 'municipalities' => $municipalities, 'benefit_summary' => $stats['benefit_summary'], 'summary' => ['total' => (int) $stats['summary']['total']]]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Map data is temporarily unavailable.']);
}
