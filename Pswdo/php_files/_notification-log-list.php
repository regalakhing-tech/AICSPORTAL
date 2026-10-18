<?php
declare(strict_types=1);

$notificationStageMeta = static function (string $stage): array {
    $value = strtolower(trim($stage));

    if (str_contains($value, 'mswdo')) {
        return ['class' => 'stage-neutral', 'icon' => 'fa-building', 'icon_class' => 'notification-icon-mswdo'];
    }
    if (str_contains($value, 'not approv') || str_contains($value, 'reject') || str_contains($value, 'disapprov')) {
        return ['class' => 'stage-rejected', 'icon' => 'fa-circle-xmark', 'icon_class' => 'notification-icon-danger'];
    }
    if (str_contains($value, 'approv')) {
        return ['class' => 'stage-approved', 'icon' => 'fa-circle-check', 'icon_class' => 'notification-icon-approved'];
    }
    if (str_contains($value, 'sched') || str_contains($value, 'interview')) {
        return ['class' => 'stage-scheduled', 'icon' => 'fa-calendar-check', 'icon_class' => 'notification-icon-scheduled'];
    }
    if (str_contains($value, 'releas') || str_contains($value, 'disburs')) {
        return ['class' => 'stage-released', 'icon' => 'fa-hand-holding-heart', 'icon_class' => 'notification-icon-released'];
    }
    if (str_contains($value, 'screen') || str_contains($value, 'review') || str_contains($value, 'pend')) {
        return ['class' => 'stage-pending', 'icon' => 'fa-clock', 'icon_class' => 'notification-icon-pending'];
    }

    return ['class' => 'stage-neutral', 'icon' => 'fa-comment-sms', 'icon_class' => 'notification-icon-neutral'];
};
?>
<?php $hiddenNotificationCount = max(0, count($notificationLogs) - 5); ?>
<div class="notification-activity-feed" data-notification-feed>
<div class="notification-activity-list" id="report-notification-activity-list">
  <?php foreach ($notificationLogs as $notificationIndex => $item): ?>
    <?php
      $stage = trim((string) ($item['stage'] ?? 'General Update')) ?: 'General Update';
      $meta = $notificationStageMeta($stage);
      $sender = trim((string) ($item['sent_by'] ?? '')) ?: 'System';
    ?>
    <details class="notification-activity-item <?= pswdo_h($meta['class']) ?>"<?= $notificationIndex >= 5 ? ' data-notification-overflow hidden' : '' ?>>
      <summary class="notification-activity-summary">
        <span class="notification-activity-icon <?= pswdo_h($meta['icon_class']) ?>" aria-hidden="true">
          <i class="fas <?= pswdo_h($meta['icon']) ?>"></i>
        </span>

        <span class="notification-identity">
          <span class="notification-reference"><?= pswdo_h((string) ($item['reference_no'] ?? '-')) ?></span>
          <strong><?= pswdo_h((string) ($item['beneficiary_name'] ?? 'Applicant')) ?></strong>
        </span>

        <span class="stage-tag <?= pswdo_h($meta['class']) ?>"><?= pswdo_h($stage) ?></span>

        <span class="notification-message-preview"><?= pswdo_h((string) ($item['message'] ?? '')) ?></span>

        <span class="notification-activity-meta">
          <span><i class="far fa-clock"></i> <?= pswdo_h((string) ($item['sent_at_label'] ?? 'Not available')) ?></span>
          <span><i class="far fa-user"></i> <?= pswdo_h($sender) ?></span>
        </span>

        <span class="notification-details-label">View details <i class="fas fa-chevron-down"></i></span>
      </summary>

      <div class="notification-message-full">
        <span>Full message</span>
        <p><?= nl2br(pswdo_h((string) ($item['message'] ?? '')), false) ?></p>
      </div>
    </details>
  <?php endforeach; ?>
</div>
<?php if ($hiddenNotificationCount > 0): ?>
  <div class="notification-see-more-wrap">
    <button
      type="button"
      class="notification-see-more-btn"
      data-notification-show-more
      aria-controls="report-notification-activity-list"
      aria-expanded="false"
    >
      See More Notifications
      <span><?= (int) $hiddenNotificationCount ?> more</span>
    </button>
  </div>
<?php endif; ?>
</div>
