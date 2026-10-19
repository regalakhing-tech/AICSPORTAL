<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blank General Intake Sheet — AICS PSWDO Antique</title>
  <link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
  <link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
  <style>
    @page {
      size: legal portrait;
      margin: 18mm 15mm 18mm 20mm;
    }

    *, *::before, *::after { box-sizing: border-box; }

    body {
      font-family: 'Times New Roman', Times, serif;
      font-size: 11pt;
      color: #000;
      background: #fff;
      margin: 0;
      padding: 16px 20px;
    }

    .gis-header {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
    }
    .gis-header-text {
      flex: 1;
      text-align: center;
    }
    .gis-header-text p {
      margin: 0;
      line-height: 1.4;
      font-size: 10pt;
    }
    .gis-header-text .office-name {
      font-size: 11pt;
      font-weight: bold;
      text-transform: uppercase;
    }
    .gis-form-title {
      text-align: center;
      font-size: 14pt;
      font-weight: bold;
      text-transform: uppercase;
      letter-spacing: 2px;
      margin: 8px 0 4px;
      border-top: 2.5px solid #000;
      border-bottom: 2.5px solid #000;
      padding: 4px 0;
    }

    .gis-meta-row {
      display: flex;
      justify-content: space-between;
      margin: 6px 0 10px;
      font-size: 10pt;
    }
    .gis-meta-item {
      display: flex;
      gap: 6px;
      align-items: baseline;
    }
    .gis-meta-item label { font-weight: bold; }
    .gis-meta-line {
      border-bottom: 1px solid #000;
      min-width: 100px;
      height: 14px;
      display: inline-block;
    }

    .section-heading {
      background: #1e3a8a;
      color: #fff;
      font-size: 10.5pt;
      font-weight: bold;
      padding: 3px 8px;
      margin: 10px 0 4px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .gis-data-grid {
      display: grid;
      gap: 0;
      border-left: 1px solid #000;
      border-top: 1px solid #000;
      margin-bottom: 4px;
    }
    .gis-data-grid.cols-2 { grid-template-columns: 1fr 1fr; }
    .gis-data-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
    .gis-data-grid.cols-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }

    .gis-field {
      border-right: 1px solid #000;
      border-bottom: 1px solid #000;
      padding: 3px 6px;
    }
    .gis-field label {
      display: block;
      font-size: 8.5pt;
      font-weight: bold;
      text-transform: uppercase;
      color: #333;
      margin-bottom: 1px;
    }
    .gis-field .gis-value {
      font-size: 11pt;
      min-height: 18px;
      display: block;
    }
    .gis-field.span-2 { grid-column: span 2; }
    .gis-field.span-3 { grid-column: span 3; }
    .gis-field.span-4 { grid-column: span 4; }

    .gis-family-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10pt;
      margin-bottom: 4px;
    }
    .gis-family-table th {
      background: #dbeafe;
      color: #1e3a8a;
      font-weight: bold;
      border: 1px solid #93c5fd;
      padding: 3px 5px;
      text-align: center;
      font-size: 9.5pt;
      text-transform: uppercase;
    }
    .gis-family-table td {
      border: 1px solid #000;
      padding: 3px 5px;
      vertical-align: top;
      height: 22px;
    }

    .gis-narrative {
      border: 1px solid #000;
      min-height: 80px;
      padding: 5px 8px;
      font-size: 11pt;
      margin-bottom: 4px;
    }
    .gis-narrative.short { min-height: 50px; }

    .gis-check-box {
      width: 12px;
      height: 12px;
      border: 1.5px solid #000;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 9pt;
      font-weight: bold;
      flex-shrink: 0;
      background: #fff;
    }

    .gis-cat-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      border: 1px solid #000;
      margin-bottom: 4px;
    }
    .gis-cat-col {
      border-right: 1px solid #000;
      padding: 4px 8px;
    }
    .gis-cat-col:last-child { border-right: none; }
    .gis-cat-item {
      display: flex;
      align-items: center;
      gap: 5px;
      margin-bottom: 4px;
      font-size: 10pt;
    }
    .gis-cat-other {
      display: flex;
      align-items: center;
      gap: 5px;
      font-size: 10pt;
    }
    .gis-cat-other-line {
      border-bottom: 1px solid #000;
      flex: 1;
      min-width: 40px;
      height: 14px;
    }

    .gis-sig-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 40px;
      margin-top: 28px;
    }
    .gis-sig-block { text-align: left; }
    .gis-sig-header { font-size: 10pt; margin-bottom: 36px; }
    .gis-sig-line {
      border-top: 1.5px solid #000;
      margin-bottom: 3px;
      width: 60%;
    }
    .gis-sig-role { font-size: 10pt; }

    @media screen {
      body { max-width: 820px; margin: 20px auto; padding: 24px 32px; }
      .print-action-bar {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        align-items: center;
        justify-content: space-between;
      }
      .close-tab-link {
        color: #475569;
        font-size: 14px;
        font-family: sans-serif;
        text-decoration: none;
        white-space: nowrap;
      }
      .print-btn {
        background: #1d4ed8;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 9px 20px;
        font-size: 14px;
        cursor: pointer;
        font-family: sans-serif;
        display: flex;
        align-items: center;
        gap: 6px;
      }
      .print-btn:hover { background: #1e40af; }
      .note-text {
        font-family: sans-serif;
        font-size: 13px;
        color: #475569;
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 10px 16px;
        margin-bottom: 20px;
      }
    }
    @media screen and (max-width: 768px) {
      body {
        width: 100%;
        margin: 0;
        padding: 16px;
        overflow-x: hidden;
      }
      .print-action-bar {
        flex-wrap: wrap;
        align-items: stretch;
      }
      .print-action-bar > * {
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }
      .print-btn {
        flex: 1 1 240px;
      }
      .gis-preview-scroll {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-inline: contain;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        -webkit-overflow-scrolling: touch;
      }
      .gis-sheet {
        min-width: 760px;
        padding: 16px;
      }
    }
    @media print {
      .print-action-bar { display: none !important; }
      .note-text { display: none !important; }
      body { padding: 0; margin: 0; }
      .gis-preview-scroll { overflow: visible; border: 0; }
      .gis-sheet { min-width: 0; padding: 0; }
    }
  </style>
</head>
<body>

  <div class="print-action-bar">
    <a class="close-tab-link" id="close-tab-link" href="javascript:window.close()">&#10005; Close Tab</a>
    <button class="print-btn" id="download-btn" onclick="downloadGIS()">&#11015; Download GIS Form (PDF)</button>
  </div>

  <div class="note-text">
    <strong>Instructions:</strong> Print this blank form and fill it out by hand. Bring the completed form to the PSWDO office together with your supporting documents. PSWDO staff will encode your information into the system.
  </div>

  <div class="gis-preview-scroll" role="region" aria-label="Scrollable General Intake Sheet preview" tabindex="0">
  <div class="gis-sheet">
  <!-- HEADER -->
  <div class="gis-header">
    <div class="gis-header-text">
      <p>Republic of the Philippines</p>
      <p>Province of Antique</p>
      <p class="office-name">Office of the Provincial Social Welfare and Development Officer</p>
      <p>San Jose, Antique</p>
    </div>
  </div>

  <div class="gis-form-title">General Intake Sheet</div>

  <!-- META ROW -->
  <div class="gis-meta-row">
    <div class="gis-meta-item">
      <label>Case No.:</label>
      <span class="gis-meta-line"></span>
    </div>
    <div class="gis-meta-item">
      <label>Date:</label>
      <span class="gis-meta-line"></span>
    </div>
    <div class="gis-meta-item">
      <label>Reference No.:</label>
      <span class="gis-meta-line"></span>
    </div>
  </div>

  <!-- SECTION I -->
  <div class="section-heading">I. Demographic Data of the Client</div>

  <div class="gis-data-grid cols-2" style="grid-template-columns:1fr;">
    <div class="gis-field">
      <label>Full Name (Last Name, First Name, Middle Initial, Extension)</label>
      <span class="gis-value">&nbsp;</span>
    </div>
  </div>

  <div class="gis-data-grid cols-4">
    <div class="gis-field">
      <label>Date of Birth</label>
      <span class="gis-value">&nbsp;</span>
    </div>
    <div class="gis-field">
      <label>Age</label>
      <span class="gis-value">&nbsp;</span>
    </div>
    <div class="gis-field">
      <label>Sex</label>
      <span class="gis-value">&nbsp;</span>
    </div>
    <div class="gis-field">
      <label>Civil Status</label>
      <span class="gis-value">&nbsp;</span>
    </div>
  </div>

  <div class="gis-data-grid cols-2">
    <div class="gis-field">
      <label>Highest Educational Attainment</label>
      <span class="gis-value">&nbsp;</span>
    </div>
    <div class="gis-field">
      <label>Present Occupation / Source of Income</label>
      <span class="gis-value">&nbsp;</span>
    </div>
  </div>

  <div class="gis-data-grid cols-2">
    <div class="gis-field">
      <label>Estimated Monthly Income</label>
      <span class="gis-value">&nbsp;</span>
    </div>
    <div class="gis-field">
      <label>Contact Number</label>
      <span class="gis-value">&nbsp;</span>
    </div>
  </div>

  <div class="gis-data-grid" style="grid-template-columns:1fr;">
    <div class="gis-field">
      <label>Present Address (Sitio/Purok, Barangay, Municipality, Province)</label>
      <span class="gis-value">&nbsp;</span>
    </div>
  </div>

  <div class="gis-data-grid cols-2">
    <div class="gis-field">
      <label>Applicant (if different from beneficiary)</label>
      <span class="gis-value">&nbsp;</span>
    </div>
    <div class="gis-field">
      <label>Relationship to Beneficiary</label>
      <span class="gis-value">&nbsp;</span>
    </div>
  </div>

  <!-- SECTION II: FAMILY COMPOSITION -->
  <div class="section-heading">II. Family Composition</div>

  <table class="gis-family-table">
    <thead>
      <tr>
        <th style="width:30%">Name</th>
        <th style="width:8%">Age</th>
        <th style="width:10%">Sex</th>
        <th style="width:16%">Relationship</th>
        <th style="width:18%">Occupation</th>
        <th style="width:18%">Educational Attainment</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($i = 0; $i < 8; $i++): ?>
      <tr>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <!-- SECTION III: PROBLEM PRESENTED -->
  <div class="section-heading">III. Problem Presented</div>
  <div class="gis-narrative">&nbsp;</div>

  <!-- SECTION IV: CASE WORKER'S ASSESSMENT -->
  <div class="section-heading">IV. Case Worker's Assessment / Initial Recommendation</div>
  <div class="gis-narrative short">&nbsp;</div>

  <!-- CLIENT CATEGORY -->
  <div style="font-size:10pt; font-weight:bold; margin: 10px 0 3px;">Client&rsquo;s Category:</div>
  <div class="gis-cat-grid">
    <div class="gis-cat-col">
      <div class="gis-cat-item"><div class="gis-check-box"></div> Youth</div>
      <div class="gis-cat-item"><div class="gis-check-box"></div> Senior Citizen</div>
      <div class="gis-cat-item"><div class="gis-check-box"></div> PWD</div>
      <div class="gis-cat-item"><div class="gis-check-box"></div> Pantawid</div>
    </div>
    <div class="gis-cat-col">
      <div class="gis-cat-item"><div class="gis-check-box"></div> IPS&rsquo;s</div>
      <div class="gis-cat-item"><div class="gis-check-box"></div> Solo Parent</div>
      <div class="gis-cat-item"><div class="gis-check-box"></div> Men/Women</div>
      <div class="gis-cat-other"><div class="gis-check-box"></div> Others: <span class="gis-cat-other-line"></span></div>
    </div>
    <div class="gis-cat-col">
      <div class="gis-cat-item"><div class="gis-check-box"></div> Medical</div>
      <div class="gis-cat-item"><div class="gis-check-box"></div> Burial</div>
      <div class="gis-cat-item"><div class="gis-check-box"></div> Victims of Calamity</div>
      <div class="gis-cat-other"><div class="gis-check-box"></div> Others: <span class="gis-cat-other-line"></span></div>
    </div>
  </div>

  <!-- SIGNATURES -->
  <div class="gis-sig-row">
    <div class="gis-sig-block">
      <div class="gis-sig-header">Interviewed by:</div>
      <div class="gis-sig-line"></div>
      <div class="gis-sig-role">Social Worker / Case Management Officer</div>
    </div>
    <div class="gis-sig-block">
      <div class="gis-sig-header">Client:</div>
      <div class="gis-sig-line"></div>
      <div class="gis-sig-role">Name and Signature of Client</div>
    </div>
  </div>
  </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <script>
    function downloadGIS() {
      var btn = document.getElementById('download-btn');
      var bar = document.querySelector('.print-action-bar');
      var note = document.querySelector('.note-text');
      btn.disabled = true;
      btn.textContent = 'Generating PDF…';
      bar.style.display = 'none';
      note.style.display = 'none';
      html2pdf().set({
        margin: [10, 8, 10, 8],
        filename: 'AICS-Blank-GIS.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF: { unit: 'mm', format: 'legal', orientation: 'portrait' }
      }).from(document.body).save().then(function () {
        bar.style.display = '';
        note.style.display = '';
        btn.disabled = false;
        btn.innerHTML = '&#11015; Download GIS Form (PDF)';
      });
    }
  </script>
</body>
</html>
