/* Shared MapLibre helpers for AICS public and authenticated Antique maps. */
import * as maplibregl from '../vendor/maplibre-gl/maplibre-gl.mjs';


const normalize = value => {
  const cleaned = String(value || '').toLowerCase().replace(/\b(?:municipality|city)\s+of\b/g, '').replace(/\(.*?\)/g, '').replace(/[^a-z0-9]/g, '');
  return cleaned === 'sanjose' ? 'sanjosedebuenavista' : cleaned;
};
const municipalityName = properties => {
  const name = String(properties.city_name || properties.ADM3_EN || '').replace(/^\s*(?:municipality|city)\s+of\s+/i, '').replace(/\s*\(Dao\)\s*$/i, '').trim();
  return normalize(name) === 'sanjosedebuenavista' ? 'San Jose de Buenavista' : name;
};
const isAntiqueMunicipality = feature => normalize(feature.properties?.prov_name) === 'antique';
const isAntiqueBarangay = feature => normalize(feature.properties?.ADM2_EN || feature.properties?.prov_name) === 'antique';
const coordinatesFor = geometry => geometry?.type === 'Polygon' ? geometry.coordinates.flat(1) : geometry?.type === 'MultiPolygon' ? geometry.coordinates.flat(2) : [];
const boundsFor = feature => {
  const coordinates = coordinatesFor(feature?.geometry);
  if (!coordinates.length) return null;
  let west = Infinity, east = -Infinity, south = Infinity, north = -Infinity;
  coordinates.forEach(([lng, lat]) => { west = Math.min(west, lng); east = Math.max(east, lng); south = Math.min(south, lat); north = Math.max(north, lat); });
  return [[west, south], [east, north]];
};
const boundsForFeatures = features => {
  const points = features.flatMap(feature => coordinatesFor(feature.geometry));
  if (!points.length) return null;
  return [[Math.min(...points.map(point => point[0])), Math.min(...points.map(point => point[1]))], [Math.max(...points.map(point => point[0])), Math.max(...points.map(point => point[1]))]];
};
const ringContains = (ring, point) => {
  let inside = false;
  for (let index = 0, previous = ring.length - 1; index < ring.length; previous = index++) {
    const [x, y] = ring[index], [previousX, previousY] = ring[previous];
    if ((y > point[1]) !== (previousY > point[1]) && point[0] < ((previousX - x) * (point[1] - y)) / (previousY - y) + x) inside = !inside;
  }
  return inside;
};
const polygonContains = (polygon, point) => ringContains(polygon[0] || [], point) && !polygon.slice(1).some(ring => ringContains(ring, point));
const representativePoint = feature => {
  const geometry = feature?.geometry;
  const polygons = geometry?.type === 'Polygon' ? [geometry.coordinates] : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
  const polygon = polygons.sort((left, right) => coordinatesFor({ type: 'Polygon', coordinates: right }).length - coordinatesFor({ type: 'Polygon', coordinates: left }).length)[0];
  if (!polygon?.[0]?.length) return null;
  const ring = polygon[0], average = ring.reduce((sum, point) => [sum[0] + point[0], sum[1] + point[1]], [0, 0]).map(value => value / ring.length);
  if (polygonContains(polygon, average)) return average;
  const bounds = boundsFor({ geometry: { type: 'Polygon', coordinates: polygon } });
  if (!bounds) return null;
  const [westSouth, eastNorth] = bounds;
  for (let row = 1; row < 8; row += 1) for (let column = 1; column < 8; column += 1) {
    const point = [westSouth[0] + ((eastNorth[0] - westSouth[0]) * column) / 8, westSouth[1] + ((eastNorth[1] - westSouth[1]) * row) / 8];
    if (polygonContains(polygon, point)) return point;
  }
  return ring[0];
};

window.maplibregl = maplibregl;
window.AntiqueMap = {
  normalize,
  municipalityName,
  isAntiqueMunicipality,
  isAntiqueBarangay,
  boundsFor,
  boundsForFeatures,
  representativePoint,
  styleUrl: {
    version: 8,
    sources: {
      osm: {
        type: 'raster',
        tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
        tileSize: 256,
        attribution: '© OpenStreetMap contributors'
      }
    },
    layers: [
      { id: 'background', type: 'background', paint: { 'background-color': '#f8fafc' } },
      {
        id: 'osm',
        type: 'raster',
        source: 'osm',
        paint: {
          'raster-opacity': 0.35,
          'raster-saturation': -0.75,
          'raster-contrast': -0.15
        }
      }
    ]
  }
};
