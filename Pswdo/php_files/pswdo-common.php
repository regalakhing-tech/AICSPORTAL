<?php
declare(strict_types=1);

require_once __DIR__ . '/../../Applicants/php_files/auth-common.php';
require_once __DIR__ . '/../../includes/document-requirements.php';
require_once __DIR__ . '/../../includes/document-ai-reviews.php';
require_once __DIR__ . '/../../includes/beneficiary-priority.php';

function pswdo_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $httpsOn = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if ($httpsOn) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('AICSPSWDOSESSID');
    session_start();
}

function pswdo_require_login(): void
{
    pswdo_start_session();
    aics_require_role_login('pswdo', '../login.php');
}

function pswdo_h(mixed $value): string
{
    return aics_display_h($value);
}

function pswdo_request_reopen_id_after_action(int $requestId, string $actionType, string $responseStatus): int
{
    if ($responseStatus === 'success' && in_array($actionType, [
        'view_request',
        'reject_request',
        'mark_invalid',
        'mark_invalid_with_sms',
    ], true)) {
        // These decisions move the request out of the incoming queue, so its
        // detail template will not exist when Request Management reloads.
        return 0;
    }

    return $requestId;
}

function pswdo_mswdo_nav_link(string $dashboardUrl, bool $active = false): string
{
    $className = $active ? 'nav-link active' : 'nav-link';
    $href = pswdo_h($dashboardUrl) . '?page=all_releases';

    return '<a class="' . $className . '" href="' . $href . '"><i class="fas fa-envelope"></i> MSWDO</a>';
}

/** @return array{label:string,class:string,icon:string} */
function pswdo_duplicate_badge_meta(string $status): array
{
    return match (strtolower(trim($status))) {
        'confirmed duplicate', 'duplicate found', 'duplicate' => ['label' => 'Confirmed Duplicate', 'class' => 'pill-danger', 'icon' => 'fa-circle-xmark'],
        'needs verification', 'under review' => ['label' => 'Needs Verification', 'class' => 'pill-info', 'icon' => 'fa-magnifying-glass'],
        'possible duplicate' => ['label' => 'Possible Duplicate', 'class' => 'pill-warning', 'icon' => 'fa-triangle-exclamation'],
        default => ['label' => 'No Duplicate', 'class' => 'pill-info', 'icon' => 'fa-circle-check'],
    };
}

/** @return array{class:string,icon:string} */
function pswdo_priority_badge_meta(string $priorityLevel, string $medicalUrgency = ''): array
{
    if (aics_smart_priority_analysis_enabled()) {
        return match (aics_smart_priority_tier($priorityLevel, $medicalUrgency)) {
            0 => ['class' => 'priority-chip-urgent', 'icon' => ''],
            1 => ['class' => 'priority-chip-request-high', 'icon' => ''],
            default => ['class' => 'priority-chip-normal', 'icon' => ''],
        };
    }
    $base = aics_normalize_base_priority($priorityLevel);
    $urgency = aics_normalize_medical_urgency($medicalUrgency);
    if ($medicalUrgency !== '' && $urgency === 'REQUIRES_STAFF_REVIEW') {
        return ['class' => 'priority-chip-review', 'icon' => 'fa-magnifying-glass'];
    }
    return $base === 'HIGH' || $urgency === 'URGENT'
        ? ['class' => 'priority-chip-high', 'icon' => 'fa-circle-check']
        : ['class' => 'priority-chip-normal', 'icon' => 'fa-minus'];
}

/** Compact, user-facing Smart Prioritization label for request rows. */
function pswdo_priority_display_label(string $basePriority, string $medicalUrgency): string
{
    return aics_smart_priority_label($basePriority, $medicalUrgency);
}

/** Three display badges used by Request Management; stored priority and queue order are unchanged. */
function pswdo_request_management_indicator(string $beneficiaryCategoriesJson, string $medicalUrgency, string $assistanceCode): array
{
    $categories = json_decode($beneficiaryCategoriesJson, true);
    $categories = is_array($categories) ? array_filter($categories, 'is_string') : [];
    $seniorOrPwdMedical = aics_priority_from_beneficiary_categories($categories)['label'] === 'HIGH'
        && strtolower(trim($assistanceCode)) === 'medical';
    if (!$seniorOrPwdMedical) {
        return ['label' => 'STANDARD', 'class' => 'priority-chip-normal', 'icon' => 'fa-minus'];
    }
    if (aics_normalize_medical_urgency($medicalUrgency) === 'URGENT') {
        return ['label' => 'URGENT', 'class' => 'priority-chip-urgent', 'icon' => 'fa-triangle-exclamation'];
    }
    return ['label' => 'HIGH', 'class' => 'priority-chip-request-high', 'icon' => 'fa-arrow-up'];
}

/** Use the same Smart Priority decision in the screening queue and Request Management. */
function pswdo_screening_priority_indicator(array $item): array
{
    return pswdo_request_management_indicator(
        (string) ($item['client_categories_json'] ?? '[]'),
        (string) ($item['urgency_indicator'] ?? ''),
        (string) ($item['assistance_code'] ?? '')
    );
}

/** Show the stored medical findings without the old provider attribution. */
function pswdo_priority_explanation_for_display(string $explanation): string
{
    $findings = trim($explanation);
    $findings = preg_replace('/^Gemini priority analysis found sufficient current medical urgency\.\s*/i', '', $findings) ?? $findings;
    return $findings !== '' ? $findings : 'Detailed findings are unavailable.';
}

/** Human-readable urgency wording shared by queues and Full Application Details. */
function pswdo_medical_urgency_label(string $medicalUrgency): string
{
    return match (aics_normalize_medical_urgency($medicalUrgency)) {
        'URGENT' => 'Urgent',
        'HIGH' => 'High',
        'STANDARD' => 'Standard',
        default => 'Review Needed',
    };
}

/** Canonical PSWDO display/status metadata for completed AI document reviews. */
function pswdo_ai_review_status_meta(string $status): array
{
    return match (strtolower(trim($status))) {
        'completed', 'likely_valid' => ['label' => 'Review Complete', 'class' => 'pill-success', 'summary' => 'The uploaded file appears to match the required document.'],
        'needs_staff_review' => ['label' => 'Staff Review Needed', 'class' => 'pill-warning', 'summary' => 'The document is related to the requirement, but staff confirmation is needed.'],
        'needs_reupload' => ['label' => 'Replace Document', 'class' => 'pill-warning', 'summary' => 'The uploaded file needs to be replaced before it can be reliably reviewed.'],
        'flagged_inconsistency' => ['label' => 'Flagged Inconsistency', 'class' => 'pill-danger', 'summary' => 'The document type or submitted details require staff review.'],
        'failed' => ['label' => 'AI Review Unavailable', 'class' => 'pill-danger', 'summary' => 'AI review is currently unavailable.'],
        'processing' => ['label' => 'Reviewing Document', 'class' => 'pill-info', 'summary' => 'Document review is in progress.'],
        default => ['label' => 'Waiting for Review', 'class' => 'pill-info', 'summary' => 'Waiting for document review.'],
    };
}

/** @param array<string,mixed> $findings */
function pswdo_ai_review_summary(string $status, array $findings = []): string
{
    $normalized = strtolower(trim($status));
    $typeDecision = strtolower(trim((string) ($findings['checks']['document_type']['classification']['decision'] ?? '')));
    $qualityStatus = strtolower(trim((string) ($findings['checks']['quality']['status'] ?? '')));
    if (in_array($normalized, ['needs_reupload', 'flagged_inconsistency'], true) && $typeDecision === 'wrong_slot') {
        return 'The uploaded file does not match the required document. Ask the applicant to submit the correct document.';
    }
    if ($normalized === 'needs_reupload' && $qualityStatus === 'reupload') {
        return 'The uploaded document is not clear enough for reliable reading.';
    }
    if ($normalized === 'flagged_inconsistency') {
        return 'Submitted information contains differences that require staff review.';
    }
    return (string) (pswdo_ai_review_status_meta($normalized)['summary'] ?? 'Automated document review is pending.');
}

function pswdo_ai_review_requires_attention(string $status): bool
{
    return in_array(strtolower(trim($status)), ['needs_staff_review', 'needs_reupload', 'flagged_inconsistency', 'failed'], true);
}

/** @param array<int,array<string,mixed>> $documents */
function pswdo_request_has_flagged_inconsistency(array $documents): bool
{
    foreach ($documents as $document) {
        if (!empty($document['uploaded']) && strtolower(trim((string) ($document['ai_status'] ?? ''))) === 'flagged_inconsistency') {
            return true;
        }
    }
    return false;
}

/**
 * Normalize the four staff-facing AI assessments without inventing a pass for
 * legacy or incomplete findings.
 *
 * @param array<string,mixed> $findings
 * @return array<string,array{title:string,state:string,label:string,explanation:string}>
 */
function pswdo_ai_component_assessments(array $findings): array
{
    $source = is_array($findings['assessments'] ?? null) ? $findings['assessments'] : [];
    $definitions = [
        'document_match' => ['Document match', 'Document check unavailable'],
        'readability' => ['Readability', 'Readability check unavailable'],
        'applicant_match' => ['Beneficiary match', 'Beneficiary comparison unavailable'],
        'alteration_signal' => ['Alteration signal', 'Alteration check unavailable'],
    ];
    $result = [];
    foreach ($definitions as $key => [$title, $fallbackLabel]) {
        $item = is_array($source[$key] ?? null) ? $source[$key] : [];
        $state = strtolower(trim((string) ($item['state'] ?? 'unavailable')));
        if (!in_array($state, ['pass', 'review', 'fail', 'unavailable', 'not_applicable'], true)) $state = 'unavailable';
        $label = trim((string) ($item['label'] ?? '')) ?: $fallbackLabel;
        $explanation = trim((string) ($item['explanation'] ?? '')) ?: 'Review the original document and confirm this item manually.';
        $result[$key] = compact('title', 'state', 'label', 'explanation');
    }
    return $result;
}

/**
 * Describe whether the optional external provider contributed evidence.
 * Technical provider details remain server-only.
 *
 * @param array<string,mixed> $findings
 * @return array{state:string,label:string,detail:string,icon:string}
 */
function pswdo_external_ai_provider_meta(array $findings): array
{
    $provider = is_array($findings['external_ai'] ?? null) ? $findings['external_ai'] : [];
    $status = strtolower(trim((string) ($provider['status'] ?? '')));
    if ($status === 'completed') {
        $imageAssisted = strtolower(trim((string) ($provider['payload_mode'] ?? 'text'))) === 'image';
        $isGemini = strtolower(trim((string) ($provider['provider'] ?? ''))) === 'google_gemini';
        return [
            'state' => 'checked',
            'label' => $isGemini ? 'AICS AI checked' : 'External AI checked',
            'detail' => $imageAssisted ? 'Document image reviewed as a staff advisory.' : 'OCR text reviewed as a staff advisory.',
            'icon' => 'fa-shield-halved',
        ];
    }
    if ($status === 'not_needed') {
        return [
            'state' => 'local',
            'label' => 'External AI not needed',
            'detail' => 'Local evidence was sufficient for staff review.',
            'icon' => 'fa-laptop',
        ];
    }
    if ($status === 'unavailable') {
        return [
            'state' => 'unavailable',
            'label' => 'External AI unavailable',
            'detail' => 'Local checks completed. Continue the normal staff review.',
            'icon' => 'fa-circle-exclamation',
        ];
    }
    return [
        'state' => 'unknown',
        'label' => 'Provider details unavailable',
        'detail' => 'This earlier review contains local verification results only.',
        'icon' => 'fa-circle-info',
    ];
}

/**
 * @return array<string, string>
 */
function pswdo_paths(): array
{
    $scriptDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    $scriptDirectory = rtrim($scriptDirectory, '/');

    $isPhpFilesRoute = str_ends_with($scriptDirectory, '/php_files');
    $pswdoBaseUrl = $isPhpFilesRoute ? dirname($scriptDirectory) : $scriptDirectory;
    $phpFilesBaseUrl = $isPhpFilesRoute ? $scriptDirectory : $scriptDirectory . '/php_files';
    $dashboardUrl = $pswdoBaseUrl . '/dashboard.php';
    $projectRootUrl = dirname(dirname($pswdoBaseUrl));
    $cssFilePath = __DIR__ . '/../css files/pswdo-pages.css';
    $jsFilePath = __DIR__ . '/../js files/pswdo-pages.js';
    $aiPollerFilePath = __DIR__ . '/../js files/ai-request-poller.js';
    $cssVersion = is_file($cssFilePath) ? (string) filemtime($cssFilePath) : '1';
    $jsVersion = is_file($jsFilePath) ? (string) filemtime($jsFilePath) : '1';
    $aiPollerVersion = is_file($aiPollerFilePath) ? (string) filemtime($aiPollerFilePath) : '1';

    return [
        'pswdo_base_url' => $pswdoBaseUrl,
        'php_files_base_url' => $phpFilesBaseUrl,
        'dashboard_url' => $dashboardUrl,
        'project_root_url' => $projectRootUrl,
        'logo_url' => $projectRootUrl . '/asset/logo.png',
        'css_url' => $pswdoBaseUrl . '/css files/pswdo-pages.css?v=' . $cssVersion,
        'js_url' => $pswdoBaseUrl . '/js files/pswdo-pages.js?v=' . $jsVersion,
        'ai_request_poller_js_url' => $pswdoBaseUrl . '/js files/ai-request-poller.js?v=' . $aiPollerVersion,
        'logout_url' => $phpFilesBaseUrl . '/auth-logout.php',
    ];
}

function pswdo_current_display_name(): string
{
    pswdo_start_session();
    $displayName = trim((string) ($_SESSION['aics_display_name'] ?? 'PSWDO Personnel'));
    return $displayName !== '' ? $displayName : 'PSWDO Personnel';
}

function pswdo_initials(string $displayName): string
{
    $parts = preg_split('/\s+/', trim($displayName)) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'PS';
}

function pswdo_repair_lookup_auto_increment(mysqli $connection, string $tableName): void
{
    $maxResult = $connection->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM ' . $tableName);
    $maxRow = $maxResult?->fetch_assoc();
    $maxId = (int) ($maxRow['max_id'] ?? 0);
    $nextId = max(1, $maxId + 1);

    if ($nextId > 255) {
        $nextId = 255;
    }

    $connection->query('ALTER TABLE ' . $tableName . ' AUTO_INCREMENT = ' . $nextId);
}

function pswdo_insert_role_if_missing(mysqli $connection, string $roleCode, string $roleName): void
{
    $existsStmt = $connection->prepare('SELECT id FROM aics_roles WHERE role_code = ? LIMIT 1');
    $existsStmt->bind_param('s', $roleCode);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()?->fetch_assoc();
    $existsStmt->close();

    if (is_array($exists) && (int) ($exists['id'] ?? 0) > 0) {
        return;
    }

    $insertStmt = $connection->prepare('INSERT INTO aics_roles (role_code, role_name) VALUES (?, ?)');
    $insertStmt->bind_param('ss', $roleCode, $roleName);
    $insertStmt->execute();
    $insertStmt->close();
}

function pswdo_insert_status_if_missing(mysqli $connection, string $statusCode, string $statusName): void
{
    $existsStmt = $connection->prepare('SELECT id FROM aics_account_statuses WHERE status_code = ? LIMIT 1');
    $existsStmt->bind_param('s', $statusCode);
    $existsStmt->execute();
    $exists = $existsStmt->get_result()?->fetch_assoc();
    $existsStmt->close();

    if (is_array($exists) && (int) ($exists['id'] ?? 0) > 0) {
        return;
    }

    $insertStmt = $connection->prepare('INSERT INTO aics_account_statuses (status_code, status_name) VALUES (?, ?)');
    $insertStmt->bind_param('ss', $statusCode, $statusName);
    $insertStmt->execute();
    $insertStmt->close();
}

function pswdo_ensure_schema(mysqli $connection): void
{
    aics_ensure_auth_schema($connection);

    $queries = [
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_staff_profiles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                account_id INT UNSIGNED NOT NULL,
                first_name VARCHAR(120) NOT NULL,
                middle_name VARCHAR(120) DEFAULT NULL,
                last_name VARCHAR(120) NOT NULL,
                position_title VARCHAR(120) DEFAULT NULL,
                contact_number VARCHAR(20) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_staff_profiles_account_id (account_id),
                CONSTRAINT fk_aics_staff_profiles_account_id FOREIGN KEY (account_id) REFERENCES aics_accounts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_assistance_types (
                id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                assistance_code VARCHAR(60) NOT NULL,
                assistance_name VARCHAR(120) NOT NULL,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_assistance_types_code (assistance_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_document_types (
                id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                document_code VARCHAR(80) NOT NULL,
                document_name VARCHAR(180) NOT NULL,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_document_types_code (document_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_assistance_required_documents (
                assistance_type_id SMALLINT UNSIGNED NOT NULL,
                document_type_id SMALLINT UNSIGNED NOT NULL,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (assistance_type_id, document_type_id),
                KEY idx_aics_ard_document_type_id (document_type_id),
                CONSTRAINT fk_aics_ard_assistance_type_id FOREIGN KEY (assistance_type_id) REFERENCES aics_assistance_types(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_ard_document_type_id FOREIGN KEY (document_type_id) REFERENCES aics_document_types(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_assistance (
                request_id INT UNSIGNED NOT NULL,
                assistance_type_id SMALLINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (request_id, assistance_type_id),
                KEY idx_aics_request_assistance_assistance_type_id (assistance_type_id),
                CONSTRAINT fk_aics_request_assistance_request_id FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_assistance_assistance_type_id FOREIGN KEY (assistance_type_id) REFERENCES aics_assistance_types(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_statuses (
                id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                status_code VARCHAR(60) NOT NULL,
                status_name VARCHAR(120) NOT NULL,
                timeline_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
                is_terminal TINYINT(1) NOT NULL DEFAULT 0,
                is_cancellable TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_request_statuses_code (status_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_status_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id INT UNSIGNED NOT NULL,
                status_id SMALLINT UNSIGNED NOT NULL,
                remarks VARCHAR(500) DEFAULT NULL,
                changed_by_account_id INT UNSIGNED DEFAULT NULL,
                changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_request_status_history_request_id (request_id),
                KEY idx_aics_request_status_history_status_id (status_id),
                KEY idx_aics_request_status_history_changed_by (changed_by_account_id),
                CONSTRAINT fk_aics_request_status_history_request_id FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_status_history_status_id FOREIGN KEY (status_id) REFERENCES aics_request_statuses(id),
                CONSTRAINT fk_aics_request_status_history_changed_by FOREIGN KEY (changed_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_document_reviews (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_document_id INT UNSIGNED NOT NULL,
                reviewed_by_account_id INT UNSIGNED DEFAULT NULL,
                is_checked TINYINT(1) NOT NULL DEFAULT 0,
                remarks VARCHAR(255) DEFAULT NULL,
                reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aics_request_document_reviews_document_id (request_document_id),
                KEY idx_aics_request_document_reviews_reviewed_by (reviewed_by_account_id),
                CONSTRAINT fk_aics_request_document_reviews_document_id FOREIGN KEY (request_document_id) REFERENCES aics_request_documents(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_document_reviews_reviewed_by FOREIGN KEY (reviewed_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_validation_results (
                request_id INT UNSIGNED NOT NULL,
                duplicate_flag TINYINT(1) NOT NULL DEFAULT 0,
                repeated_claim_flag TINYINT(1) NOT NULL DEFAULT 0,
                inconsistency_flag TINYINT(1) NOT NULL DEFAULT 0,
                risk_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                validation_note VARCHAR(500) DEFAULT NULL,
                updated_by_account_id INT UNSIGNED DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (request_id),
                KEY idx_aics_request_validation_results_updated_by (updated_by_account_id),
                CONSTRAINT fk_aics_request_validation_results_request_id FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_validation_results_updated_by FOREIGN KEY (updated_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_prioritization (
                request_id INT UNSIGNED NOT NULL,
                urgency_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                urgency_indicator VARCHAR(20) DEFAULT NULL,
                vulnerability_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                readiness_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                risk_penalty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                priority_score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                priority_rank INT UNSIGNED DEFAULT NULL,
                priority_level VARCHAR(40) NOT NULL DEFAULT 'Normal',
                updated_by_account_id INT UNSIGNED DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (request_id),
                KEY idx_aics_request_prioritization_priority_rank (priority_rank),
                KEY idx_aics_request_prioritization_updated_by (updated_by_account_id),
                CONSTRAINT fk_aics_request_prioritization_request_id FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_prioritization_updated_by FOREIGN KEY (updated_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id INT UNSIGNED NOT NULL,
                notification_stage VARCHAR(80) NOT NULL,
                message_text VARCHAR(600) NOT NULL,
                sent_by_account_id INT UNSIGNED DEFAULT NULL,
                sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aics_request_notifications_request_id (request_id),
                KEY idx_aics_request_notifications_sent_by (sent_by_account_id),
                CONSTRAINT fk_aics_request_notifications_request_id FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_notifications_sent_by FOREIGN KEY (sent_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_prescreening (
                request_id INT UNSIGNED NOT NULL,
                decision_status ENUM('for_screening', 'incomplete_requirements', 'invalid_requirements') NOT NULL,
                required_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                uploaded_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                missing_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                remarks VARCHAR(500) DEFAULT NULL,
                confirmed_by_account_id INT UNSIGNED DEFAULT NULL,
                confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (request_id),
                KEY idx_aics_request_prescreening_decision (decision_status),
                KEY idx_aics_request_prescreening_confirmed_by (confirmed_by_account_id),
                KEY idx_aics_request_prescreening_confirmed_at (confirmed_at),
                CONSTRAINT fk_aics_request_prescreening_request_id FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_prescreening_confirmed_by FOREIGN KEY (confirmed_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<SQL
            CREATE TABLE IF NOT EXISTS aics_request_release_schedule (
                request_id INT UNSIGNED NOT NULL,
                scheduled_date DATE DEFAULT NULL,
                scheduled_time TIME DEFAULT NULL,
                venue VARCHAR(180) DEFAULT NULL,
                release_batch VARCHAR(120) DEFAULT NULL,
                release_status ENUM('unscheduled', 'scheduled', 'rescheduled', 'ready_for_release', 'missed', 'released') NOT NULL DEFAULT 'unscheduled',
                notes VARCHAR(500) DEFAULT NULL,
                scheduled_by_account_id INT UNSIGNED DEFAULT NULL,
                updated_by_account_id INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (request_id),
                KEY idx_aics_request_release_schedule_status (release_status),
                KEY idx_aics_request_release_schedule_date (scheduled_date),
                KEY idx_aics_request_release_schedule_scheduled_by (scheduled_by_account_id),
                KEY idx_aics_request_release_schedule_updated_by (updated_by_account_id),
                CONSTRAINT fk_aics_request_release_schedule_request_id FOREIGN KEY (request_id) REFERENCES aics_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_aics_request_release_schedule_scheduled_by FOREIGN KEY (scheduled_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL,
                CONSTRAINT fk_aics_request_release_schedule_updated_by FOREIGN KEY (updated_by_account_id) REFERENCES aics_accounts(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    ];

    $connection->query("ALTER TABLE aics_request_prioritization ADD COLUMN IF NOT EXISTS urgency_indicator VARCHAR(20) DEFAULT NULL AFTER urgency_score");
    $connection->query("ALTER TABLE aics_request_prioritization ADD COLUMN IF NOT EXISTS priority_basis VARCHAR(700) DEFAULT NULL AFTER priority_level");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS urgency_response VARCHAR(3) DEFAULT NULL AFTER assistance_types_json");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS urgency_indicator VARCHAR(20) DEFAULT NULL AFTER urgency_response");

    foreach ($queries as $query) {
        $connection->query($query);
    }

    // Kept here as a non-destructive migration so prototype installations do
    // not need a separate database upgrade before using release notices.
    $connection->query("ALTER TABLE aics_request_notifications ADD COLUMN IF NOT EXISTS channel VARCHAR(60) NOT NULL DEFAULT 'applicant_sms' AFTER notification_stage");
    $connection->query("ALTER TABLE aics_request_notifications ADD COLUMN IF NOT EXISTS delivery_mode VARCHAR(20) DEFAULT NULL AFTER channel");
    $connection->query("ALTER TABLE aics_request_notifications ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(20) DEFAULT NULL AFTER delivery_mode");
    $connection->query("ALTER TABLE aics_request_notifications ADD COLUMN IF NOT EXISTS municipality VARCHAR(120) DEFAULT NULL AFTER delivery_status");
    $connection->query("ALTER TABLE aics_request_notifications ADD COLUMN IF NOT EXISTS batch_key VARCHAR(160) DEFAULT NULL AFTER municipality");
    $connection->query("ALTER TABLE aics_request_notifications ADD COLUMN IF NOT EXISTS notification_template TEXT DEFAULT NULL AFTER message_text");
    $connection->query("ALTER TABLE aics_request_notifications ADD COLUMN IF NOT EXISTS dedupe_key CHAR(64) DEFAULT NULL AFTER notification_template");
    $connection->query('ALTER TABLE aics_request_notifications ADD UNIQUE INDEX IF NOT EXISTS uq_aics_notification_dedupe_key (dedupe_key)');

    // Keep old schema revisions compatible.
    $connection->query("ALTER TABLE aics_request_documents ADD COLUMN IF NOT EXISTS document_type VARCHAR(60) DEFAULT NULL AFTER request_id");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_contact_number VARCHAR(15) DEFAULT NULL AFTER applicant_age");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_email VARCHAR(180) DEFAULT NULL AFTER applicant_contact_number");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_occupation VARCHAR(150) DEFAULT NULL AFTER applicant_email");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS applicant_estimated_monthly_income VARCHAR(40) DEFAULT NULL AFTER applicant_occupation");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_barangay VARCHAR(120) DEFAULT NULL AFTER beneficiary_complete_name");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_sitio_purok VARCHAR(120) DEFAULT NULL AFTER beneficiary_barangay");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_municipality VARCHAR(120) DEFAULT NULL AFTER beneficiary_sitio_purok");
    $connection->query("ALTER TABLE aics_requests ADD COLUMN IF NOT EXISTS beneficiary_province VARCHAR(120) DEFAULT NULL AFTER beneficiary_municipality");
    $connection->query("ALTER TABLE aics_request_prescreening MODIFY COLUMN decision_status ENUM('for_screening', 'incomplete_requirements', 'invalid_requirements') NOT NULL");
    $connection->query("ALTER TABLE aics_request_release_schedule MODIFY COLUMN release_status ENUM('unscheduled', 'scheduled', 'rescheduled', 'ready_for_release', 'missed', 'released') NOT NULL DEFAULT 'unscheduled'");
    $connection->query("ALTER TABLE aics_request_release_schedule ADD COLUMN IF NOT EXISTS release_batch VARCHAR(120) DEFAULT NULL AFTER venue");
    $connection->query("ALTER TABLE aics_request_release_schedule ADD COLUMN IF NOT EXISTS amount_given DECIMAL(10,2) DEFAULT NULL AFTER release_batch");
    $connection->query("ALTER TABLE aics_request_document_reviews ADD COLUMN IF NOT EXISTS review_status ENUM('pending','checked','needs_reupload','invalid') NOT NULL DEFAULT 'pending' AFTER is_checked");
    $connection->query("ALTER TABLE aics_request_document_reviews ADD COLUMN IF NOT EXISTS notified_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_at");
    aics_ensure_ai_review_schema($connection);

    pswdo_repair_lookup_auto_increment($connection, 'aics_roles');
    pswdo_repair_lookup_auto_increment($connection, 'aics_account_statuses');
    pswdo_seed_roles_and_accounts($connection);
    pswdo_seed_lookup_tables($connection);
    pswdo_migrate_removed_assistance_types($connection);
    pswdo_backfill_request_assistance($connection);
    pswdo_backfill_status_history($connection);
}

function pswdo_seed_roles_and_accounts(mysqli $connection): void
{
    pswdo_insert_role_if_missing($connection, 'pswdo', 'PSWDO Personnel');
    pswdo_insert_status_if_missing($connection, 'active', 'Active');
    pswdo_insert_status_if_missing($connection, 'disabled', 'Disabled');
    pswdo_insert_status_if_missing($connection, 'restricted', 'Restricted');
    pswdo_insert_status_if_missing($connection, 'locked', 'Locked');
    pswdo_insert_status_if_missing($connection, 'banned', 'Banned');

    $roleRow = $connection->query("SELECT id FROM aics_roles WHERE role_code = 'pswdo' LIMIT 1")?->fetch_assoc();
    $statusRow = $connection->query("SELECT id FROM aics_account_statuses WHERE status_code = 'active' LIMIT 1")?->fetch_assoc();

    $roleId = (int) ($roleRow['id'] ?? 0);
    $statusId = (int) ($statusRow['id'] ?? 0);

    if ($roleId <= 0 || $statusId <= 0) {
        return;
    }

    $existingStmt = $connection->prepare('SELECT id FROM aics_accounts WHERE role_id = ? LIMIT 1');
    $existingStmt->bind_param('i', $roleId);
    $existingStmt->execute();
    $existingAccount = $existingStmt->get_result()?->fetch_assoc();
    $existingStmt->close();

    if (is_array($existingAccount)) {
        return;
    }

    $email = 'pswdo@aics.local';
    $passwordHash = password_hash('Pswdo123!', PASSWORD_DEFAULT);

    $insertAccount = $connection->prepare(
        'INSERT INTO aics_accounts (role_id, account_status_id, email, password_hash) VALUES (?, ?, ?, ?)'
    );
    $insertAccount->bind_param('iiss', $roleId, $statusId, $email, $passwordHash);
    $insertAccount->execute();
    $accountId = (int) $connection->insert_id;
    $insertAccount->close();

    if ($accountId <= 0) {
        return;
    }

    $firstName = 'PSWDO';
    $middleName = null;
    $lastName = 'Personnel';
    $positionTitle = 'Case Management Officer';
    $contactNumber = 'N/A';

    $insertProfile = $connection->prepare(
        'INSERT INTO aics_staff_profiles (account_id, first_name, middle_name, last_name, position_title, contact_number) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertProfile->bind_param('isssss', $accountId, $firstName, $middleName, $lastName, $positionTitle, $contactNumber);
    $insertProfile->execute();
    $insertProfile->close();
}

function pswdo_seed_lookup_tables(mysqli $connection): void
{
    $assistanceTypes = [
        ['medical', 'Medical', 10],
        ['burial', 'Burial', 20],
        ['disaster_victims', 'Disaster Victims', 30],
        ['special_cases', 'Special Cases', 40],
        ['educational_basic', 'Educational Assistance — Basic Education', 50],
        ['high_inflation_affected', 'Persons Affected by High Inflation Rate', 70],
    ];

    $documentTypes = [
        // Retained for OPSWDO's internal issuance workflow; never a client requirement.
        ['coe_opswdo', 'Certificate of Eligibility (Issued by OPSWDO)', 10],
        ['med_cert', 'Medical Certificate', 20],
        ['billing_statement', 'Prescription / Billing Statement', 30],
        ['valid_id', 'Valid ID', 40],
        ['death_cert', 'Death Certificate', 60],
        ['funeral_contract', 'Funeral Contract', 70],
        ['mdrrmo_cert', 'MDRRMO Certification of Damage or Displacement', 80],
        ['damaged_house_photo', 'Photo of Damaged Property', 90],
        ['report_police_med', 'Police or Medical Report', 100],
        ['eclip_cert', 'Certificate from E-CLIP or JTF-ELCAC', 110],
        ['school_assessment', 'School Assessment Form', 115],
        ['registration_form', 'Registration Form', 116],
        ['crisis_situation_cert', 'Certificate of Person in Crisis Situation', 117],
        // Backward-compatible aliases from older uploads
        ['medical_certificate', 'Medical Certificate', 120],
        ['death_certificate', 'Death Certificate', 130],
        ['certificate_of_indigency', 'Certificate of Indigency', 140],
    ];

    $statuses = [
        ['pending_review', 'Pending Review', 1, 0, 1],
        ['incomplete_requirements', 'Incomplete Requirements', 2, 0, 1],
        ['for_interview', 'For Interview / Further Screening', 2, 0, 1],
        ['under_review', 'Under Review', 3, 0, 1],
        ['approved', 'Approved', 4, 0, 1],
        ['not_approved', 'Not Approved', 4, 1, 0],
        ['released', 'Released / Distributed', 5, 1, 0],
        ['cancelled', 'Cancelled', 5, 1, 0],
    ];

    foreach ($assistanceTypes as [$code, $name, $sortOrder]) {
        $stmt = $connection->prepare(
            'INSERT INTO aics_assistance_types (assistance_code, assistance_name, sort_order) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE assistance_name = VALUES(assistance_name), sort_order = VALUES(sort_order)'
        );
        $stmt->bind_param('ssi', $code, $name, $sortOrder);
        $stmt->execute();
        $stmt->close();
    }

    foreach ($documentTypes as [$code, $name, $sortOrder]) {
        $stmt = $connection->prepare(
            'INSERT INTO aics_document_types (document_code, document_name, sort_order) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE document_name = VALUES(document_name), sort_order = VALUES(sort_order)'
        );
        $stmt->bind_param('ssi', $code, $name, $sortOrder);
        $stmt->execute();
        $stmt->close();
    }

    foreach ($statuses as [$code, $name, $timelineLevel, $isTerminal, $isCancellable]) {
        $stmt = $connection->prepare(
            'INSERT INTO aics_request_statuses (status_code, status_name, timeline_level, is_terminal, is_cancellable) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status_name = VALUES(status_name), timeline_level = VALUES(timeline_level), is_terminal = VALUES(is_terminal), is_cancellable = VALUES(is_cancellable)'
        );
        $stmt->bind_param('ssiii', $code, $name, $timelineLevel, $isTerminal, $isCancellable);
        $stmt->execute();
        $stmt->close();
    }

    $requirementsMatrix = array_map(static fn (array $requirements): array => array_map(static fn (array $requirement): string => $requirement['code'], $requirements), aics_document_requirements());

    foreach ($requirementsMatrix as $assistanceCode => $documentCodes) {
        $position = 1;
        foreach ($documentCodes as $documentCode) {
            $insertSql = <<<SQL
                INSERT INTO aics_assistance_required_documents (assistance_type_id, document_type_id, sort_order)
                SELECT at.id, dt.id, ?
                FROM aics_assistance_types at
                INNER JOIN aics_document_types dt ON dt.document_code = ?
                WHERE at.assistance_code = ?
                ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)
            SQL;
            $stmt = $connection->prepare($insertSql);
            $stmt->bind_param('iss', $position, $documentCode, $assistanceCode);
            $stmt->execute();
            $stmt->close();
            $position++;
        }
    }

    // Certificate of Eligibility remains outside applicant uploads.
    $connection->query("DELETE ard FROM aics_assistance_required_documents ard INNER JOIN aics_document_types dt ON dt.id = ard.document_type_id WHERE dt.document_code = 'coe_opswdo'");

    // High-inflation applications intentionally require only a Valid ID.
    $connection->query("DELETE ard FROM aics_assistance_required_documents ard INNER JOIN aics_assistance_types at ON at.id = ard.assistance_type_id INNER JOIN aics_document_types dt ON dt.id = ard.document_type_id WHERE at.assistance_code = 'high_inflation_affected' AND dt.document_code <> 'valid_id'");

    // Keep prior uploads; disaster assistance now requires only an ID and damage photo.
    $connection->query("DELETE ard FROM aics_assistance_required_documents ard INNER JOIN aics_assistance_types at ON at.id = ard.assistance_type_id INNER JOIN aics_document_types dt ON dt.id = ard.document_type_id WHERE at.assistance_code IN ('disaster_victims', 'victim_of_disaster') AND dt.document_code NOT IN ('valid_id', 'damaged_house_photo', 'damage_photos')");

    // client_categories_json remains the backward-compatible storage name for beneficiary categories.
}

/** Reclassify retired categories without deleting their application records. */
function pswdo_migrate_removed_assistance_types(mysqli $connection): void
{
    $connection->query("DELETE ard FROM aics_assistance_required_documents ard INNER JOIN aics_assistance_types at ON at.id = ard.assistance_type_id INNER JOIN aics_document_types dt ON dt.id = ard.document_type_id WHERE at.assistance_code = 'high_inflation_affected' AND dt.document_code <> 'valid_id'");

    $specialRow = $connection->query("SELECT id FROM aics_assistance_types WHERE assistance_code = 'special_cases' LIMIT 1")?->fetch_assoc();
    $specialId = (int) ($specialRow['id'] ?? 0);
    if ($specialId <= 0) return;

    $retiredIds = [];
    $retiredResult = $connection->query("SELECT id FROM aics_assistance_types WHERE assistance_code IN ('former_rebels', 'former_rebel')");
    while ($row = $retiredResult?->fetch_assoc()) {
        $retiredId = (int) ($row['id'] ?? 0);
        if ($retiredId > 0) $retiredIds[] = $retiredId;
    }

    foreach ($retiredIds as $retiredId) {
        $reclassify = $connection->prepare('INSERT IGNORE INTO aics_request_assistance (request_id, assistance_type_id) SELECT request_id, ? FROM aics_request_assistance WHERE assistance_type_id = ?');
        $reclassify->bind_param('ii', $specialId, $retiredId);
        $reclassify->execute();
        $reclassify->close();

        $removeRequirements = $connection->prepare('DELETE FROM aics_assistance_required_documents WHERE assistance_type_id = ?');
        $removeRequirements->bind_param('i', $retiredId);
        $removeRequirements->execute();
        $removeRequirements->close();

        $removeMappings = $connection->prepare('DELETE FROM aics_request_assistance WHERE assistance_type_id = ?');
        $removeMappings->bind_param('i', $retiredId);
        $removeMappings->execute();
        $removeMappings->close();
    }

    $requests = $connection->query('SELECT id, assistance_types_json FROM aics_requests');
    while ($request = $requests?->fetch_assoc()) {
        $decoded = json_decode((string) ($request['assistance_types_json'] ?? '[]'), true);
        if (!is_array($decoded)) continue;
        $changed = false;
        $normalized = [];
        foreach ($decoded as $code) {
            $value = strtolower(trim((string) $code));
            if (in_array($value, ['former_rebels', 'former_rebel'], true)) {
                $value = 'special_cases';
                $changed = true;
            }
            if ($value !== '' && !in_array($value, $normalized, true)) $normalized[] = $value;
        }
        if (!$changed) continue;
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) continue;
        $requestId = (int) ($request['id'] ?? 0);
        $update = $connection->prepare('UPDATE aics_requests SET assistance_types_json = ? WHERE id = ?');
        $update->bind_param('si', $json, $requestId);
        $update->execute();
        $update->close();
    }

    if ($retiredIds !== []) {
        $deleteRetired = $connection->prepare("DELETE FROM aics_assistance_types WHERE assistance_code IN ('former_rebels', 'former_rebel')");
        $deleteRetired->execute();
        $deleteRetired->close();
    }
}

function pswdo_backfill_request_assistance(mysqli $connection): void
{
    $lookup = [];
    $lookupResult = $connection->query('SELECT id, assistance_code FROM aics_assistance_types');
    while ($row = $lookupResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $lookup[strtolower((string) $row['assistance_code'])] = (int) ($row['id'] ?? 0);
    }

    $requestSql = <<<SQL
        SELECT r.id, r.assistance_types_json
        FROM aics_requests r
        LEFT JOIN aics_request_assistance ra ON ra.request_id = r.id
        WHERE ra.request_id IS NULL
    SQL;

    $result = $connection->query($requestSql);
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $requestId = (int) ($row['id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        $decoded = json_decode((string) ($row['assistance_types_json'] ?? '[]'), true);
        $assistanceCode = '';
        if (is_array($decoded) && count($decoded) > 0) {
            $assistanceCode = strtolower(trim((string) $decoded[0]));
        }

        if ($assistanceCode === '' || !isset($lookup[$assistanceCode])) {
            continue;
        }

        $assistanceId = (int) $lookup[$assistanceCode];
        $insert = $connection->prepare('INSERT IGNORE INTO aics_request_assistance (request_id, assistance_type_id) VALUES (?, ?)');
        $insert->bind_param('ii', $requestId, $assistanceId);
        $insert->execute();
        $insert->close();
    }
}

function pswdo_backfill_status_history(mysqli $connection): void
{
    $statusLookup = [];
    $statusResult = $connection->query('SELECT id, status_code FROM aics_request_statuses');
    while ($row = $statusResult?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }
        $statusLookup[(string) $row['status_code']] = (int) ($row['id'] ?? 0);
    }

    $fallbackStatusId = (int) ($statusLookup['pending_review'] ?? 0);
    if ($fallbackStatusId <= 0) {
        return;
    }

    $query = <<<SQL
        SELECT r.id, r.request_status, r.updated_at
        FROM aics_requests r
        LEFT JOIN aics_request_status_history h ON h.request_id = r.id
        WHERE h.id IS NULL
    SQL;

    $result = $connection->query($query);
    while ($row = $result?->fetch_assoc()) {
        if (!is_array($row)) {
            break;
        }

        $requestId = (int) ($row['id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        $statusCode = pswdo_normalize_status_code((string) ($row['request_status'] ?? 'pending'));
        $statusId = (int) ($statusLookup[$statusCode] ?? $fallbackStatusId);
        $changedAt = (string) ($row['updated_at'] ?? '');
        if ($changedAt === '' || $changedAt === '0000-00-00 00:00:00') {
            $changedAt = date('Y-m-d H:i:s');
        }

        $insert = $connection->prepare(
            'INSERT INTO aics_request_status_history (request_id, status_id, remarks, changed_by_account_id, changed_at) VALUES (?, ?, ?, NULL, ?)'
        );
        $remarks = 'Initial status backfill from existing request status.';
        $insert->bind_param('iiss', $requestId, $statusId, $remarks, $changedAt);
        $insert->execute();
        $insert->close();
    }
}

function pswdo_normalize_status_code(string $rawStatus): string
{
    $normalized = strtolower(trim($rawStatus));

    return match ($normalized) {
        'pending', 'for_review', 'for validation', 'for_validation', 'pending_review' => 'pending_review',
        'incomplete_requirements', 'incomplete requirements' => 'incomplete_requirements',
        'for_interview', 'for interview', 'for interview/further screening', 'for interview / further screening' => 'for_interview',
        'under_review', 'under review', 'under assessment', 'under_assessment', 'assessment', 'in_review' => 'under_review',
        'approved', 'validated', 'for_release', 'for release' => 'approved',
        'released', 'distributed', 'completed', 'closed' => 'released',
        'rejected', 'denied', 'not_approved', 'not approved' => 'not_approved',
        'cancelled', 'canceled' => 'cancelled',
        default => 'pending_review',
    };
}

/**
 * @return array<string, string>
 */
function pswdo_status_labels(): array
{
    return [
        'pending_review' => 'Pending Review',
        'incomplete_requirements' => 'Incomplete Requirements',
        'for_interview' => 'For Interview / Further Screening',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'not_approved' => 'Not Approved',
        'released' => 'Released / Distributed',
        'cancelled' => 'Cancelled',
    ];
}

/**
 * @return array<string, string>
 */
function pswdo_status_to_request_string_map(): array
{
    return [
        'pending_review' => 'pending',
        'incomplete_requirements' => 'incomplete_requirements',
        'for_interview' => 'for_interview',
        'under_review' => 'under_review',
        'approved' => 'approved',
        'not_approved' => 'not_approved',
        'released' => 'released',
        'cancelled' => 'cancelled',
    ];
}

function pswdo_normalize_release_view(string $view): string
{
    $normalized = strtolower(trim($view));
    $allowed = ['all', 'waiting_release', 'unscheduled', 'scheduled', 'ready_for_release', 'missed', 'released'];
    return in_array($normalized, $allowed, true) ? $normalized : 'all';
}

function pswdo_normalize_release_sort(string $sort): string
{
    $normalized = strtolower(trim($sort));
    $allowed = ['default', 'client_classification', 'type_of_assistance'];
    return in_array($normalized, $allowed, true) ? $normalized : 'default';
}

function pswdo_normalize_release_sort_group(string $sort, string $group): string
{
    $releaseSort = pswdo_normalize_release_sort($sort);
    $normalized = strtolower(trim($group));

    if ($releaseSort === 'client_classification') {
        $allowed = ['all', 'senior_citizen', 'solo_parent', 'pwd', 'ips'];
        return in_array($normalized, $allowed, true) ? $normalized : 'all';
    }

    if ($releaseSort === 'type_of_assistance') {
        $allowed = ['all', 'medical', 'burial', 'victim_of_disaster', 'special_cases'];
        return in_array($normalized, $allowed, true) ? $normalized : 'all';
    }

    return 'all';
}

function pswdo_normalize_prescreening_sort(string $sort): string
{
    $normalized = strtolower(trim($sort));
    $allowed = ['default', 'client_classification', 'type_of_assistance', 'high_priority'];
    return in_array($normalized, $allowed, true) ? $normalized : 'default';
}

function pswdo_normalize_prescreening_sort_group(string $sort, string $group): string
{
    $prescreenSort = pswdo_normalize_prescreening_sort($sort);
    $normalized = strtolower(trim($group));

    if ($prescreenSort === 'client_classification') {
        $allowed = ['all', 'senior_citizen', 'solo_parent', 'pwd', 'ips'];
        return in_array($normalized, $allowed, true) ? $normalized : 'all';
    }

    if ($prescreenSort === 'type_of_assistance') {
        $allowed = ['all', 'medical', 'burial', 'victim_of_disaster', 'special_cases'];
        return in_array($normalized, $allowed, true) ? $normalized : 'all';
    }

    if ($prescreenSort === 'high_priority') {
        $allowed = ['all', 'high_priority'];
        return in_array($normalized, $allowed, true) ? $normalized : 'all';
    }

    return 'all';
}
