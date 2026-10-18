"""Application-first field consistency with an isolated trusted Valid-ID reference."""
from datetime import date
import re

from .field_normalization import normalize_address, normalize_date, normalize_sex
from .matching import name_match_details

NAME_KEYS = ('patient_name', 'deceased_name', 'subject_name', 'client_name', 'name')
HARD_IDENTITY_FIELDS = {'full_name', 'date_of_birth', 'sex'}

# The extractor may know about more fields than a particular document normally
# carries.  This profile is the single authority for whether a field belongs in
# cross-document identity verification.  ``core`` fields are shown even when
# absent; ``optional`` fields are compared only when the document extracted a
# usable value.  Everything else remains informational extraction data.
CONSISTENCY_FIELDS = {
    'valid_id': {'core': ('full_name',), 'optional': ('date_of_birth', 'sex', 'address')},
    'med_cert': {'core': ('full_name',), 'optional': ('date_of_birth', 'sex', 'age', 'address')},
    'billing_statement': {'core': ('full_name',), 'optional': ('date_of_birth', 'sex', 'age', 'address')},
    'death_cert': {'core': ('full_name',), 'optional': ('date_of_birth', 'sex', 'age', 'address')},
    'funeral_contract': {'core': ('full_name',), 'optional': ('address',)},
    'report_police_med': {'core': ('full_name',), 'optional': ('date_of_birth', 'sex', 'age', 'address')},
    'coe_mswdo': {'core': ('full_name',), 'optional': ('address',)},
}


def _display(value):
    if value in (None, ''):
        return ''
    return value.isoformat() if hasattr(value, 'isoformat') else value


def _field(fields: dict, keys) -> dict:
    for key in keys if isinstance(keys, tuple) else (keys,):
        candidate = fields.get(key)
        if isinstance(candidate, dict):
            return candidate
    return {'status': 'not_present', 'value': None, 'normalized': None, 'confidence': 0}


def _is_extracted(field: dict) -> bool:
    return field.get('status', 'extracted' if field.get('value') not in (None, '') else 'not_present') == 'extracted'


def _field_for_comparison(fields: dict, key: str) -> dict:
    return _field(fields, NAME_KEYS if key == 'full_name' else ('birth_date' if key == 'date_of_birth' else key))


def _confidence(field: dict) -> float:
    try:
        return float(field.get('confidence', .7))
    except (TypeError, ValueError):
        return 0


def _name_comparison(field: dict, expected: str | None) -> dict:
    state = field.get('status', 'extracted' if field.get('value') else 'not_present')
    if state != 'extracted':
        return {'status': state, 'application': expected or '', 'document': field.get('value') or ''}
    if _confidence(field) < .68:
        return {'status': 'uncertain', 'application': expected or '', 'document': field.get('value') or ''}
    details = name_match_details(str(field.get('value') or ''), expected)
    return {'status': details['result'], 'application': expected or '', 'document': field.get('value') or '',
            'match_confidence': details['confidence']}


def _normalized_comparison(field: dict, expected, normalizer) -> dict:
    state = field.get('status', 'extracted' if field.get('value') not in (None, '') else 'not_present')
    result = {'status': state, 'application': _display(expected), 'document': _display(field.get('raw_value', field.get('value')))}
    if state != 'extracted':
        return result
    actual_normalized = field.get('normalized') or normalizer(field.get('value'))
    expected_normalized = normalizer(expected)
    if _confidence(field) < .68 or not actual_normalized or not expected_normalized:
        result['status'] = 'uncertain'
    else:
        result['status'] = 'match' if actual_normalized == expected_normalized else 'mismatch'
    return result


def _address_part(value: str, kind: str) -> str:
    normalized = normalize_address(value)
    prefixes = {
        'barangay': ('barangay ',),
        'municipality': ('municipality of ', 'municipality '),
        'province': ('province of ', 'province '),
    }
    for prefix in prefixes.get(kind, ()):
        if normalized.startswith(prefix):
            return normalized[len(prefix):].strip()
    return normalized


def _has_street_detail(value: str) -> bool:
    tokens = set(normalize_address(value).split())
    return bool(tokens & {'street', 'st', 'road', 'rd', 'avenue', 'ave', 'sitio', 'purok', 'zone', 'block', 'blk', 'lot'})


def _address_comparison(field: dict, application_context: dict) -> dict:
    expected = application_context.get('address')
    result = _normalized_comparison(field, expected, normalize_address)
    if result['status'] not in ('match', 'mismatch'):
        return result
    document_value = str(field.get('value') or '')
    document_normalized = normalize_address(document_value)
    barangay = normalize_address(application_context.get('barangay'))
    municipality = normalize_address(application_context.get('municipality'))
    if barangay and municipality:
        province = normalize_address(application_context.get('province'))
        if not province:
            expected_parts = [part.strip() for part in str(expected or '').split(',') if part.strip()]
            province = normalize_address(expected_parts[-1]) if len(expected_parts) >= 3 else ''
        address_parts = [part.strip() for part in re.split(r'[,;\n]+', document_value) if part.strip()]
        if len(address_parts) >= 2:
            if province and _address_part(address_parts[-1], 'province') == province:
                address_parts.pop()
            if len(address_parts) >= 2:
                document_barangay = _address_part(address_parts[-2], 'barangay')
                document_municipality = _address_part(address_parts[-1], 'municipality')
                if _has_street_detail(document_barangay):
                    result['status'] = 'uncertain'
                else:
                    result['status'] = ('match' if document_barangay == barangay and
                                        document_municipality == municipality else 'mismatch')
            else:
                result['status'] = 'uncertain'
        else:
            expected_suffix = ' '.join(part for part in (barangay, municipality, province) if part)
            if document_normalized == expected_suffix or document_normalized.endswith(f' {expected_suffix}'):
                result['status'] = 'match'
            elif _has_street_detail(document_normalized):
                result['status'] = 'uncertain'
            elif province and document_normalized.endswith(f' {province}'):
                result['status'] = 'mismatch'
            else:
                result['status'] = 'uncertain'
        return result
    application = set(normalize_address(expected).split())
    document = set(document_normalized.split())
    overlap = application & document
    if not application or not document:
        result['status'] = 'uncertain'
    elif overlap and (len(overlap) >= 2 or len(overlap) / min(len(application), len(document)) >= .5):
        result['status'] = 'likely_match' if application != document else 'match'
    elif len(application) < 2 or len(document) < 2:
        result['status'] = 'uncertain'
    else:
        result['status'] = 'mismatch'
    return result


def _age_comparison(field: dict, birth_date, document_date) -> dict:
    state = field.get('status', 'extracted' if field.get('value') not in (None, '') else 'not_present')
    result = {'status': state, 'application': '', 'document': field.get('value') or ''}
    birth, at = normalize_date(birth_date), normalize_date(document_date)
    if state != 'extracted':
        return result
    if not birth or not at or _confidence(field) < .68:
        result['status'] = 'uncertain'
        return result
    born, on_date = date.fromisoformat(birth), date.fromisoformat(at)
    expected = on_date.year - born.year - ((on_date.month, on_date.day) < (born.month, born.day))
    result['application'] = expected
    result['status'] = 'match' if abs(int(field['value']) - expected) <= 1 else 'mismatch'
    return result


def _trusted_valid_id(prior: list[dict], context: dict) -> dict | None:
    for sibling in prior:
        if sibling.get('_document_type') != 'valid_id':
            continue
        if sibling.get('_quality_usable', True) is False or sibling.get('_type_match', True) is False:
            continue
        fields = sibling.get('fields') if isinstance(sibling.get('fields'), dict) else sibling
        identity_results = (
            _name_comparison(_field(fields, NAME_KEYS), context.get('full_name')).get('status'),
            _normalized_comparison(_field(fields, 'birth_date'), context.get('date_of_birth'), normalize_date).get('status'),
            _normalized_comparison(_field(fields, 'sex'), context.get('sex'), normalize_sex).get('status'),
        )
        # Missing fields are normal, but any confidently contradictory core
        # identity field disqualifies this ID as a reference for siblings.
        if identity_results[0] in ('match', 'likely_match') and 'mismatch' not in identity_results:
            return fields
    return None


def build_consistency_check(document_type: str, fields: dict, application_context: dict,
                            prior_documents: list[dict] | None = None) -> dict:
    """Compare only this document to the request snapshot and corroborating Valid ID."""
    prior_documents = prior_documents or []
    trusted_id = (None if application_context.get('_valid_id_owner_mode') == 'applicant'
                  else _trusted_valid_id(prior_documents, application_context))
    profile = CONSISTENCY_FIELDS.get(document_type, {'core': ('full_name',), 'optional': ()})
    comparisons = {}
    for key in (*profile['core'], *profile['optional']):
        field = _field_for_comparison(fields, key)
        # Optional means compare an actual document value, not that a missing
        # extractor diagnostic becomes a visible identity failure.
        if key in profile['optional'] and not _is_extracted(field):
            continue
        if key == 'full_name':
            comparisons[key] = _name_comparison(field, application_context.get('full_name'))
        elif key == 'date_of_birth':
            comparisons[key] = _normalized_comparison(field, application_context.get('date_of_birth'), normalize_date)
        elif key == 'sex':
            comparisons[key] = _normalized_comparison(field, application_context.get('sex'), normalize_sex)
        elif key == 'address':
            # Historical requests intentionally have no beneficiary address.
            if application_context.get('address'):
                comparisons[key] = _address_comparison(field, application_context)
        elif key == 'age':
            document_date = _field(fields, ('document_date', 'date_of_death')).get('normalized') or _field(fields, ('document_date', 'date_of_death')).get('value')
            comparisons[key] = _age_comparison(field, application_context.get('date_of_birth'), document_date)
    if 'age' in comparisons:
        document_date = _field(fields, ('document_date', 'date_of_death')).get('normalized') or _field(fields, ('document_date', 'date_of_death')).get('value')

    if trusted_id:
        trusted_values = {
            'full_name': _field(trusted_id, NAME_KEYS).get('value'),
            'date_of_birth': _field(trusted_id, 'birth_date').get('raw_value', _field(trusted_id, 'birth_date').get('value')),
            'sex': _field(trusted_id, 'sex').get('value'),
        }
        for key, value in trusted_values.items():
            if key in comparisons and value not in (None, ''):
                comparisons[key]['valid_id'] = value

    mismatches = [key for key, comparison in comparisons.items()
                  if comparison.get('status') == 'mismatch' and key in HARD_IDENTITY_FIELDS]
    uncertain = [key for key, comparison in comparisons.items() if comparison.get('status') == 'uncertain']
    return {
        'status': 'mismatch' if mismatches else ('uncertain' if uncertain else 'match'),
        'reference': 'submitted_request_snapshot',
        'trusted_valid_id_used': bool(trusted_id),
        'comparisons': comparisons,
        'mismatched_fields': mismatches,
        'uncertain_fields': uncertain,
    }
