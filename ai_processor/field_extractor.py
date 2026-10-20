"""Document-aware extraction from the single shared Tesseract OCR result."""
import re

from .document_profiles import get_profile, supported_document_types
from .field_normalization import normalize_address, normalize_amount, normalize_date, normalize_name, normalize_sex

DATE_PATTERN = (
    r"(?:\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[/-]\d{1,2}[/-]\d{4}|"
    r"(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|"
    r"Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)"
    r"\s+\d{1,2}(?:,\s*|\s+)\d{4}|\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|"
    r"Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|"
    r"Nov(?:ember)?|Dec(?:ember)?)\s+\d{4})"
)

BOUNDARY_LABELS = (
    'Identification Number', 'Account Number', 'Reference Number', 'Registration Number',
    'Expiration Date', 'Date of Birth', 'Date of Death', 'Place of Death', 'Patient Name',
    'Deceased Name', 'Subject Name', 'Client Name', 'Beneficiary Name', 'Full Name',
    'Birth Date', 'Birthdate', 'Valid Until', 'Valid Thru', 'Expiry Date', 'Issue Date',
    'Document Date', 'Statement Date', 'Billing Date', 'Prescription Date', 'Report Date',
    'Contract Date', 'Certificate Date', 'ID Number', 'Report Number', 'Contract Number',
    'Registry Number', 'Cardholder', 'Name of Patient', 'Attending Physician',
    'Attending Doctor', 'Medical Officer', 'Funeral Home', 'Service Provider',
    'Issuing Office', 'Issuing Agency', 'Issued By', 'Hospital/Clinic', 'Hospital / Clinic', 'Hospital', 'Clinic', 'Facility',
    'Physician', 'Doctor', 'Patient', 'Deceased', 'Subject', 'Client', 'Beneficiary',
    'Address', 'Gender', 'Amount Due', 'Total Amount', 'Total', 'Amount', 'Balance',
    'Age', 'Name', 'Sex', 'Date', 'DOB', 'Born', 'Expires',
)
_BOUNDARY_PATTERN = '|'.join(re.escape(label) for label in BOUNDARY_LABELS)

_LEGACY_FIELD_SPECS = {
    # Deliberately omit ID numbers: they are neither required for AICS consistency
    # checks nor appropriate to retain in AI-review metadata.
    'valid_id': ('name', 'birth_date', 'sex', 'address', 'valid_until', 'issuing_organization'),
    'med_cert': ('patient_name', 'birth_date', 'age', 'sex', 'document_date', 'hospital_or_clinic', 'physician_name', 'diagnosis_present', 'urgency_evidence'),
    # Account/invoice references and amounts are not needed for identity
    # consistency and must not enter ordinary AI-review metadata.
    'billing_statement': ('patient_name', 'birth_date', 'age', 'facility', 'document_date', 'document_subtype', 'urgency_evidence'),
    # Registry numbers are not needed for consistency checks and are not retained.
    'death_cert': ('deceased_name', 'birth_date', 'age', 'sex', 'date_of_death', 'place_of_death'),
    'funeral_contract': ('deceased_name', 'funeral_home', 'document_date'),
    'report_police_med': ('subject_name', 'birth_date', 'age', 'sex', 'document_date', 'issuing_organization', 'report_subtype'),
    'coe_mswdo': ('client_name', 'address', 'document_date', 'issuing_organization', 'reference_number'),
    # Stage-5 certificates retain only the beneficiary identity needed for the
    # existing consistency check.  References, narratives and staff details are
    # deliberately outside normal AI-review metadata.
    'crisis_situation_cert': ('client_name',),
    'school_assessment': ('client_name', 'document_date', 'issuing_organization'),
    'registration_form': ('client_name', 'document_date', 'issuing_organization'),
    'school_id': ('client_name', 'issuing_organization'),
    'mdrrmo_cert': ('client_name', 'document_date', 'issuing_organization'),
    'eclip_cert': ('client_name',),
}

# Keep the existing extractor API while sourcing canonical field lists from
# the shared registry. ``coe_mswdo`` remains a read-only legacy alias.
FIELD_SPECS = {
    code: get_profile(code).extractable_fields
    for code in supported_document_types()
    if get_profile(code).extractable_fields
}
FIELD_SPECS['coe_mswdo'] = _LEGACY_FIELD_SPECS['coe_mswdo']


def _empty_field() -> dict:
    return {'value': None, 'normalized': None, 'confidence': 0.0, 'status': 'not_present', 'reliable': False, 'reason_if_unreliable': 'not_extracted'}


_PHILID_LABEL_WORDS = {
    'apelyido', 'pangalan', 'pangalanan', 'gitnang', 'petsa', 'kapanganakan',
    'tirahan', 'address', 'national', 'identification', 'philsys', 'psa',
}


def _meaningful_words(value: str) -> list[str]:
    return re.findall(r"[A-Za-z]{2,}", value or '')


def _reliable_philid_name(value: str | None) -> bool:
    """Reject short OCR debris; structural labels are not identity values."""
    words = _meaningful_words(value or '')
    letters = ''.join(words)
    return (
        len(letters) >= 6
        and len(words) >= 2
        and sum(len(word) >= 3 for word in words) >= 2
        and not any(word.lower() in _PHILID_LABEL_WORDS for word in words)
    )


def _reliable_identity_name(value: str | None) -> bool:
    """Shared low-risk name check for labelled non-ID identity fields."""
    words = _meaningful_words(value or '')
    letters = ''.join(words)
    rejected = {'et', 'ne', 'xx', 'acdress', 'address', 'name', 'beneficiary', 'client'}
    prose_words = {
        'and', 'the', 'between', 'patient', 'physician', 'doctor', 'form',
        'professional', 'confidence', 'confidences', 'accompanying', 'herein',
        'hereby',
    }
    return (
        len(words) >= 2 and len(words) <= 8 and len(letters) >= 6
        and sum(len(word) == 1 for word in words) == 0
        and not any(len(word) > 18 or any(char.isdigit() for char in word) for word in words)
        and not any(word.lower() in rejected for word in words)
        and not any(word.lower() in prose_words for word in words)
    )


def _mostly_label_residue(value: str | None, labels) -> bool:
    """Reject OCR that is principally a damaged repetition of its anchor."""
    compact = re.sub(r'[^a-z]', '', (value or '').lower())
    if not compact:
        return True
    label_forms = [re.sub(r'[^a-z]', '', str(label).lower()) for label in labels]
    label_forms = [label for label in label_forms if len(label) >= 3]
    if not label_forms:
        return False
    # Substring catches ordinary residue; SequenceMatcher safely covers small
    # OCR misspellings such as "Acdress" without retaining the raw value.
    from difflib import SequenceMatcher
    return any(label in compact or SequenceMatcher(None, compact, label).ratio() >= .82 for label in label_forms)


def _reliable_address(value: str | None, labels) -> bool:
    words = _meaningful_words(value or '')
    letters = ''.join(words)
    noise = len(re.findall(r'[^A-Za-z0-9\s,./#-]', value or ''))
    raw = value or ''
    # A damaged leading label is still label residue even if noisy OCR adds
    # several arbitrary words after it (for example ``Acdress oe ...``).
    from difflib import SequenceMatcher
    first_word_is_label = bool(words) and any(
        SequenceMatcher(None, words[0].lower(), re.sub(r'[^a-z]', '', str(label).lower())).ratio() >= .82
        for label in labels if re.sub(r'[^a-z]', '', str(label).lower())
    )
    short_words = sum(len(word) <= 2 for word in words)
    return (len(raw) <= 180 and len(letters) >= 12 and len(words) >= 3 and len(words) <= 24
            and sum(len(word) >= 3 for word in words) >= 2 and short_words <= max(3, len(words) // 3)
            and noise <= max(2, len(value or '') // 12) and not first_word_is_label
            and not _mostly_label_residue(value, labels))


def _mark_unreliable(field: dict, reason: str) -> None:
    """Keep rejected OCR out of normal persisted field values."""
    field.pop('raw_value', None)
    field.pop('context', None)
    field.pop('page', None)
    field.update({'value': None, 'normalized': None, 'confidence': 0.0,
                  'status': 'uncertain', 'reliable': False,
                  'reason_if_unreliable': reason})


def _finalize_field_reliability(fields: dict) -> None:
    """Apply one source-independent reliability contract to all profiles."""
    identity_keys = {'name', 'patient_name', 'deceased_name', 'subject_name', 'client_name'}
    for key, field in fields.items():
        if not isinstance(field, dict):
            continue
        if field.get('status') != 'extracted' or field.get('value') in (None, ''):
            field['reliable'] = False
            field.setdefault('reason_if_unreliable', 'not_extracted')
            continue
        value = str(field.get('value', ''))
        if key in identity_keys and not _reliable_identity_name(value):
            _mark_unreliable(field, 'identity_text_is_incoherent')
        elif key == 'address' and not _reliable_address(value, ('address', 'tirahan')):
            _mark_unreliable(field, 'address_text_is_incoherent')
        elif key in {'birth_date', 'document_date', 'date_of_death', 'valid_until'}:
            normalized = normalize_date(value)
            if not normalized:
                _mark_unreliable(field, 'date_is_not_parseable')
            else:
                year = int(normalized[:4])
                if key == 'birth_date' and not (1900 <= year <= 2026):
                    _mark_unreliable(field, 'birth_date_is_implausible')
                elif key != 'birth_date' and not (1900 <= year <= 2100):
                    _mark_unreliable(field, 'date_is_implausible')
                else:
                    field['reliable'] = True
                    field['reason_if_unreliable'] = None
        else:
            field['reliable'] = True
            field['reason_if_unreliable'] = None


def _reliable_philid_address(value: str | None) -> bool:
    """An address needs several substantial location words, not OCR fragments."""
    words = _meaningful_words(value or '')
    letters = ''.join(words)
    return (
        len(letters) >= 12
        and len(words) >= 3
        and sum(len(word) >= 3 for word in words) >= 3
        and not any(word.lower() in _PHILID_LABEL_WORDS for word in words)
    )


def _philid_region_value(metadata: dict | None, labels: tuple[str, ...], multi_line: bool = False) -> tuple[str, float] | None:
    """Read only a value spatially anchored to a PhilID demographic label."""
    normalized_labels = tuple(re.sub(r'[^a-z]', '', label.lower()) for label in labels)
    for page in (metadata or {}).get('pages', []):
        lines = [line for line in page.get('lines', []) if isinstance(line, dict) and isinstance(line.get('box'), list) and len(line['box']) == 4]
        for index, anchor in enumerate(lines):
            anchor_text = str(anchor.get('text', ''))
            anchor_normalized = re.sub(r'[^a-z]', '', anchor_text.lower())
            if not any(label in anchor_normalized for label in normalized_labels):
                continue
            # Same-line values are permitted only after a clear delimiter.
            same_line = re.split(r'[:\-]\s*', anchor_text, maxsplit=1)
            if len(same_line) == 2 and same_line[1].strip():
                return same_line[1].strip(), float(anchor.get('confidence', 0.0))
            ax1, ay1, ax2, ay2 = [float(value) for value in anchor['box']]
            height = max(1.0, ay2 - ay1)
            selected = []
            for candidate in lines[index + 1:]:
                x1, y1, x2, y2 = [float(value) for value in candidate['box']]
                if y1 < ay2 or y1 - ay2 > height * (7 if multi_line else 3):
                    continue
                if x2 < ax1 - height * 2 or x1 > ax2 + height * 8:
                    continue
                text = str(candidate.get('text', '')).strip()
                if not text or any(label in re.sub(r'[^a-z]', '', text.lower()) for label in normalized_labels):
                    continue
                selected.append(candidate)
                if not multi_line or len(selected) >= 2:
                    break
            if selected:
                value = ' '.join(str(line['text']).strip() for line in selected)
                confidence = sum(float(line.get('confidence', 0.0)) for line in selected) / len(selected)
                return value, confidence
    return None


def _clean_value(value: str) -> str:
    value = value.strip(' \t:-')
    value = re.split(r'\s+(?=(?:' + _BOUNDARY_PATTERN + r')(?:[ \t]*[:\-]|[ \t]+))', value, maxsplit=1, flags=re.I)[0]
    return value.strip(' \t:-')


def _labelled_value(text: str, labels: tuple[str, ...], require_separator: bool = False,
                    strict_label_boundary: bool = False) -> tuple[str, str] | None:
    alternatives = '|'.join(re.escape(label) for label in labels)
    separator = r'[ \t]*[:\-][ \t]*' if require_separator else (
        r'[ \t]*(?:[:\-][ \t]*|(?=\r?$))' if strict_label_boundary else r'[ \t]*[:\-]?[ \t]*')
    pattern = re.compile(r'(?<!\S)(' + alternatives + r')' + separator + r'([^\r\n]*)', re.I | re.M)
    for match in pattern.finditer(text):
        value = _clean_value(match.group(2))
        if value:
            return value, match.group(0).strip()
        for line in text[match.end():].splitlines():
            candidate = line.strip()
            if not candidate:
                continue
            if re.match(r'^\s*(?:' + _BOUNDARY_PATTERN + r')\s*[:\-]?', candidate, re.I):
                break
            candidate = _clean_value(candidate)
            if candidate:
                return candidate, match.group(1) + ': ' + candidate
            break
    return None


def _page_for_context(metadata: dict | None, context: str) -> int | None:
    needle = re.sub(r'\s+', ' ', context).strip().lower()
    for page in (metadata or {}).get('pages', []):
        if needle and needle in re.sub(r'\s+', ' ', page.get('text', '')).lower():
            return page.get('page_number')
    return None


def _confidence(base: float, metadata: dict | None) -> float:
    ocr = (metadata or {}).get('mean_confidence')
    return base if ocr is None else round(min(base, max(.35, float(ocr) / 100)), 3)


def _set_text(fields, key, text, labels, metadata, base=.72, normalizer=None, require_separator=False):
    roi = (metadata or {}).get('roi_values', {}).get(key) if isinstance((metadata or {}).get('roi_values', {}), dict) else None
    found = (str(roi.get('value', '')), 'targeted label region') if isinstance(roi, dict) and str(roi.get('value', '')).strip() else _labelled_value(text, labels, require_separator=require_separator)
    if not found:
        return
    value, context = found
    normalized = normalizer(value) if normalizer else value.strip()
    if _mostly_label_residue(value, labels):
        fields[key].update({'value': None, 'normalized': None, 'confidence': 0.0, 'status': 'uncertain'})
        return
    if key in ('name', 'patient_name', 'deceased_name', 'subject_name', 'client_name') and not _reliable_identity_name(normalized):
        fields[key].update({'value': None, 'normalized': None, 'confidence': 0.0, 'status': 'uncertain'})
        return
    if key == 'address' and not _reliable_address(normalized, labels):
        fields[key].update({'value': None, 'normalized': None, 'confidence': 0.0, 'status': 'uncertain'})
        return
    fields[key].update({'value': value, 'normalized': normalized, 'confidence': round(float(roi.get('confidence', 0)) / 100, 3) if isinstance(roi, dict) else _confidence(base, metadata),
                        'status': 'extracted' if normalized else 'uncertain', 'context': context})
    page = _page_for_context(metadata, context)
    if page:
        fields[key]['page'] = page


def _set_certification_person(fields, text, metadata):
    """Extract a certified person only inside a confirmed Stage-5 profile.

    This is intentionally not a general "certify that" parser: callers are
    restricted to the two certificate families below, and officer/signatory
    labels are never candidates.
    """
    if fields['client_name']['status'] == 'extracted':
        return
    match = re.search(
        r'\bthis\s+is\s+to\s+certify\s+that\s+([A-Za-z][A-Za-z .\'-]{4,90}?)'
        r'(?=\s+(?:is|was|has|who)\b|\s*,|\.)', text, re.I | re.S,
    )
    if not match:
        return
    value = re.sub(r'\s+', ' ', match.group(1)).strip(' ,.-')
    normalized = normalize_name(value)
    if not _reliable_identity_name(normalized):
        fields['client_name'].update({'value': None, 'normalized': None, 'confidence': 0.0, 'status': 'uncertain'})
        return
    fields['client_name'].update({
        'value': value, 'normalized': normalized, 'confidence': _confidence(.72, metadata),
        'status': 'extracted', 'context': 'certification sentence',
    })


def _set_date(fields, key, text, labels, metadata, base=.78, strict=False):
    found = _labelled_value(text, labels, strict_label_boundary=strict)
    if not found:
        return
    value, context = found
    date_match = re.search(DATE_PATTERN, value, re.I)
    raw = date_match.group(0) if date_match else value
    normalized = normalize_date(raw) if date_match else None
    fields[key].update({'value': normalized or raw, 'raw_value': raw, 'normalized': normalized,
                        'confidence': _confidence(base, metadata),
                        'status': 'extracted' if normalized else 'uncertain', 'context': context})
    page = _page_for_context(metadata, context)
    if page:
        fields[key]['page'] = page


def _set_age(fields, text, metadata):
    found = _labelled_value(text, ('Age',))
    if found:
        match = re.search(r'\b(\d{1,3})\b', found[0])
        if match and 0 < int(match.group(1)) < 125:
            fields['age'].update({'value': int(match.group(1)), 'normalized': int(match.group(1)),
                                  'confidence': _confidence(.75, metadata), 'status': 'extracted', 'context': found[1]})


# Only identity-comparison fields may opt into a complete value from another
# bounded OCR candidate.  Amounts, reference numbers, diagnoses, narratives,
# staff identities and all other excluded content remain outside this path.
FIELD_LEVEL_CANDIDATE_FIELDS = {
    'valid_id': ('name', 'birth_date', 'address'),
    'med_cert': ('patient_name',),
    'death_cert': ('deceased_name',),
    'report_police_med': ('subject_name',),
    'school_assessment': ('client_name',),
    'registration_form': ('client_name',),
    'school_id': ('client_name',),
    'mdrrmo_cert': ('client_name',),
    'eclip_cert': ('client_name',),
    'crisis_situation_cert': ('client_name',),
}


def _valid_id_name_source_is_clean(candidate_text: str) -> bool:
    """Reject source rows with OCR symbols/digits before name normalization."""
    found = False
    for line in candidate_text.splitlines():
        match = re.match(r"\s*(?:given names|last name|middle name|mga pangalan|apelyido|gitnang apelyido)\s*[:\-]\s*(.+?)\s*$", line, re.I)
        if not match:
            continue
        found = True
        raw = match.group(1).strip()
        # A separated trailing slash is a common card-artifact token. It is
        # not joined to, or used to repair, a name component.
        raw = re.sub(r"\s+[|/]\s*$", "", raw)
        if (not re.fullmatch(r"[A-Za-z]+(?:[ .'-][A-Za-z]+)*", raw)
                or any(len(word) < 2 for word in re.findall(r"[A-Za-z]+", raw))):
            return False
    return found


def _field_candidate_score(key: str, field: dict, candidate_text: str = '',
                           candidate_name: str = '') -> float | None:
    """Return a conservative score for a complete, already-reliable value."""
    if not isinstance(field, dict) or field.get('status') != 'extracted' or not field.get('reliable'):
        return None
    value = str(field.get('normalized') or '')
    if not value:
        return None
    if key in {'name', 'patient_name', 'deceased_name', 'subject_name', 'client_name'}:
        words = re.findall(r"[A-Za-z]+", value)
        raw_value = str(field.get('value') or '').strip()
        # Do not select a candidate which has a one-letter OCR fragment in a
        # person's name (for example ``ANA I REYES``). This is stricter only
        # for the optional candidate fallback; it does not lower any existing
        # reliability guard or manufacture text from other candidates.
        if (len(words) < 2 or any(len(word) < 2 for word in words)
                or not re.fullmatch(r"[A-Za-z]+(?:[ .'-][A-Za-z]+)*", raw_value)):
            return None
        if key == 'name' and not _valid_id_name_source_is_clean(candidate_text):
            return None
        # Prefer the native original only after its complete field is proven
        # structurally clean. This counters interpolation artefacts without
        # assembling text across candidates.
        source_bonus = 25.0 if candidate_name == 'original' else 0.0
        return 200.0 + source_bonus + float(field.get('confidence', 0.0)) * 100 + min(len(words), 5)
    if key == 'birth_date' and not normalize_date(value):
        return None
    return 150.0 + float(field.get('confidence', 0.0)) * 100


def _select_field_level_candidates(document_type: str, fields: dict, ocr_metadata: dict | None) -> None:
    """Fill only weak safe fields from one coherent bounded OCR candidate."""
    safe_fields = FIELD_LEVEL_CANDIDATE_FIELDS.get(document_type, ())
    candidates = (ocr_metadata or {}).get('field_candidates', [])
    if not safe_fields or not isinstance(candidates, list):
        return
    for key in safe_fields:
        primary = fields.get(key, {})
        if primary.get('status') == 'extracted' and primary.get('reliable'):
            continue
        best = None
        for candidate in candidates:
            if not isinstance(candidate, dict) or not str(candidate.get('text', '')).strip():
                continue
            candidate_metadata = {
                'mean_confidence': candidate.get('mean_confidence', 0.0),
                'pages': candidate.get('pages', []),
            }
            candidate_fields = extract_document_fields(document_type, str(candidate['text']), candidate_metadata, _field_candidates=False)['fields']
            candidate_field = candidate_fields.get(key, {})
            candidate_name = str(candidate.get('candidate') or 'unknown')
            score = _field_candidate_score(key, candidate_field, str(candidate.get('text', '')), candidate_name)
            if score is not None and (best is None or score > best[0]):
                best = (score, candidate_name, candidate_field)
        if best is not None:
            score, candidate_name, candidate_field = best
            selected = dict(candidate_field)
            selected['field_candidate'] = candidate_name
            selected['field_candidate_score'] = round(score, 3)
            fields[key] = selected


def _set_amount(fields, text, metadata):
    found = _labelled_value(text, ('Amount Due', 'Total Amount', 'Grand Total', 'Contract Amount', 'Total', 'Amount', 'Balance'))
    if found:
        match = re.search(r'(?:PHP|P|â‚±)?\s*(\d[\d,]*(?:\.\d{1,2})?)', found[0], re.I)
        normalized = normalize_amount(match.group(1)) if match else None
        fields['amount'].update({'value': match.group(1) if match else found[0], 'normalized': normalized,
                                 'confidence': _confidence(.7, metadata),
                                 'status': 'extracted' if normalized else 'uncertain', 'context': found[1]})


def _set_medical_urgency_evidence(fields, text, metadata):
    """Capture explicit timing only; diagnoses never create urgency evidence."""
    patterns = (
        ('scheduled surgery', r'\b(?:scheduled|schedule for|booked for)\s+(?:surgery|surgical procedure|operation)\b'),
        ('scheduled medical procedure', r'\b(?:scheduled|schedule for|booked for)\s+(?:medical )?procedure\b'),
        ('current hospitalization or admission', r'\b(?:currently )?(?:hospitali[sz]ed|admitted|admission)\b'),
        ('emergency treatment', r'\b(?:emergency|emergent)\s+(?:medical )?treatment\b'),
        ('immediate medical intervention', r'\b(?:immediate|urgent)\s+(?:medical )?(?:intervention|treatment|procedure|care)\b'),
        ('time-sensitive treatment date', r'\b(?:treatment|procedure|surgery)\s+date\s*[:\-]?\s*' + DATE_PATTERN),
    )
    for label, pattern in patterns:
        match = re.search(pattern, text, re.I)
        if not match:
            continue
        context = next((line.strip() for line in text[match.start():].splitlines() if line.strip()), match.group(0))
        value = label
        date_match = re.search(DATE_PATTERN, context, re.I)
        if date_match and normalize_date(date_match.group(0)):
            value += ' on ' + normalize_date(date_match.group(0))
        fields['urgency_evidence'].update({'value': value, 'normalized': label,
                                           'confidence': _confidence(.78, metadata), 'status': 'extracted', 'context': context})
        page = _page_for_context(metadata, context)
        if page:
            fields['urgency_evidence']['page'] = page
        return


def extract_document_fields(document_type: str, ocr_text: str, ocr_metadata: dict | None = None,
                            _field_candidates: bool = True) -> dict:
    kind, text = document_type or '', ocr_text or ''
    fields = {key: _empty_field() for key in FIELD_SPECS.get(kind, ())}
    if kind == 'valid_id':
        roi_values = (ocr_metadata or {}).get('philid_roi_values', {})
        def philid_component(labels):
            region = _philid_region_value(ocr_metadata, labels)
            return (region[0], 'PhilID label region') if region else _labelled_value(text, labels)
        given = philid_component(('Given Names', 'Mga Pangalan'))
        middle = philid_component(('Middle Name', 'Gitnang Apelyido'))
        last = philid_component(('Last Name', 'Surname', 'Apelyido'))
        parts = [part[0] for part in (given, middle, last) if part]
        if len(parts) >= 2:
            composed = normalize_name(' '.join(parts))
            if _reliable_philid_name(composed):
                fields['name'].update({'value': composed, 'normalized': composed, 'confidence': _confidence(.8, ocr_metadata), 'status': 'extracted', 'context': 'PhilID name labels'})
        if fields['name']['status'] != 'extracted':
            roi_name = roi_values.get('name') if isinstance(roi_values, dict) else None
            if isinstance(roi_name, dict) and _reliable_philid_name(str(roi_name.get('value', ''))):
                value = normalize_name(str(roi_name['value']))
                fields['name'].update({'value': value, 'normalized': value, 'confidence': round(float(roi_name.get('confidence', 0)) / 100, 3), 'status': 'extracted', 'context': 'PhilID targeted label region'})
        if fields['name']['status'] != 'extracted':
            _set_text(fields, 'name', text, ('Full Name', 'Name', 'Cardholder', 'Holder'), ocr_metadata, .78, normalize_name)
            if fields['name']['status'] == 'extracted' and not _reliable_philid_name(fields['name'].get('normalized')):
                fields['name'].update({'value': None, 'normalized': None, 'confidence': 0.0, 'status': 'uncertain'})
        dob_region = _philid_region_value(ocr_metadata, ('Date of Birth', 'Birth Date', 'Petsa ng Kapanganakan'))
        roi_dob = roi_values.get('birth_date') if isinstance(roi_values, dict) else None
        if isinstance(roi_dob, dict): dob_region = (str(roi_dob.get('value', '')), float(roi_dob.get('confidence', 0)))
        if dob_region:
            raw = re.search(DATE_PATTERN, dob_region[0], re.I)
            normalized = normalize_date(raw.group(0)) if raw else None
            if normalized: fields['birth_date'].update({'value': normalized, 'raw_value': raw.group(0), 'normalized': normalized, 'confidence': round(dob_region[1] / 100, 3), 'status': 'extracted', 'context': 'PhilID label region'})
        if fields['birth_date']['status'] != 'extracted': _set_date(fields, 'birth_date', text, ('Date of Birth', 'Birth Date', 'Birthdate', 'DOB', 'Born', 'Petsa ng Kapanganakan'), ocr_metadata)
        _set_text(fields, 'sex', text, ('Sex', 'Gender'), ocr_metadata, .75, normalize_sex)
        address_region = _philid_region_value(ocr_metadata, ('Address', 'Tirahan'), multi_line=True)
        roi_address = roi_values.get('address') if isinstance(roi_values, dict) else None
        if isinstance(roi_address, dict): address_region = (str(roi_address.get('value', '')), float(roi_address.get('confidence', 0)))
        if address_region and _reliable_philid_address(address_region[0]): fields['address'].update({'value': address_region[0], 'normalized': normalize_address(address_region[0]), 'confidence': round(address_region[1] / 100, 3), 'status': 'extracted', 'context': 'PhilID label region'})
        else: _set_text(fields, 'address', text, ('Address', 'Tirahan'), ocr_metadata, .68, normalize_address)
        if fields['address']['status'] == 'extracted' and not _reliable_philid_address(fields['address'].get('normalized')):
            fields['address'].update({'value': None, 'normalized': None, 'confidence': 0.0, 'status': 'uncertain'})
        _set_date(fields, 'valid_until', text, ('Valid Until', 'Valid Thru', 'Expiration Date', 'Expiry Date', 'Expires'), ocr_metadata)
        _set_text(fields, 'issuing_organization', text, ('Issued By', 'Issuing Organization', 'Authority'), ocr_metadata, .65)
    elif kind == 'med_cert':
        _set_text(fields, 'patient_name', text, ('Patient Name', 'Name of Patient', 'Patient', 'Name'), ocr_metadata, .78, normalize_name)
        _set_date(fields, 'birth_date', text, ('Date of Birth', 'Birth Date', 'Birthdate', 'DOB', 'Born'), ocr_metadata)
        _set_age(fields, text, ocr_metadata)
        _set_text(fields, 'sex', text, ('Sex', 'Gender'), ocr_metadata, .75, normalize_sex)
        _set_date(fields, 'document_date', text, ('Date Issued', 'Issued On', 'Certificate Date', 'Examined On', 'Consultation Date'), ocr_metadata)
        if fields['document_date']['status'] == 'not_present':
            # Signed forms commonly render this bounded label as
            # ``(Date) September 24, 2026``.
            _set_date(fields, 'document_date', text, ('(Date)',), ocr_metadata, .72)
        if fields['document_date']['status'] == 'not_present':
            _set_date(fields, 'document_date', text, ('Date',), ocr_metadata, .7, strict=True)
        _set_text(fields, 'hospital_or_clinic', text, ('Hospital/Clinic', 'Hospital / Clinic', 'Hospital', 'Clinic', 'Facility'), ocr_metadata, .68)
        _set_text(fields, 'physician_name', text, ('Attending Physician', 'Attending Doctor', 'Physician', 'Doctor', 'Medical Officer'), ocr_metadata, .7)
        present = bool(re.search(r'\b(?:diagnosis|clinical impression|medical condition|assessment)\b', text, re.I))
        fields['diagnosis_present'].update({'value': present, 'normalized': present, 'confidence': _confidence(.7, ocr_metadata), 'status': 'extracted' if present else 'not_present'})
        _set_medical_urgency_evidence(fields, text, ocr_metadata)
    elif kind == 'billing_statement':
        subtype = 'prescription' if re.search(r'\b(?:prescription|rx|dosage|frequency)\b', text, re.I) else 'billing_statement'
        fields['document_subtype'].update({'value': subtype, 'normalized': subtype, 'confidence': _confidence(.8, ocr_metadata), 'status': 'extracted'})
        _set_text(fields, 'patient_name', text, ('Patient Name', 'Name of Patient', 'Patient', 'Beneficiary Name', 'Name'), ocr_metadata, .75, normalize_name)
        _set_date(fields, 'birth_date', text, ('Date of Birth', 'Birth Date', 'DOB'), ocr_metadata)
        _set_age(fields, text, ocr_metadata)
        _set_text(fields, 'facility', text, ('Hospital', 'Clinic', 'Facility', 'Pharmacy'), ocr_metadata, .65)
        labels = ('Prescription Date', 'Date Issued') if subtype == 'prescription' else ('Statement Date', 'Billing Date', 'Date Issued')
        _set_date(fields, 'document_date', text, labels, ocr_metadata, .7)
        if fields['document_date']['status'] == 'not_present':
            _set_date(fields, 'document_date', text, ('Date',), ocr_metadata, .65, strict=True)
        # Prescriber identity, account/invoice references and amounts remain
        # outside the safe extraction contract for this upload slot.
        _set_medical_urgency_evidence(fields, text, ocr_metadata)
    elif kind == 'death_cert':
        _set_text(fields, 'deceased_name', text, ('Deceased Full Name', 'Name of Deceased', 'Deceased Name', 'Deceased'), ocr_metadata, .78, normalize_name)
        _set_date(fields, 'birth_date', text, ('Date of Birth', 'Birth Date', 'DOB'), ocr_metadata)
        _set_age(fields, text, ocr_metadata)
        _set_text(fields, 'sex', text, ('Sex', 'Gender'), ocr_metadata, .75, normalize_sex)
        _set_date(fields, 'date_of_death', text, ('Date of Death',), ocr_metadata, .82)
        _set_text(fields, 'place_of_death', text, ('Place of Death',), ocr_metadata, .7)
    elif kind == 'funeral_contract':
        _set_text(fields, 'deceased_name', text, ('Deceased Name', 'Name of Deceased', 'Deceased'), ocr_metadata, .77, normalize_name)
        _set_text(fields, 'funeral_home', text, ('Funeral Home', 'Service Provider', 'Provider'), ocr_metadata, .7)
        _set_date(fields, 'document_date', text, ('Contract Date', 'Document Date', 'Date'), ocr_metadata, .72, strict=True)
    elif kind == 'report_police_med':
        patterns = (('medico_legal_report', r'medico[ -]?legal'), ('police_blotter', r'police blotter'), ('police_report', r'police report'), ('incident_report', r'incident report'), ('medical_report', r'medical report'), ('legal_report', r'legal report'))
        subtype = next((code for code, pattern in patterns if re.search(pattern, text, re.I)), None)
        if subtype:
            fields['report_subtype'].update({'value': subtype, 'normalized': subtype, 'confidence': _confidence(.75, ocr_metadata), 'status': 'extracted'})
        # Victim/patient/examinee is the person-served signal.  Do not use a
        # reporting person or complainant as an identity-comparison fallback.
        _set_text(fields, 'subject_name', text, ('Victim Name', 'Victim', 'Injured Person', 'Patient Name', 'Name of Patient', 'Name of Examinee', 'Examined Person', 'Subject Name', 'Client Name', 'Person Name'), ocr_metadata, .7, normalize_name)
        _set_date(fields, 'birth_date', text, ('Date of Birth', 'Birth Date', 'DOB'), ocr_metadata)
        _set_age(fields, text, ocr_metadata)
        _set_text(fields, 'sex', text, ('Sex', 'Gender'), ocr_metadata, .7, normalize_sex)
        _set_date(fields, 'document_date', text, ('Report Date', 'Incident Date', 'Date Issued', 'Date'), ocr_metadata, .7, strict=True)
        _set_text(fields, 'issuing_organization', text, ('Issuing Agency', 'Police Station', 'Hospital', 'Facility'), ocr_metadata, .65)
    elif kind == 'school_assessment':
        _set_text(fields, 'client_name', text, ('Learner Name', 'Name of Learner', 'Student Name', 'Name of Student'), ocr_metadata, .75, normalize_name)
        _set_date(fields, 'document_date', text, ('Date Assessed', 'Date Printed', 'Date Issued'), ocr_metadata, .72, strict=True)
        _set_text(fields, 'issuing_organization', text, ('School', 'School Name', 'Issuing Office'), ocr_metadata, .68)
    elif kind == 'registration_form':
        _set_text(fields, 'client_name', text, ('Learner Name', 'Name of Learner', 'Student Name', 'Name of Student'), ocr_metadata, .75, normalize_name)
        _set_date(fields, 'document_date', text, ('Enrollment Date', 'Date Enrolled', 'Date Registered', 'Registration Date'), ocr_metadata, .72, strict=True)
        _set_text(fields, 'issuing_organization', text, ('School', 'School Name', 'Issuing Office'), ocr_metadata, .68)
    elif kind == 'school_id':
        _set_text(fields, 'client_name', text, ('Student Name', 'Name of Student', 'Learner Name', 'Name'), ocr_metadata, .72, normalize_name)
        _set_text(fields, 'issuing_organization', text, ('School', 'School Name'), ocr_metadata, .68)
    elif kind == 'mdrrmo_cert':
        _set_text(fields, 'client_name', text, ('Name of Displaced Person', 'Displaced Person', 'Name of Affected Person', 'Name of Client', 'Name of Beneficiary', 'Name of House Owner', 'Name of Household Head'), ocr_metadata, .75, normalize_name)
        _set_date(fields, 'document_date', text, ('Date Issued', 'Certificate Date', 'Date of Certification'), ocr_metadata, .72, strict=True)
        _set_text(fields, 'issuing_organization', text, ('Municipal Disaster Risk Reduction and Management Office', 'Local Disaster Risk Reduction and Management Office', 'City Disaster Risk Reduction and Management Office', 'Provincial Disaster Risk Reduction and Management Office', 'DRRMO'), ocr_metadata, .68)
    elif kind in ('crisis_situation_cert', 'eclip_cert'):
        # Never treat a generic Name, signatory, officer or staff field as the
        # beneficiary.  Prose is a conservative fall-through only.
        _set_text(fields, 'client_name', text, ('Name of Beneficiary', 'Beneficiary Name', 'Beneficiary', 'Client Name'), ocr_metadata, .75, normalize_name, require_separator=True)
        _set_certification_person(fields, text, ocr_metadata)
    elif kind == 'coe_mswdo':
        _set_text(fields, 'client_name', text, ('Client Name', 'Beneficiary Name'), ocr_metadata, .75, normalize_name)
        _set_text(fields, 'address', text, ('Address',), ocr_metadata, .68, normalize_address)
        _set_date(fields, 'document_date', text, ('Certificate Date', 'Date Issued', 'Date'), ocr_metadata, .72, strict=True)
        _set_text(fields, 'issuing_organization', text, ('Issuing Office', 'Issued By', 'Office'), ocr_metadata, .68)
        _set_text(fields, 'reference_number', text, ('Reference Number', 'Certificate Number'), ocr_metadata, .65)
    _finalize_field_reliability(fields)
    if _field_candidates:
        _select_field_level_candidates(kind, fields, ocr_metadata)
    return {'document_type': kind, 'fields': fields}


def extract(text: str, kind: str, ocr_metadata: dict | None = None) -> dict:
    """Backward-compatible fields-only API."""
    return extract_document_fields(kind, text, ocr_metadata)['fields']
