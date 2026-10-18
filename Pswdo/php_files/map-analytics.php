<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/../../includes/geo-analytics.php';

$paths = pswdo_paths();
$displayName = pswdo_current_display_name();
$initials = pswdo_initials($displayName);
$mapAnalyticsJsPath = __DIR__ . '/../js files/map-analytics.js';
$mapAnalyticsJsVersion = is_file($mapAnalyticsJsPath) ? (string) filemtime($mapAnalyticsJsPath) : '1';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>PSWDO Portal | Map Analytics</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= pswdo_h($paths['css_url']) ?>">
  <link rel="stylesheet" href="<?= pswdo_h($paths['project_root_url']) ?>/assets/vendor/maplibre-gl/maplibre-gl.css">
</head>
<body class="map-analytics-page" data-map-endpoint="map-analytics-data.php" data-geojson-url="<?= pswdo_h($paths['project_root_url']) ?>/assets/data/antique-municipalities.geojson">
  <div class="app-shell">
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <a href="<?= pswdo_h($paths['dashboard_url']) ?>?page=overview" class="brand"><img src="<?= pswdo_h($paths['logo_url']) ?>" alt="AICS Logo"></a>
        <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
      </div>
      <nav class="sidebar-nav">
        <p class="nav-title">PSWDO Menu</p>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=overview"><i class="fas fa-layer-group"></i> Overview Dashboard</a>
        <a class="nav-link active" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=map"><i class="fas fa-map-location-dot"></i> Map Analytics</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=requests"><i class="fas fa-folder-open"></i> Request Management</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=prescreening"><i class="fas fa-clipboard-check"></i> Screening Queue</a>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=release"><i class="fas fa-calendar-check"></i> Release Scheduling</a>
        <?= pswdo_mswdo_nav_link($paths['dashboard_url']) ?>
        <a class="nav-link" href="<?= pswdo_h($paths['dashboard_url']) ?>?page=reports"><i class="fas fa-chart-pie"></i> Reports &amp; Lists</a>
      </nav>
      <div class="sidebar-footer"><a href="<?= pswdo_h($paths['logout_url']) ?>" class="logout-link"><i class="fas fa-right-from-bracket"></i> Log Out</a></div>
    </aside>
    <div class="sidebar-overlay" id="overlay"></div>
    <main class="main-content" id="main-content">
      <header class="top-nav">
        <div class="top-nav-right"><div class="profile-chip"><div class="avatar"><?= pswdo_h($initials) ?></div><span><?= pswdo_h($displayName) ?></span></div></div>
      </header>
      <section class="content-wrap map-analytics-content">
        <section class="map-hero-bar">
          <div class="map-hero-copy">
            <span class="map-hero-icon"><i class="fas fa-map-location-dot"></i></span>
            <div>
              <div class="map-hero-title-row">
                <h1>Antique Assistance Map</h1>
                <span class="map-dataset-badge" id="geo-dataset-label">Historical Dataset</span>
              </div>
              <p>Explore client concentration and assistance coverage across the province.</p>
            </div>
          </div>

          <form id="map-analytics-filters" class="map-filter-bar">
            <div class="filter-group">
              <label for="map-source-select"><i class="fas fa-database"></i> Data</label>
              <div class="select-wrapper">
                <select name="source" id="map-source-select">
                  <option value="historical" selected>Historical (4,597 cases)</option>
                  <option value="live">Live submissions</option>
                </select>
              </div>
            </div>

            <div class="filter-group">
              <label for="map-municipality-select"><i class="fas fa-location-dot"></i> Area</label>
              <div class="select-wrapper">
                <select name="municipality" id="map-municipality-select">
                  <option value="">All 18 municipalities</option>
                  <option value="Anini-Y">Anini-y</option>
                  <option value="Barbaza">Barbaza</option>
                  <option value="Belison">Belison</option>
                  <option value="Bugasong">Bugasong</option>
                  <option value="Caluya">Caluya</option>
                  <option value="Culasi">Culasi</option>
                  <option value="Hamtic">Hamtic</option>
                  <option value="Laua-An">Laua-an</option>
                  <option value="Libertad">Libertad</option>
                  <option value="Pandan">Pandan</option>
                  <option value="Patnongon">Patnongon</option>
                  <option value="San Jose de Buenavista">San Jose de Buenavista</option>
                  <option value="San Remigio">San Remigio</option>
                  <option value="Sebaste">Sebaste</option>
                  <option value="Sibalom">Sibalom</option>
                  <option value="Tibiao">Tibiao</option>
                  <option value="Tobias Fornier">Tobias Fornier</option>
                  <option value="Valderrama">Valderrama</option>
                </select>
                <input type="hidden" id="map-municipality-search" value="">
              </div>
            </div>
          </form>
        </section>

        <div class="map-summary-grid">
          <article class="map-summary-card">
            <div class="summary-card-icon"><i class="fas fa-users-viewfinder"></i></div>
            <div class="summary-card-body">
              <span>Total Clients Assisted</span>
              <strong id="map-total">4,597</strong>
              <small id="map-total-meta">18 Municipalities verified</small>
            </div>
          </article>
          <article class="map-summary-card">
            <div class="summary-card-icon highlight"><i class="fas fa-peso-sign"></i></div>
            <div class="summary-card-body">
              <span>Total Disbursed</span>
              <strong id="map-disbursed">₱14,817,500</strong>
              <small id="map-disbursed-meta">AICS Provincial Program Fund</small>
            </div>
          </article>
          <article class="map-summary-card">
            <div class="summary-card-icon accent"><i class="fas fa-trophy"></i></div>
            <div class="summary-card-body">
              <span>Highest Concentration</span>
              <strong id="map-top">Sibalom</strong>
              <small id="map-top-meta">775 clients (16.9% of total)</small>
            </div>
          </article>
          <article class="map-summary-card">
            <div class="summary-card-icon primary"><i class="fas fa-hand-holding-medical"></i></div>
            <div class="summary-card-body">
              <span>Primary Sector</span>
              <strong id="map-top-benefit">Medical</strong>
              <small id="map-top-benefit-meta">2,760 clients (60.0%)</small>
            </div>
          </article>
        </div>

        <section class="map-panel map-workspace-shell">
          <div class="map-workspace">
            <div class="map-stage">
              <div class="map-floating-controls" aria-label="Map controls">
                <label class="map-density-switch" title="Show or hide the soft density overlay">
                  <input type="checkbox" id="map-density-toggle" checked>
                  <span class="switch-slider"></span>
                  <span class="switch-label">Heat overlay</span>
                </label>
                <button type="button" class="btn-map-control" id="map-fit-area" title="Reset the active map view">
                  <i class="fas fa-expand"></i> <span>Reset</span>
                </button>
                <button type="button" class="btn-map-control" id="map-back-overview" hidden>
                  <i class="fas fa-arrow-left"></i> <span>Province</span>
                </button>
              </div>

              <div class="map-canvas-wrap">
                <div id="pswdo-antique-map" aria-label="Map Analytics of Antique municipalities"></div>
                <aside class="map-choropleth-legend" aria-label="Client concentration color legend">
                  <strong>Client concentration</strong>
                  <span><i class="legend-swatch is-low"></i> Low (1-250)</span>
                  <span><i class="legend-swatch is-medium"></i> Medium (251-500)</span>
                  <span><i class="legend-swatch is-high"></i> High (501+)</span>
                </aside>
                <aside id="map-density-legend" class="map-density-legend" aria-label="Heat overlay legend"></aside>
              </div>
            </div>

            <aside class="map-insights-panel">
              <header class="map-insights-head">
                <span><i class="fas fa-chart-pie"></i> Area insights</span>
                <p id="map-selection">Application concentration across Antique.</p>
              </header>
              <span id="map-no-data" class="map-no-data" hidden>No applications found for this view.</span>
              <section class="map-location-analytics" aria-live="polite"><div id="map-location-analytics-content"></div></section>
              <p class="map-insights-note"><i class="fas fa-circle-info"></i> Colors remain visible while zooming. Enable heat for an additional density view.</p>
            </aside>
          </div>
        </section>
      </section>
    </main>
  </div>
  <script type="module" src="<?= pswdo_h($paths['project_root_url']) ?>/assets/js/shared-antique-map.js"></script>
  <script src="<?= pswdo_h($paths['js_url']) ?>"></script>
  <script type="module" src="../js files/map-analytics.js?v=<?= pswdo_h($mapAnalyticsJsVersion) ?>"></script>
</body>
</html>
