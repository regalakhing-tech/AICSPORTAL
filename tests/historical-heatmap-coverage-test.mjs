import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

globalThis.window = {
  AntiqueMap: {
    municipalityName: properties => properties?.city_name === 'San Jose' ? 'San Jose de Buenavista' : properties?.city_name || '',
    normalize: value => String(value || '').toLowerCase()
  }
};

const { AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT, aicsConcentrationCategory, aicsHeatmapWeight, buildAicsMunicipalityHeatmap, historicalMunicipalityHeatmapWeight, historicalSampleHeatWeight, pointInFeature } = await import('../../assets/js/aics-heatmap.js');
for (const [count, expected] of [[0, 0], [1, 1], [250, 1], [251, 2], [500, 2], [501, 3], [1000, 3]]) {
  assert.equal(aicsConcentrationCategory(count), expected, `${count} clients must use the temporary concentration band ${expected}.`);
  assert.equal(aicsHeatmapWeight(count), historicalMunicipalityHeatmapWeight(count), `${count} clients must use the same fixed heat weight in live and historical maps.`);
}
const municipalities = JSON.parse(await readFile(new URL('../../assets/data/antique-municipalities.geojson', import.meta.url), 'utf8')).features;
const totals = new Map([
  ['Anini-Y', 91], ['Barbaza', 235], ['Belison', 111], ['Bugasong', 195], ['Caluya', 25], ['Culasi', 687], ['Tobias Fornier', 205], ['Hamtic', 181], ['Laua-An', 121], ['Libertad', 81], ['Pandan', 68], ['Patnongon', 390], ['San Jose de Buenavista', 546], ['San Remigio', 336], ['Sebaste', 85], ['Sibalom', 775], ['Tibiao', 392], ['Valderrama', 73]
]);
const aggregateForFeature = ({ municipality }) => ({ total: totals.get(municipality) || 0 });
const first = buildAicsMunicipalityHeatmap(municipalities, aggregateForFeature);
const second = buildAicsMunicipalityHeatmap(municipalities, aggregateForFeature);

assert.equal(first.summary.total, 4597, 'Historical aggregate total must remain 4,597.');
assert.equal(first.collection.features.reduce((sum, point) => sum + point.properties.client_weight, 0), 4597, 'Visualization sample weights must sum exactly to the historical total.');
assert.deepEqual(first.collection.features.map(point => point.geometry.coordinates), second.collection.features.map(point => point.geometry.coordinates), 'Coverage samples must be deterministic.');

const featuresByPsgc = new Map(municipalities.map(feature => [String(feature.properties.psgc_10d), feature]));
const samplesByMunicipality = new Map();
for (const point of first.collection.features) {
  const samples = samplesByMunicipality.get(point.properties.municipality) || [];
  samples.push(point);
  samplesByMunicipality.set(point.properties.municipality, samples);
  assert.equal(pointInFeature(featuresByPsgc.get(point.properties.psgc), point.geometry.coordinates), true, `${point.properties.municipality} sample must remain inside its official polygon.`);
}
for (const [municipality, total] of totals) {
  assert.equal((samplesByMunicipality.get(municipality) || []).reduce((sum, point) => sum + point.properties.client_weight, 0), total, `${municipality} sample weights must preserve its historical aggregate.`);
}
const visualWeight = municipality => (samplesByMunicipality.get(municipality) || []).map(point => point.properties.heat_weight);
for (const municipality of totals.keys()) assert.equal(new Set(visualWeight(municipality)).size, 1, `${municipality} visual heat weight must not be diluted across its samples.`);
assert.ok(['Sibalom', 'Culasi', 'San Jose de Buenavista', 'Patnongon', 'Hamtic', 'Caluya'].every(municipality => visualWeight(municipality)[0] > 0 && visualWeight(municipality)[0] <= 1), 'Historical heat weights must remain in the controlled 0–1 range.');
assert.ok(historicalMunicipalityHeatmapWeight(775) > historicalMunicipalityHeatmapWeight(687) && historicalMunicipalityHeatmapWeight(687) > historicalMunicipalityHeatmapWeight(546) && historicalMunicipalityHeatmapWeight(546) > historicalMunicipalityHeatmapWeight(390) && historicalMunicipalityHeatmapWeight(390) > historicalMunicipalityHeatmapWeight(181) && historicalMunicipalityHeatmapWeight(181) > historicalMunicipalityHeatmapWeight(25), 'Historical municipality target strengths must increase from Caluya through Sibalom.');
assert.equal(historicalMunicipalityHeatmapWeight(775), 1, 'The strongest historical municipality must be able to reach the maximum target density.');
const visualStrength = municipality => (samplesByMunicipality.get(municipality) || []).reduce((sum, point) => sum + point.properties.heat_weight, 0);
assert.ok(visualStrength('Sibalom') > visualStrength('Culasi') && visualStrength('Culasi') > visualStrength('San Jose de Buenavista') && visualStrength('San Jose de Buenavista') > visualStrength('Patnongon') && visualStrength('Patnongon') > visualStrength('Hamtic') && visualStrength('Hamtic') > visualStrength('Caluya'), 'Density-compensated historical visual strength must retain the intended municipality ordering.');
assert.ok(visualWeight('Caluya')[0] < visualWeight('Sibalom')[0], 'Caluya must not receive maximum visual heat weight.');
assert.equal(AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT['heatmap-weight'][1], 'heat_weight', 'Historical MapLibre paint must read heat_weight.');
assert.ok(['#eab308', '#f97316', '#dc2626'].every(color => AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT['heatmap-color'].includes(color)), 'Historical color ramp must retain reachable yellow, orange, and red stops.');
assert.equal(AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT['heatmap-color'].at(-2), .94, 'Historical color ramp must reserve red for the upper density range.');
assert.equal(AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT['heatmap-radius'].at(-1), 56, 'Historical close-zoom radius must remain localized and nonzero.');
assert.ok(AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT['heatmap-intensity'].at(-1) > 0, 'Historical close-zoom intensity must keep heat visible.');

const sameCountDifferentCoverage = (area, psgc) => ({ type: 'Feature', properties: { city_name: 'Coverage Test', psgc_10d: psgc }, geometry: { type: 'Polygon', coordinates: [[[122, 10], [122 + area, 10], [122 + area, 10 + area], [122, 10 + area], [122, 10]]] } });
const sparseCoverage = buildAicsMunicipalityHeatmap([sameCountDifferentCoverage(.01, 'coverage-small')], () => ({ total: 390 })).collection.features;
const denseCoverage = buildAicsMunicipalityHeatmap([sameCountDifferentCoverage(.1, 'coverage-large')], () => ({ total: 390 })).collection.features;
assert.notEqual(sparseCoverage.length, denseCoverage.length, 'Coverage fixture must produce different sample counts.');
assert.ok(sparseCoverage[0].properties.heat_weight > denseCoverage[0].properties.heat_weight, 'More overlapping samples must receive moderated visual contribution.');
assert.ok(sparseCoverage.every(point => point.properties.heat_weight === historicalSampleHeatWeight(390, sparseCoverage.length)) && denseCoverage.every(point => point.properties.heat_weight === historicalSampleHeatWeight(390, denseCoverage.length)), 'Sample-density compensation must use the generated source count.');

for (const [municipality, minimum] of [['Sibalom', 100], ['Culasi', 100], ['San Jose de Buenavista', 47], ['Patnongon', 40]]) {
  assert.ok((samplesByMunicipality.get(municipality) || []).length >= minimum, `${municipality} must receive dense close-zoom coverage.`);
}
assert.ok((samplesByMunicipality.get('Caluya') || []).length >= 35, 'Caluya multipolygon coverage must include its island geometry.');

const diagnostics = Object.fromEntries(['Caluya', 'Hamtic', 'Patnongon', 'San Jose de Buenavista', 'Culasi', 'Sibalom'].map(municipality => {
  const points = samplesByMunicipality.get(municipality) || [], clientWeights = points.map(point => point.properties.client_weight), heatWeights = points.map(point => point.properties.heat_weight), average = values => values.reduce((sum, value) => sum + value, 0) / values.length;
  return [municipality, { total: totals.get(municipality), samples: points.length, client_weight: { average: Number(average(clientWeights).toFixed(6)), min: Math.min(...clientWeights), max: Math.max(...clientWeights) }, heat_weight: { average: Number(average(heatWeights).toFixed(6)), min: Math.min(...heatWeights), max: Math.max(...heatWeights) }, visual_strength: Number(heatWeights.reduce((sum, value) => sum + value, 0).toFixed(6)) }];
}));
console.log(`Historical visual-weight checks passed: ${first.collection.features.length} source features; ${JSON.stringify(diagnostics)}; client-weight total 4597.`);
