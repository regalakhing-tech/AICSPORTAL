<?php
declare(strict_types=1);

function ui_refinement_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$login = file_get_contents(__DIR__ . '/../Applicants/login.html');
$loginCss = file_get_contents(__DIR__ . '/../Applicants/css files/login.css');
$loginJs = file_get_contents(__DIR__ . '/../Applicants/js files/login.js');
$submit = file_get_contents(__DIR__ . '/../Applicants/php_files/submit-request.php');
$applicantJs = file_get_contents(__DIR__ . '/../Applicants/js files/applicant-pages.js');
$applicantCss = file_get_contents(__DIR__ . '/../Applicants/css files/applicant-pages.css');
$profileJs = file_get_contents(__DIR__ . '/../Applicants/js files/my-profile.js');
$profileSave = file_get_contents(__DIR__ . '/../Applicants/php_files/profile-save.php');
$saveRequest = file_get_contents(__DIR__ . '/../Applicants/php_files/save-request.php');
$draftLib = file_get_contents(__DIR__ . '/../Applicants/php_files/draft-lib.php');
$overview = file_get_contents(__DIR__ . '/../Applicants/php_files/overview-dashboard.php');
$pswdoCss = file_get_contents(__DIR__ . '/../Pswdo/css files/pswdo-pages.css');
foreach ([$login, $loginCss, $loginJs, $submit, $applicantJs, $applicantCss, $profileJs, $profileSave, $saveRequest, $draftLib, $overview, $pswdoCss] as $source) {
    ui_refinement_assert(is_string($source), 'A required UI source could not be read.');
}

ui_refinement_assert(str_contains($loginCss, '.auth-stage.is-register-view') && str_contains($loginCss, 'width: min(920px'), 'Signup does not have a centered, signup-specific width.');
ui_refinement_assert(str_contains($loginCss, '.auth-stage.is-login-view') && str_contains($loginCss, 'width: min(760px'), 'The established login-stage width is not preserved.');
$gridOrder = '"first-name last-name"' . "\n" . '    "middle-name email"' . "\n" . '    "phone otp"' . "\n" . '    "password confirm-password"';
ui_refinement_assert(str_contains($loginCss, $gridOrder), 'Desktop signup field order is incorrect.');
ui_refinement_assert(str_contains($login, 'Middle Name <span class="field-optional">(if any)</span>') && str_contains($login, 'name="middle_name"') && !str_contains($login, 'maxlength="1"') && !str_contains($login, 'Middle Initial'), 'Sign-up must ask for the full middle name, not an initial.');
ui_refinement_assert(str_contains($login, 'id="register-otp"') && !preg_match('/id="register-otp"[^>]+\srequired(?:\s|>)/s', $login), 'The optional OTP vessel is missing or required.');
ui_refinement_assert((bool) preg_match('/<button type="button" class="btn signup-otp-button">Send OTP<\/button>/', $login), 'Send OTP is not an inert type=button control.');
ui_refinement_assert(!str_contains($loginJs, 'register-otp'), 'OTP acquired JavaScript behavior.');
ui_refinement_assert(!str_contains($login, 'class="signup-password-help"') && str_contains($login, 'register-password-error') && str_contains($loginJs, "password.addEventListener('invalid'"), 'Password requirements are not failure-only.');

ui_refinement_assert(!str_contains($overview, 'section-chip-live') && !preg_match('/>\s*Live\s*</i', $overview), 'Latest Request still renders the Live chip.');
ui_refinement_assert(str_contains($submit, '>Go to Profile</a>'), 'Submit Request lacks Go to Profile navigation.');
ui_refinement_assert(str_contains($profileJs, 'Profile completed successfully.') && str_contains($profileJs, 'Profile changes saved successfully.') && str_contains($profileJs, 'profile_complete_before_save') && str_contains($profileJs, 'profileWasComplete'), 'Profile completion/update feedback is incomplete.');
ui_refinement_assert(str_contains($profileSave, "'profile_complete_before_save' => \$completionBeforeSave['complete']") && str_contains($profileSave, "'profile_complete_after_save' => \$completion['complete']"), 'Profile save does not return the authoritative completion transition.');

ui_refinement_assert(str_contains($submit, 'data-application-date=') && str_contains($submit, 'id="date-applied-display"') && str_contains($submit, 'type="hidden" name="date_applied"'), 'New request date is not rendered as server-provided, non-editable application metadata.');
ui_refinement_assert(!str_contains($submit, '<input type="date" name="date_applied"'), 'Applicant can still open a Date of Application picker.');
ui_refinement_assert(str_contains($applicantJs, 'form.dataset.applicationDate') && str_contains($applicantJs, 'syncApplicationDateDisplay'), 'Client date display does not reuse the server-rendered application date.');
ui_refinement_assert(str_contains($applicantJs, 'if (name === "date_applied") return;'), 'A resumed draft can restore an obsolete application date.');
ui_refinement_assert(str_contains($saveRequest, "aics_current_application_date()") && str_contains($saveRequest, "\$requestPost['date_applied'] = \$isEditMode") && str_contains($saveRequest, "\$editContext['date_applied']"), 'Server does not override manipulated new-request dates while preserving historical edit dates.');

ui_refinement_assert(substr_count($submit, '>Same as Applicant</label>') === 1 && (bool) preg_match('/<input type="checkbox" name="applicant_is_beneficiary" id="applicant-is-beneficiary"/s', $submit), 'Step 1 does not expose exactly one native Same as Applicant checkbox.');
ui_refinement_assert(!str_contains($submit, 'Same as Applicant Address') && !str_contains($submit, 'Applicant is the Beneficiary'), 'A legacy split applicant-copy choice remains visible.');
ui_refinement_assert(str_contains($applicantJs, 'beneficiaryManualSnapshot') && str_contains($applicantJs, 'applyApplicantAddressToBeneficiary(source)') && str_contains($applicantJs, 'sourceValue("applicant_first_name"'), 'The unified choice does not copy and restore both information groups.');
ui_refinement_assert(str_contains($applicantJs, 'function applyApplicantAddressToBeneficiary(source)') && str_contains($applicantJs, 'selectExistingOption(municipalitySelect, sourceMunicipality)') && str_contains($applicantJs, 'selectExistingOption(barangaySelect, sourceBarangay)'), 'Same as Applicant does not populate dependent beneficiary address selections in order.');
ui_refinement_assert(str_contains($applicantJs, 'source?.sitio_purok ?? source?.address_line') && str_contains($applicantJs, 'Please complete your profile address first'), 'Same as Applicant lacks profile-address fallback and incomplete-address feedback.');
ui_refinement_assert(str_contains($applicantJs, 'applicantIsBeneficiary.dispatchEvent(new Event("change", { bubbles: true }))'), 'A restored checked Same as Applicant state does not reapply the beneficiary address.');
ui_refinement_assert(str_contains($applicantCss, '.same-as-applicant-control input[type="checkbox"]') && str_contains($applicantCss, 'position: static;'), 'Same as Applicant still inherits the card-style hidden checkbox treatment.');
ui_refinement_assert(str_contains($applicantCss, '.beneficiary-address-grid') && str_contains($applicantCss, 'margin-top: 26px;'), 'Step 1 beneficiary address spacing is not explicitly balanced.');
ui_refinement_assert(str_contains($profileJs, 'showSavedFeedback(successMessage)') && str_contains($profileJs, 'window.setTimeout(() => window.location.assign(returnTo), 900)'), 'Profile save confirmation is not visibly rendered before an authorized return.');
ui_refinement_assert(str_contains($applicantCss, '.step-layout-grid {') && str_contains($applicantCss, 'align-items: start;') && str_contains($applicantCss, '.step-panel-card {') && str_contains($applicantCss, 'align-self: start;'), 'Short Submit Request cards still stretch to a taller sibling.');
ui_refinement_assert(str_contains($applicantCss, '.intake-shell-senior #aics-intake-form') && str_contains($applicantCss, 'min-height: 0;') && str_contains($applicantCss, 'column-gap: 24px;') && str_contains($applicantCss, 'row-gap: 18px;'), 'Submit Request does not use a content-driven, balanced form grid.');
ui_refinement_assert(str_contains($submit, '<label>Highest Educational Attainment</label>') && !str_contains($submit, '<div class="field field-full">\n                      <label>Highest Educational Attainment</label>'), 'Applicant education still creates an unintended full-width grid row.');
ui_refinement_assert(str_contains($submit, 'id="applicant-address-province"') && str_contains($submit, 'id="applicant-address-municipality"') && str_contains($submit, 'id="applicant-address-barangay"') && str_contains($submit, 'id="applicant-address-line"'), 'Applicant address panel is not structured into profile-address components.');
ui_refinement_assert(str_contains($applicantJs, 'function syncApplicantAddressPresentation(source)') && str_contains($applicantJs, 'syncApplicantAddressPresentation(values);') && str_contains($applicantJs, 'syncApplicantAddressPresentation(p);'), 'Structured applicant address presentation is not hydrated from existing profile/edit data.');
ui_refinement_assert((bool) preg_match('/<input type="checkbox" name="family_members_not_applicable" id="family-members-not-applicable"/s', $submit), 'Family Not Applicable is not a native checkbox.');
ui_refinement_assert(str_contains($applicantJs, 'function setFamilyNotApplicable(active)') && str_contains($applicantJs, "#family-members-not-applicable") && str_contains($applicantJs, 'familySummary = form.querySelector'), 'Family Not Applicable does not update validation, entry visibility, and review summary.');
ui_refinement_assert(str_contains($saveRequest, 'normalize_family_composition($post, $familyNotApplicable)') && str_contains($saveRequest, "[['not_applicable' => true]]") && str_contains($saveRequest, 'Family Composition is required unless Not Applicable is selected.'), 'Server-side family Not Applicable validation is incomplete.');
ui_refinement_assert(str_contains($applicantJs, 'const checkboxStates = {};') && str_contains($applicantJs, "form.querySelectorAll('input[type=\"checkbox\"][name]')"), 'Submit Request does not discover every named checkbox for draft persistence.');
ui_refinement_assert(str_contains($applicantJs, 'checkboxStates[field.name] = field.checked;') && str_contains($applicantJs, 'data.checkbox_states = checkboxStates;'), 'Checkbox draft serialization does not explicitly retain checked and unchecked states.');
ui_refinement_assert(str_contains($applicantJs, 'function restoreCheckboxStates(states, applyDefaults = false)') && str_contains($applicantJs, 'checkbox.checked = checkboxStateIsChecked(savedStates[checkbox.name]);'), 'Checkbox draft restoration does not use boolean checked state.');
ui_refinement_assert(str_contains($applicantJs, 'restored.forEach((checkbox) => checkbox.dispatchEvent(new Event("change", { bubbles: true })))'), 'Restored checkbox state does not replay existing dependent UI handlers.');
ui_refinement_assert(str_contains($applicantJs, 'restoreCheckboxStates({}, true);') && str_contains($applicantJs, 'beneficiaryManualSnapshot = null;'), 'Discard Draft does not reset checkbox state and its dependent snapshot state.');
ui_refinement_assert(str_contains($draftLib, "'checkbox_states'") && str_contains($draftLib, "['checkbox_states'][\$name]"), 'Draft payload allowlist does not preserve generic checkbox state.');
preg_match_all('/<input\s+type="checkbox"\s+name="([^"]+)"/i', $submit, $submitCheckboxMatches);
$submitCheckboxNames = $submitCheckboxMatches[1] ?? [];
sort($submitCheckboxNames);
ui_refinement_assert($submitCheckboxNames === ['applicant_is_beneficiary', 'family_members_not_applicable'], 'Submit Request checkbox inventory changed without draft-persistence coverage.');
require_once __DIR__ . '/../Applicants/php_files/draft-lib.php';
$checkboxPayload = aics_draft_payload(['checkbox_states' => ['applicant_is_beneficiary' => true, 'family_members_not_applicable' => false]]);
ui_refinement_assert(($checkboxPayload['checkbox_states']['applicant_is_beneficiary'] ?? null) === true && ($checkboxPayload['checkbox_states']['family_members_not_applicable'] ?? null) === false, 'Draft payload does not preserve both checkbox boolean states.');

ui_refinement_assert(str_contains($applicantJs, 'data-upload-drop-zone') && str_contains($applicantJs, 'new DataTransfer()') && str_contains($applicantJs, 'input.dispatchEvent(new Event("change", { bubbles: true }))'), 'Per-slot drag/drop does not reuse the file-input change path.');
ui_refinement_assert(str_contains($applicantJs, 'const allowedExtensions = ["pdf", "jpg", "jpeg", "png"]') && str_contains($applicantJs, 'const maxFileSize = 25 * 1024 * 1024'), 'Existing upload validation rules changed.');
ui_refinement_assert(str_contains($applicantCss, '.upload-drop.is-dragover'), 'Dragover feedback styling is missing.');
ui_refinement_assert(str_contains($applicantJs, 'const payload = await submitFormData();') && str_contains($applicantJs, '}, 900);') && !str_contains($applicantJs, '}, 2500);'), 'Post-success timing is not server-authoritative and approximately one second.');

ui_refinement_assert(str_contains($pswdoCss, '.map-density-legend {') && str_contains($pswdoCss, 'bottom: 44px; z-index: 5;'), 'Map legend is not kept in the content layer.');
ui_refinement_assert(str_contains($pswdoCss, '.sidebar {') && str_contains($pswdoCss, 'z-index: 110;') && str_contains($pswdoCss, '.sidebar-overlay {') && str_contains($pswdoCss, 'z-index: 100;'), 'Sidebar/backdrop layers no longer sit above map controls.');
ui_refinement_assert(str_contains($pswdoCss, '.confirm-modal {') && str_contains($pswdoCss, 'z-index: 340;'), 'Modal layering no longer remains above the sidebar.');

echo "Applicant submit UI refinement contract test passed.\n";
