<?php
declare(strict_types=1);

$printEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<header class="report-print-header print-header">
  <div class="print-header-brand">
    <p class="print-agency">Provincial Social Welfare and Development Office</p>
    <p class="print-province">Province of Antique</p>
    <p class="print-program">Assistance to Individuals in Crisis Situation</p>
  </div>
  <h1 class="print-title"><?= $printEscape($printReportTitle ?? 'Application Report') ?></h1>
  <dl class="print-meta-row">
    <div><dt>Report Period</dt><dd><?= $printEscape($printPeriodLabel ?? '') ?></dd></div>
    <div><dt>Generated</dt><dd><?= $printEscape($printGeneratedAt ?? '') ?></dd></div>
    <div><dt>Prepared by</dt><dd><?= $printEscape($printPreparedBy ?? '') ?></dd></div>
  </dl>
</header>
