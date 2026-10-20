<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/geo-analytics.php';

/** @param mixed $coordinates */
function map_analytics_assert_coordinates(mixed $coordinates, string $source): void
{
    if (!is_array($coordinates)) {
        throw new RuntimeException($source . ' has invalid coordinates.');
    }

    if (isset($coordinates[0]) && is_numeric($coordinates[0])) {
        if (!isset($coordinates[1]) || !is_numeric($coordinates[1]) || abs((float) $coordinates[0]) > 180 || abs((float) $coordinates[1]) > 90) {
            throw new RuntimeException($source . ' contains an invalid longitude/latitude pair.');
        }
        return;
    }

    foreach ($coordinates as $child) {
        map_analytics_assert_coordinates($child, $source);
    }
}

/** @param array<string, mixed> $geometry */
function map_analytics_assert_polygon_geometry(array $geometry, string $source): void
{
    $type = $geometry['type'] ?? '';
    $polygons = $type === 'Polygon' ? [$geometry['coordinates'] ?? null] : ($type === 'MultiPolygon' ? ($geometry['coordinates'] ?? null) : null);
    if (!is_array($polygons)) {
        throw new RuntimeException($source . ' contains a non-polygon geometry.');
    }

    foreach ($polygons as $polygon) {
        if (!is_array($polygon) || $polygon === []) {
            throw new RuntimeException($source . ' contains an empty polygon.');
        }
        foreach ($polygon as $ring) {
            if (!is_array($ring) || count($ring) < 4 || $ring[0] !== $ring[array_key_last($ring)]) {
                throw new RuntimeException($source . ' contains an unclosed polygon ring.');
            }
        }
    }
}

$municipalityKeys = [];
$barangayKeys = [];
foreach (['antique-municipalities.geojson', 'antique-barangays.geojson'] as $fileName) {
    $path = __DIR__ . '/../../assets/data/' . $fileName;
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($payload['type'] ?? '') !== 'FeatureCollection' || !is_array($payload['features'] ?? null) || $payload['features'] === []) {
        throw new RuntimeException($fileName . ' is not a populated GeoJSON FeatureCollection.');
    }
    foreach ($payload['features'] as $feature) {
        $geometry = $feature['geometry'] ?? [];
        map_analytics_assert_polygon_geometry($geometry, $fileName);
        map_analytics_assert_coordinates($geometry['coordinates'] ?? null, $fileName);
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        if ($fileName === 'antique-municipalities.geojson') {
            $name = trim((string) ($properties['city_name'] ?? $properties['ADM3_EN'] ?? ''));
            $geographicId = trim((string) ($properties['psgc_10d'] ?? $properties['city_code'] ?? ''));
            $key = $geographicId !== '' ? strtolower($geographicId) : strtolower((string) preg_replace('/[^a-z0-9]/', '', $name));
            if ($key === '' || isset($municipalityKeys[$key])) {
                throw new RuntimeException('Municipality GeoJSON keys must be unique and non-empty.');
            }
            $municipalityKeys[$key] = true;
        } else {
            $municipality = trim((string) ($properties['ADM3_EN'] ?? ''));
            $barangay = trim((string) ($properties['ADM4_EN'] ?? $properties['brgy_name'] ?? ''));
            $geographicId = trim((string) ($properties['psgc_code'] ?? $properties['ADM4_PCODE'] ?? ''));
            $key = $geographicId !== ''
                ? strtolower($municipality) . ':' . strtolower($geographicId)
                : strtolower((string) preg_replace('/[^a-z0-9:]/', '', $municipality . ':' . $barangay));
            if ($key === '' || isset($barangayKeys[$key])) {
                throw new RuntimeException('Barangay GeoJSON keys must be unique within their municipality identity.');
            }
            $barangayKeys[$key] = true;
        }
    }
}
$indexedBarangayCount = array_sum(array_map('count', aics_geo_antique_barangay_index()));
if ($indexedBarangayCount !== count($barangayKeys)) {
    throw new RuntimeException('Official barangay normalization keys must not collapse distinct PSGC records.');
}

if (aics_geo_normalize_municipality('Municipality of San Jose (Dao)') !== 'San Jose de Buenavista') {
    throw new RuntimeException('Map municipality normalization is inconsistent.');
}
$poblacionMatch = aics_geo_match_antique_barangay('HAMTIC', ' Brgy. POBLACION 4 ');
if (($poblacionMatch['name'] ?? '') !== 'Poblacion 4' || ($poblacionMatch['psgc_code'] ?? '') !== '0600608048') {
    throw new RuntimeException('Barangay address normalization must resolve to the official Antique PSGC identity.');
}
$bongbonganTwo = aics_geo_match_antique_barangay('Hamtic', 'Bongbongan I-II');
$bongbonganThree = aics_geo_match_antique_barangay('Hamtic', 'Bongbongan III');
if (($bongbonganTwo['psgc_code'] ?? '') === ($bongbonganThree['psgc_code'] ?? '')) {
    throw new RuntimeException('Barangay normalization must preserve distinct official PSGC identities.');
}

$aggregatedMunicipalities = [];
$aggregateTotal = 0;
$aggregateUnmapped = 0;
foreach ([
    ['municipality' => 'Hamtic', 'barangay' => 'Brgy. Poblacion 4', 'assistance_types_json' => '["medical"]'],
    ['municipality' => 'Hamtic', 'barangay' => 'Poblacion 4', 'assistance_types_json' => '["medical"]'],
    ['municipality' => 'Hamtic', 'barangay' => 'Bongbongan III', 'assistance_types_json' => '["burial"]'],
    ['municipality' => 'Hamtic', 'barangay' => 'Unknown Barangay', 'assistance_types_json' => '[]'],
] as $row) {
    aics_geo_accumulate_application_row($aggregatedMunicipalities, $aggregateTotal, $aggregateUnmapped, $row);
}
$hamtic = $aggregatedMunicipalities['Hamtic'] ?? [];
if ($aggregateTotal !== 4 || $aggregateUnmapped !== 0 || (int) ($hamtic['unmapped_barangay'] ?? 0) !== 1) {
    throw new RuntimeException('Unmatched barangays must be tracked without being assigned to a map location.');
}
if ((int) ($hamtic['barangays']['Poblacion 4']['total'] ?? 0) !== 2 || (int) ($hamtic['barangays']['Bongbongan III']['total'] ?? 0) !== 1) {
    throw new RuntimeException('Matched applications must aggregate to their exact official barangay identities.');
}
if ((int) ($hamtic['total'] ?? 0) !== 3 || array_sum(array_map(static fn(array $barangay): int => (int) $barangay['total'], $hamtic['barangays'] ?? [])) !== 3) {
    throw new RuntimeException('Municipality totals must equal the sum of matched barangay totals.');
}

$mapScript = (string) file_get_contents(__DIR__ . '/../Pswdo/js files/map-analytics.js');
$publicMapScript = (string) file_get_contents(__DIR__ . '/../../assets/js/public-antique-map.js');
$sharedHeatmapScript = (string) file_get_contents(__DIR__ . '/../../assets/js/aics-heatmap.js');
$publicMapEndpoint = (string) file_get_contents(__DIR__ . '/../public-map-analytics.php');
$landingPage = (string) file_get_contents(__DIR__ . '/../../landingpage.php');
$historicalMunicipalities = aics_geo_historical_municipality_seed();
$historicalByName = [];
foreach ($historicalMunicipalities as $item) $historicalByName[$item['municipality']] = $item;
if (count($historicalMunicipalities) !== 18 || array_sum(array_column($historicalMunicipalities, 'total')) !== 4597 || (int) ($historicalByName['Laua-An']['total'] ?? 0) !== 121) {
    throw new RuntimeException('Historical municipality aggregate must contain the verified 18-municipality total of 4,597.');
}
if (array_sum(aics_geo_historical_benefit_summary()) !== 4597 || aics_geo_historical_benefit_summary() !== ['Medical Assistance' => 2760, 'Calamity Assistance' => 1125, 'Burial Assistance' => 712]) {
    throw new RuntimeException('Historical benefit summary must preserve the verified 4,597-client totals.');
}
if (($historicalByName['San Jose de Buenavista']['psgc_code'] ?? '') !== '0600613000') {
    throw new RuntimeException('Historical San Jose aggregate must use the official San Jose de Buenavista PSGC identity.');
}
foreach (["'rgba(0,0,0,0)'", "'rgba(34,197,94,.34)'", "'#22c55e'", "'#eab308'", "'#f97316'", "'#dc2626'"] as $expectedHeatmapColor) {
    if (!str_contains($sharedHeatmapScript, $expectedHeatmapColor)) {
        throw new RuntimeException('The shared heatmap must retain the transparent-to-red density gradient.');
    }
}
if (str_contains($sharedHeatmapScript, '56,189,248') || str_contains($sharedHeatmapScript, '#06b6d4')) {
    throw new RuntimeException('The shared heatmap gradient must not include cyan.');
}
if (!str_contains($mapScript, 'AICS_HEATMAP_PAINT') || !str_contains($mapScript, 'buildAicsBarangayHeatmap') || !str_contains($mapScript, 'buildAicsMunicipalityHeatmap') || !str_contains($publicMapScript, 'AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT') || !str_contains($publicMapScript, 'buildAicsMunicipalityHeatmap')) {
    throw new RuntimeException('PSWDO and public maps must reuse the shared historical municipality heatmap implementation.');
}
if (!str_contains($publicMapEndpoint, 'aics_historical_application_geo_stats(aics_db_connect())') || !str_contains($mapScript, "payload.mode === 'historical'")) {
    throw new RuntimeException('The public map and default PSWDO overview must use historical municipality aggregates.');
}
foreach (['id="map-search"', 'id="map-back"', 'id="map-notice"'] as $removedPublicControl) {
    if (str_contains($landingPage, $removedPublicControl)) {
        throw new RuntimeException('Landing-page map controls must remain removed.');
    }
}
if (str_contains($publicMapScript, "map.on('click'")) {
    throw new RuntimeException('The public map must remain a simple hover-only concentration view.');
}
if (!str_contains($mapScript, 'aicsConcentrationCategory(applicationCount)') || !str_contains($publicMapScript, 'aicsConcentrationCategory(total)') || !str_contains($mapScript, 'application_count: applicationCount')) {
    throw new RuntimeException('PSWDO and public concentration categories must use the shared fixed count bands.');
}
if (!str_contains($sharedHeatmapScript, 'return point && count > 0')) {
    throw new RuntimeException('Zero-count barangays must not create heatmap hotspots.');
}
if (!str_contains($sharedHeatmapScript, 'export function aicsConcentrationCategory(count)')
    || !str_contains($sharedHeatmapScript, 'count <= 250')
    || !str_contains($sharedHeatmapScript, 'count <= 500')) {
    throw new RuntimeException('Shared concentration classification must use zero, 1-250, 251-500, and 501+.');
}
foreach (["municipalityFill: 'aics-municipality-fill'", "municipalityLine: 'aics-municipality-line'", "municipalityLabel: 'aics-municipality-label'", "barangayFill: 'aics-barangay-fill'", "barangayLine: 'aics-barangay-line'"] as $expectedLayer) {
    if (!str_contains($mapScript, $expectedLayer)) {
        throw new RuntimeException('Map source/layer IDs must remain centralized and consistent.');
    }
}
if (!str_contains($mapScript, "['feature-state', 'hover']") || !str_contains($mapScript, ".setFeatureState(hoveredFeature, { hover: true })")) {
    throw new RuntimeException('Visible hover paint and feature-state must use the same state property.');
}
if (str_contains($mapScript, 'layout: type ===') || str_contains($mapScript, 'layout: undefined')) {
    throw new RuntimeException('Fill layers must be created with explicit valid MapLibre layer definitions.');
}
if (!str_contains($mapScript, "heatmap: 'aics-barangay-heatmap'") || !str_contains($mapScript, "type: 'heatmap'") || !str_contains($sharedHeatmapScript, 'heatmap_weight:')) {
    throw new RuntimeException('A weighted barangay aggregate heatmap source and layer are required.');
}
if (!str_contains($sharedHeatmapScript, 'return point && count > 0') || !str_contains($sharedHeatmapScript, "window.AntiqueMap.representativePoint(feature)")) {
    throw new RuntimeException('Only populated barangays may create representative aggregate heatmap points.');
}
foreach (['historicalHeatmapSampleCount', 'coverageSamples', 'sampleAllocation', 'pointInPolygon', 'pointInFeature', 'client_weight', 'heat_weight', 'buildAicsMunicipalityHeatmap'] as $requiredHistoricalHeatmapPart) {
    if (!str_contains($sharedHeatmapScript, $requiredHistoricalHeatmapPart)) {
        throw new RuntimeException('Historical municipality heatmap must use deterministic, weighted interior samples.');
    }
}
foreach (['AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT', 'historicalMunicipalityHeatmapWeight', 'count <= 250', 'count <= 500'] as $requiredHistoricalBandPart) {
    if (!str_contains($sharedHeatmapScript, $requiredHistoricalBandPart)) {
        throw new RuntimeException('Historical municipality heatmap must expose the fixed low, medium, and high ranges.');
    }
}
foreach ([['Low', '(1-250)'], ['Medium', '(251-500)'], ['High', '(501+)']] as [$bandName, $bandRange]) {
    if (!str_contains($sharedHeatmapScript, "['$bandName', '$bandRange']") || !str_contains($landingPage, "$bandName $bandRange")) {
        throw new RuntimeException('Public map legends must show the temporary count bands.');
    }
}
if (!str_contains($mapScript, 'historicalMode ? AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT : AICS_HEATMAP_PAINT') || !str_contains($publicMapScript, 'AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT')) {
    throw new RuntimeException('Fixed historical municipality scaling must not replace live transactional heatmap scaling.');
}
if (!str_contains($sharedHeatmapScript, "'heatmap-weight': ['get', 'heat_weight']") || !str_contains($sharedHeatmapScript, "heat_weight: heatWeight") || str_contains($sharedHeatmapScript, 'heatmap_weight: municipalityWeight * (clientWeights[index] / item.count)')) {
    throw new RuntimeException('Historical MapLibre heat must use a separate municipality-level heat_weight rather than a diluted client-weight fraction.');
}
foreach ([".52, '#eab308'", ".72, '#f97316'", ".94, '#dc2626'"] as $historicalColorStop) {
    if (!str_contains($sharedHeatmapScript, $historicalColorStop)) {
        throw new RuntimeException('Historical heat colors must keep reachable yellow, orange, and red density stops.');
    }
}
if (!str_contains($sharedHeatmapScript, 'Math.min(160, Math.max(Math.ceil(Math.sqrt(count) * 2), Math.ceil(area / .00014)))') || !str_contains($sharedHeatmapScript, 'candidateTarget = Math.max(count * 3, 24)')) {
    throw new RuntimeException('Historical sample density must account for both municipality area and application volume with a deterministic coverage grid.');
}
if (!str_contains($mapScript, "className = 'map-popup-title'") || !str_contains($mapScript, "className = 'map-popup-row'") || !str_contains($publicMapScript, "className = 'map-popup-title'") || !str_contains($publicMapScript, "className = assistance.className = 'map-popup-row'")) {
    throw new RuntimeException('Municipality and barangay hover popups must use separated title and data-row markup.');
}
if (!str_contains($sharedHeatmapScript, "'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 8, 28, 10, 32, 12, 38, 14, 44, 16, 50, 18, 56]") || !str_contains($sharedHeatmapScript, "'heatmap-opacity': ['interpolate', ['linear'], ['zoom'], 8, .88, 10, .86, 12, .84, 14, .82, 16, .8, 18, .78]") || !str_contains($sharedHeatmapScript, "'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 8, .68, 10, .74, 12, .8, 14, .86, 16, .92, 18, .98]")) {
    throw new RuntimeException('Historical heatmap must retain sustained high-zoom radius, intensity, and opacity.');
}
if (str_contains($sharedHeatmapScript, "'heatmap-opacity': ['interpolate', ['linear'], ['zoom'], 8, .9, 10, .88, 12, .86, 14, .84, 16, .82, 18, 0]")) {
    throw new RuntimeException('Historical heatmap must not fade to zero opacity at high zoom.');
}
if (!str_contains($mapScript, 'if (!feature || historicalMode) return;') || !str_contains($mapScript, 'if (!historicalMode) await ensureBarangays();')) {
    throw new RuntimeException('Historical municipality aggregates must not be assigned to barangay drill-downs.');
}
if (!str_contains($sharedHeatmapScript, 'return historicalMunicipalityHeatmapWeight(count);')
    || !str_contains($sharedHeatmapScript, 'heatmap_weight: aicsHeatmapWeight(item.count)')) {
    throw new RuntimeException('Live heatmap weights must use the same absolute count bands as historical weights.');
}

echo "Map analytics GeoJSON checks passed.\n";
