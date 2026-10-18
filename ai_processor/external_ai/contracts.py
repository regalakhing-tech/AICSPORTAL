"""Conservative invocation and schema contracts for external AI evidence."""
from dataclasses import dataclass
import re
from typing import Any


_ASSESSMENT_STATES = frozenset({'support', 'review', 'conflict', 'unavailable'})
_CONFIDENCE_BANDS = frozenset({'low', 'moderate', 'high'})
_FIELD_STATES = _ASSESSMENT_STATES
_REASON_PATTERN = re.compile(r'^[A-Z0-9_]{3,64}$')
_ALLOWED_REASON_CODES = frozenset({
    'APPLICANT_NAME_MISMATCH', 'APPLICANT_NAME_UNCERTAIN', 'DATE_UNCERTAIN',
    'DOCUMENT_TYPE_MISMATCH', 'DOCUMENT_TYPE_UNCERTAIN', 'FIELD_CONFLICT',
    'FIELD_UNCERTAIN', 'INSUFFICIENT_EVIDENCE', 'LOW_OCR_QUALITY',
    'NO_CONFLICT_FOUND', 'ALTERATION_REVIEW_SIGNAL', 'NO_ALTERATION_SIGNAL',
})
_FORBIDDEN_KEYS = frozenset({
    'decision', 'approval', 'approve', 'reject', 'fraud', 'fraudulent',
    'forged', 'forgery', 'fake', 'authentic', 'confirmed_duplicate',
})
_BENEFICIARY_KEYS = ('full_name', 'date_of_birth', 'age', 'sex', 'address', 'assistance_types')
_TAMPER_STATES = frozenset({'no_signal', 'review_signal', 'unavailable'})
_ACCUSATORY_TAMPER_TEXT = re.compile(
    r'(?i)\b(?:forg(?:ed|ery)|fraud(?:ulent)?|fake|authentic(?:ity)?|definitely\s+altered|'
    r'confirmed\s+tamper(?:ed|ing)?|(?:document|image|field|value)\s+(?:was|is|appears)\s+(?:tampered|altered))\b'
)
_TAMPER_INDICATOR_TYPES = frozenset({
    'typography_mismatch', 'baseline_or_spacing_mismatch', 'ink_or_color_mismatch',
    'pasted_region_boundary', 'background_texture_discontinuity',
    'compression_or_resampling_difference', 'date_context_conflict',
    'value_context_conflict', 'signature_or_stamp_anomaly', 'other_visual_anomaly',
})


class ExternalAiContractError(ValueError):
    pass


@dataclass(frozen=True)
class InvocationDecision:
    invoke: bool
    reason: str


def _assessment_state(local_result: dict, key: str) -> str:
    findings = local_result.get('findings') if isinstance(local_result, dict) else {}
    assessments = findings.get('assessments') if isinstance(findings, dict) else {}
    item = assessments.get(key) if isinstance(assessments, dict) else {}
    return str(item.get('state') or '').strip().lower() if isinstance(item, dict) else ''


def decide_invocation(local_result: dict, classification: dict, fields: dict, force: bool = False) -> InvocationDecision:
    if force:
        return InvocationDecision(True, 'staff_requested_diagnostic')
    if _assessment_state(local_result, 'readability') == 'fail' or str(local_result.get('status') or '') == 'needs_reupload':
        return InvocationDecision(True, 'document_quality_review')
    applicant_state = _assessment_state(local_result, 'applicant_match')
    if applicant_state in {'review', 'fail', 'unavailable'}:
        return InvocationDecision(True, 'beneficiary_match_uncertain')
    document_state = _assessment_state(local_result, 'document_match')
    classification_decision = str((classification or {}).get('decision') or '').strip().lower()
    if document_state in {'review', 'fail', 'unavailable'} or classification_decision not in {'match', 'not_applicable'}:
        return InvocationDecision(True, 'document_type_uncertain')
    if any(isinstance(value, dict) and str(value.get('status') or '') in {'uncertain', 'conflict'} for value in (fields or {}).values()):
        return InvocationDecision(True, 'field_evidence_uncertain')
    return InvocationDecision(True, 'beneficiary_cross_reference')


def _safe_scalar(value: Any, limit: int = 500) -> Any:
    if value is None or isinstance(value, (bool, int, float)):
        return value
    if isinstance(value, str):
        return value[:limit]
    if isinstance(value, (list, tuple)):
        return [_safe_scalar(item, limit) for item in value[:20] if isinstance(item, (str, bool, int, float)) or item is None]
    return None


def build_evidence(expected_type: str, ocr_text: str, fields: dict, local_result: dict, application_context: dict) -> dict:
    safe_fields = {}
    for key, item in (fields or {}).items():
        if not isinstance(key, str) or key.startswith('_') or not isinstance(item, dict):
            continue
        safe_item = {}
        for item_key in ('status', 'normalized', 'value', 'raw_value', 'confidence', 'page'):
            if item_key in item:
                safe_item[item_key] = _safe_scalar(item[item_key])
        safe_fields[key[:64]] = safe_item
    assessments = ((local_result or {}).get('findings') or {}).get('assessments', {})
    safe_assessments = {}
    if isinstance(assessments, dict):
        for key in ('document_match', 'readability', 'applicant_match', 'alteration_signal'):
            item = assessments.get(key)
            if isinstance(item, dict):
                safe_assessments[key] = {'state': str(item.get('state') or '')[:32]}
    safe_beneficiary = {key: _safe_scalar((application_context or {}).get(key)) for key in _BENEFICIARY_KEYS if key in (application_context or {})}
    evidence = {
        'schema_version': 'external-ai-evidence-v1',
        'expected_document_type': str(expected_type or '')[:100],
        'ocr_text': str(ocr_text or '')[:12000],
        'fields': safe_fields,
        'local_assessments': safe_assessments,
    }
    if expected_type == 'valid_id' and (application_context or {}).get('reference_owner') == 'applicant':
        evidence['reference_person'] = 'applicant'
        evidence['applicant'] = safe_beneficiary
    else:
        evidence['beneficiary'] = safe_beneficiary
    return evidence


def _invalid_schema() -> ExternalAiContractError:
    return ExternalAiContractError('EXTERNAL_AI_INVALID_SCHEMA')


def _contains_forbidden_key(value: object) -> bool:
    if isinstance(value, dict):
        return any(str(key).strip().lower() in _FORBIDDEN_KEYS or _contains_forbidden_key(item) for key, item in value.items())
    if isinstance(value, (list, tuple)):
        return any(_contains_forbidden_key(item) for item in value)
    return False


def _normalize_tamper_assessment(section: object, payload_mode: str, page_count: int) -> dict:
    unavailable = {
        'state': 'unavailable', 'confidence_band': 'low',
        'summary': 'Visual alteration review requires document images.', 'indicators': [],
    }
    if payload_mode != 'image':
        return unavailable
    if not isinstance(section, dict):
        raise _invalid_schema()
    state = str(section.get('state') or '').strip().lower()
    confidence = str(section.get('confidence_band') or '').strip().lower()
    summary = section.get('summary')
    indicators = section.get('indicators')
    if state not in _TAMPER_STATES or confidence not in _CONFIDENCE_BANDS:
        raise _invalid_schema()
    if not isinstance(summary, str) or not summary.strip() or len(summary.strip()) > 240:
        raise _invalid_schema()
    if _ACCUSATORY_TAMPER_TEXT.search(summary):
        raise _invalid_schema()
    if not isinstance(indicators, list) or len(indicators) > 12:
        raise _invalid_schema()
    normalized = []
    for item in indicators:
        if not isinstance(item, dict) or set(item) != {'type', 'page', 'box_2d', 'confidence_band', 'reason'}:
            raise _invalid_schema()
        kind = str(item.get('type') or '').strip().lower()
        band = str(item.get('confidence_band') or '').strip().lower()
        reason = item.get('reason')
        page = item.get('page')
        box = item.get('box_2d')
        if kind not in _TAMPER_INDICATOR_TYPES or band not in _CONFIDENCE_BANDS:
            raise _invalid_schema()
        if not isinstance(reason, str) or not reason.strip() or len(reason.strip()) > 240:
            raise _invalid_schema()
        if _ACCUSATORY_TAMPER_TEXT.search(reason):
            raise _invalid_schema()
        if isinstance(page, bool) or not isinstance(page, int) or page < 1 or page > max(0, int(page_count)):
            raise _invalid_schema()
        if (not isinstance(box, list) or len(box) != 4 or
                any(isinstance(value, bool) or not isinstance(value, int) or value < 0 or value > 1000 for value in box) or
                box[0] >= box[2] or box[1] >= box[3]):
            raise _invalid_schema()
        normalized.append({'type': kind, 'page': page, 'box_2d': list(box),
                           'confidence_band': band, 'reason': reason.strip()})
    if state == 'no_signal' and normalized:
        raise _invalid_schema()
    return {'state': state, 'confidence_band': confidence,
            'summary': summary.strip(), 'indicators': normalized}


def normalize_assessment(payload: dict, *, payload_mode: str = 'text', page_count: int = 0) -> dict:
    if not isinstance(payload, dict) or _contains_forbidden_key(payload):
        raise _invalid_schema()
    schema_version = payload.get('schema_version')
    if schema_version not in {'external-ai-assessment-v1', 'external-ai-assessment-v2'}:
        raise _invalid_schema()
    allowed_top = {'schema_version', 'state', 'confidence_band', 'document_type', 'reasons', 'fields',
                   'document_quality', 'signature_review', 'visual_integrity'}
    if schema_version == 'external-ai-assessment-v2':
        allowed_top.add('tamper_assessment')
    if set(payload) != allowed_top:
        raise _invalid_schema()
    state = str(payload.get('state') or '').strip().lower()
    confidence = str(payload.get('confidence_band') or '').strip().lower()
    if state not in _ASSESSMENT_STATES or confidence not in _CONFIDENCE_BANDS:
        raise _invalid_schema()
    reasons = payload.get('reasons')
    if (not isinstance(reasons, list) or len(reasons) > 8
            or any(not isinstance(reason, str) or not _REASON_PATTERN.fullmatch(reason)
                   or reason not in _ALLOWED_REASON_CODES for reason in reasons)):
        raise _invalid_schema()
    fields = payload.get('fields')
    if not isinstance(fields, dict) or len(fields) > 30:
        raise _invalid_schema()
    normalized_fields = {}
    for name, item in fields.items():
        if (not isinstance(name, str) or not re.fullmatch(r'[a-z][a-z0-9_]{0,63}', name)
                or not isinstance(item, dict) or set(item) != {'value', 'state'}):
            raise _invalid_schema()
        field_state = str(item.get('state') or '').strip().lower()
        if field_state not in _FIELD_STATES:
            raise _invalid_schema()
        value = item.get('value')
        if value is not None and not isinstance(value, (str, bool, int, float)):
            raise _invalid_schema()
        normalized_fields[name] = {'value': _safe_scalar(value), 'state': field_state}
    document_type = payload.get('document_type')
    if document_type is not None and (not isinstance(document_type, str) or not re.fullmatch(r'[a-z0-9_]{1,100}', document_type)):
        raise _invalid_schema()
    def normalized_visual_section(key: str, allowed: set[str]) -> dict:
        section = payload.get(key)
        if not isinstance(section, dict):
            raise _invalid_schema()
        if set(section) != {'state', 'reason'}:
            raise _invalid_schema()
        section_state = str(section.get('state') or '').strip().lower()
        reason = section.get('reason')
        if section_state not in allowed or not isinstance(reason, str) or not reason.strip():
            raise _invalid_schema()
        if _ACCUSATORY_TAMPER_TEXT.search(reason):
            raise _invalid_schema()
        return {'state': section_state, 'reason': reason.strip()[:240]}

    normalized = {
        'schema_version': schema_version,
        'state': state,
        'confidence_band': confidence,
        'document_type': document_type,
        'reasons': list(reasons),
        'fields': normalized_fields,
        'document_quality': normalized_visual_section('document_quality', {'good', 'usable', 'recapture_required'}),
        'signature_review': normalized_visual_section('signature_review', {'visible', 'not_visible', 'review_needed'}),
        'visual_integrity': normalized_visual_section('visual_integrity', {'no_obvious_issue', 'review_needed', 'unavailable'}),
    }
    if schema_version == 'external-ai-assessment-v2':
        normalized['tamper_assessment'] = _normalize_tamper_assessment(
            payload.get('tamper_assessment'), payload_mode, page_count,
        )
    else:
        normalized['tamper_assessment'] = {
            'state': 'unavailable', 'confidence_band': 'low',
            'summary': 'This earlier provider result did not include localized alteration analysis.',
            'indicators': [],
        }
    return normalized
