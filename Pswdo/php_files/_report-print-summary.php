<?php
declare(strict_types=1);

$printSummaryRows = [
    'Total applications submitted' => (int) ($summary['total_submitted'] ?? 0),
    'Approved applications' => (int) ($summary['approved'] ?? 0),
    'Applications not approved' => (int) ($summary['not_approved'] ?? 0),
    'Released assistance' => (int) ($summary['released'] ?? 0),
    'Applications under review' => (int) ($summary['under_review'] ?? 0),
];
?>
<section class="report-print-summary" aria-label="Application summary">
  <h2>Application Summary</h2>
  <?php if (($loadError ?? '') !== ''): ?>
    <p class="report-print-error"><strong>Report unavailable.</strong> The report data could not be loaded. Please try again before printing this report.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th scope="col">Report Item</th>
          <th scope="col">Number of Applications</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($printSummaryRows as $label => $value): ?>
          <tr>
            <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= number_format($value) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
