"""Conservative, profile-aware chronology evidence for document review."""
from __future__ import annotations

from collections import Counter
from datetime import date
import re

from ..field_normalization import normalize_date
from ..field_extractor import DATE_PATTERN


_LONG_GAP_DAYS = 730
_MAX_INDICATORS = 6
_YEAR = re.compile(r'\b(?:19|20)\d{2}\b')
_PERSONAL_DATE = re.compile(r'(?i)\b(?:date\s+of\s+birth|birth\s*date|born|dob)\b')
_EVENT_DATE_CONTEXT = re.compile(
    r'(?i)\b(?:date|issued|signed|certified|confined|admitted|discharged|examined|treated|'
    r'incident|occurred|service|billing|assessment|semester|school\s+year|death|died|burial|funeral)\b'
)
_MEDICAL_EVENT_DATE_CONTEXT = re.compile(
    r'(?i)\b(?:attending\s+physician|illness|hospitali[sz]ed|patient|leave)\b'
)
_SUPPORTED = frozenset({
    'med_cert', 'billing_statement', 'death_cert', 'funeral_contract',
    'report_police_med', 'school_assessment', 'registration_form', 'mdrrmo_cert',
})


def _date(value) -> date | None:
    normalized = normalize_date(value, allow_ambiguous_numeric=True)
    if not normalized:
        return None
    try:
        return date.fromisoformat(normalized)
    except ValueError:
        return None


def _lines(metadata: dict) -> list[dict]:
    output = []
    pages = metadata.get('pages', []) if isinstance(metadata, dict) else []
    for page_index, page in enumerate(pages, 1):
        if not isinstance(page, dict):
            continue
        page_number = int(page.get('page_number') or page_index)
        for line in page.get('lines', []):
            if not isinstance(line, dict) or not isinstance(line.get('text'), str):
                continue
            box = line.get('box')
            if not isinstance(box, list) or len(box) != 4:
                box = [0, 0, 1000, 1000]
            output.append({'page': page_number, 'text': line['text'], 'box': box,
                           'confidence': float(line.get('confidence') or 0)})
    return output


def _normalized_box(box: list, width: int, height: int) -> list[int]:
    x1, y1, x2, y2 = [int(value) for value in box]
    x1, x2 = max(0, x1), min(width, x2)
    y1, y2 = max(0, y1), min(height, y2)
    if x1 >= x2 or y1 >= y2:
        return [0, 0, 1000, 1000]
    return [round(y1 * 1000 / height), round(x1 * 1000 / width),
            round(y2 * 1000 / height), round(x2 * 1000 / width)]


def analyze_chronology(expected_type: str, ocr_metadata: dict, fields: dict, images: list | None = None) -> dict:
    """Return chronology candidates that require visual corroboration."""
    base = {'status': 'completed', 'family': 'semantic_chronology', 'decision': 'clear', 'indicators': []}
    if str(expected_type or '') not in _SUPPORTED:
        return base
    document_field = (fields or {}).get('document_date', {})
    document_date = _date(document_field.get('normalized') or document_field.get('value') or document_field.get('raw_value')) if isinstance(document_field, dict) else None
    if document_date is None:
        return base
    lines = _lines(ocr_metadata)
    document_date_line = next((line for line in lines
                               if str(document_date.year) in line['text']
                               and re.search(r'(?i)\b(?:date|issued|signed|certified)\b', line['text'])
                               and not _PERSONAL_DATE.search(line['text'])
                               and line.get('confidence', 0) >= 70), None)
    indicators = []
    conflicting_years = []
    for line in lines:
        page_index = max(0, int(line['page']) - 1)
        if not images or page_index >= len(images) or images[page_index] is None:
            continue
        height, width = images[page_index].shape[:2]
        if _PERSONAL_DATE.search(line['text']):
            continue
        if line.get('confidence', 0) < 70:
            continue
        has_event_context = bool(_EVENT_DATE_CONTEXT.search(line['text']))
        if expected_type == 'med_cert':
            has_event_context = has_event_context or bool(_MEDICAL_EVENT_DATE_CONTEXT.search(line['text']))
        if not has_event_context:
            continue
        parsed_dates = [_date(match.group(0)) for match in re.finditer(DATE_PATTERN, line['text'], re.I)]
        parsed_dates = [value for value in parsed_dates if value is not None]
        years = [value.year for value in parsed_dates] or [int(match.group(0)) for match in _YEAR.finditer(line['text'])]
        if not years or document_date.year in years:
            continue
        farthest_days = max((abs((document_date - value).days) for value in parsed_dates), default=max(abs(document_date.year - year) * 365 for year in years))
        if farthest_days < _LONG_GAP_DAYS:
            continue
        # Count each conflicting year once per independently recognized line.
        conflicting_years.extend(set(years))
        target = document_date_line if document_date_line is not None else line
        target_index = max(0, int(target['page']) - 1)
        if target_index >= len(images) or images[target_index] is None:
            continue
        target_height, target_width = images[target_index].shape[:2]
        indicators.append({
            'type': 'date_context_conflict', 'page': target['page'],
            'box_2d': _normalized_box(target['box'], target_width, target_height), 'confidence_band': 'moderate',
            'reason': 'A prominent document date is unusually far from another dated event and requires staff inspection.',
        })
    repeated_conflict = any(count >= 2 for count in Counter(conflicting_years).values())
    if indicators:
        confidence_band = 'high' if repeated_conflict else 'moderate'
        if repeated_conflict:
            for indicator in indicators:
                indicator['confidence_band'] = 'high'
                indicator['reason'] = (
                    'The signature or issue date conflicts with repeated high-confidence '
                    'event dates and requires staff inspection.'
                )
        base['decision'] = 'review'
        base['indicators'] = indicators[:_MAX_INDICATORS]
        base['confidence_band'] = confidence_band
        base['repeated_conflict'] = repeated_conflict
        if repeated_conflict:
            repeated_years = sorted(
                year for year, count in Counter(conflicting_years).items() if count >= 2
            )
            base['date_conflict'] = {
                'document_date': document_date.isoformat(),
                'conflicting_years': repeated_years,
            }
    return base
