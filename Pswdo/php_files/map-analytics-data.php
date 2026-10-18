<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/../../includes/geo-analytics.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    $db = aics_db_connect();

    // Check if any filter is actively supplied
    $filterKeys = ['assistance_type', 'status', 'date_from', 'date_to', 'municipality', 'year_start', 'year_end'];
    $hasFilter = false;
    foreach ($filterKeys as $key) {
        if (!empty($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $hasFilter = true;
            break;
        }
    }

    $source = strtolower(trim((string)($_GET['source'] ?? 'historical')));
    $selectedMunicipality = trim((string)($_GET['municipality'] ?? ''));
    $assistanceType = trim((string)($_GET['assistance_type'] ?? ''));

    if ($source === 'live') {
        $live = aics_submitted_application_geo_stats($db, $_GET);
        echo json_encode(['ok' => true, 'mode' => 'live'] + $live);
        return;
    }

    // Historical dataset (4,597 verified cases across all 18 Antique LGUs)
    $stats = aics_historical_application_geo_stats($db);
    $totalDisbursed = 14817500; // Php 14,817,500 total disbursed from official report
    $stats['total_disbursed'] = $totalDisbursed;

    $totalClients = (int)($stats['summary']['total'] ?? 4597);
    $rankIndex = 1;
    foreach ($stats['municipalities'] as $mName => &$mData) {
        $mTotal = (int)($mData['total'] ?? 0);
        $mData['rank'] = $rankIndex++;
        $mData['percentage'] = $totalClients > 0 ? round(($mTotal / $totalClients) * 100, 1) : 0;
        $mData['estimated_disbursed'] = $totalClients > 0 ? (int)round($mTotal * ($totalDisbursed / $totalClients)) : 0;
    }
    unset($mData);

    if ($selectedMunicipality !== '') {
        $normalizedMun = aics_geo_normalize_municipality($selectedMunicipality);
        $stats['selected_municipality'] = $normalizedMun;
        if (isset($stats['municipalities'][$normalizedMun])) {
            $sel = $stats['municipalities'][$normalizedMun];
            $stats['filtered_summary'] = [
                'municipality' => $normalizedMun,
                'total' => $sel['total'],
                'rank' => $sel['rank'] ?? null,
                'percentage' => $sel['percentage'],
                'estimated_disbursed' => $sel['estimated_disbursed'],
            ];
        }
    }

    if ($assistanceType !== '') {
        $normType = aics_geo_normalize_assistance_type($assistanceType);
        $typesMap = aics_geo_assistance_types();
        $label = $typesMap[$normType] ?? $assistanceType;
        $stats['selected_assistance_type'] = $label;
        if (isset($stats['benefit_summary'][$label])) {
            $stats['filtered_benefit_count'] = (int)$stats['benefit_summary'][$label];
        }
    }

    echo json_encode(['ok' => true] + $stats);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load map analytics: ' . $error->getMessage()]);
}
