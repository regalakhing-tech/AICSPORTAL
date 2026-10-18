<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/auth-common.php';

aics_start_session();

$scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
$scriptDirectory = rtrim($scriptDirectory, '/');

$isPhpFilesRoute = str_ends_with($scriptDirectory, '/php_files');
$applicantsBaseUrl = $isPhpFilesRoute ? dirname($scriptDirectory) : $scriptDirectory;
$phpFilesBaseUrl = $isPhpFilesRoute ? $scriptDirectory : $scriptDirectory . '/php_files';
$dashboardUrl = $applicantsBaseUrl . '/dashboard.php';
$projectRootUrl = dirname(dirname($applicantsBaseUrl));
$assetCssVersion = (string) (filemtime(__DIR__ . '/../css files/applicant-pages.css') ?: time());
$assetJsVersion = (string) (filemtime(__DIR__ . '/../js files/applicant-pages.js') ?: time());
$currentAccountStatusCode = aics_current_session_status_code();
$forceBannedModal = aics_should_force_banned_modal();
$displayName = aics_current_session_display_name();
$initials = aics_current_session_initials();

$profileGetUrl = $phpFilesBaseUrl . '/profile-get.php';
$profileSaveUrl = $phpFilesBaseUrl . '/profile-save.php';
$profileIdPhotoUrl = $phpFilesBaseUrl . '/profile-id-photo.php';
$logoutUrl = $phpFilesBaseUrl . '/auth-logout.php';

$logoUrl = $projectRootUrl . '/asset/logo.png';
$cssUrl = $applicantsBaseUrl . '/css files/applicant-pages.css?v=' . urlencode($assetCssVersion);
$appJsUrl = $applicantsBaseUrl . '/js files/applicant-pages.js?v=' . urlencode($assetJsVersion);
$barangaysJsUrl = $applicantsBaseUrl . '/js files/antique-barangays.js';
$profileJsVersion = (string) (filemtime(__DIR__ . '/../js files/my-profile.js') ?: time());
$profileJsUrl = $applicantsBaseUrl . '/js files/my-profile.js?v=' . urlencode($profileJsVersion);
$educationOptions = aics_education_options();
$occupationOptions = aics_occupation_options();
$returnTo = '';
$returnCandidate = trim((string) ($_GET['return_to'] ?? ''));
if ($returnCandidate !== '') {
  $parts = parse_url($returnCandidate);
  $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
  parse_str(is_array($parts) ? (string) ($parts['query'] ?? '') : '', $query);
  $allowedPages = ['overview', 'documents', 'status', 'cancel-request', 'notifications'];
  $isDashboardReturn = str_ends_with($path, '/Applicants/dashboard.php') && in_array((string) ($query['page'] ?? ''), $allowedPages, true);
  $allowedDirectPages = ['overview-dashboard.php', 'documents.php', 'application-status.php', 'cancel-request.php', 'notifications.php'];
  $isDirectReturn = str_contains($path, '/Applicants/php_files/') && in_array(basename($path), $allowedDirectPages, true);
  if (!isset($parts['scheme'], $parts['host']) && ($isDashboardReturn || $isDirectReturn)) $returnTo = $returnCandidate;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>AICS Applicant | My Profile</title>
<link rel="icon" type="image/png" href="/AICS/AICS/asset/favicon.png">
<link rel="apple-touch-icon" href="/AICS/AICS/asset/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body data-account-status="<?= htmlspecialchars($currentAccountStatusCode, ENT_QUOTES, 'UTF-8') ?>" data-banned-modal-required="<?= $forceBannedModal ? '1' : '0' ?>">
<div class="app-container">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=overview" class="logo" style="padding-top: 4px;">
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="AICS | PSWDO Logo" style="height: 42px; width: auto; object-fit: contain;">
      </a>
      <button type="button" class="toggle-btn" id="toggle-btn" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
    </div>

    <div class="nav-menu">
      <div class="nav-header">Main Menu</div>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=overview" class="nav-item "><i class="fas fa-border-all"></i> Overview Dashboard</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=profile" class="nav-item active"><i class="fas fa-user-circle"></i> My Profile</a>

      <div class="nav-header nav-header-gap">Application</div>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=submit" class="nav-item " data-submit-request-nav data-submit-request-href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=submit"><i class="fas fa-file-signature"></i> Submit Application</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=documents" class="nav-item "><i class="fas fa-file-invoice"></i> Documents</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=status" class="nav-item "><i class="fas fa-route"></i> Application Status</a>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=cancel-request" class="nav-item "><i class="fas fa-pen-to-square"></i> Manage Applications</a>

      <div class="nav-header nav-header-gap">Support</div>
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=notifications" class="nav-item "><i class="fas fa-bell"></i> Notifications</a>
    </div>

    <div class="sidebar-footer">
      <a href="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Log Out</a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="overlay"></div>

  <main class="main-content" id="main-content">
    <header class="top-nav">
      <div class="nav-left">
        <div class="top-nav-page-info">
          <span class="top-nav-badge"><i class="fas fa-user-circle"></i></span>
          <div class="top-nav-text">
            <span class="top-nav-sub">Main Menu</span>
            <h1 class="top-nav-heading">My Profile</h1>
          </div>
        </div>
      </div>

      <div class="nav-right">
        <a class="btn-icon" data-notification-trigger href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=notifications" aria-label="Notifications" aria-expanded="false">
          <i class="fas fa-bell"></i>
          <span class="dot" hidden aria-hidden="true"></span>
        </a>
        <div class="profile-chip">
          <div class="profile-av"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
          <span><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </header>

    <div class="content-area content-area-senior profile-page-content">
      <section class="section-card profile-hero-executive">
        <div class="profile-hero-executive-main">
          <div class="profile-hero-badge-row">
            <span class="profile-hero-pill">
              <span class="status-pulse-dot"></span>
              Live Account Profile
            </span>
            <span class="profile-hero-ref-pill">
              <i class="fas fa-id-card"></i> Applicant Record
            </span>
          </div>
          <div class="profile-hero-copy">
            <h1 class="page-title">My Profile</h1>
            <p class="page-sub">Logged in as <strong id="summary-full-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></strong>. Keep your personal and contact details updated for your AICS requests.</p>
          </div>
        </div>

        <div class="profile-hero-executive-actions">
          <?php if ($returnTo !== ''): ?>
            <a href="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>" class="btn-profile-hero-secondary">
              <i class="fas fa-arrow-left"></i>
              <span>Back to Previous</span>
            </a>
          <?php else: ?>
            <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>?page=overview" class="btn-profile-hero-secondary">
              <i class="fas fa-border-all"></i>
              <span>Overview Dashboard</span>
            </a>
          <?php endif; ?>
        </div>
      </section>

      <?php if ((string) ($_GET['profile_notice'] ?? '') === 'incomplete'): ?>
        <div class="senior-alert senior-alert-error"><i class="fas fa-circle-info"></i><span>Complete your profile before submitting an application. Update the required information below.</span></div>
      <?php endif; ?>

      <div class="profile-layout profile-layout-senior">
        <section class="section-card profile-summary-senior profile-summary-executive">
          <div class="profile-summary-header">
            <div class="profile-summary-avatar-wrap">
              <div class="profile-summary-avatar" id="summary-avatar"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
              <span class="profile-summary-online-dot" title="Active Account"></span>
            </div>
            <h2 class="profile-summary-name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h2>
            <span class="profile-summary-badge"><i class="fas fa-user-check"></i> Registered Applicant</span>
            <span class="profile-summary-email-sub" id="summary-email">-</span>
          </div>

          <div class="profile-summary-divider"></div>

          <div class="panel-head panel-head-senior">
            <div class="panel-title-wrap">
              <div class="panel-title-icon tone-brand"><i class="fas fa-id-badge"></i></div>
              <div>
                <h3 class="panel-title">Account Summary</h3>
                <p class="panel-sub">Linked safely to your citizen login.</p>
              </div>
            </div>
          </div>

          <div class="profile-kv-list">
            <div class="profile-kv-item">
              <div class="profile-kv-icon tone-success"><i class="fas fa-phone"></i></div>
              <div class="profile-kv-copy">
                <span>Phone Number</span>
                <strong id="summary-phone">-</strong>
              </div>
            </div>
            <div class="profile-kv-item">
              <div class="profile-kv-icon tone-info"><i class="fas fa-location-dot"></i></div>
              <div class="profile-kv-copy">
                <span>Home Address</span>
                <strong id="summary-address">-</strong>
              </div>
            </div>
            <div class="profile-kv-item">
              <div class="profile-kv-icon tone-warning"><i class="fas fa-user-tag"></i></div>
              <div class="profile-kv-copy">
                <span>Account Role</span>
                <strong>Applicant</strong>
              </div>
            </div>
          </div>

          <section class="profile-id-card" id="profile-id-card" data-photo-url="<?= htmlspecialchars($profileIdPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" hidden aria-labelledby="profile-id-title">
            <div class="profile-id-card-heading">
              <div>
                <h3 id="profile-id-title">Sign-up ID</h3>
                <p>View the ID photos you submitted when creating your account.</p>
              </div>
              <i class="fas fa-id-card" aria-hidden="true"></i>
            </div>
            <p class="profile-id-type" id="profile-id-type"></p>
            <div class="profile-id-photos">
              <button type="button" class="profile-id-photo" data-id-side="front" aria-label="Open front of sign-up ID">
                <img alt="Front of sign-up ID" loading="lazy">
                <span>Front <i class="fas fa-magnifying-glass-plus" aria-hidden="true"></i></span>
              </button>
              <button type="button" class="profile-id-photo" data-id-side="back" aria-label="Open back of sign-up ID">
                <img alt="Back of sign-up ID" loading="lazy">
                <span>Back <i class="fas fa-magnifying-glass-plus" aria-hidden="true"></i></span>
              </button>
            </div>
          </section>

          <div class="profile-tip-card">
            <div class="profile-tip-icon">
              <i class="fas fa-lightbulb"></i>
            </div>
            <div class="profile-tip-copy">
              <strong>Stay Reachable</strong>
              <span>Keep your contact details up to date so AICS officers can reach you quickly.</span>
            </div>
          </div>
        </section>

        <section class="section-card profile-edit-senior profile-edit-executive">
          <div class="profile-edit-header">
            <div class="panel-title-wrap">
              <div class="panel-title-icon tone-info"><i class="fas fa-pen-to-square"></i></div>
              <div>
                <h2 class="panel-title">Update My Information</h2>
                <p class="panel-sub">Change any detail below, then press Save.</p>
              </div>
            </div>
          </div>

          <form
            id="profile-update-form"
            class="profile-form profile-form-senior"
            data-get-url="<?= htmlspecialchars($profileGetUrl, ENT_QUOTES, 'UTF-8') ?>"
            data-save-url="<?= htmlspecialchars($profileSaveUrl, ENT_QUOTES, 'UTF-8') ?>"
            data-return-to="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>"
            novalidate
          >
            <div class="profile-fieldset">
              <div class="profile-fieldset-header">
                <div class="profile-group-pill">
                  <span class="profile-group-num">1</span>
                  <span class="profile-group-tag">Personal Details</span>
                </div>
              </div>
              <div class="form-grid">
                <div class="field">
                  <label for="profile-first-name">First Name <span class="field-required">*</span></label>
                  <input type="text" id="profile-first-name" name="first_name" placeholder="Enter first name" required>
                </div>

                <div class="field">
                  <label for="profile-last-name">Last Name <span class="field-required">*</span></label>
                  <input type="text" id="profile-last-name" name="last_name" placeholder="Enter last name" required>
                </div>

                <div class="field">
                  <label for="profile-middle-name">Middle Name <span class="field-optional">(Optional)</span></label>
                  <input type="text" id="profile-middle-name" name="middle_name" placeholder="Enter middle name">
                </div>

                <div class="field">
                  <label for="profile-extension-name">Extension Name <span class="field-optional">(Optional)</span></label>
                  <input type="text" id="profile-extension-name" name="extension_name" maxlength="20" placeholder="Jr., Sr., III">
                </div>

                <div class="field">
                  <label for="profile-date-of-birth">Date of Birth <span class="field-required">*</span></label>
                  <input type="date" id="profile-date-of-birth" name="date_of_birth" required>
                </div>

                <div class="field">
                  <label for="profile-age">Age <span class="field-auto-pill">Calculated</span></label>
                  <input type="number" id="profile-age" readonly class="readonly-field" aria-readonly="true" placeholder="Auto">
                </div>

                <div class="field">
                  <label for="profile-civil-status">Civil Status <span class="field-required">*</span></label>
                  <select id="profile-civil-status" name="civil_status" required>
                    <option value="" selected disabled hidden>Select civil status</option>
                    <?php foreach (aics_civil_status_options() as $value => $label): ?>
                      <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="field" id="profile-civil-status-other-field" hidden>
                  <label for="profile-civil-status-other">Specify Civil Status <span class="field-required">*</span></label>
                  <input type="text" id="profile-civil-status-other" name="civil_status_other" maxlength="50" placeholder="Specify civil status">
                </div>

                <div class="field">
                  <label for="profile-education">Highest Educational Attainment <span class="field-required">*</span></label>
                  <select id="profile-education" name="highest_educational_attainment" required>
                    <option value="" selected disabled hidden>Select educational attainment</option>
                    <?php foreach ($educationOptions as $option): ?>
                      <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="profile-fieldset">
              <div class="profile-fieldset-header">
                <div class="profile-group-pill">
                  <span class="profile-group-num">2</span>
                  <span class="profile-group-tag">Contact & Socio-Economic</span>
                </div>
              </div>
              <div class="form-grid">
                <div class="field">
                  <label for="profile-contact-number">Phone Number <span class="field-required">*</span></label>
                  <input type="tel" id="profile-contact-number" name="contact_number" placeholder="09XXXXXXXXX" pattern="(?:09[0-9]{9}|639[0-9]{9})" inputmode="numeric" maxlength="12" data-digits-only required>
                </div>

                <div class="field field-full">
                  <label for="profile-email">Email Address <span class="field-optional">(Optional)</span></label>
                  <input type="email" id="profile-email" name="email" placeholder="name@example.com">
                </div>

                <div class="field">
                  <label for="profile-occupation">Occupation / Source of Income <span class="field-required">*</span></label>
                  <select id="profile-occupation" name="occupation" required>
                    <option value="" selected disabled hidden>Select occupation / source of income</option>
                    <?php foreach ($occupationOptions as $option): ?>
                      <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="field" id="profile-occupation-other-field" hidden>
                  <label for="profile-occupation-other">Specify Occupation / Source of Income <span class="field-required">*</span></label>
                  <input type="text" id="profile-occupation-other" name="occupation_other" maxlength="150" placeholder="Specify occupation">
                </div>

                <div class="field">
                  <label for="profile-income">Estimated Monthly Income <span class="field-required">*</span></label>
                  <select id="profile-income" name="estimated_monthly_income" required>
                    <option value="" selected disabled hidden>Select estimated monthly income</option>
                    <?php foreach (aics_income_options() as $option): ?>
                      <option value="<?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($option, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="profile-fieldset">
              <div class="profile-fieldset-header">
                <div class="profile-group-pill">
                  <span class="profile-group-num">3</span>
                  <span class="profile-group-tag">Address</span>
                </div>
              </div>
              <div class="form-grid">
                <div class="field field-full">
                  <label for="profile-complete-address">Complete Address</label>
                  <textarea id="profile-complete-address" name="complete_address" rows="3" maxlength="255" placeholder="Complete address will fill in automatically." readonly class="readonly-field"></textarea>
                  <small class="field-help"><i class="fas fa-info-circle"></i> This is built automatically from the fields below.</small>
                </div>

                <div class="field">
                  <label for="profile-municipality">Municipality</label>
                  <select id="profile-municipality" required>
                    <option value="" selected disabled hidden>Select municipality</option>
                  </select>
                </div>

                <div class="field">
                  <label for="profile-barangay">Barangay</label>
                  <select id="profile-barangay" disabled required>
                    <option value="" selected disabled hidden>Select municipality first</option>
                  </select>
                </div>

                <div class="field">
                  <label for="profile-province">Province</label>
                  <input type="text" id="profile-province" value="Antique" readonly class="readonly-field" required>
                </div>

                <div class="field">
                  <label for="profile-address-line">House No. / Street / Sitio / Purok <span class="field-optional">(Optional)</span></label>
                  <input type="text" id="profile-address-line" maxlength="255" placeholder="House no., street, sitio or purok">
                </div>
              </div>
            </div>

            <div class="profile-form-footer">
              <div class="profile-save-feedback" id="profile-feedback" role="status" aria-live="polite" hidden>
                <i class="fas fa-circle-check" aria-hidden="true"></i>
                <span class="profile-save-feedback-message"></span>
                <button type="button" class="profile-save-feedback-close" aria-label="Dismiss profile save message">
                  <i class="fas fa-times" aria-hidden="true"></i>
                </button>
              </div>

              <div class="action-row intake-actions-senior">
                <button type="submit" class="btn-senior btn-senior-primary btn-profile-save" id="profile-save-btn" disabled>
                  <i class="fas fa-floppy-disk"></i>
                  <span>Save</span>
                </button>
              </div>
            </div>
          </form>
        </section>
      </div>

    </div>
  </main>
</div>

<dialog class="profile-id-dialog" id="profile-id-dialog" data-side="front" aria-labelledby="profile-id-dialog-title" aria-describedby="profile-id-dialog-description">
  <div class="profile-id-dialog-panel">
    <div class="profile-id-dialog-header">
      <span class="profile-id-dialog-icon" aria-hidden="true"><i class="fas fa-id-card"></i></span>
      <div class="profile-id-dialog-heading">
        <h2 id="profile-id-dialog-title">Sign-up ID</h2>
        <p id="profile-id-dialog-description"><span id="profile-id-dialog-type">Valid ID</span> &middot; Only you can see these photos.</p>
      </div>
      <button type="button" class="profile-id-dialog-close" data-close-id-dialog aria-label="Close ID photo">
        <i class="fas fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="profile-id-dialog-image-wrap">
      <img id="profile-id-large-image" alt="">
      <span class="profile-id-dialog-badge" id="profile-id-dialog-caption" aria-live="polite">Front of ID</span>
      <button type="button" class="profile-id-dialog-arrow is-prev" data-id-arrow="front" aria-label="Show front of ID">
        <i class="fas fa-chevron-left" aria-hidden="true"></i>
      </button>
      <button type="button" class="profile-id-dialog-arrow is-next" data-id-arrow="back" aria-label="Show back of ID">
        <i class="fas fa-chevron-right" aria-hidden="true"></i>
      </button>
    </div>
    <div class="profile-id-dialog-footer">
      <div class="profile-id-dialog-sides" role="group" aria-label="ID photo side">
        <button type="button" data-view-side="front"><img alt="" data-id-thumb="front"><span>Front</span></button>
        <button type="button" data-view-side="back"><img alt="" data-id-thumb="back"><span>Back</span></button>
      </div>
      <div class="profile-id-dialog-tools" role="group" aria-label="Rotate photo">
        <button type="button" class="profile-id-dialog-rotate" data-rotate-id-photo="-90" title="Rotate left" aria-label="Rotate left">
          <i class="fas fa-rotate-left" aria-hidden="true"></i>
        </button>
        <button type="button" class="profile-id-dialog-rotate" data-rotate-id-photo="90" title="Rotate right" aria-label="Rotate right">
          <i class="fas fa-rotate-right" aria-hidden="true"></i>
        </button>
      </div>
    </div>
  </div>
</dialog>

<script src="<?= htmlspecialchars(dirname($applicantsBaseUrl), ENT_QUOTES, 'UTF-8') ?>/asset/digits-only.js"></script>
<script src="<?= htmlspecialchars($appJsUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($barangaysJsUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($profileJsUrl, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php require __DIR__ . '/../../chatbot/applicant-widget.php'; ?>
</body>
</html>
