import './shared-antique-map.js';

export const AICS_HEATMAP_PAINT = {
  'heatmap-weight': ['interpolate', ['linear'], ['get', 'heatmap_weight'], 0, 0, .08, .12, .3, .42, .6, .72, 1, 1],
  'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 8, .62, 10, .84, 13, 1],
  'heatmap-color': ['interpolate', ['linear'], ['heatmap-density'], 0, 'rgba(0,0,0,0)', .14, 'rgba(34,197,94,.34)', .32, '#22c55e', .52, '#eab308', .72, '#f97316', .9, '#dc2626'],
  'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 8, 30, 10, 24, 12, 18, 14, 12],
  'heatmap-opacity': ['interpolate', ['linear'], ['zoom'], 8, .88, 12, .82, 14, .7]
};

/** Temporary count ranges shared by the historical and live concentration maps. */
export const AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT = {
  // Historical samples retain their fractional client_weight for accounting,
  // but all samples in a municipality share its aggregate visual heat weight.
  'heatmap-weight': ['get', 'heat_weight'],
  'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 8, .68, 10, .74, 12, .8, 14, .86, 16, .92, 18, .98],
  'heatmap-color': ['interpolate', ['linear'], ['heatmap-density'], 0, 'rgba(0,0,0,0)', .12, 'rgba(34,197,94,.34)', .3, '#22c55e', .52, '#eab308', .72, '#f97316', .94, '#dc2626'],
  'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 8, 28, 10, 32, 12, 38, 14, 44, 16, 50, 18, 56],
  'heatmap-opacity': ['interpolate', ['linear'], ['zoom'], 8, .88, 10, .86, 12, .84, 14, .82, 16, .8, 18, .78]
};

export function aicsHeatmapWeight(count) {
  return historicalMunicipalityHeatmapWeight(count);
}
export function aicsConcentrationCategory(count) {
  count = Number(count);
  if (!Number.isFinite(count) || count <= 0) return 0;
  if (count <= 250) return 1;
  if (count <= 500) return 2;
  return 3;
}
/**
 * A target is driven by both the municipality's aggregate volume and its mapped
 * footprint.  The footprint component prevents a large municipality from being
 * represented by only a handful of points at close zoom.
 */
export const historicalHeatmapSampleCount = (count, area = 0) => count > 0 ? Math.max(12, Math.min(160, Math.max(Math.ceil(Math.sqrt(count) * 2), Math.ceil(area / .00014)))) : 0;
export const historicalMunicipalityHeatmapWeight = count => {
  const category = aicsConcentrationCategory(count);
  if (!category) return 0;
  if (category === 1) return .08 + (.18 * (count / 250));
  if (category === 2) return .26 + (.33 * ((count - 250) / 250));
  return .6 + (.4 * Math.min(1, (count - 500) / 275));
};
/** Moderate overlapping grid samples while retaining total-based historical bands. */
export const historicalSampleHeatWeight = (count, sampleCount) => historicalMunicipalityHeatmapWeight(count) / Math.sqrt(Math.max(1, sampleCount));

const hashSeed = value => { let hash = 2166136261; for (const character of String(value)) { hash ^= character.charCodeAt(0); hash = Math.imul(hash, 16777619); } return hash >>> 0; };
const ringArea = ring => Math.abs((ring || []).slice(1).reduce((area, point, index) => area + ((ring[index][0] * point[1]) - (point[0] * ring[index][1])), 0) / 2);
const polygonArea = polygon => Math.max(0, ringArea(polygon?.[0]) - (polygon || []).slice(1).reduce((sum, ring) => sum + ringArea(ring), 0));
const polygonsArea = polygons => (polygons || []).reduce((sum, polygon) => sum + polygonArea(polygon), 0);
const polygonBounds = polygon => { const points = (polygon || []).flat(); return points.length ? [[Math.min(...points.map(point => point[0])), Math.min(...points.map(point => point[1]))], [Math.max(...points.map(point => point[0])), Math.max(...points.map(point => point[1]))]] : null; };
const ringContains = (ring, point) => { let inside = false; for (let index = 0, previous = ring.length - 1; index < ring.length; previous = index++) { const [x, y] = ring[index], [previousX, previousY] = ring[previous]; if ((y > point[1]) !== (previousY > point[1]) && point[0] < ((previousX - x) * (point[1] - y)) / (previousY - y) + x) inside = !inside; } return inside; };
export const pointInPolygon = (polygon, point) => ringContains(polygon?.[0] || [], point) && !(polygon || []).slice(1).some(ring => ringContains(ring, point));
export const pointInFeature = (feature, point) => { const geometry = feature?.geometry, polygons = geometry?.type === 'Polygon' ? [geometry.coordinates] : geometry?.type === 'MultiPolygon' ? geometry.coordinates : []; return polygons.some(polygon => pointInPolygon(polygon, point)); };
const interiorFallback = polygon => { const bounds = polygonBounds(polygon); if (!bounds) return null; const [[west, south], [east, north]] = bounds; for (let row = 1; row < 20; row += 1) for (let column = 1; column < 20; column += 1) { const point = [west + ((east - west) * column) / 20, south + ((north - south) * row) / 20]; if (pointInPolygon(polygon, point)) return point; } return null; };
const coverageSamples = (polygon, count, seed) => {
  const bounds = polygonBounds(polygon);
  if (!bounds || count <= 0) return [];
  const [[west, south], [east, north]] = bounds, width = east - west, height = north - south;
  if (width <= 0 || height <= 0) return [];
  const aspect = Math.max(.2, Math.min(5, width / height)), candidateTarget = Math.max(count * 3, 24);
  let columns = Math.max(1, Math.ceil(Math.sqrt(candidateTarget * aspect))), rows = Math.max(1, Math.ceil(candidateTarget / columns)), candidates = [];
  for (let pass = 0; candidates.length < count && pass < 4; pass += 1) {
    candidates = [];
    for (let row = 0; row < rows; row += 1) {
      const stagger = ((row + (seed % 2)) % 2) * .5;
      for (let column = 0; column < columns; column += 1) {
        const normalizedX = (column + .5 + stagger) / columns;
        if (normalizedX >= 1) continue;
        const point = [west + (width * normalizedX), south + (height * ((row + .5) / rows))];
        if (pointInPolygon(polygon, point)) candidates.push(point);
      }
    }
    rows *= 2;
    columns *= 2;
  }
  if (candidates.length <= count) {
    const fallback = interiorFallback(polygon);
    while (fallback && candidates.length < count) candidates.push([...fallback]);
    return candidates;
  }
  const offset = (seed % 100000) / 100000, goldenRatio = .61803398875;
  return candidates.map((point, index) => ({ point, rank: ((index * goldenRatio) + offset) % 1 })).sort((left, right) => left.rank - right.rank).slice(0, count).map(item => item.point);
};
const sampleAllocation = (polygons, count) => {
  const areas = polygons.map(polygonArea), valid = areas.map((area, index) => ({ area, index })).filter(item => item.area > 0);
  if (!valid.length || count <= 0) return [];
  const basePerPolygon = count >= valid.length ? 1 : 0, remainingTarget = count - (basePerPolygon * valid.length), totalArea = valid.reduce((sum, item) => sum + item.area, 0), quotas = valid.map(item => ({ ...item, exact: remainingTarget * (item.area / totalArea), count: basePerPolygon }));
  quotas.forEach(item => { item.count += Math.floor(item.exact); });
  let remaining = count - quotas.reduce((sum, item) => sum + item.count, 0);
  quotas.sort((left, right) => (right.exact - Math.floor(right.exact)) - (left.exact - Math.floor(left.exact)) || right.area - left.area).forEach(item => { if (remaining > 0) { item.count += 1; remaining -= 1; } });
  return quotas.filter(item => item.count > 0).sort((left, right) => left.index - right.index);
};
const distributeClientWeight = (total, count) => Array.from({ length: count }, (_, index) => Math.floor(total / count) + (index < total % count ? 1 : 0));

export function buildAicsBarangayHeatmap(features, aggregateForFeature) {
  const aggregates = features.map(feature => { const municipality = window.AntiqueMap.municipalityName(feature.properties) || feature.properties?.ADM3_EN || '', barangay = feature.properties?.ADM4_EN || feature.properties?.brgy_name || 'Barangay', psgc = String(feature.properties?.psgc_code || feature.properties?.ADM4_PCODE || ''), count = Number(aggregateForFeature({ municipality, barangay, psgc, feature })?.total || 0), point = window.AntiqueMap.representativePoint(feature); return point && count > 0 ? { municipality, barangay, psgc, count, point } : null; }).filter(Boolean);
  const max = Math.max(0, ...aggregates.map(item => item.count)), points = aggregates.map(item => ({ type: 'Feature', id: `${window.AntiqueMap.normalize(item.municipality)}:${item.psgc}`, properties: { municipality: item.municipality, barangay: item.barangay, psgc: item.psgc, application_count: item.count, heatmap_weight: aicsHeatmapWeight(item.count) }, geometry: { type: 'Point', coordinates: item.point } }));
  return { collection: { type: 'FeatureCollection', features: points }, summary: { total: aggregates.reduce((sum, item) => sum + item.count, 0), locations: aggregates.length, municipalities: new Set(aggregates.map(item => window.AntiqueMap.normalize(item.municipality))).size, max } };
}

/** Municipality aggregate visualization samples; coordinates are synthetic and deterministic. */
export function buildAicsMunicipalityHeatmap(features, aggregateForFeature) {
  const aggregates = features.map(feature => { const municipality = window.AntiqueMap.municipalityName(feature.properties), psgc = String(feature.properties?.psgc_10d || feature.properties?.city_code || ''), count = Number(aggregateForFeature({ municipality, psgc, feature })?.total || 0), geometry = feature?.geometry, polygons = geometry?.type === 'Polygon' ? [geometry.coordinates] : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [], area = polygonsArea(polygons); return municipality && psgc && count > 0 && polygons.length && area > 0 ? { municipality, psgc, count, polygons, area } : null; }).filter(Boolean);
  const max = Math.max(0, ...aggregates.map(item => item.count));
  const points = aggregates.flatMap(item => { const sampleCount = historicalHeatmapSampleCount(item.count, item.area), seed = hashSeed(`${item.psgc}:${item.municipality}:${item.count}`), coordinates = sampleAllocation(item.polygons, sampleCount).flatMap(allocation => coverageSamples(item.polygons[allocation.index], allocation.count, seed + allocation.index)), fallback = interiorFallback(item.polygons[0]), safeCoordinates = coordinates.length ? coordinates : fallback ? [fallback] : [], clientWeights = distributeClientWeight(item.count, safeCoordinates.length), heatWeight = historicalSampleHeatWeight(item.count, safeCoordinates.length); return safeCoordinates.map((point, index) => ({ type: 'Feature', id: `${item.psgc}:${index}`, properties: { municipality: item.municipality, psgc: item.psgc, application_count: item.count, client_weight: clientWeights[index], heat_weight: heatWeight, synthetic_sample: true }, geometry: { type: 'Point', coordinates: point } })); });
  return { collection: { type: 'FeatureCollection', features: points }, summary: { total: aggregates.reduce((sum, item) => sum + item.count, 0), locations: aggregates.length, municipalities: aggregates.length, samples: points.length, max } };
}

export function renderAicsHeatmapLegend(element, summary, options = {}) {
  if (!element) return;
  const scale = document.createElement('div'), labels = document.createElement('div'), ranges = document.createElement('div'), meta = document.createElement('small');
  const bands = [['Low', '(1-250)'], ['Medium', '(251-500)'], ['High', '(501+)']];
  scale.className = 'map-heatmap-scale';
  labels.className = 'map-heatmap-scale-labels';
  ranges.className = 'map-heatmap-scale-ranges';
  labels.append(...bands.map(([name]) => Object.assign(document.createElement('span'), { textContent: name })));
  ranges.append(...bands.map(([, range]) => Object.assign(document.createElement('span'), { textContent: range })));
  const benefitSummary = options.benefitSummary && typeof options.benefitSummary === 'object' ? Object.entries(options.benefitSummary) : [], parts = benefitSummary.length ? [...benefitSummary.map(([label, count]) => `${label}: ${Number(count).toLocaleString()}`), `Total Applicants: ${Number(summary.total || 0).toLocaleString()}`] : [`Total applications: ${Number(summary.total || 0)}`, `Barangays with applications: ${Number(summary.locations || 0)}`, `Municipalities with applications: ${Number(summary.municipalities || 0)}`];
  if (!benefitSummary.length && options.unmatchedCount > 0) parts.push(`Unmatched barangay records: ${Number(options.unmatchedCount)}`);
  meta.replaceChildren(...parts.flatMap((text, index) => index ? [document.createElement('br'), document.createTextNode(text)] : [document.createTextNode(text)]));
  element.replaceChildren(Object.assign(document.createElement('strong'), { textContent: 'AICS APPLICATION CONCENTRATION' }), labels, scale, ranges, meta);
}
