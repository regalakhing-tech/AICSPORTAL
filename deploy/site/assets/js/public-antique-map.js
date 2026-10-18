import { AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT, aicsConcentrationCategory, buildAicsMunicipalityHeatmap, renderAicsHeatmapLegend } from './aics-heatmap.js?v=20261008-2';

(() => {
  const loading = document.getElementById('map-loading'), error = document.getElementById('map-error'), legend = document.getElementById('public-map-density-legend');
  if (!loading || !window.maplibregl) return;

  let map, popup, hoveredFeature, municipalities = [], benefitSummary = {};
  const municipalityStats = new Map();
  const sources = { mask: 'public-antique-mask', municipalities: 'public-municipalities', heatmap: 'public-municipality-heatmap', labels: 'public-municipality-labels' };
  const layers = { mask: 'public-antique-mask', fill: 'public-municipality-fill', heatmap: 'public-municipality-heatmap-density', line: 'public-municipality-line', label: 'public-municipality-label' };
  const municipalityName = feature => window.AntiqueMap.municipalityName(feature.properties);
  const municipalityCollection = () => {
    return { type: 'FeatureCollection', features: municipalities.map(feature => {
      const name = municipalityName(feature), total = Number(municipalityStats.get(window.AntiqueMap.normalize(name))?.total || 0), key = String(feature.properties?.psgc_10d || feature.properties?.city_code || window.AntiqueMap.normalize(name));
      return { ...feature, id: key, properties: { ...feature.properties, key, name, application_count: total, class: aicsConcentrationCategory(total) } };
    }) };
  };
  const antiqueMask = () => {
    const outer = [[110, 5], [135, 5], [135, 20], [110, 20], [110, 5]];
    const holes = municipalities.flatMap(feature => feature.geometry.type === 'Polygon'
      ? feature.geometry.coordinates.map(polygon => polygon)
      : feature.geometry.coordinates.flatMap(polygon => polygon));
    return { type: 'FeatureCollection', features: [{ type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [outer, ...holes] } }] };
  };
  const labelCollection = collection => ({ type: 'FeatureCollection', features: collection.features.map(feature => { const point = window.AntiqueMap.representativePoint(feature); return point && { type: 'Feature', id: `${feature.id}:label`, properties: { key: feature.properties.key, name: feature.properties.name }, geometry: { type: 'Point', coordinates: point } }; }).filter(Boolean) });
  const updateSources = () => {
    const boundaries = municipalityCollection(), heatmap = buildAicsMunicipalityHeatmap(municipalities, ({ municipality }) => municipalityStats.get(window.AntiqueMap.normalize(municipality)) || {});
    map.getSource(sources.municipalities).setData(boundaries); map.getSource(sources.heatmap).setData(heatmap.collection); map.getSource(sources.labels).setData(labelCollection(boundaries));
    renderAicsHeatmapLegend(legend, heatmap.summary, { benefitSummary });
  };
  const showError = () => { loading.hidden = true; error.textContent = 'The Antique map is currently unavailable.'; error.hidden = false; };

  async function init() {
    try {
      const [municipalityResponse, statResponse] = await Promise.all([fetch('assets/data/antique-municipalities.geojson'), fetch('AICS/public-map-analytics.php', { cache: 'no-store' })]);
      if (!municipalityResponse.ok || !statResponse.ok) throw new Error('Map data is unavailable.');
      municipalities = (await municipalityResponse.json()).features.filter(window.AntiqueMap.isAntiqueMunicipality);
      const payload = await statResponse.json();
      if (!payload.ok || payload.mode !== 'historical') throw new Error('Historical map analytics are unavailable.');
      benefitSummary = payload.benefit_summary || {};
      Object.entries(payload.municipalities || {}).forEach(([name, data]) => municipalityStats.set(window.AntiqueMap.normalize(name), data));
      const antiqueBounds = window.AntiqueMap.boundsForFeatures(municipalities);
      if (!antiqueBounds) throw new Error('Antique boundaries are unavailable.');

      // Leave enough room around the full province for the centered overview.
      const maxAntiqueBounds = [[120.2, 9.5], [123.8, 12.8]];

      const getFitPadding = () => window.innerWidth <= 480
        ? { top: 80, bottom: 220, left: 20, right: 20 }
        : window.innerWidth <= 640 ? { top: 70, bottom: 110, left: 20, right: 20 } : 36;
      const fitCurrent = () => {
        map.fitBounds(antiqueBounds, { padding: getFitPadding(), duration: 650, maxZoom: 10 });
      };

      const mapStyle = { ...window.AntiqueMap.styleUrl, glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf' };
      map = new window.maplibregl.Map({
        container: 'antique-map',
        style: mapStyle,
        attributionControl: true,
        bounds: antiqueBounds,
        fitBoundsOptions: { padding: getFitPadding(), maxZoom: 10 },
        maxBounds: maxAntiqueBounds,
        minZoom: 7.0,
        maxZoom: 15.0
      });
      map.addControl(new window.maplibregl.NavigationControl({ showCompass: false }), 'top-right');
      popup = new window.maplibregl.Popup({ className: 'map-tooltip', closeButton: false, closeOnClick: false, offset: 12 });

      const btnReset = document.getElementById('btn-map-reset');
      const btnProvince = document.getElementById('btn-map-province');
      const densityToggle = document.getElementById('public-map-density-toggle');
      btnReset?.addEventListener('click', fitCurrent);
      btnProvince?.addEventListener('click', fitCurrent);
      densityToggle?.addEventListener('change', () => {
        if (map.getLayer(layers.heatmap)) map.setLayoutProperty(layers.heatmap, 'visibility', densityToggle.checked ? 'visible' : 'none');
        legend.hidden = !densityToggle.checked;
      });

      map.on('load', () => {
        const emptyData = { type: 'FeatureCollection', features: [] };
        map.addSource(sources.mask, { type: 'geojson', data: antiqueMask() });
        map.addLayer({ id: layers.mask, type: 'fill', source: sources.mask, paint: { 'fill-color': '#f8fafc', 'fill-opacity': .88 } });
        map.addSource(sources.municipalities, { type: 'geojson', data: emptyData, promoteId: 'key' }); map.addSource(sources.heatmap, { type: 'geojson', data: emptyData, promoteId: 'psgc' }); map.addSource(sources.labels, { type: 'geojson', data: emptyData, promoteId: 'key' });
        map.addLayer({ id: layers.fill, type: 'fill', source: sources.municipalities, paint: { 'fill-color': ['match', ['get', 'class'], 0, '#f1f5f9', 1, '#dcfce7', 2, '#fde68a', '#fdba74'], 'fill-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], .9, .78] } });
        map.addLayer({ id: layers.heatmap, type: 'heatmap', source: sources.heatmap, paint: AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT });
        map.addLayer({ id: layers.line, type: 'line', source: sources.municipalities, layout: { 'line-join': 'round', 'line-cap': 'round' }, paint: { 'line-color': ['case', ['boolean', ['feature-state', 'hover'], false], '#0284c7', '#0369a1'], 'line-width': ['case', ['boolean', ['feature-state', 'hover'], false], 2, 0.95], 'line-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], 1, .62] } });
        map.addLayer({ id: layers.label, type: 'symbol', source: sources.labels, layout: { 'text-field': ['get', 'name'], 'text-font': ['Open Sans Regular'], 'text-size': 11, 'text-anchor': 'center', 'text-allow-overlap': false, 'text-ignore-placement': false }, paint: { 'text-color': '#0f172a', 'text-halo-color': 'rgba(255,255,255,.98)', 'text-halo-width': 1.6, 'text-opacity': ['interpolate', ['linear'], ['zoom'], 8.0, .85, 12, 1] } });
        map.on('mouseenter', layers.fill, () => { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mousemove', layers.fill, event => {
          const feature = event.features?.[0];
          if (!feature) return;
          if (hoveredFeature && hoveredFeature !== feature.id) map.setFeatureState({ source: sources.municipalities, id: hoveredFeature }, { hover: false });
          hoveredFeature = feature.id;
          map.setFeatureState({ source: sources.municipalities, id: hoveredFeature }, { hover: true });
          const content = document.createElement('div');
          const title = document.createElement('strong');
          const applicants = document.createElement('div');
          const assistance = document.createElement('div');
          content.className = 'map-concentration-popup';
          title.className = 'map-popup-title';
          applicants.className = assistance.className = 'map-popup-row';
          title.textContent = feature.properties.name;
          applicants.append(
            Object.assign(document.createElement('span'), { textContent: 'Applicants' }),
            Object.assign(document.createElement('strong'), { textContent: Number(feature.properties.application_count || 0).toLocaleString() })
          );
          assistance.append(
            Object.assign(document.createElement('span'), { textContent: 'Concentration' }),
            Object.assign(document.createElement('strong'), { textContent: ['No data', 'Low', 'Medium', 'High'][Number(feature.properties.class || 0)] || 'No data' })
          );
          content.append(title, applicants, assistance);
          popup.setLngLat(event.lngLat).setDOMContent(content).addTo(map);
        });
        map.on('mouseleave', layers.fill, () => { if (hoveredFeature) map.setFeatureState({ source: sources.municipalities, id: hoveredFeature }, { hover: false }); hoveredFeature = null; map.getCanvas().style.cursor = ''; popup.remove(); });
        updateSources(); loading.hidden = true;
      });
      new ResizeObserver(() => requestAnimationFrame(() => map.resize())).observe(map.getContainer());
    } catch (_) { showError(); }
  }
  init();
})();
