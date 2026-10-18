"""Attach external provider evidence without changing the local decision."""
from copy import deepcopy
from datetime import date, datetime

from ..field_normalization import normalize_address, normalize_date, normalize_sex
from ..matching import name_match_details


_BENEFICIARY_FIELDS = frozenset({
    'name', 'full_name', 'patient_name', 'deceased_name', 'subject_name',
    'client_name', 'birth_date', 'date_of_birth', 'age', 'sex', 'address',
})
_BENEFICIARY_KEY = {
    'name': 'full_name', 'full_name': 'full_name', 'patient_name': 'full_name',
    'deceased_name': 'full_name', 'subject_name': 'full_name', 'client_name': 'full_name',
    'birth_date': 'date_of_birth', 'date_of_birth': 'date_of_birth',
    'age': 'age', 'sex': 'sex', 'address': 'address',
}


def _external_date(value) -> str | None:
    normalized = normalize_date(value, allow_ambiguous_numeric=True)
    if normalized:
        return normalized
    raw = str(value or '').strip().replace('.', '')
    for pattern in ('%d %b %y', '%d %B %y', '%b %d %y', '%B %d %y'):
        try:
            parsed = datetime.strptime(raw, pattern).date()
            if parsed > date.today():
                parsed = parsed.replace(year=parsed.year - 100)
            return parsed.isoformat()
        except ValueError:
            continue
    return None


def _deterministic_state(field_key: str, value, expected) -> str:
    if value in (None, '') or expected in (None, ''):
        return 'review'
    if _BENEFICIARY_KEY[field_key] == 'full_name':
        result = name_match_details(str(value), str(expected))['result']
        return 'support' if result in {'match', 'likely_match'} else 'conflict'
    if _BENEFICIARY_KEY[field_key] == 'date_of_birth':
        actual, target = _external_date(value), _external_date(expected)
        return 'support' if actual and target and actual == target else ('conflict' if actual and target else 'review')
    if field_key == 'sex':
        actual, target = normalize_sex(str(value)), normalize_sex(str(expected))
        return 'support' if actual and target and actual == target else ('conflict' if actual and target else 'review')
    if field_key == 'age':
        try:
            return 'support' if abs(int(value) - int(expected)) <= 1 else 'conflict'
        except (TypeError, ValueError):
            return 'review'
    if field_key == 'address':
        actual_tokens = set(normalize_address(str(value)).split())
        expected_tokens = set(normalize_address(str(expected)).split())
        if not actual_tokens or not expected_tokens:
            return 'review'
        overlap = actual_tokens & expected_tokens
        if actual_tokens == expected_tokens or len(overlap) >= 2 or len(overlap) / min(len(actual_tokens), len(expected_tokens)) >= .5:
            return 'support'
        return 'conflict' if len(actual_tokens) >= 2 and len(expected_tokens) >= 2 else 'review'
    return 'review'


def enforce_beneficiary_comparisons(provider_result: dict, beneficiary: dict,
                                    *, document_type_matches: bool = True) -> dict:
    """Override model match labels with comparisons to the selected reference person."""
    output = deepcopy(provider_result or {})
    assessment = output.get('assessment') if output.get('status') == 'completed' else None
    fields = assessment.get('fields') if isinstance(assessment, dict) else None
    if not isinstance(fields, dict) or not isinstance(beneficiary, dict):
        return output
    if not document_type_matches:
        # A death certificate in an ID slot describes the deceased, not an ID
        # cardholder. Do not turn that role difference into a name conflict.
        for field_key in _BENEFICIARY_KEY:
            if isinstance(fields.get(field_key), dict):
                fields[field_key]['state'] = 'review'
        if assessment.get('state') == 'conflict':
            assessment['state'] = 'review'
        reasons = assessment.get('reasons') if isinstance(assessment.get('reasons'), list) else []
        assessment['reasons'] = [reason for reason in reasons
                                 if reason not in {'FIELD_CONFLICT', 'APPLICANT_NAME_MISMATCH'}]
        return output
    conflict_found = False
    invalid_conflict_downgraded = False
    for field_key, beneficiary_key in _BENEFICIARY_KEY.items():
        item = fields.get(field_key)
        if not isinstance(item, dict):
            continue
        if item.get('value') in (None, ''):
            if str(item.get('state') or '').lower() == 'conflict':
                item['state'] = 'review'
                invalid_conflict_downgraded = True
            continue
        expected = beneficiary.get(beneficiary_key)
        state = _deterministic_state(field_key, item.get('value'), expected)
        item['state'] = state
        conflict_found = conflict_found or state == 'conflict'
    if conflict_found:
        assessment['state'] = 'conflict'
        reasons = assessment.get('reasons') if isinstance(assessment.get('reasons'), list) else []
        if 'FIELD_CONFLICT' not in reasons:
            reasons.append('FIELD_CONFLICT')
        assessment['reasons'] = reasons
    elif invalid_conflict_downgraded and not any(
        isinstance(item, dict) and str(item.get('state') or '').lower() == 'conflict'
        for item in fields.values()
    ):
        assessment['state'] = 'review'
        reasons = assessment.get('reasons') if isinstance(assessment.get('reasons'), list) else []
        assessment['reasons'] = [reason for reason in reasons if reason != 'FIELD_CONFLICT']
    return output


def _safe_assessment(assessment: dict) -> dict:
    """Do not persist a conflicting person value beside the beneficiary record."""
    safe = deepcopy(assessment)
    fields = safe.get('fields') if isinstance(safe, dict) else None
    if not isinstance(fields, dict):
        return safe
    for item in fields.values():
        if isinstance(item, dict) and str(item.get('state') or '').lower() == 'conflict':
            item['value'] = None
            item['difference_detected'] = True
    return safe


def attach_external_assessment(findings: dict, assessment: dict, invocation_reason: str) -> dict:
    output = dict(findings or {})
    source = assessment if isinstance(assessment, dict) else {}
    status = str(source.get('status') or 'unavailable')
    if status not in {'completed', 'unavailable', 'not_needed'}:
        status = 'unavailable'
    safe = {
        'status': status,
        'invocation_reason': str(invocation_reason or source.get('reason') or '')[:80],
        'payload_mode': str(source.get('payload_mode') or 'text') if str(source.get('payload_mode') or 'text') in {'text', 'image'} else 'text',
        'cache_hit': bool(source.get('cache_hit', False)),
        'elapsed_ms': max(0, int(source.get('elapsed_ms') or 0)),
        'attempts': max(0, int(source.get('attempts') or 0)),
    }
    for key in ('provider', 'model', 'error_code', 'reason'):
        if source.get(key):
            safe[key] = str(source[key])[:120]
    if status == 'completed' and isinstance(source.get('assessment'), dict):
        safe['assessment'] = _safe_assessment(source['assessment'])
    output['external_ai'] = safe
    return output


def apply_external_recommendation(result: dict, provider_result: dict,
                                  reference_owner: str = 'beneficiary') -> dict:
    """Escalate the AI recommendation without making a staff workflow decision."""
    output = dict(result or {})
    findings = deepcopy(output.get('findings') or {})
    assessments = findings.setdefault('assessments', {})
    provider = provider_result if isinstance(provider_result, dict) else {}
    assessment = provider.get('assessment') if provider.get('status') == 'completed' else None
    if not isinstance(assessment, dict):
        output['findings'] = findings
        return output

    quality = assessment.get('document_quality') if isinstance(assessment.get('document_quality'), dict) else {}
    quality_state = str(quality.get('state') or '').lower()
    quality_reason = str(quality.get('reason') or '').strip()[:240]
    current_readability = assessments.get('readability') if isinstance(assessments.get('readability'), dict) else {}
    if quality_state == 'recapture_required':
        assessments['readability'] = {
            'state': 'fail', 'label': 'Unreadable - replace document',
            'explanation': quality_reason or 'AICS AI could not read enough of the document reliably.',
        }
    elif quality_state in {'good', 'usable'} and str(current_readability.get('state') or '') != 'fail':
        assessments['readability'] = {
            'state': 'pass', 'label': 'Readable',
            'explanation': quality_reason or 'AICS AI found enough readable content for staff review.',
        }

    fields = assessment.get('fields') if isinstance(assessment.get('fields'), dict) else {}
    document_match = assessments.get('document_match') if isinstance(assessments.get('document_match'), dict) else {}
    wrong_slot = document_match.get('state') == 'fail'
    identity_states = [
        str(item.get('state') or '').lower()
        for key, item in fields.items()
        if not wrong_slot and key in _BENEFICIARY_FIELDS and isinstance(item, dict)
    ]
    identity_conflict = 'conflict' in identity_states
    identity_review = any(state in {'review', 'unavailable'} for state in identity_states)
    identity_supported = bool(identity_states) and all(state == 'support' for state in identity_states)
    current_identity = assessments.get('applicant_match') if isinstance(assessments.get('applicant_match'), dict) else {}
    person = 'applicant' if reference_owner == 'applicant' else 'beneficiary'
    if identity_conflict:
        assessments['applicant_match'] = {
            'state': 'fail', 'label': f'{person.title()} information differs',
            'explanation': f'A readable document value differs from the submitted {person} record. Inspect the original document.',
        }
    elif identity_review:
        assessments['applicant_match'] = {
            'state': 'review', 'label': f'Confirm {person} details',
            'explanation': f'AICS AI could not confirm one or more {person} fields reliably.',
        }
    elif identity_supported and str(current_identity.get('state') or '') != 'fail':
        assessments['applicant_match'] = {
            'state': 'pass', 'label': f'{person.title()} details matched',
            'explanation': f'Readable identity fields agree with the submitted {person} record.',
        }

    current_status = str(output.get('status') or 'completed')
    reason_codes = {str(reason) for reason in assessment.get('reasons', []) if isinstance(reason, str)}
    tamper_only_review = (
        str(assessment.get('schema_version') or '') == 'external-ai-assessment-v2'
        and reason_codes
        and reason_codes <= {'ALTERATION_REVIEW_SIGNAL', 'NO_ALTERATION_SIGNAL'}
    )
    if quality_state == 'recapture_required' and current_status != 'flagged_inconsistency':
        output['status'] = 'needs_reupload'
    elif identity_conflict and current_status != 'needs_reupload':
        output['status'] = 'flagged_inconsistency'
    elif (identity_review or (str(assessment.get('state') or '').lower() == 'review' and not tamper_only_review)) and current_status not in {'needs_reupload', 'flagged_inconsistency'}:
        output['status'] = 'needs_staff_review'

    reasons = findings.get('reasons') if isinstance(findings.get('reasons'), list) else []
    if quality_state == 'recapture_required':
        reasons.append('AICS AI found the document unreadable and recommends a clearer replacement image.')
    elif identity_conflict:
        reasons.append(f'A document value differs from the submitted {person} record; staff inspection is required.')
    elif identity_review:
        reasons.append(f'AICS AI could not confidently cross-reference all {person} information.')
    findings['reasons'] = list(dict.fromkeys(str(reason) for reason in reasons if str(reason).strip()))
    output['findings'] = findings
    return output
