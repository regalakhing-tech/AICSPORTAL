<?php
declare(strict_types=1);

require_once __DIR__ . '/ai-submission-gate.php';

function aics_geo_normalize_municipality(string $value): string
{
    $value = trim((string) preg_replace('/^\s*(?:municipality|city)\s+of\s+/i', '', $value));
    $value = trim((string) preg_replace('/\s*\(Dao\)\s*$/i', '', $value));
    return strcasecmp($value, 'San Jose') === 0 ? 'San Jose de Buenavista' : $value;
}

function aics_geo_location_key(string $value): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = (string) preg_replace('/^\s*(?:barangay|brgy)\.?\s+/iu', '', $value);
    $value = str_replace('&', ' and ', $value);
    $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/** @return array<string, array<string, array{name:string,psgc_code:string}>> */
function aics_geo_antique_barangay_index(): array
{
    static $index;
    if (is_array($index)) {
        return $index;
    }
    $index = [];
    $path = __DIR__ . '/../../assets/data/antique-barangays.geojson';
    $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    foreach (is_array($payload['features'] ?? null) ? $payload['features'] : [] as $feature) {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        if (strcasecmp(trim((string) ($properties['ADM2_EN'] ?? $properties['prov_name'] ?? '')), 'Antique') !== 0) {
            continue;
        }
        $municipality = aics_geo_normalize_municipality((string) ($properties['ADM3_EN'] ?? ''));
        $barangay = trim((string) ($properties['ADM4_EN'] ?? $properties['brgy_name'] ?? ''));
        $municipalityKey = aics_geo_location_key($municipality);
        $barangayKey = aics_geo_location_key($barangay);
        if ($municipalityKey === '' || $barangayKey === '') {
            continue;
        }
        $index[$municipalityKey][$barangayKey] = [
            'name' => $barangay,
            'psgc_code' => trim((string) ($properties['psgc_code'] ?? $properties['ADM4_PCODE'] ?? '')),
        ];
    }
    return $index;
}

/** @return array{name:string,psgc_code:string}|null */
function aics_geo_match_antique_barangay(string $municipality, string $barangay): ?array
{
    $municipalityKey = aics_geo_location_key(aics_geo_normalize_municipality($municipality));
    $rawBarangay = trim($barangay);
    $variants = [$rawBarangay];
    if (str_contains($rawBarangay, ',')) {
        $variants[] = (string) strstr($rawBarangay, ',', true);
    }
    $index = aics_geo_antique_barangay_index()[$municipalityKey] ?? [];
    foreach (array_unique(array_map('aics_geo_location_key', $variants)) as $key) {
        if ($key !== '' && isset($index[$key])) {
            return $index[$key];
        }
    }
    return null;
}

/** @return array<string, string> */
function aics_geo_assistance_types(): array
{
    return [
        'medical' => 'Medical Assistance',
        'burial' => 'Burial Assistance',
        'disaster_victims' => 'Disaster Victims',
        'special_cases' => 'Special Cases',
        'educational_basic' => 'Educational Assistance for Basic Education',
        'high_inflation_affected' => 'Persons Affected by High Inflation Rate',
    ];
}

function aics_geo_normalize_assistance_type(string $value): string
{
    $value = strtolower(trim($value));
    return match ($value) {
        'victim_of_disaster' => 'disaster_victims',
        default => $value,
    };
}

/** @return list<array{psgc_code:string, municipality:string, total:int}> */
function aics_geo_historical_municipality_seed(): array
{
    return [
        ['psgc_code' => '0600601000', 'municipality' => 'Anini-Y', 'total' => 91],
        ['psgc_code' => '0600602000', 'municipality' => 'Barbaza', 'total' => 235],
        ['psgc_code' => '0600603000', 'municipality' => 'Belison', 'total' => 111],
        ['psgc_code' => '0600604000', 'municipality' => 'Bugasong', 'total' => 195],
        ['psgc_code' => '0600605000', 'municipality' => 'Caluya', 'total' => 25],
        ['psgc_code' => '0600606000', 'municipality' => 'Culasi', 'total' => 687],
        ['psgc_code' => '0600607000', 'municipality' => 'Tobias Fornier', 'total' => 205],
        ['psgc_code' => '0600608000', 'municipality' => 'Hamtic', 'total' => 181],
        ['psgc_code' => '0600609000', 'municipality' => 'Laua-An', 'total' => 121],
        ['psgc_code' => '0600610000', 'municipality' => 'Libertad', 'total' => 81],
        ['psgc_code' => '0600611000', 'municipality' => 'Pandan', 'total' => 68],
        ['psgc_code' => '0600612000', 'municipality' => 'Patnongon', 'total' => 390],
        ['psgc_code' => '0600613000', 'municipality' => 'San Jose de Buenavista', 'total' => 546],
        ['psgc_code' => '0600614000', 'municipality' => 'San Remigio', 'total' => 336],
        ['psgc_code' => '0600615000', 'municipality' => 'Sebaste', 'total' => 85],
        ['psgc_code' => '0600616000', 'municipality' => 'Sibalom', 'total' => 775],
        ['psgc_code' => '0600617000', 'municipality' => 'Tibiao', 'total' => 392],
        ['psgc_code' => '0600618000', 'municipality' => 'Valderrama', 'total' => 73],
    ];
}

/** @return array<string, int> */
function aics_geo_historical_benefit_summary(): array
{
    return ['Medical Assistance' => 2760, 'Calamity Assistance' => 1125, 'Burial Assistance' => 712];
}

function aics_geo_ensure_historical_map_tables(mysqli $connection): void
{
    $connection->query('CREATE TABLE IF NOT EXISTS aics_historical_map_municipality_stats (municipality_psgc VARCHAR(12) NOT NULL PRIMARY KEY, municipality_name VARCHAR(120) NOT NULL, client_total INT UNSIGNED NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $connection->query('CREATE TABLE IF NOT EXISTS aics_historical_map_benefit_stats (benefit_label VARCHAR(80) NOT NULL PRIMARY KEY, client_total INT UNSIGNED NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $municipalityStatement = $connection->prepare('INSERT INTO aics_historical_map_municipality_stats (municipality_psgc, municipality_name, client_total) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE municipality_name = VALUES(municipality_name), client_total = VALUES(client_total)');
    if (!$municipalityStatement) throw new RuntimeException('Unable to seed historical municipality map statistics.');
    foreach (aics_geo_historical_municipality_seed() as $row) {
        $municipalityStatement->bind_param('ssi', $row['psgc_code'], $row['municipality'], $row['total']);
        $municipalityStatement->execute();
    }
    $municipalityStatement->close();
    $benefitStatement = $connection->prepare('INSERT INTO aics_historical_map_benefit_stats (benefit_label, client_total) VALUES (?, ?) ON DUPLICATE KEY UPDATE client_total = VALUES(client_total)');
    if (!$benefitStatement) throw new RuntimeException('Unable to seed historical benefit map statistics.');
    foreach (aics_geo_historical_benefit_summary() as $label => $total) {
        $benefitStatement->bind_param('si', $label, $total);
        $benefitStatement->execute();
    }
    $benefitStatement->close();
}

/** @return array<string, mixed> */
function aics_historical_application_geo_stats(mysqli $connection): array
{
    aics_geo_ensure_historical_map_tables($connection);
    $municipalities = [];
    $statement = $connection->prepare('SELECT municipality_psgc, municipality_name, client_total FROM aics_historical_map_municipality_stats ORDER BY client_total DESC, municipality_name ASC');
    if (!$statement) throw new RuntimeException('Unable to load historical municipality map statistics.');
    $statement->execute();
    $rows = $statement->get_result();
    while ($row = $rows->fetch_assoc()) {
        $municipality = aics_geo_normalize_municipality((string) $row['municipality_name']);
        $municipalities[$municipality] = aics_geo_empty_location_stats($municipality) + ['psgc_code' => (string) $row['municipality_psgc'], 'barangays' => [], 'historical' => true];
        $municipalities[$municipality]['total'] = (int) $row['client_total'];
    }
    $statement->close();
    $benefitSummary = [];
    $benefitStatement = $connection->prepare('SELECT benefit_label, client_total FROM aics_historical_map_benefit_stats ORDER BY FIELD(benefit_label, \'Medical Assistance\', \'Calamity Assistance\', \'Burial Assistance\')');
    if (!$benefitStatement) throw new RuntimeException('Unable to load historical benefit map statistics.');
    $benefitStatement->execute();
    $benefitRows = $benefitStatement->get_result();
    while ($row = $benefitRows->fetch_assoc()) $benefitSummary[(string) $row['benefit_label']] = (int) $row['client_total'];
    $benefitStatement->close();
    $total = array_sum(array_map(static fn(array $item): int => (int) $item['total'], $municipalities));
    if (count($municipalities) !== 18 || $total !== 4597 || array_sum($benefitSummary) !== 4597) throw new RuntimeException('Historical map aggregate validation failed.');
    return ['mode' => 'historical', 'assistance_types' => aics_geo_assistance_types(), 'municipalities' => $municipalities, 'benefit_summary' => $benefitSummary, 'summary' => ['total' => $total, 'top_municipality' => array_key_first($municipalities), 'unmatched_barangay' => 0]];
}

/**
 * The bundled Antique GeoJSON has geographic identifiers and area, but no
 * authoritative population values. This shared hook keeps a future vetted
 * municipality/barangay population join independent from request aggregation.
 */
function aics_geo_population_for_location(string $municipality, string $barangay = ''): ?int
{
    return null;
}

/** @return array<string, mixed> */
function aics_geo_empty_location_stats(string $municipality, string $barangay = ''): array
{
    return [
        'population' => aics_geo_population_for_location($municipality, $barangay),
        'total' => 0,
        'assistance_counts' => array_fill_keys(array_keys(aics_geo_assistance_types()), 0),
    ];
}

/**
 * Add one submitted application to the shared municipality/barangay map aggregate.
 *
 * @param array<string, array<string, mixed>> $municipalities
 * @param array<string, mixed> $row
 */
function aics_geo_accumulate_application_row(array &$municipalities, int &$total, int &$unmapped, array $row, string $selectedMunicipality = ''): void
{
    $municipality = aics_geo_normalize_municipality((string) ($row['municipality'] ?? ''));
    if ($selectedMunicipality !== '' && strcasecmp($municipality, $selectedMunicipality) !== 0) {
        return;
    }

    $total++;
    if ($municipality === '') {
        $unmapped++;
        return;
    }
    if (!isset($municipalities[$municipality])) {
        $municipalities[$municipality] = aics_geo_empty_location_stats($municipality) + ['barangays' => [], 'unmapped_barangay' => 0];
    }

    $assistanceTypes = json_decode((string) ($row['assistance_types_json'] ?? '[]'), true);
    if (!is_array($assistanceTypes)) {
        $assistanceTypes = [];
    }
    $barangayMatch = aics_geo_match_antique_barangay($municipality, (string) ($row['barangay'] ?? ''));
    if ($barangayMatch === null) {
        $municipalities[$municipality]['unmapped_barangay']++;
        return;
    }

    $barangay = $barangayMatch['name'];
    if (!isset($municipalities[$municipality]['barangays'][$barangay])) {
        $municipalities[$municipality]['barangays'][$barangay] = aics_geo_empty_location_stats($municipality, $barangay) + ['psgc_code' => $barangayMatch['psgc_code']];
    }
    $municipalities[$municipality]['barangays'][$barangay]['total']++;
    $municipalities[$municipality]['total']++;
    foreach ($assistanceTypes as $type) {
        $key = aics_geo_normalize_assistance_type((string) $type);
        if (array_key_exists($key, $municipalities[$municipality]['barangays'][$barangay]['assistance_counts'])) {
            $municipalities[$municipality]['barangays'][$barangay]['assistance_counts'][$key]++;
        }
        if (array_key_exists($key, $municipalities[$municipality]['assistance_counts'])) {
            $municipalities[$municipality]['assistance_counts'][$key]++;
        }
    }
}

/** Shared application-volume aggregation for public and PSWDO maps. */
function aics_submitted_application_geo_stats(mysqli $connection, array $filters = []): array
{
    $where = ["LOWER(TRIM(COALESCE(r.province, 'antique'))) = 'antique'", aics_ai_gate_visible_sql('r')];
    $types = '';
    $params = [];
    foreach (['status' => 'r.request_status'] as $input => $column) {
        $value = trim((string) ($filters[$input] ?? ''));
        if ($value !== '') {
            $where[] = "LOWER(TRIM($column)) = LOWER(?)";
            $types .= 's';
            $params[] = $value;
        }
    }
    foreach (['date_from' => '>=', 'date_to' => '<='] as $input => $operator) {
        $value = trim((string) ($filters[$input] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $where[] = "DATE(r.date_applied) $operator ?";
            $types .= 's';
            $params[] = $value;
        }
    }
    $yearStart = filter_var($filters['year_start'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2200]]);
    $yearEnd = filter_var($filters['year_end'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1900, 'max_range' => 2200]]);
    if ($yearStart !== false && $yearEnd !== false && $yearStart > $yearEnd) {
        [$yearStart, $yearEnd] = [$yearEnd, $yearStart];
    }
    if ($yearStart !== false) {
        $where[] = 'DATE(r.date_applied) >= ?';
        $types .= 's';
        $params[] = sprintf('%04d-01-01', $yearStart);
    }
    if ($yearEnd !== false) {
        $where[] = 'DATE(r.date_applied) <= ?';
        $types .= 's';
        $params[] = sprintf('%04d-12-31', $yearEnd);
    }
    $assistance = aics_geo_normalize_assistance_type((string) ($filters['assistance_type'] ?? ''));
    if ($assistance !== '' && array_key_exists($assistance, aics_geo_assistance_types())) {
        $where[] = "LOWER(COALESCE(r.assistance_types_json, '')) LIKE ?";
        $types .= 's';
        $params[] = '%' . $assistance . '%';
    }

    // The submitted application address is the canonical geographic snapshot;
    // beneficiary address fields remain separate and are not substituted here.
    $stmt = $connection->prepare('SELECT r.municipality, r.barangay, r.assistance_types_json FROM aics_requests r WHERE ' . implode(' AND ', $where));
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare map analytics.');
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result();
    $selectedMunicipality = aics_geo_normalize_municipality((string) ($filters['municipality'] ?? ''));
    $municipalities = [];
    $total = 0;
    $unmapped = 0;

    while ($row = $rows->fetch_assoc()) {
        aics_geo_accumulate_application_row($municipalities, $total, $unmapped, $row, $selectedMunicipality);
    }
    $stmt->close();
    uasort($municipalities, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
    $mappedTotal = array_sum(array_map(static fn(array $item): int => (int) ($item['total'] ?? 0), $municipalities));
    $unmappedBarangays = array_sum(array_map(static fn(array $item): int => (int) ($item['unmapped_barangay'] ?? 0), $municipalities));

    $yearStmt = $connection->prepare("SELECT DISTINCT YEAR(r.date_applied) AS application_year FROM aics_requests r WHERE LOWER(TRIM(COALESCE(r.province, 'antique'))) = 'antique' AND " . aics_ai_gate_visible_sql('r') . " AND r.date_applied IS NOT NULL AND r.date_applied <> '0000-00-00' ORDER BY application_year ASC");
    $availableYears = [];
    if ($yearStmt) {
        $yearStmt->execute();
        $yearRows = $yearStmt->get_result();
        while ($yearRow = $yearRows->fetch_assoc()) {
            $availableYears[] = (int) $yearRow['application_year'];
        }
        $yearStmt->close();
    }

    return [
        'assistance_types' => aics_geo_assistance_types(),
        'available_years' => $availableYears,
        'municipalities' => $municipalities,
        'summary' => [
            'total' => $mappedTotal,
            'source_total' => $total,
            'top_municipality' => array_key_first($municipalities) ?: null,
            'unmapped' => $unmapped + $unmappedBarangays,
            'unmapped_municipality' => $unmapped,
            'unmatched_barangay' => $unmappedBarangays,
        ],
    ];
}
