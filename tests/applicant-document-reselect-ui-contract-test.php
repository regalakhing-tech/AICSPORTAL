<?php
declare(strict_types=1);

function document_ui_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$js = file_get_contents(__DIR__ . '/../Applicants/js files/applicant-pages.js');
document_ui_assert(is_string($js), 'Unable to read Applicant upload UI source.');
$requirements = file_get_contents(__DIR__ . '/../includes/document-requirements.php');
document_ui_assert(is_string($requirements), 'Unable to read document requirement matrix.');
document_ui_assert(str_contains($js, 'existingInfo ? \'\' : `<label class="upload-drop"'), 'Saved documents still render the replacement dropzone.');
document_ui_assert(!str_contains($js, '<i class="fas fa-upload"></i> Reselect'), 'Separate Reselect action remains.');
document_ui_assert(str_contains($js, 'aria-label="Reselect ${req.label}"'), 'Document photo does not identify its reselect action.');
document_ui_assert(!str_contains($js, 'Upload replacement file (optional)'), 'Redundant replacement prompt remains.');
document_ui_assert(str_contains($js, 'file.size > 25 * 1024 * 1024'), 'Client 25 MB replacement validation is missing.');
document_ui_assert(str_contains($js, "input.value = '';\n          return;"), 'Invalid replacement does not preserve the existing card state.');
document_ui_assert(str_contains($requirements, '$document(\'med_cert\', \'Medical Abstract or Medical Certificate\')'), 'Medical Abstract/Certificate is no longer the canonical med_cert OR-group slot.');
document_ui_assert(str_contains($js, 'name="doc_${req.id}" id="file-${req.id}" data-document-type="${req.id}"'), 'Generated file inputs no longer use the canonical requirement key for their unique ID and submission name.');
document_ui_assert(str_contains($js, 'data-upload-drop-zone data-upload-input-id="file-${req.id}"'), 'Dynamic upload zones do not explicitly map to their generated file input.');
document_ui_assert(str_contains($js, 'function initializeDynamicUploadDropZones(container)') && str_contains($js, 'container.dataset.uploadDropZonesReady = "true"'), 'Dynamic upload zones are not initialized once on the persistent container.');
document_ui_assert(str_contains($js, 'container.addEventListener("drop", (event) =>') && str_contains($js, 'input.dispatchEvent(new Event("change", { bubbles: true }))'), 'Dropped files do not enter the same change path as Browse File.');
document_ui_assert(str_contains($js, '["dragenter", "dragover"]') && str_contains($js, 'event.preventDefault();'), 'Dynamic upload dragover prevention is missing.');
document_ui_assert(str_contains($js, 'const transfer = new DataTransfer();') && str_contains($js, 'input.files = transfer.files;'), 'Dropped files are not synchronized to the actual file input.');

echo "Applicant document reselect UI contract test passed.\n";
