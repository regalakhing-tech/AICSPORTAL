<?php
declare(strict_types=1);
?>
<?php if ($loadError !== ''): ?>
  <p class="report-print-error"><strong>Report unavailable.</strong> The list data could not be loaded. Please try again before printing this report.</p>
<?php elseif (count($reportItems) === 0): ?>
  <p class="empty-note">No released beneficiaries found for the selected date range.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="report-table" style="min-width:900px;">
      <thead>
        <tr>
          <th scope="col" style="width:36px;">Number</th>
          <th scope="col">Reference Number</th>
          <th scope="col">Beneficiary or Patient Name</th>
          <th scope="col">Applicant Name</th>
          <th scope="col">Address</th>
          <th scope="col">Classification</th>
          <th scope="col">Type of Assistance</th>
          <th scope="col">Date Applied</th>
          <th scope="col">Release Date</th>
          <th scope="col">Release Time</th>
          <th scope="col">Venue</th>
          <th scope="col">Batch</th>
          <th scope="col">Date Recorded</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($reportItems as $i => $row): ?>
          <tr>
            <td style="text-align:center;"><?= $i + 1 ?></td>
            <td><strong><?= pswdo_h($row['reference_no']) ?></strong></td>
            <td><?= pswdo_h($row['beneficiary_name']) ?></td>
            <td><?= pswdo_h($row['applicant_name']) ?></td>
            <td><?= pswdo_h($row['address']) ?></td>
            <td><?= pswdo_h($row['categories']) ?></td>
            <td><?= pswdo_h($row['assistance']) ?></td>
            <td><?= pswdo_h($row['date_applied']) ?></td>
            <td><?= pswdo_h($row['release_date']) ?></td>
            <td><?= pswdo_h($row['release_time']) ?></td>
            <td><?= pswdo_h($row['venue']) ?></td>
            <td><?= pswdo_h($row['batch']) ?></td>
            <td><?= pswdo_h($row['released_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($loadError === ''): ?>
  <div class="report-signature">
    <div class="sig-block">
      <div class="sig-line"></div>
      <strong><?= pswdo_h($displayName) ?></strong>
      <p>Prepared by</p>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <strong>PSWDO Head or MSWDO</strong>
      <p>Noted by</p>
    </div>
  </div>
<?php endif; ?>
