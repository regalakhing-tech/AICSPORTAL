<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/document-requirements.php';

/** Count uploaded files plus any required document types still missing. */
function pswdo_release_document_progress(array $requiredTypes, array $uploadedTypes, int $uploadedTotal): array
{
    $normalize = static function (array $types): array {
        $lookup = [];
        foreach ($types as $type) {
            $code = aics_normalize_document_type((string) $type);
            if ($code !== '') $lookup[$code] = true;
        }
        return $lookup;
    };
    $required = $normalize($requiredTypes);
    $uploaded = $normalize($uploadedTypes);
    $missing = count(array_diff_key($required, $uploaded));
    $uploadedTotal = max(0, $uploadedTotal);
    $expected = $uploadedTotal + $missing;
    $complete = $required !== [] && $missing === 0;
    $hint = $complete ? 'All required documents uploaded.'
        : ($missing > 0 ? $missing . ' required document' . ($missing === 1 ? '' : 's') . ' missing.' : 'No required document list available.');
    return [
        'uploaded_total' => $uploadedTotal,
        'expected_file_total' => $expected,
        'missing_total' => $missing,
        'requirements_complete' => $complete,
        'requirements_label' => $uploadedTotal . ' / ' . $expected . ' files',
        'requirements_hint' => $hint,
    ];
}

/** A static count with an icon and a readable completeness state. */
function pswdo_render_release_document_progress(array $item): void
{
    $complete = (bool) ($item['requirements_complete'] ?? false);
    $missing = max(0, (int) ($item['missing_total'] ?? 0));
    $uploaded = max(0, (int) ($item['uploaded_total'] ?? 0));
    $expected = max(0, (int) ($item['expected_file_total'] ?? 0));
    $state = $complete ? 'complete' : ($missing > 0 ? 'incomplete' : 'neutral');
    $caption = $complete ? 'Complete' : ($missing > 0 ? $missing . ' missing' : ($uploaded > 0 ? 'Uploaded' : 'No files'));
    $icon = $complete ? 'fa-file-circle-check' : 'fa-file-lines';
    ?>
    <div class="release-document-summary">
      <span class="release-document-count is-<?= $state ?>" title="<?= pswdo_h((string) ($item['requirements_hint'] ?? '')) ?>">
        <i class="fas <?= $icon ?>" aria-hidden="true"></i>
        <span><?= $uploaded ?> / <?= $expected ?> files</span>
      </span>
      <span class="release-document-caption is-<?= $state ?>"><?= pswdo_h($caption) ?></span>
    </div>
    <?php
}
