<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/document-requirements.php';
require_once __DIR__ . '/../Applicants/php_files/application-rules.php';

function aics_chatbot_extract_reference(string $message): string
{
    if (preg_match('/\bAICS[-\s]?(\d{4})[-\s]?([A-F0-9]{6})\b/i', $message, $match) !== 1) {
        return '';
    }
    return 'AICS-' . $match[1] . '-' . strtoupper($match[2]);
}

function aics_chatbot_sanitize_remark(string $remark): string
{
    $remark = html_entity_decode(strip_tags($remark), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $remark = trim(preg_replace('/\s+/u', ' ', $remark) ?? '');
    return function_exists('mb_substr') ? mb_substr($remark, 0, 240, 'UTF-8') : substr($remark, 0, 240);
}

/** @return array{code:string,label:string} */
function aics_chatbot_assistance_meta(string $json): array
{
    $decoded = json_decode($json, true);
    $code = is_array($decoded) && isset($decoded[0]) ? strtolower(trim((string) $decoded[0])) : '';
    $labels = [
        'medical' => 'Medical Assistance',
        'burial' => 'Burial Assistance',
        'disaster_victims' => 'Disaster Victims',
        'special_cases' => 'Special Cases',
        'educational_basic' => 'Educational Assistance for Basic Education',
        'high_inflation_affected' => 'Persons Affected by High Inflation Rate',
    ];
    return ['code' => $code, 'label' => $labels[$code] ?? ($code !== '' ? ucwords(str_replace('_', ' ', $code)) : 'Not specified')];
}

/** @return array{reference_found:bool,selected_application:?array,cooldown:array,recent_notifications:array} */
function aics_chatbot_fetch_applicant_context(mysqli $connection, int $accountId, string $reference = ''): array
{
    $empty = [
        'reference_found' => $reference === '',
        'selected_application' => null,
        'cooldown' => [
            'years' => (int) AICS_APPLICATION_COOLDOWN_YEARS,
            'active' => false,
            'last_application_date' => '',
            'eligible_on' => '',
            'days_remaining' => 0,
        ],
        'recent_notifications' => [],
        'applications' => [],
    ];
    if ($accountId <= 0) return $empty;

    // Retrieve the entire account-owned index before selecting a detailed record.
    $indexStmt = $connection->prepare('SELECT r.reference_no, r.assistance_types_json, r.client_categories_json, r.request_status, r.date_applied FROM aics_requests r INNER JOIN aics_account_requests ar ON ar.request_id=r.id WHERE ar.account_id=? ORDER BY r.date_applied DESC, r.id DESC');
    $indexStmt->bind_param('i', $accountId);
    $indexStmt->execute();
    $indexRows = $indexStmt->get_result();
    while ($item = $indexRows->fetch_assoc()) {
        $categories = json_decode((string) ($item['client_categories_json'] ?? '[]'), true);
        $empty['applications'][] = [
            'reference' => $item['reference_no'],
            'assistance' => aics_chatbot_assistance_meta($item['assistance_types_json'])['label'],
            'classification' => is_array($categories) ? array_values(array_filter($categories, 'is_string')) : [],
            'status' => $item['request_status'], 'applied_date' => $item['date_applied'],
        ];
    }
    $indexStmt->close();

    $reference = strtoupper(trim($reference));
    $sql = <<<SQL
        SELECT r.id, r.reference_no, r.assistance_types_json, r.request_status,
               DATE(COALESCE(r.date_applied, r.created_at)) AS applied_date,
               r.updated_at, rs.scheduled_date, rs.scheduled_time, rs.venue,
               rs.release_batch, rs.release_status
        FROM aics_requests r
        INNER JOIN aics_account_requests ar ON ar.request_id = r.id
        LEFT JOIN aics_request_release_schedule rs ON rs.request_id = r.id
        WHERE ar.account_id = ?
    SQL;
    if ($reference !== '') $sql .= ' AND UPPER(r.reference_no) = ?';
    $sql .= ' ORDER BY COALESCE(r.date_applied, r.created_at) DESC, r.id DESC LIMIT 1';
    $stmt = $connection->prepare($sql);
    if ($reference === '') $stmt->bind_param('i', $accountId);
    else $stmt->bind_param('is', $accountId, $reference);
    $stmt->execute();
    $request = $stmt->get_result()?->fetch_assoc();
    $stmt->close();

    $cooldownState = aics_application_cooldown_state($connection, $accountId);
    $empty['cooldown'] = [
        'years' => (int) ($cooldownState['cooldown_years'] ?? AICS_APPLICATION_COOLDOWN_YEARS),
        'active' => (bool) ($cooldownState['is_in_cooldown'] ?? false),
        'last_application_date' => (string) ($cooldownState['last_date'] ?? ''),
        'eligible_on' => (string) ($cooldownState['eligible_on'] ?? ''),
        'days_remaining' => max(0, (int) ($cooldownState['days_remaining'] ?? 0)),
    ];
    if (!is_array($request)) {
        $empty['reference_found'] = $reference === '';
        return $empty;
    }

    $requestId = (int) $request['id'];
    $assistance = aics_chatbot_assistance_meta((string) ($request['assistance_types_json'] ?? '[]'));
    $statusCode = strtolower(trim((string) ($request['request_status'] ?? 'pending')));
    $statusMeta = function_exists('aics_dashboard_status_meta') ? aics_dashboard_status_meta($statusCode) : ['label' => ucwords(str_replace('_', ' ', $statusCode))];

    $documentsByType = [];
    $documentStmt = $connection->prepare(<<<SQL
        SELECT d.document_type, COALESCE(dr.is_checked, 0) AS is_checked,
               COALESCE(dr.review_status, 'pending') AS review_status,
               COALESCE(dr.remarks, '') AS review_remarks
        FROM aics_request_documents d
        LEFT JOIN aics_request_document_reviews dr ON dr.request_document_id = d.id
        WHERE d.request_id = ?
        ORDER BY d.id
        SQL);
    $documentStmt->bind_param('i', $requestId);
    $documentStmt->execute();
    $documentResult = $documentStmt->get_result();
    while ($row = $documentResult?->fetch_assoc()) {
        $type = aics_normalize_document_type((string) ($row['document_type'] ?? ''));
        if ($type === '' || isset($documentsByType[$type])) continue;
        $documentsByType[$type] = $row;
    }
    $documentStmt->close();

    $documents = [];
    $uploadedCount = 0;
    $reviewedCount = 0;
    $actionItems = [];
    foreach (aics_document_requirements_for($assistance['code']) as $requirement) {
        $code = (string) $requirement['code'];
        $uploaded = isset($documentsByType[$code]);
        $row = $uploaded ? $documentsByType[$code] : [];
        $reviewStatus = strtolower(trim((string) ($row['review_status'] ?? 'missing')));
        $checked = $uploaded && ((int) ($row['is_checked'] ?? 0) === 1 || $reviewStatus === 'checked');
        if ($uploaded) $uploadedCount++;
        if ($checked) $reviewedCount++;
        if (!$uploaded) $actionItems[] = 'Upload ' . $requirement['label'];
        elseif (in_array($reviewStatus, ['needs_reupload', 'invalid'], true)) $actionItems[] = 'Review ' . $requirement['label'];
        $documents[] = [
            'label' => (string) $requirement['label'],
            'uploaded' => $uploaded,
            'review_status' => $reviewStatus,
            'staff_remark' => aics_chatbot_sanitize_remark((string) ($row['review_remarks'] ?? '')),
        ];
    }

    $notifications = [];
    $noticeStmt = $connection->prepare(<<<SQL
        SELECT notification_stage, sent_at
        FROM aics_request_notifications
        WHERE request_id = ? AND COALESCE(channel, 'applicant_sms') = 'applicant_sms'
        ORDER BY sent_at DESC, id DESC LIMIT 5
        SQL);
    $noticeStmt->bind_param('i', $requestId);
    $noticeStmt->execute();
    $noticeResult = $noticeStmt->get_result();
    while ($row = $noticeResult?->fetch_assoc()) {
        $notifications[] = [
            'title' => aics_chatbot_sanitize_remark((string) ($row['notification_stage'] ?? 'Application update')),
            'created_at' => (string) ($row['sent_at'] ?? ''),
        ];
    }
    $noticeStmt->close();

    $releaseStatus = strtolower(trim((string) ($request['release_status'] ?? 'unscheduled')));
    $empty['reference_found'] = true;
    $empty['recent_notifications'] = $notifications;
    $empty['selected_application'] = [
        'reference' => (string) $request['reference_no'],
        'assistance_label' => $assistance['label'],
        'status_code' => $statusCode,
        'status_label' => (string) ($statusMeta['label'] ?? ucwords(str_replace('_', ' ', $statusCode))),
        'applied_date' => (string) ($request['applied_date'] ?? ''),
        'last_updated' => (string) ($request['updated_at'] ?? ''),
        'release' => [
            'status' => $releaseStatus !== '' ? $releaseStatus : 'unscheduled',
            'date' => (string) ($request['scheduled_date'] ?? ''),
            'time' => (string) ($request['scheduled_time'] ?? ''),
            'venue' => trim((string) ($request['venue'] ?? '')),
            'batch' => trim((string) ($request['release_batch'] ?? '')),
        ],
        'documents' => $documents,
        'required_count' => count($documents),
        'uploaded_count' => $uploadedCount,
        'reviewed_count' => $reviewedCount,
        'action_items' => array_slice($actionItems, 0, 8),
    ];
    foreach ($empty['applications'] as $item) {
        if ($item['reference'] === $request['reference_no']) $empty['selected_application']['classification'] = $item['classification'];
    }
    return $empty;
}
