<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/ai-verdict-view.php';

/**
 * Render the AI verdict card. Shared by the request page and the live status
 * endpoint so a rescan shows the same card without a page reload. Every value
 * is escaped with pswdo_h().
 *
 * @param array<string,mixed> $view Result of aics_ai_verdict_view()
 * @param array{document_date:string,event_dates:string}|null $dateFacts
 */
function pswdo_ai_verdict_card_html(array $view, ?array $dateFacts, string $requiredFallback): string
{
    if (!($view['available'] ?? false)) return '';
    $statusIcon = $view['tone'] === 'success' ? 'fa-circle-check' : ($view['tone'] === 'danger' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation');
    ob_start();
    ?>
<section class="ai-verdict-card is-<?= pswdo_h($view['tone']) ?>" aria-label="AI document verification result">
  <header class="ai-verdict-banner">
    <div class="ai-verdict-banner-top">
      <span class="ai-verdict-status"><i class="fas <?= $statusIcon ?>" aria-hidden="true"></i> <?= pswdo_h($view['status_label']) ?></span>
      <span class="ai-verdict-source"><i class="fas <?= $view['source'] === 'gemini' ? 'fa-wand-magic-sparkles' : 'fa-laptop' ?>" aria-hidden="true"></i> <?= pswdo_h($view['source_label']) ?></span>
    </div>
    <h4 class="ai-verdict-headline"><?= pswdo_h($view['headline']) ?></h4>
    <p class="ai-verdict-reason"><?= pswdo_h($view['reason']) ?></p>
    <p class="ai-verdict-action"><i class="fas fa-hand-point-right" aria-hidden="true"></i> <span><strong>Staff action:</strong> <?= pswdo_h($view['action']) ?></span></p>
    <?php if ($view['notice'] !== ''): ?><p class="ai-verdict-notice"><?= pswdo_h($view['notice']) ?></p><?php endif; ?>
    <?php if (is_array($dateFacts)): ?><dl class="ai-flag-facts"><div><dt>Document / signature date</dt><dd><?= pswdo_h((string) ($dateFacts['document_date'] ?? '')) ?></dd></div><div><dt>Repeated event date</dt><dd><?= pswdo_h((string) ($dateFacts['event_dates'] ?? '')) ?></dd></div></dl><?php endif; ?>
  </header>
  <div class="ai-verdict-section">
    <p class="ai-verdict-section-title">Verification checks <span><?= (int) $view['passed_count'] ?> of 5 passed</span></p>
    <ul class="ai-verdict-checks">
      <?php foreach ($view['checks'] as $check): ?>
        <li class="ai-verdict-check is-<?= pswdo_h($check['state']) ?>">
          <i class="fas <?= pswdo_h($check['icon']) ?>" aria-hidden="true"></i>
          <div>
            <p class="ai-verdict-check-title"><?= pswdo_h($check['title']) ?> <span class="ai-verdict-check-label"><?= pswdo_h($check['label']) ?></span></p>
            <?php if ($check['reason'] !== ''): ?><p class="ai-verdict-check-reason"><?= pswdo_h($check['reason']) ?></p><?php endif; ?>
            <?php if ($check['missing'] !== []): ?><p class="ai-verdict-check-missing">Missing: <?= pswdo_h(implode(', ', $check['missing'])) ?></p><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="ai-verdict-section">
    <dl class="ai-verdict-type">
      <div><dt>Required document</dt><dd><?= pswdo_h($view['type']['required'] !== '' ? $view['type']['required'] : $requiredFallback) ?></dd></div>
      <div class="is-<?= pswdo_h($view['type']['state']) ?>"><dt>Detected document</dt><dd><i class="fas <?= pswdo_h($view['type']['icon']) ?>" aria-hidden="true"></i> <?= pswdo_h($view['type']['detected']) ?><?php if ($view['type']['title'] !== '' && strcasecmp($view['type']['title'], $view['type']['detected']) !== 0): ?> <small>Printed title: “<?= pswdo_h($view['type']['title']) ?>”</small><?php endif; ?></dd></div>
    </dl>
  </div>
  <?php if ($view['rows'] !== []): ?>
  <div class="ai-verdict-section">
    <p class="ai-verdict-section-title">Document vs. application</p>
    <div class="ai-verdict-compare-wrap">
      <table class="ai-verdict-compare">
        <thead><tr><th scope="col">Detail</th><th scope="col">On the document</th><th scope="col">In the application</th><th scope="col"><span class="sr-only">Result</span></th></tr></thead>
        <tbody>
          <?php foreach ($view['rows'] as $row): ?>
            <tr class="is-<?= pswdo_h($row['state']) ?>">
              <th scope="row"><?= pswdo_h($row['label']) ?></th>
              <td><?= pswdo_h($row['document']) ?></td>
              <td><?= pswdo_h($row['application']) ?></td>
              <td class="ai-verdict-compare-state"><?php if (!in_array($row['state'], ['info', 'not_compared'], true)): ?><i class="fas <?= pswdo_h($row['icon']) ?>" aria-hidden="true" title="<?= pswdo_h($row['note']) ?>"></i><span class="sr-only"><?= pswdo_h($row['note']) ?></span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
  <?php if (($view['metadata']['rows'] ?? []) !== []): ?>
  <div class="ai-verdict-section">
    <p class="ai-verdict-section-title">File metadata</p>
    <dl class="ai-verdict-metadata">
      <?php foreach ($view['metadata']['rows'] as $metaRow): ?>
        <div><dt><?= pswdo_h($metaRow['label']) ?></dt><dd><?= pswdo_h($metaRow['value']) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <p class="ai-verdict-metadata-note"><i class="fas fa-circle-info" aria-hidden="true"></i> <?= pswdo_h($view['metadata']['note']) ?></p>
  </div>
  <?php endif; ?>
  <p class="ai-verdict-footnote">AI results are decision support only. PSWDO staff make the final decision.</p>
</section>
    <?php
    return (string) ob_get_clean();
}

/** Short staff label for an evidence box on the document image. */
function pswdo_ai_indicator_label(string $type): string
{
    return match ($type) {
        'digital_text_overlay' => 'Typed-on text',
        'date_context_conflict' => 'Date conflict',
        'typography_mismatch', 'baseline_or_spacing_mismatch' => 'Font or spacing differs',
        'ink_or_color_mismatch' => 'Ink colour differs',
        'pasted_region_boundary' => 'Pasted area',
        'background_texture_discontinuity' => 'Background differs',
        'compression_or_resampling_difference' => 'Image quality differs',
        'copy_move_region' => 'Copied area',
        'repeated_visual_region' => 'Repeated mark',
        'erasure_or_cover_region' => 'Erased or covered',
        'handwritten_correction' => 'Written over',
        'value_context_conflict' => 'Value conflict',
        'signature_or_stamp_anomaly' => 'Signature or stamp',
        'sample_or_watermark' => 'Sample image',
        'metadata_date_conflict', 'file_format_mismatch' => 'File details',
        'invalid_pdf_signature', 'pdf_changed_after_signing' => 'PDF signature',
        'ai_generated_content' => 'Made with AI',
        default => 'Check this area',
    };
}

/**
 * Evidence boxes for the image viewer: de-duplicated, labelled, and toned.
 * "strong" (red) = high-confidence or inherently strong evidence; "hint" (amber)
 * = weaker signals staff should glance at. Strong items come first.
 *
 * @param array<int,mixed> $indicators Sanitized tamper indicators
 * @return array<int,array<string,mixed>>
 */
function pswdo_ai_evidence_items(array $indicators): array
{
    $items = [];
    $seen = [];
    foreach ($indicators as $item) {
        if (!is_array($item)) continue;
        $type = (string) ($item['type'] ?? '');
        $key = $type . '|' . (int) ($item['page'] ?? 1) . '|' . json_encode($item['box_2d'] ?? null);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $band = (string) ($item['confidence_band'] ?? 'moderate');
        $strong = $band === 'high';  // red only for high-confidence findings; everything else is an amber hint
        $items[] = array_merge($item, [
            'page' => max(1, (int) ($item['page'] ?? 1)),
            'label' => pswdo_ai_indicator_label($type),
            'tone' => $strong ? 'strong' : 'hint',
            'reason' => trim((string) ($item['reason'] ?? '')) ?: 'Inspect this area in the original document.',
        ]);
    }
    usort($items, static fn (array $a, array $b): int => ($a['tone'] === 'strong' ? 0 : 1) <=> ($b['tone'] === 'strong' ? 0 : 1));
    return array_slice($items, 0, 12);
}

/** Staff name for each automated check. */
function pswdo_ai_provider_label(string $name): string
{
    return match ($name) {
        'gemini' => 'AICS AI image reading',
        'semantic_chronology' => 'Date consistency',
        'local_regional' => 'Font and spacing comparison',
        'local_visual' => 'Pixel forensics (typed-on text, copies, erasures)',
        'metadata' => 'File metadata',
        'qr' => 'QR code reader',
        'adcd_net' => 'Deep-learning tamper model',
        default => ucwords(str_replace('_', ' ', $name)),
    };
}
