import json
from datetime import datetime

STAGE_PROGRESS = {
    'queued': 0,
    'preprocessing': 10,
    'ocr': 35,
    'verifying': 65,
    'external_review': 75,
    'tamper_scan': 88,
}


def update_stage(connection, review_id: int, stage: str, progress: int) -> None:
    """Persist a validated active stage without exposing document contents."""
    if stage not in STAGE_PROGRESS or progress != STAGE_PROGRESS[stage]:
        raise ValueError('Invalid AI processing stage.')
    with connection.cursor() as cur:
        cur.execute(
            "UPDATE document_ai_reviews SET processing_stage=%s, progress_percent=%s, "
            "stage_updated_at=NOW(), worker_heartbeat_at=NOW() "
            "WHERE id=%s AND status='processing'",
            (stage, progress, review_id),
        )
    connection.commit()

def connect(settings):
    import pymysql
    # The Apache test alias must never let a detached worker fall back to the
    # development database if its explicitly propagated temporary DB value is
    # absent or malformed.
    if getattr(settings, 'http_test_mode', False) and settings.db_name != 'aics_http_regression':
        raise RuntimeError('Isolated HTTP test database is not configured for the AI worker.')
    return pymysql.connect(host=settings.db_host, port=settings.db_port, user=settings.db_user, password=settings.db_password, database=settings.db_name, cursorclass=pymysql.cursors.DictCursor, autocommit=False)

def claim_one(connection, stale_minutes: int):
    with connection.cursor() as cur:
        cur.execute("UPDATE document_ai_reviews SET status='failed', processing_stage='failed', progress_percent=100, stage_updated_at=NOW(), worker_heartbeat_at=NULL, last_error_code='AI_PROCESSING_TIMEOUT', last_error_message='Local processor did not complete within the configured retry window.', processed_at=NOW() WHERE status='processing' AND processing_started_at < DATE_SUB(NOW(), INTERVAL %s MINUTE) AND attempt_count >= 3", (stale_minutes,))
        cur.execute(
            """
            SELECT ai.id
            FROM document_ai_reviews ai
            JOIN aics_request_documents d ON d.id = ai.request_document_id
            JOIN aics_requests r ON r.id = d.request_id
            WHERE ai.status = 'processing'
              AND ai.attempt_count < 3
              AND (ai.processing_started_at IS NULL OR ai.processing_started_at < DATE_SUB(NOW(), INTERVAL %s MINUTE))
              AND (LOWER(r.assistance_types_json) LIKE %s OR LOWER(r.assistance_types_json) LIKE %s)
            ORDER BY ai.id
            LIMIT 1
            FOR UPDATE
            """,
            (stale_minutes, '%medical%', '%burial%'),
        )
        row = cur.fetchone()
        if not row:
            connection.rollback()
            return None
        cur.execute(
            "UPDATE document_ai_reviews SET processing_started_at=NOW(), processing_stage='preprocessing', progress_percent=10, stage_updated_at=NOW(), worker_heartbeat_at=NOW(), attempt_count=attempt_count+1 WHERE id=%s AND status='processing'",
            (row['id'],),
        )
        cur.execute(
            """
            SELECT ai.*, d.id AS document_id, d.file_path, d.mime_type,
                   r.id AS request_id, r.beneficiary_complete_name, r.beneficiary_age,
                   r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name,
                   r.beneficiary_birth_date, r.applicant_birth_date,
                   r.gender, r.beneficiary_barangay, r.beneficiary_sitio_purok, r.beneficiary_municipality, r.beneficiary_province,
                   r.assistance_types_json
            FROM document_ai_reviews ai
            JOIN aics_request_documents d ON d.id = ai.request_document_id
            JOIN aics_requests r ON r.id = d.request_id
            WHERE ai.id = %s
            """,
            (row['id'],),
        )
        result = cur.fetchone()
        connection.commit()
        return result

def claim_document(connection, document_id: int):
    """Claim only the requested queued document; never drain the shared queue."""
    with connection.cursor() as cur:
        cur.execute(
            """
            SELECT ai.id
            FROM document_ai_reviews ai
            JOIN aics_request_documents d ON d.id = ai.request_document_id
            WHERE d.id = %s AND ai.status = 'processing' AND ai.attempt_count < 3
            LIMIT 1 FOR UPDATE
            """,
            (document_id,),
        )
        row = cur.fetchone()
        if not row:
            connection.rollback()
            return None
        cur.execute(
            "UPDATE document_ai_reviews SET processing_started_at=NOW(), processing_stage='preprocessing', progress_percent=10, stage_updated_at=NOW(), worker_heartbeat_at=NOW(), attempt_count=attempt_count+1 WHERE id=%s AND status='processing'",
            (row['id'],),
        )
        cur.execute(
            """
            SELECT ai.*, d.id AS document_id, d.file_path, d.mime_type,
                   r.id AS request_id, r.beneficiary_complete_name, r.beneficiary_age,
                   r.applicant_first_name, r.applicant_middle_name, r.applicant_last_name,
                   r.beneficiary_birth_date, r.applicant_birth_date,
                   r.gender, r.beneficiary_barangay, r.beneficiary_sitio_purok, r.beneficiary_municipality, r.beneficiary_province,
                   r.assistance_types_json
            FROM document_ai_reviews ai
            JOIN aics_request_documents d ON d.id = ai.request_document_id
            JOIN aics_requests r ON r.id = d.request_id
            WHERE ai.id = %s
            """,
            (row['id'],),
        )
        result = cur.fetchone()
        connection.commit()
        return result

def finish(connection, review_id: int, result: dict):
    extracted_json = json.dumps(result.get('extracted_fields', {}), default=str, allow_nan=False)
    findings_json = json.dumps(result['findings'], default=str, allow_nan=False)
    with connection.cursor() as cur:
        cur.execute("UPDATE document_ai_reviews SET status=%s, processing_stage='completed', progress_percent=100, stage_updated_at=NOW(), worker_heartbeat_at=NULL, detected_document_type=%s, confidence_score=%s, extracted_fields=%s, findings=%s, model_service=%s, model_version=%s, last_error_code=NULL, last_error_message=NULL, processed_at=NOW() WHERE id=%s", (result['status'], result.get('detected_document_type'), result.get('confidence_score'), extracted_json, findings_json, result.get('model_service', 'local_ocr_rules'), result.get('model_version', 'component-assessments-v2'), review_id))
    connection.commit()

def fail(connection, review_id: int, code: str):
    connection.rollback()
    with connection.cursor() as cur: cur.execute("UPDATE document_ai_reviews SET status='failed', processing_stage='failed', progress_percent=100, stage_updated_at=NOW(), worker_heartbeat_at=NULL, last_error_code=%s, last_error_message='Local processor could not complete this verification.', processed_at=NOW() WHERE id=%s", (code, review_id))
    connection.commit()

def prior_fields(connection, request_id: int, document_id: int):
    with connection.cursor() as cur:
        cur.execute("SELECT ai.expected_document_type, ai.detected_document_type, ai.status, ai.extracted_fields, ai.findings FROM document_ai_reviews ai JOIN aics_request_documents d ON d.id=ai.request_document_id WHERE d.request_id=%s AND d.id<>%s AND ai.status IN ('completed','likely_valid','needs_staff_review','flagged_inconsistency')", (request_id, document_id))
        prior = []
        for row in cur.fetchall():
            if not row.get('extracted_fields'):
                continue
            fields = json.loads(row['extracted_fields']) if isinstance(row['extracted_fields'], str) else row['extracted_fields']
            if isinstance(fields, dict):
                fields['_document_type'] = row.get('expected_document_type')
                fields['_status'] = row.get('status')
                findings = (json.loads(row['findings']) if isinstance(row.get('findings'), str)
                            else (row.get('findings') or {}))
                checks = findings.get('checks', {}) if isinstance(findings, dict) else {}
                fields['_quality_usable'] = checks.get('quality', {}).get('status') == 'acceptable'
                fields['_type_match'] = (checks.get('document_type', {}).get('classification', {}).get('decision') == 'match'
                                         or row.get('detected_document_type') == row.get('expected_document_type'))
                prior.append(fields)
        return prior


def get_request_verification_context(job: dict) -> dict:
    """Return the immutable request snapshot fields used by document verification."""
    address = ', '.join(str(job.get(key) or '').strip() for key in ('beneficiary_sitio_purok', 'beneficiary_barangay', 'beneficiary_municipality', 'beneficiary_province')
                        if str(job.get(key) or '').strip())
    assistance = job.get('assistance_types_json')
    try:
        assistance = json.loads(assistance) if isinstance(assistance, str) else assistance
    except json.JSONDecodeError:
        assistance = []
    return {
        'applicant': {
            'full_name': ' '.join(str(job.get(key) or '').strip() for key in (
                'applicant_first_name', 'applicant_middle_name', 'applicant_last_name'
            ) if str(job.get(key) or '').strip()),
            'date_of_birth': job.get('applicant_birth_date'),
        },
        'full_name': str(job.get('beneficiary_complete_name') or '').strip(),
        'date_of_birth': job.get('beneficiary_birth_date'),
        'age': job.get('beneficiary_age'),
        'sex': job.get('gender'),
        'address': address,
        'barangay': str(job.get('beneficiary_barangay') or '').strip(),
        'municipality': str(job.get('beneficiary_municipality') or '').strip(),
        'province': str(job.get('beneficiary_province') or '').strip(),
        'assistance_types': assistance or [],
        # The current Burial intake stores the deceased/person served in the
        # submitted beneficiary snapshot; there is no separate deceased row.
        'burial_identity_source': 'beneficiary_snapshot',
        # Special Cases has no separately captured victim/incident person;
        # the beneficiary snapshot is the only person-served reference.
        'special_case_identity_source': 'beneficiary_snapshot',
        'educational_identity_source': 'beneficiary_snapshot',
        # Disaster Victims has no separate affected-person or property row;
        # the submitted beneficiary snapshot is the only person-served reference.
        'disaster_identity_source': 'beneficiary_snapshot',
    }
