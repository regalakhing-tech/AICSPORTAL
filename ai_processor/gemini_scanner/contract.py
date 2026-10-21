"""Validate and bound the Gemini scan before anything is stored or shown."""
import re

from .catalog import IDENTITY_ROLES
from .prompt import CHECK_KEYS, CHECK_STATES, DOCUMENT_TYPES, INDICATOR_TYPES

__all__ = ['CHECK_KEYS', 'normalize_scan']

_ACCUSATORY = re.compile(r'(?i)\b(?:forg(?:ed|ery|eries)|fraud(?:ulent)?|fakes?|faked|counterfeit|authentic(?:ity)?)\b')
_NEUTRAL_REASON = {
    'complete': 'Check that every page and expected part of the document is present.',
    'consistent': 'Compare the document details with the requirement and with each other.',
    'readable': 'Check that the key details can be read clearly.',
    'unaltered': 'Inspect the marked entries on the original document for physical changes.',
    'untampered': 'Inspect the marked areas on the original document for signs of editing.',
}
_NEUTRAL_SUMMARY = 'Staff should inspect the original document for the items marked for review.'


def _text(value, limit: int) -> str:
    if not isinstance(value, (str, int, float)) or isinstance(value, bool):
        return ''
    return re.sub(r'\s+', ' ', str(value)).strip()[:limit]


def _optional_text(value, limit: int) -> str | None:
    text = _text(value, limit)
    return text if text and text.lower() not in {'null', 'none', 'n/a', 'unknown'} else None


def _neutral(text: str, fallback: str) -> str:
    return fallback if _ACCUSATORY.search(text) else text


def _box(value) -> list[int] | None:
    if not isinstance(value, list) or len(value) != 4:
        return None
    try:
        box = [min(1000, max(0, int(round(float(item))))) for item in value]
    except (TypeError, ValueError):
        return None
    return box if box[0] < box[2] and box[1] < box[3] else None


def _check(value, key: str) -> dict:
    if not isinstance(value, dict):
        return {'state': 'unavailable', 'reason': 'AICS AI did not return this check.', 'missing': []}
    state = str(value.get('state') or '').strip().lower()
    if state not in CHECK_STATES:
        state = 'review'
    reason = _neutral(_text(value.get('reason'), 240), _NEUTRAL_REASON[key]) or _NEUTRAL_REASON[key]
    missing_source = value.get('missing') if isinstance(value.get('missing'), list) else []
    missing = [_neutral(_text(item, 60), '') for item in missing_source]
    return {'state': state, 'reason': reason, 'missing': [item for item in missing if item][:8]}


def _indicator(value, page_count: int) -> dict | None:
    if not isinstance(value, dict) or value.get('type') not in INDICATOR_TYPES:
        return None
    check = value.get('check') if value.get('check') in {'unaltered', 'untampered'} else 'untampered'
    band = value.get('confidence_band') if value.get('confidence_band') in {'low', 'moderate', 'high'} else 'low'
    try:
        page = int(value.get('page') or 1)
    except (TypeError, ValueError):
        page = 1
    reason = _neutral(_text(value.get('reason'), 200), _NEUTRAL_REASON[check]) or _NEUTRAL_REASON[check]
    return {
        'type': value['type'], 'check': check, 'page': min(max(1, page), max(1, int(page_count))),
        'box_2d': _box(value.get('box_2d')), 'confidence_band': band, 'reason': reason,
    }


def _totals(value) -> list[dict]:
    """Printed totals and the amounts they should add up to, copied as text."""
    totals = []
    for item in value[:6] if isinstance(value, list) else []:
        if not isinstance(item, dict):
            continue
        stated = _text(item.get('stated_total'), 40)
        source = item.get('line_amounts') if isinstance(item.get('line_amounts'), list) else []
        lines = [line for line in (_text(entry, 40) for entry in source[:40]) if line]
        if stated and lines:
            totals.append({'label': _neutral(_text(item.get('label'), 80), ''), 'stated_total': stated,
                           'line_amounts': lines})
    return totals


def normalize_scan(raw, page_count: int) -> dict:
    """Return a bounded scan dict; raise ValueError for a non-object response."""
    if not isinstance(raw, dict):
        raise ValueError('scan response is not an object')
    document_type = str(raw.get('document_type') or '').strip().lower()
    if document_type not in DOCUMENT_TYPES:
        document_type = 'unknown'
    try:
        confidence = int(round(float(raw.get('type_confidence') or 0)))
    except (TypeError, ValueError):
        confidence = 0
    person = raw.get('primary_person') if isinstance(raw.get('primary_person'), dict) else {}
    role = str(person.get('role') or '').strip().lower()
    checks_source = raw.get('checks') if isinstance(raw.get('checks'), dict) else {}
    indicator_source = raw.get('indicators') if isinstance(raw.get('indicators'), list) else []
    indicators = [_indicator(item, page_count) for item in indicator_source[:12]]
    sample = raw.get('sample_or_specimen') if isinstance(raw.get('sample_or_specimen'), dict) else {}
    sample_detected = sample.get('detected') is True
    summary = _neutral(_text(raw.get('summary'), 400), _NEUTRAL_SUMMARY)
    return {
        'document_type': document_type,
        'document_title': _neutral(_text(raw.get('document_title'), 120), ''),
        'type_confidence': min(100, max(0, confidence)),
        'primary_person': {
            'name': _optional_text(person.get('name'), 120),
            'role': role if role in IDENTITY_ROLES else 'other',
            'birth_date': _optional_text(person.get('birth_date'), 40),
            'sex': _optional_text(person.get('sex'), 20),
            'address': _optional_text(person.get('address'), 200),
        },
        'beneficiary_name_found': raw.get('beneficiary_name_found') is True,
        'document_date': _optional_text(raw.get('document_date'), 40),
        'issuer': _optional_text(raw.get('issuer'), 120),
        'checks': {key: _check(checks_source.get(key), key) for key in CHECK_KEYS},
        'indicators': [item for item in indicators if item is not None],
        'sample_or_specimen': {
            'detected': sample_detected,
            'reason': _neutral(_text(sample.get('reason'), 200), 'The image may be a sample or copied picture.') if sample_detected else '',
        },
        'totals': _totals(raw.get('totals')),
        'summary': summary,
    }
