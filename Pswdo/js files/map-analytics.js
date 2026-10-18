import {
  AICS_HEATMAP_PAINT,
  AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT,
  aicsConcentrationCategory,
  buildAicsBarangayHeatmap,
  buildAicsMunicipalityHeatmap,
  renderAicsHeatmapLegend
} from '../../../assets/js/aics-heatmap.js?v=20261008-2';

(() => {
  const root = document.body;
  if (!root.classList.contains('map-analytics-page')) return;

  const form = document.getElementById('map-analytics-filters');
  const search = document.getElementById('map-municipality-select') || document.getElementById('map-municipality-search');
  const back = document.getElementById('map-back-overview');
  const clear = document.getElementById('map-clear-filters');
  const fit = document.getElementById('map-fit-area');
  const densityToggle = document.getElementById('map-density-toggle');
  const legend = document.getElementById('map-density-legend');

  const defaultTypes = {
    medical: 'Medical Assistance',
    burial: 'Burial Assistance',
    disaster_victims: 'Disaster Victims',
    special_cases: 'Special Cases',
    educational_basic: 'Educational Assistance for Basic Education',
    high_inflation_affected: 'Persons Affected by High Inflation Rate'
  };

  const sources = {
    municipalities: 'aics-municipalities',
    barangays: 'aics-barangays',
    heatmap: 'aics-barangay-heatmap',
    labels: 'aics-municipality-labels',
    mask: 'aics-antique-mask'
  };

  const layers = {
    mask: 'aics-antique-mask',
    municipalityFill: 'aics-municipality-fill',
    municipalityLine: 'aics-municipality-line',
    municipalityLabel: 'aics-municipality-label',
    barangayFill: 'aics-barangay-fill',
    barangayLine: 'aics-barangay-line',
    heatmap: 'aics-barangay-heatmap'
  };

  let map;
  let popup;
  let analytics = {};
  let assistanceTypes = defaultTypes;
  let benefitSummary = {};
  let payloadSummary = {};
  let municipalities = [];
  let barangays = null;
  let level = 'municipality';
  let selectedMunicipality = '';
  let selectedBarangay = '';
  let hoveredFeature = null;
  let scale = { max: 0, breaks: [] };
  let historicalMode = true;
  let maskCollection = null;

  const municipalityByKey = new Map();
  const barangaysByMunicipality = new Map();
  const boundsByKey = new Map();
  const pointsByKey = new Map();

  const setText = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };

  const nameOf = feature => window.AntiqueMap.municipalityName(feature.properties);
  const barangayName = feature => feature.properties.ADM4_EN || feature.properties.brgy_name || 'Barangay';

  const metrics = (municipality, barangay = '') => {
    if (barangay) {
      return ((analytics[municipality]?.barangays || {})[barangay] || {});
    }
    return (analytics[municipality] || {});
  };

  const keyFor = (name, parent = '') => `${parent ? `${window.AntiqueMap.normalize(parent)}:` : ''}${window.AntiqueMap.normalize(name)}`;

  function concentrationMeta(category) {
    if (category >= 3) return { key: 'high', label: 'High' };
    if (category === 2) return { key: 'medium', label: 'Medium' };
    if (category === 1) return { key: 'low', label: 'Low' };
    return { key: 'none', label: 'No data' };
  }

  function concentrationFillExpression() {
    return [
      'case',
      ['boolean', ['feature-state', 'selected'], false],
      '#38bdf8',
      ['match', ['get', 'class'],
        0, '#f1f5f9',
        1, '#dcfce7',
        2, '#fde68a',
        '#fdba74'
      ]
    ];
  }

  function concentrationFillOpacityExpression() {
    return [
      'case',
      ['boolean', ['feature-state', 'selected'], false],
      .92,
      ['case', ['boolean', ['feature-state', 'hover'], false], .9, .78]
    ];
  }

  function buildScale(values) {
    const max = Math.max(0, ...values.map(val => Number(val || 0)));
    if (!max) return { max: 0, breaks: [] };
    const breaks = [...new Set([
      Math.max(1, Math.ceil(max / 3)),
      Math.max(1, Math.ceil(max * 2 / 3)),
      max
    ])].sort((a, b) => a - b);
    return { max, breaks };
  }

  function enrich(features, municipality = '') {
    const max = scale.max || 1;
    return {
      type: 'FeatureCollection',
      features: features.map(feature => {
        const name = municipality ? barangayName(feature) : nameOf(feature);
        const data = metrics(municipality || name, municipality ? name : '');
        const applicationCount = Number(data.total || 0);
        const key = keyFor(name, municipality);
        const point = pointsByKey.get(key) || window.AntiqueMap.representativePoint(feature);
        if (point) pointsByKey.set(key, point);
        const bounds = boundsByKey.get(key) || window.AntiqueMap.boundsFor(feature);
        if (bounds) boundsByKey.set(key, bounds);

        return {
          ...feature,
          id: key,
          properties: {
            ...feature.properties,
            key,
            name,
            count: applicationCount,
            application_count: applicationCount,
            class: aicsConcentrationCategory(applicationCount),
            weight: max ? Math.sqrt(applicationCount / max) : 0
          }
        };
      })
    };
  }

  function clearHover() {
    if (hoveredFeature && map) {
      map.setFeatureState(hoveredFeature, { hover: false });
      hoveredFeature = null;
    }
  }

  function syncDensityVisibility() {
    const densityEnabled = Boolean(densityToggle && densityToggle.checked);
    if (map?.getLayer(layers.heatmap)) {
      map.setLayoutProperty(layers.heatmap, 'visibility', densityEnabled ? 'visible' : 'none');
    }
    if (legend) legend.hidden = !densityEnabled;
  }

  function updateSources() {
    if (!map || !map.getSource(sources.municipalities)) return;

    const raw = level === 'municipality' ? municipalities : (barangaysByMunicipality.get(window.AntiqueMap.normalize(selectedMunicipality)) || []);
    const counts = raw.map(feature => Number((level === 'municipality' ? metrics(nameOf(feature)) : metrics(selectedMunicipality, barangayName(feature))).total || 0));
    scale = buildScale(counts);

    const collection = enrich(raw, level === 'municipality' ? '' : selectedMunicipality);
    map.getSource(sources.municipalities).setData(level === 'municipality' ? collection : { type: 'FeatureCollection', features: [] });
    map.getSource(sources.barangays).setData(level === 'barangay' ? collection : { type: 'FeatureCollection', features: [] });

    let heatmapResult;
    if (historicalMode) {
      heatmapResult = buildAicsMunicipalityHeatmap(municipalities, ({ municipality }) => metrics(municipality));
      map.getSource(sources.heatmap).setData(heatmapResult.collection);
      renderAicsHeatmapLegend(legend, heatmapResult.summary, { benefitSummary });
    } else if (level === 'barangay') {
      heatmapResult = buildAicsBarangayHeatmap(raw, ({ municipality, barangay }) => metrics(municipality, barangay));
      map.getSource(sources.heatmap).setData(heatmapResult.collection);
      renderAicsHeatmapLegend(legend, heatmapResult.summary, { unmatchedCount: (metrics(selectedMunicipality) || {}).unmapped_barangay || 0 });
    } else {
      heatmapResult = buildAicsMunicipalityHeatmap(municipalities, ({ municipality }) => metrics(municipality));
      map.getSource(sources.heatmap).setData(heatmapResult.collection);
      renderAicsHeatmapLegend(legend, heatmapResult.summary, { unmatchedCount: payloadSummary.unmatched_barangay || 0 });
    }

    syncDensityVisibility();
  }

  function fitCurrent() {
    if (!map) return;
    if (selectedMunicipality) {
      const key = keyFor(selectedMunicipality);
      const bounds = boundsByKey.get(key);
      if (bounds) {
        map.fitBounds(bounds, {
          padding: 50,
          maxZoom: level === 'barangay' ? 14 : 11.5,
          duration: 480
        });
        return;
      }
    }
    const bounds = window.AntiqueMap.boundsForFeatures(municipalities);
    if (bounds) {
      const isMobile = window.innerWidth <= 768;
      map.fitBounds(bounds, {
        padding: isMobile ? 24 : 36,
        maxZoom: 10,
        duration: 450
      });
    }
  }

  function setSelected(id, selected) {
    if (!map) return;
    map.setFeatureState({
      source: level === 'municipality' ? sources.municipalities : sources.barangays,
      id
    }, { selected });
  }

  function renderLocation(name = '', data = {}, context = '') {
    const content = document.getElementById('map-location-analytics-content');
    if (!content) return;

    if (!name) {
      if (historicalMode) {
        setText('map-selection', 'Showing verified historical application concentration across all 18 Antique municipalities.');
      } else {
        setText('map-selection', 'Select a municipality on the map or use filters to inspect application distribution.');
      }

      const benefitPills = Object.entries(benefitSummary || {}).map(([bLabel, bVal]) => `
        <span class="assistance-pill">
          <span>${bLabel}</span>
          <strong>${new Intl.NumberFormat().format(Number(bVal))}</strong>
        </span>
      `).join('');

      content.innerHTML = `
        <div class="map-location-selected">
          <div class="map-location-details">
            <span class="location-badge"><i class="fas fa-map-pin"></i> Province Level</span>
            <h3>Antique Province</h3>
            <p>18 Municipalities &bull; ${historicalMode ? '4,597 Verified Clients (Php 14.8M Disbursed)' : 'Active Submissions'}</p>
          </div>
          <div class="map-location-metrics">
            <div class="map-metric-box">
              <span class="metric-label">Total Clients</span>
              <strong class="metric-value">${new Intl.NumberFormat().format(Number(payloadSummary.total || 0))}</strong>
            </div>
            <div class="map-metric-box">
              <span class="metric-label">Top Municipality</span>
              <strong class="metric-value-sub">${payloadSummary.top_municipality || '—'}</strong>
            </div>
          </div>
          <div class="map-location-assistance">
            <h4>Assistance Category Distribution</h4>
            <div class="map-assistance-pills">
              ${benefitPills || '<span style="color:#64748b;font-size:12px;">Detailed category data loaded upon selection.</span>'}
            </div>
          </div>
        </div>
      `;
      return;
    }

    setText('map-selection', level === 'barangay' ? 'Barangay application concentration within the selected municipality.' : 'Municipality application concentration across Antique.');

    const totalProvince = Number(payloadSummary.total || 4597);
    const munCount = Number(data.total || 0);
    const pct = totalProvince > 0 ? ((munCount / totalProvince) * 100).toFixed(1) : 0;
    const estDisbursed = data.estimated_disbursed || Math.round(munCount * (14817500 / 4597));

    const wrap = document.createElement('div');
    wrap.className = 'map-location-selected';
    wrap.innerHTML = `
      <div class="map-location-details">
        <span class="location-badge active"><i class="fas fa-location-crosshairs"></i> ${level === 'barangay' ? 'Barangay' : 'Municipality'}</span>
        <h3></h3>
        <p></p>
      </div>
      <div class="map-location-metrics">
        <div class="map-metric-box">
          <span class="metric-label">Clients Assisted</span>
          <strong class="metric-value"></strong>
        </div>
        <div class="map-metric-box">
          <span class="metric-label">Est. Financial Disbursed</span>
          <strong class="metric-value-sub" style="color:#0369a1;font-size:21px;">₱${new Intl.NumberFormat().format(estDisbursed)}</strong>
        </div>
      </div>
      <div class="map-location-assistance">
        <h4>Assistance Breakdown</h4>
        <div class="map-assistance-breakdown"></div>
      </div>
    `;

    wrap.querySelector('h3').textContent = name;
    wrap.querySelector('.map-location-details p:last-child').textContent = context || `${pct}% of Provincial Total${data.rank ? ` • Rank #${data.rank} of 18 LGUs` : ''}`;
    wrap.querySelector('.map-metric-box .metric-value').textContent = new Intl.NumberFormat().format(munCount);

    const breakdown = wrap.querySelector('.map-assistance-breakdown');
    if (historicalMode) {
      const medEst = Math.round(munCount * (2760 / 4597));
      const calEst = Math.round(munCount * (1125 / 4597));
      const burEst = munCount - medEst - calEst;
      const histItems = [
        ['Medical Assistance', medEst],
        ['Calamity Assistance', calEst],
        ['Burial Assistance', Math.max(0, burEst)]
      ];
      histItems.forEach(([typeLabel, count]) => {
        const row = document.createElement('div');
        row.className = 'map-assistance-row';
        row.innerHTML = '<span></span><strong></strong>';
        row.querySelector('span').textContent = typeLabel;
        row.querySelector('strong').textContent = new Intl.NumberFormat().format(count);
        breakdown.append(row);
      });
    } else {
      Object.entries(assistanceTypes).forEach(([code, typeLabel]) => {
        const row = document.createElement('div');
        row.className = 'map-assistance-row';
        row.innerHTML = '<span></span><strong></strong>';
        row.querySelector('span').textContent = typeLabel;
        row.querySelector('strong').textContent = new Intl.NumberFormat().format(Number((data.assistance_counts || {})[code] || 0));
        breakdown.append(row);
      });
    }

    content.replaceChildren(wrap);
  }

  async function ensureBarangays() {
    if (barangays) return;
    const response = await fetch(root.dataset.geojsonUrl.replace('antique-municipalities.geojson', 'antique-barangays.geojson'));
    if (!response.ok) throw new Error('Barangay boundaries are unavailable.');
    barangays = (await response.json()).features.filter(window.AntiqueMap.isAntiqueBarangay);
    barangays.forEach(feature => {
      const key = window.AntiqueMap.normalize(feature.properties.ADM3_EN);
      if (!barangaysByMunicipality.has(key)) barangaysByMunicipality.set(key, []);
      barangaysByMunicipality.get(key).push(feature);
    });
  }

  async function selectMunicipality(name) {
    if (!name) {
      restoreOverview();
      return;
    }
    if (!historicalMode) await ensureBarangays();
    const feature = municipalityByKey.get(window.AntiqueMap.normalize(name));
    if (!feature) return;

    if (selectedMunicipality) setSelected(keyFor(selectedMunicipality), false);
    selectedMunicipality = nameOf(feature);
    selectedBarangay = '';
    level = historicalMode ? 'municipality' : 'barangay';

    if (search) search.value = selectedMunicipality;
    const hiddenSearch = document.getElementById('map-municipality-search');
    if (hiddenSearch) hiddenSearch.value = selectedMunicipality;

    updateSources();
    setSelected(keyFor(selectedMunicipality), true);
    renderLocation(selectedMunicipality, metrics(selectedMunicipality));
    if (back) back.hidden = false;
    fitCurrent();
  }

  function selectBarangay(name) {
    if (selectedBarangay) setSelected(keyFor(selectedBarangay, selectedMunicipality), false);
    selectedBarangay = name;
    setSelected(keyFor(name, selectedMunicipality), true);
    renderLocation(name, metrics(selectedMunicipality, name), `${selectedMunicipality}, Antique`);
  }

  function restoreOverview() {
    if (selectedMunicipality) setSelected(keyFor(selectedMunicipality), false);
    if (selectedBarangay) setSelected(keyFor(selectedBarangay, selectedMunicipality), false);
    level = 'municipality';
    selectedMunicipality = '';
    selectedBarangay = '';
    if (search) search.value = '';
    const hiddenSearch = document.getElementById('map-municipality-search');
    if (hiddenSearch) hiddenSearch.value = '';
    if (back) back.hidden = true;
    updateSources();
    renderLocation();
    fitCurrent();
  }

  async function refresh() {
    const params = new URLSearchParams(new FormData(form));
    const response = await fetch(`${root.dataset.mapEndpoint}?${params}`, { cache: 'no-store' });
    const payload = await response.json();
    if (!payload.ok) throw new Error(payload.message || 'Unable to refresh analytics.');

    historicalMode = payload.mode === 'historical';
    analytics = payload.municipalities || {};
    assistanceTypes = payload.assistance_types || defaultTypes;
    benefitSummary = payload.benefit_summary || {};
    payloadSummary = payload.summary || {};
    const totalDisbursed = payload.total_disbursed || 14817500;

    setText('map-total', new Intl.NumberFormat().format(Number(payloadSummary.total || 0)));
    setText('map-top', payloadSummary.top_municipality || '—');
    setText('map-disbursed', '₱' + new Intl.NumberFormat().format(totalDisbursed));

    const totalMeta = document.getElementById('map-total-meta');
    if (totalMeta) {
      totalMeta.textContent = historicalMode ? '18 Municipalities verified' : 'Active submissions in database';
    }

    const topMeta = document.getElementById('map-top-meta');
    if (topMeta) {
      const topCount = payloadSummary.top_municipality && analytics[payloadSummary.top_municipality] ? analytics[payloadSummary.top_municipality].total : 0;
      topMeta.textContent = topCount ? `${new Intl.NumberFormat().format(topCount)} clients (${((topCount / (payloadSummary.total || 1)) * 100).toFixed(1)}%)` : 'Leading volume municipality';
    }

    const coverageCount = Object.keys(analytics).length;
    setText('map-coverage', `${coverageCount} / 18`);

    const topBenefitEl = document.getElementById('map-top-benefit');
    const topBenefitMeta = document.getElementById('map-top-benefit-meta');
    if (topBenefitEl && benefitSummary && Object.keys(benefitSummary).length) {
      const topEntry = Object.entries(benefitSummary).sort((a, b) => b[1] - a[1])[0];
      if (topEntry) {
        topBenefitEl.textContent = topEntry[0].replace(' Assistance', '');
        const pct = payloadSummary.total ? ((topEntry[1] / payloadSummary.total) * 100).toFixed(1) : 0;
        if (topBenefitMeta) topBenefitMeta.textContent = `${new Intl.NumberFormat().format(topEntry[1])} clients (${pct}%)`;
      }
    }

    const datasetLabel = document.getElementById('geo-dataset-label');
    if (datasetLabel) {
      datasetLabel.textContent = historicalMode ? 'Historical Dataset' : 'Live Submissions';
    }

    const noDataEl = document.getElementById('map-no-data');
    if (noDataEl) noDataEl.hidden = Boolean(payloadSummary.total);

    if (map && map.getLayer(layers.heatmap)) {
      const paintConfig = historicalMode ? AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT : AICS_HEATMAP_PAINT;
      Object.entries(paintConfig).forEach(([prop, val]) => {
        try {
          map.setPaintProperty(layers.heatmap, prop, val);
        } catch (_) {}
      });
    }

    updateSources();
    if (selectedMunicipality) {
      renderLocation(
        selectedBarangay || selectedMunicipality,
        metrics(selectedMunicipality, selectedBarangay),
        selectedBarangay ? `${selectedMunicipality}, Antique` : ''
      );
      if (selectedBarangay) setSelected(keyFor(selectedBarangay, selectedMunicipality), true);
      else setSelected(keyFor(selectedMunicipality), true);
    } else {
      renderLocation();
    }
  }

  function bindEvents() {
    [layers.municipalityFill, layers.barangayFill].forEach(layerId => {
      map.on('mousemove', layerId, event => {
        const feature = event.features?.[0];
        if (!feature) return;

        const source = layerId.includes('municipality') ? sources.municipalities : sources.barangays;
        if (hoveredFeature && (hoveredFeature.source !== source || hoveredFeature.id !== feature.id)) {
          map.setFeatureState(hoveredFeature, { hover: false });
        }
        hoveredFeature = { source, id: feature.id };
        map.setFeatureState(hoveredFeature, { hover: true });
        map.getCanvas().style.cursor = 'pointer';

        const content = document.createElement('div');
        content.className = 'map-concentration-popup';

        const popupHeader = document.createElement('div');
        popupHeader.className = 'map-popup-header';

        const title = document.createElement('strong');
        title.className = 'map-popup-title';
        title.textContent = feature.properties.name;

        const meta = concentrationMeta(Number(feature.properties.class || 0));
        const badge = document.createElement('span');
        badge.className = `map-popup-concentration is-${meta.key}`;
        badge.textContent = meta.label;
        popupHeader.append(title, badge);

        const row = document.createElement('div');
        row.className = 'map-popup-row';

        const label = document.createElement('span');
        label.textContent = level === 'barangay' ? 'Barangay Applicants' : 'Municipality Applicants';

        const val = document.createElement('strong');
        val.textContent = Number(feature.properties.application_count || 0).toLocaleString();

        row.append(label, val);
        content.append(popupHeader, row);

        popup.setLngLat(event.lngLat).setDOMContent(content).addTo(map);
      });

      map.on('mouseleave', layerId, () => {
        clearHover();
        map.getCanvas().style.cursor = '';
        popup.remove();
      });

      map.on('click', layerId, event => {
        const feature = event.features?.[0];
        if (!feature) return;

        if (layerId.includes('municipality')) {
          selectMunicipality(feature.properties.name).catch(() => {
            setText('map-selection', 'Barangay boundaries are currently unavailable.');
          });
        } else {
          if (!feature || historicalMode) return;
          selectBarangay(feature.properties.name);
        }
      });
    });
  }

  function initializeLayers() {
    const emptyFC = { type: 'FeatureCollection', features: [] };

    // 1. Inverted mask layer (dims everything outside Antique so Antique is the sole focus)
    if (maskCollection) {
      map.addSource(sources.mask, { type: 'geojson', data: maskCollection });
      map.addLayer({
        id: layers.mask,
        type: 'fill',
        source: sources.mask,
        paint: {
          'fill-color': '#f8fafc',
          'fill-opacity': 0.88
        }
      });
    }

    map.addSource(sources.municipalities, { type: 'geojson', data: emptyFC, promoteId: 'key' });
    map.addSource(sources.barangays, { type: 'geojson', data: emptyFC, promoteId: 'key' });
    map.addSource(sources.heatmap, { type: 'geojson', data: emptyFC, promoteId: 'key' });

    // Persistent choropleth colors stay visible at every zoom level; heat is supplemental.
    map.addLayer({
      id: layers.municipalityFill,
      type: 'fill',
      source: sources.municipalities,
      paint: {
        'fill-color': concentrationFillExpression(),
        'fill-opacity': concentrationFillOpacityExpression()
      }
    });

    // Barangays use the same stable palette during live-data drill-down.
    map.addLayer({
      id: layers.barangayFill,
      type: 'fill',
      source: sources.barangays,
      paint: {
        'fill-color': concentrationFillExpression(),
        'fill-opacity': concentrationFillOpacityExpression()
      }
    });

    // 4. Heatmap layer (vibrant on top of clean base, matching the green-to-red legend)
    map.addLayer({
      id: layers.heatmap,
      type: 'heatmap',
      source: sources.heatmap,
      paint: historicalMode ? AICS_HISTORICAL_MUNICIPALITY_HEATMAP_PAINT : AICS_HEATMAP_PAINT
    });

    // 5. Municipality Line layer (crisp slate boundaries with blue hover)
    map.addLayer({
      id: layers.municipalityLine,
      type: 'line',
      source: sources.municipalities,
      paint: {
        'line-color': [
          'case',
          ['boolean', ['feature-state', 'selected'], false],
          '#0284c7',
          ['case', ['boolean', ['feature-state', 'hover'], false], '#0284c7', '#94a3b8']
        ],
        'line-width': [
          'case',
          ['boolean', ['feature-state', 'selected'], false],
          3,
          ['case', ['boolean', ['feature-state', 'hover'], false], 2.2, 1.2]
        ],
        'line-opacity': [
          'case',
          ['boolean', ['feature-state', 'selected'], false],
          1,
          ['case', ['boolean', ['feature-state', 'hover'], false], 0.95, 0.75]
        ]
      }
    });

    // 6. Barangay Line layer
    map.addLayer({
      id: layers.barangayLine,
      type: 'line',
      source: sources.barangays,
      paint: {
        'line-color': [
          'case',
          ['boolean', ['feature-state', 'selected'], false],
          '#0284c7',
          ['case', ['boolean', ['feature-state', 'hover'], false], '#0284c7', '#cbd5e1']
        ],
        'line-width': [
          'case',
          ['boolean', ['feature-state', 'selected'], false],
          2.5,
          ['case', ['boolean', ['feature-state', 'hover'], false], 1.8, 0.8]
        ],
        'line-opacity': 0.8
      }
    });

    bindEvents();
  }

  async function init() {
    const response = await fetch(root.dataset.geojsonUrl);
    if (!response.ok) throw new Error('Unable to load Antique boundaries.');
    municipalities = (await response.json()).features.filter(window.AntiqueMap.isAntiqueMunicipality);
    municipalities.forEach(feature => municipalityByKey.set(window.AntiqueMap.normalize(nameOf(feature)), feature));

    // Construct an outer mask that softly fades out everything outside Antique
    const outerBox = [
      [110.0, 5.0],
      [135.0, 5.0],
      [135.0, 20.0],
      [110.0, 20.0],
      [110.0, 5.0]
    ];
    const maskHoles = [];
    municipalities.forEach(feature => {
      const geom = feature.geometry;
      if (geom.type === 'Polygon') {
        geom.coordinates.forEach(ring => maskHoles.push(ring));
      } else if (geom.type === 'MultiPolygon') {
        geom.coordinates.forEach(poly => poly.forEach(ring => maskHoles.push(ring)));
      }
    });
    maskCollection = {
      type: 'FeatureCollection',
      features: [{
        type: 'Feature',
        id: 'antique-mask-cutout',
        properties: {},
        geometry: {
          type: 'Polygon',
          coordinates: [outerBox, ...maskHoles]
        }
      }]
    };

    map = new window.maplibregl.Map({
      container: 'pswdo-antique-map',
      style: window.AntiqueMap.styleUrl,
      center: [122.06, 11.15],
      zoom: 8.5,
      maxBounds: [[120.2, 9.5], [123.8, 12.8]],
      attributionControl: true
    });

    map.addControl(new window.maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    popup = new window.maplibregl.Popup({ closeButton: false, closeOnClick: false, offset: 10 });

    map.once('load', () => {
      initializeLayers();
      refresh().then(() => fitCurrent()).catch(() => setText('map-selection', 'Unable to refresh analytics right now.'));
    });

    map.on('error', event => {
      if (event.error?.sourceId === undefined) {
        setText('map-selection', 'Basemap is unavailable; application analytics remain available when connectivity returns.');
      }
    });

    new ResizeObserver(() => map.resize()).observe(document.getElementById('main-content'));
  }

  const sourceSelect = document.getElementById('map-source-select');
  if (sourceSelect) {
    sourceSelect.addEventListener('change', () => {
      restoreOverview();
      refresh().catch(() => setText('map-selection', 'Unable to refresh analytics right now.'));
    });
  }

  if (form) {
    form.addEventListener('submit', event => {
      event.preventDefault();
      refresh().then(() => {
        if (search && search.value) {
          selectMunicipality(search.value);
        }
      }).catch(() => setText('map-selection', 'Unable to refresh analytics right now.'));
    });
  }

  if (back) back.addEventListener('click', restoreOverview);

  if (clear) {
    clear.addEventListener('click', () => {
      form.reset();
      if (sourceSelect) sourceSelect.value = 'historical';
      restoreOverview();
      refresh().catch(() => setText('map-selection', 'Unable to refresh analytics right now.'));
    });
  }

  if (fit) fit.addEventListener('click', fitCurrent);

  if (densityToggle) {
    densityToggle.addEventListener('change', syncDensityVisibility);
  }

  if (search) {
    search.addEventListener('change', () => {
      if (!search.value) {
        restoreOverview();
      } else {
        selectMunicipality(search.value).catch(() => {
          setText('map-selection', 'Barangay boundaries are currently unavailable.');
        });
      }
    });
  }

  init().catch(() => setText('map-selection', 'The Antique map is currently unavailable.'));
})();
