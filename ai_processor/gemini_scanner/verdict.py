"""Turn a Gemini scan into the thesis five-check verdict shown to PSWDO staff.

Checks follow the paper's definition of AI-Enhanced Document Verification:
incomplete, inconsistent, unreadable, altered, or potentially tampered.
Results are advisory; staff make every decision.
"""
from copy import deepcopy
from datetime import datetime

from ..field_normalization import normalize_date
from ..matching import name_match_details
from .catalog import document_label, identity_role, normalize_code
from .prompt import CHECK_KEYS, PROMPT_VERSION

CHECK_TITLES = {
    'complete': 'Complete', 'consistent': 'Consistent', 'readable': 'Readable',
    'unaltered': 'No visible alterations', 'untampered': 'No signs of tampering',
}
_LABELS = {
    'complete': {'pass': 'All parts present', 'review': 'Something may be missing', 'fail': 'Incomplete'},
    'consistent': {'pass': 'Details agree', 'review': 'Needs confirmation', 'fail': 'Inconsistent'},
    'readable': {'pass': 'Clear to read', 'review': 'Partly hard to read', 'fail': 'Unreadable'},
    'unaltered': {'pass': 'No alterations seen', 'review': 'Possible alteration', 'fail': 'Altered entries'},
    'untampered': {'pass': 'No tampering signs', 'review': 'Possible tampering', 'fail': 'Signs of tampering'},
}
_ROLE_LABELS = {
    'cardholder': 'Cardholder name', 'patient': 'Patient name', 'deceased': 'Name of deceased',
    'subject': 'Person involved', 'learner': 'Student name', 'affected_person': 'Affected person',
    'client': 'Client name',
}
# A slot that also accepts another catalog type (a medical report is valid for
# "Police Report or Medical Report").
_COMPATIBLE = {'report_police_med': frozenset({'med_cert'})}
_RANK = {'pass': 0, 'not_applicable': 0, 'unavailable': 1, 'review': 2, 'fail': 3}
WRONG_TYPE_MIN_CONFIDENCE = 70
_TONE = {'likely_valid': 'success', 'needs_staff_review': 'warning', 'needs_reupload': 'danger',
         'flagged_inconsistency': 'danger', 'failed': 'neutral'}


def _check(key: str, state: str, reason: str, missing=None) -> dict:
    label = _LABELS[key].get(state, 'Not checked')
    return {'title': CHECK_TITLES[key], 'state': state, 'label': label, 'reason': reason,
            'missing': list(missing or [])}


def _worst(candidates: list[tuple[str, str, str]]) -> tuple[str, str, str]:
    return max(candidates, key=lambda item: _RANK.get(item[0], 1))


def _article(text: str) -> str:
    return 'an' if text[:1].upper() in 'AEIOU' else 'a'


def _type_assessment(expected: str, scan: dict) -> tuple[str, str, str]:
    """Return (state, classification decision, reason)."""
    detected = scan['document_type']
    expected_label = document_label(expected)
    if detected == expected or detected in _COMPATIBLE.get(expected, ()):
        return 'pass', 'match', ''
    if detected == 'unknown':
        return 'review', 'uncertain', f'The document type could not be identified. Confirm it is the {expected_label}.'
    title = scan.get('document_title') or ''
    described = document_label(detected) if detected != 'other' else (title.title() or 'different document')
    printed = f' ("{title}")' if title and detected != 'other' else ''
    if scan['type_confidence'] >= WRONG_TYPE_MIN_CONFIDENCE:
        return ('fail', 'wrong_slot',
                f'This looks like {_article(described)} {described}{printed}, not the required {expected_label}.')
    return ('review', 'uncertain',
            f'This may be {_article(described)} {described}{printed} instead of the required {expected_label}.')


def _name_comparison(doc_name, app_name: str, role_label: str, beneficiary_found: bool,
                     owner: str = 'beneficiary') -> tuple[str, str]:
    if not app_name:
        return 'not_compared', f'The application has no {owner} name to compare.'
    if not doc_name:
        if beneficiary_found:
            return 'pass', f'The {owner} name appears on the document.'
        return 'review', f'The {role_label.lower()} could not be read on the document.'
    details = name_match_details(doc_name, app_name)
    if details['result'] == 'match' or (details['result'] == 'likely_match' and details['confidence'] >= 97):
        return 'pass', 'The name matches the application.'
    if details['result'] == 'likely_match':
        return 'review', 'The name is similar but not identical to the application.'
    if beneficiary_found:
        return 'review', (f'The {role_label.lower()} differs from the {owner}, who is also named on the '
                          'document. Confirm their role.')
    return 'fail', f'The {role_label.lower()} on the document is {doc_name}, but the application is for {app_name}.'


def _field_comparison(expected: str, scan: dict, beneficiary: dict, type_ok: bool) -> list[dict]:
    person = scan['primary_person']
    role = identity_role(expected) if type_ok else person.get('role')
    role_label = _ROLE_LABELS.get(role or '', 'Name on document')
    app_name = str(beneficiary.get('full_name') or '').strip()
    owner = 'applicant' if expected == 'valid_id' and beneficiary.get('reference_owner') == 'applicant' else 'beneficiary'
    rows = []
    if role or person.get('name'):
        state, note = _name_comparison(person.get('name'), app_name, role_label, scan['beneficiary_name_found'], owner)
        burial_claimant_id = (expected == 'valid_id'
                              and owner == 'beneficiary'
                              and any('burial' in str(item).lower() for item in beneficiary.get('assistance_types') or []))
        if burial_claimant_id and state != 'pass':
            state, note = 'review', ('For burial assistance the ID may belong to the claimant rather than the '
                                     'deceased. Confirm whose ID this is.')
        rows.append({'field': 'name', 'label': role_label, 'document': person.get('name') or '',
                     'application': app_name, 'state': state, 'note': note})
        doc_birth = person.get('birth_date')
        if doc_birth:
            actual = normalize_date(doc_birth, allow_ambiguous_numeric=True)
            target = normalize_date(beneficiary.get('date_of_birth'), allow_ambiguous_numeric=True)
            if burial_claimant_id or not (actual and target):
                birth_state, birth_note = 'not_compared', ''
            elif actual == target:
                birth_state, birth_note = 'pass', 'The birth date matches the application.'
            else:
                birth_state, birth_note = 'fail', 'The birth date on the document differs from the application.'
            rows.append({'field': 'birth_date', 'label': 'Date of birth', 'document': actual or doc_birth,
                         'application': target or '', 'state': birth_state, 'note': birth_note})
    for field, label in (('document_date', 'Document date'), ('issuer', 'Issued by')):
        if scan.get(field):
            rows.append({'field': field, 'label': label, 'document': scan[field], 'application': '',
                         'state': 'info', 'note': ''})
    return rows


def _forensic_review(combined: dict) -> str:
    """'chronology', 'visual', or '' for the combined local tamper decision."""
    if not isinstance(combined, dict) or combined.get('decision') != 'review':
        return ''
    return 'chronology' if combined.get('review_basis') == 'deterministic_chronology_conflict' else 'visual'


def _visual_check(key: str, gemini_check: dict, forensic: str, extra_reason: str = '') -> dict:
    state, reason = gemini_check['state'], gemini_check['reason']
    if state == 'fail' and forensic != 'visual':
        state = 'review'  # a single uncorroborated source never escalates to fail
    if forensic == 'visual' and _RANK[state] < _RANK['review']:
        state, reason = 'review', 'Local image forensics marked areas that need inspection.'
    if extra_reason:
        if state == 'pass':
            state, reason = 'review', extra_reason
        elif not any(word in reason.lower() for word in ('sample', 'specimen', 'watermark', 'website', 'template')):
            reason = f'{reason} {extra_reason}'
    return _check(key, state, reason)


def _boxes_touch(left, right) -> bool:
    if not (isinstance(left, list) and isinstance(right, list) and len(left) == 4 and len(right) == 4):
        return False
    return not (left[3] < right[1] or right[3] < left[1] or left[2] < right[0] or right[2] < left[0])


def _friendly_date(value: str) -> str:
    try:
        return datetime.strptime(str(value), '%Y-%m-%d').strftime('%B %d, %Y').replace(' 0', ' ')
    except ValueError:
        return str(value)


def _edited_text_evidence(combined: dict, ai_untampered_state: str) -> dict | None:
    """Text typed on digitally (local overlay detector), strengthened when it sits
    on a conflicting date, the AI reading also saw it, or the file came from a
    design app. Metadata alone never creates this finding."""
    combined = combined if isinstance(combined, dict) else {}
    indicators = [item for item in combined.get('indicators') or [] if isinstance(item, dict)]
    overlays = [item for item in indicators if item.get('type') == 'digital_text_overlay']
    if not overlays:
        return None
    date_marks = [item for item in indicators if item.get('type') == 'date_context_conflict']
    on_date = any(int(overlay.get('page') or 1) == int(mark.get('page') or 1)
                  and _boxes_touch(overlay.get('box_2d'), mark.get('box_2d'))
                  for overlay in overlays for mark in date_marks)
    file_facts = (combined.get('auxiliary') or {}).get('file') or {}
    app = str(file_facts.get('editing_software') or '') if file_facts.get('design_app') is True else ''
    app_note = f' The file was saved with {app}, an editing app.' if app else ''
    conflict = combined.get('date_conflict') if isinstance(combined.get('date_conflict'), dict) else {}
    document_date = _friendly_date(conflict.get('document_date')) if conflict.get('document_date') else ''
    years = ', '.join(str(year) for year in conflict.get('conflicting_years') or [])
    if on_date:
        reason = ('The document date looks typed on digitally: it is flat digital black and darker than the '
                  'photographed text around it, and it conflicts with the other dates on the document.' + app_note)
        summary = (f'The date "{document_date}" ' if document_date else 'The document date ') + \
                  'looks typed on digitally' + (f' and conflicts with the {years} dates in the document.' if years
                                                else ' and conflicts with the other dates in the document.') + app_note
    else:
        reason = ('Some text is flat digital black and darker than the photographed text around it, so it may '
                  'have been typed on with an editing app.' + app_note)
        summary = reason
    corroborated = on_date or bool(app) or ai_untampered_state in {'review', 'fail'}
    return {'state': 'fail' if corroborated else 'review', 'reason': reason, 'summary': summary, 'edited_date': on_date}


def _apply_edited_text(checks: dict, edited: dict | None) -> None:
    if edited and _RANK[edited['state']] >= _RANK.get(checks['untampered']['state'], 1):
        checks['untampered'] = _check('untampered', edited['state'], edited['reason'])


def _gemini_checks(expected: str, scan: dict, beneficiary: dict, combined: dict):
    type_state, type_decision, type_reason = _type_assessment(expected, scan)
    type_ok = type_decision == 'match'
    rows = _field_comparison(expected, scan, beneficiary, type_ok)
    forensic = _forensic_review(combined)

    candidates = [(type_state, type_reason, 'type')]
    if type_decision != 'wrong_slot':
        candidates += [(row['state'], row['note'], 'identity') for row in rows if row['state'] in {'review', 'fail'}]
    if type_ok and scan['checks']['consistent']['state'] in {'review', 'fail'}:
        candidates.append((scan['checks']['consistent']['state'], scan['checks']['consistent']['reason'], 'internal'))
    if forensic == 'chronology':
        candidates.append(('fail', 'The signature or issue date conflicts with repeated event dates on the document.',
                           'internal'))
    state, reason, cause = _worst(candidates)
    if state == 'pass':
        reason = 'The document type and details agree with the application.'

    sample = scan['sample_or_specimen']
    gemini = scan['checks']
    checks = {
        'complete': _check('complete', gemini['complete']['state'], gemini['complete']['reason'], gemini['complete']['missing']),
        'consistent': _check('consistent', state, reason),
        'readable': _check('readable', gemini['readable']['state'], gemini['readable']['reason']),
        'unaltered': _visual_check('unaltered', gemini['unaltered'], forensic),
        'untampered': _visual_check('untampered', gemini['untampered'], forensic,
                                    sample['reason'] if sample['detected'] else ''),
    }
    return checks, rows, type_decision, type_reason, cause


def _status_from_checks(checks: dict) -> str:
    if checks['readable']['state'] == 'fail':
        return 'needs_reupload'
    if any(checks[key]['state'] == 'fail' for key in ('consistent', 'unaltered', 'untampered')):
        return 'flagged_inconsistency'
    if checks['complete']['state'] == 'fail':
        return 'needs_reupload'
    if any(item['state'] == 'review' for item in checks.values()):
        return 'needs_staff_review'
    return 'likely_valid'


def _edited_date_verdict(edited: dict | None, checks: dict) -> dict | None:
    if edited and edited['edited_date'] and checks['untampered']['state'] == 'fail':
        return {'headline': 'Possible edited date', 'reason': edited['summary'],
                'action': 'Compare the date with the original paper document or confirm it with the issuing office before deciding.'}
    return None


def _gemini_verdict(status: str, checks: dict, cause: str, expected: str, scan: dict,
                    edited: dict | None = None, owner: str = 'beneficiary') -> dict:
    expected_label = document_label(expected)
    edited_date = _edited_date_verdict(edited, checks)
    if checks['readable']['state'] == 'fail':
        headline, reason = 'Document is not readable', checks['readable']['reason']
        action = 'Ask the applicant to upload a clearer photo or scan of the document.'
    elif edited_date:
        return edited_date
    elif checks['consistent']['state'] == 'fail' and cause == 'type':
        headline, reason = 'Wrong document uploaded', checks['consistent']['reason']
        action = f'Ask the applicant to upload the {expected_label}.'
    elif checks['consistent']['state'] == 'fail' and cause == 'identity':
        headline, reason = 'Name does not match the application', checks['consistent']['reason']
        action = f'Compare the original document with the {owner} record before deciding.'
    elif checks['consistent']['state'] == 'fail':
        headline, reason = 'Details do not agree', checks['consistent']['reason']
        action = 'Check the dates and details on the original document before deciding.'
    elif any(checks[key]['state'] == 'fail' for key in ('unaltered', 'untampered')):
        key = 'unaltered' if checks['unaltered']['state'] == 'fail' else 'untampered'
        headline, reason = 'Possible document changes found', checks[key]['reason']
        action = 'Inspect the highlighted areas on the original document before deciding.'
    elif checks['complete']['state'] == 'fail':
        missing = checks['complete']['missing']
        headline, reason = 'Document is incomplete', checks['complete']['reason']
        action = 'Ask the applicant to upload the complete document' + (f' (missing: {", ".join(missing)}).' if missing else '.')
    elif status == 'needs_staff_review':
        first = next(checks[key] for key in ('consistent', 'complete', 'readable', 'unaltered', 'untampered')
                     if checks[key]['state'] == 'review')
        headline, reason = 'Staff review needed', first['reason']
        action = 'Check the items marked for review on the original document before deciding.'
    else:
        headline = 'Document looks good'
        reason = scan.get('summary') or 'All five checks passed.'
        action = 'Continue the normal staff verification of the original document.'
    return {'headline': headline, 'reason': reason, 'action': action}


def _humanize(field: str) -> str:
    return str(field).replace('_', ' ')


def _local_checks(findings: dict, combined: dict) -> dict:
    assessments = findings.get('assessments') if isinstance(findings.get('assessments'), dict) else {}

    def state_of(key):
        item = assessments.get(key) if isinstance(assessments.get(key), dict) else {}
        state = str(item.get('state') or 'unavailable').lower()
        return (state if state in _RANK else 'unavailable'), str(item.get('explanation') or '').strip()

    document_state, document_reason = state_of('document_match')
    applicant_state, applicant_reason = state_of('applicant_match')
    identity_check = (findings.get('checks') or {}).get('identity_check') or {}
    owner = 'applicant' if identity_check.get('reference_person') == 'applicant' else 'beneficiary'
    state, reason, _ = _worst([(document_state, document_reason or 'Document type checked with local OCR.', 'type'),
                               (applicant_state, applicant_reason or f'{owner.title()} details checked with local OCR.', 'identity')])
    forensic = _forensic_review(combined)
    if forensic == 'chronology':
        state, reason = 'fail', 'The signature or issue date conflicts with repeated event dates on the document.'
    readable_state, readable_reason = state_of('readability')
    if readable_state == 'pass':
        readable_reason = 'Local image quality checks found the document readable.'
    alteration_state, alteration_reason = state_of('alteration_signal')
    required = (findings.get('checks') or {}).get('required_fields') if isinstance(findings.get('checks'), dict) else None
    required_status = str((required or {}).get('status') or '').lower()
    missing = [_humanize(item) for item in (required or {}).get('missing') or []]
    if required_status == 'review':
        complete = _check('complete', 'review', 'Some expected details could not be read: ' + ', '.join(missing) + '.', missing)
    elif required_status == 'pass':
        complete = _check('complete', 'pass', 'Expected details were found.')
    else:
        complete = _check('complete', 'unavailable', 'Completeness was not checked.')
    unaltered_state = 'review' if forensic == 'visual' else 'unavailable'
    return {
        'complete': complete,
        'consistent': _check('consistent', state, reason or 'Checked with local OCR.'),
        'readable': _check('readable', readable_state, readable_reason or 'Checked with local image quality rules.'),
        'unaltered': _check('unaltered', unaltered_state,
                            'Local image forensics marked areas that need inspection.' if forensic == 'visual'
                            else 'Visual alteration review needs the AICS AI check, which did not run for this document.'),
        'untampered': _check('untampered', alteration_state, alteration_reason or 'Checked with local image forensics.'),
    }


def _local_verdict(status: str, findings: dict, gemini_result: dict) -> dict:
    reasons = [str(item) for item in findings.get('reasons') or [] if str(item).strip()]
    headline = {'likely_valid': 'Document looks good', 'needs_staff_review': 'Staff review needed',
                'needs_reupload': 'Document needs replacement', 'flagged_inconsistency': 'Inconsistency found'}.get(
                    status, 'Continue with manual review')
    return {
        'headline': headline,
        'reason': reasons[0] if reasons else 'Local checks completed.',
        'action': 'Compare the original document with the application before deciding.',
        'notice': 'The AICS AI check did not run for this document (' + str(gemini_result.get('error_code') or 'unavailable')
                  + '), so this result uses local OCR only and may be less accurate.',
    }


def _legacy_assessments(checks: dict, rows: list[dict], type_state: str,
                        owner: str = 'beneficiary') -> dict:
    identity_states = [row['state'] for row in rows if row['field'] in {'name', 'birth_date'} and row['state'] in _RANK]
    identity = max(identity_states, key=lambda item: _RANK[item]) if identity_states else 'not_applicable'
    alteration = max((checks['unaltered'], checks['untampered']), key=lambda item: _RANK[item['state']])
    return {
        'document_match': {'state': type_state, 'label': _LABELS['consistent'].get(type_state, 'Not checked'),
                           'explanation': checks['consistent']['reason']},
        'readability': {'state': checks['readable']['state'], 'label': checks['readable']['label'],
                        'explanation': checks['readable']['reason']},
        'applicant_match': {'state': identity, 'label': f'{owner.title()} details', 'explanation': next(
            (row['note'] for row in rows if row['field'] == 'name'), f'No {owner} name was compared.')},
        'alteration_signal': {'state': alteration['state'], 'label': alteration['label'],
                              'explanation': alteration['reason']},
    }


def apply_verdict(result: dict, expected_type: str, gemini_result: dict, combined_tamper: dict,
                  beneficiary: dict) -> dict:
    """Return the review result with findings.verdict, thesis_checks, and field_comparison."""
    output = deepcopy(result or {})
    findings = deepcopy(output.get('findings') or {})
    expected = normalize_code(expected_type)
    owner = 'applicant' if expected == 'valid_id' and (beneficiary or {}).get('reference_owner') == 'applicant' else 'beneficiary'
    gemini_result = gemini_result if isinstance(gemini_result, dict) else {}
    scan = gemini_result.get('scan') if gemini_result.get('status') == 'completed' else None

    if not isinstance(scan, dict):
        status = str(output.get('status') or 'needs_staff_review')
        checks = _local_checks(findings, combined_tamper)
        edited = _edited_text_evidence(combined_tamper, 'pass')
        _apply_edited_text(checks, edited)
        if checks['untampered']['state'] == 'fail':
            status = 'flagged_inconsistency'
        if checks['consistent']['state'] == 'fail' and _forensic_review(combined_tamper) == 'chronology':
            status = 'flagged_inconsistency'
        text = _local_verdict(status, findings, gemini_result)
        text.update(_edited_date_verdict(edited, checks) or {})
        findings['thesis_checks'] = checks
        findings['field_comparison'] = []
        if owner == 'applicant':
            findings['identity_owner'] = 'applicant'
        findings['verdict'] = {'source': 'local', 'status': status, 'tone': _TONE.get(status, 'neutral'), **text,
                               'required_label': document_label(expected),
                               'detected_label': document_label(output['detected_document_type']) if output.get('detected_document_type') else '',
                               'confidence': None, 'model': ''}
        output['status'] = status
        output['findings'] = findings
        return output

    checks, rows, type_decision, type_reason, cause = _gemini_checks(expected, scan, beneficiary or {}, combined_tamper)
    edited = _edited_text_evidence(combined_tamper, scan['checks']['untampered']['state'])
    _apply_edited_text(checks, edited)
    status = _status_from_checks(checks)
    text = _gemini_verdict(status, checks, cause, expected, scan, edited, owner)
    detected = scan['document_type']
    detected_label = (document_label(detected) if detected not in {'other', 'unknown'}
                      else (scan.get('document_title', '').title() or 'Not identified'))
    model = str(gemini_result.get('model') or '')

    findings['local_reasons'] = list(findings.get('reasons') or [])
    findings['reasons'] = list(dict.fromkeys(
        [text['reason']] + [checks[key]['reason'] for key in CHECK_KEYS if checks[key]['state'] in {'review', 'fail'}]))
    findings['summary'] = 'AICS AI reviewed the document images; PSWDO staff make the final decision.'
    findings['thesis_checks'] = checks
    findings['field_comparison'] = rows
    if owner == 'applicant':
        findings['identity_owner'] = 'applicant'
    findings['verdict'] = {
        'source': 'gemini', 'status': status, 'tone': _TONE[status], **text,
        'required_label': document_label(expected), 'detected_label': detected_label,
        'document_title': scan.get('document_title', ''), 'confidence': scan['type_confidence'], 'model': model,
    }
    findings['gemini_scan'] = {
        'status': 'completed', 'model': model, 'elapsed_ms': int(gemini_result.get('elapsed_ms') or 0),
        'cache_hit': bool(gemini_result.get('cache_hit')), 'page_count': int(gemini_result.get('page_count') or 0),
        'document_type': detected, 'document_title': scan.get('document_title', ''),
        'type_confidence': scan['type_confidence'], 'summary': scan.get('summary', ''),
        'sample_or_specimen': scan['sample_or_specimen'], 'indicator_count': len(scan.get('indicators') or []),
    }
    type_check = deepcopy((findings.get('checks') or {}).get('document_type') or {})
    classification = dict(type_check.get('classification') or {})
    classification.update({'decision': type_decision, 'detected_type': detected if detected != 'unknown' else None,
                           'display_type': detected_label, 'reason': type_reason or 'AICS AI matched the document type.',
                           'source': 'gemini'})
    type_state = {'match': 'pass', 'wrong_slot': 'fail'}.get(type_decision, 'review')
    type_check.update({'status': type_state, 'expected': expected, 'detected': classification['detected_type'],
                       'classification': classification})
    findings.setdefault('checks', {})['document_type'] = type_check
    findings['assessments'] = _legacy_assessments(checks, rows, type_state, owner)

    output.update({
        'status': status,
        'detected_document_type': detected if detected != 'unknown' else None,
        'confidence_score': scan['type_confidence'],
        'model_service': 'gemini_vision_primary',
        'model_version': f'{PROMPT_VERSION}+{model}'[:100],
        'findings': findings,
    })
    return output


def as_visual_result(gemini_result: dict) -> dict:
    """Shape Gemini's localized indicators for aggregate_tamper_evidence()."""
    scan = gemini_result.get('scan') if isinstance(gemini_result, dict) and gemini_result.get('status') == 'completed' else None
    if not isinstance(scan, dict):
        return {'status': 'unavailable', 'payload_mode': 'text',
                'error_code': str((gemini_result or {}).get('error_code') or 'GEMINI_UNAVAILABLE')}
    indicators = [{key: item[key] for key in ('type', 'page', 'box_2d', 'confidence_band', 'reason')}
                  for item in scan.get('indicators') or [] if item.get('box_2d')]
    untampered = scan['checks']['untampered']
    if not indicators and untampered['state'] in {'review', 'fail'}:
        # A whole-image finding (sample, display stand, screenshot) still gets a
        # visible, labelled box around the document in the evidence viewer.
        indicators = [{'type': 'sample_or_watermark' if scan['sample_or_specimen']['detected'] else 'other_visual_anomaly',
                       'page': 1, 'box_2d': [15, 15, 985, 985], 'confidence_band': 'moderate',
                       'reason': untampered['reason']}]
    return {
        'status': 'completed', 'payload_mode': 'image', 'provider': 'google_gemini',
        'model': str(gemini_result.get('model') or ''),
        'assessment': {
            'schema_version': 'external-ai-assessment-v2',
            'tamper_assessment': {
                'state': 'review_signal' if indicators else 'no_signal',
                'confidence_band': 'moderate' if indicators else 'low',
                'summary': scan['checks']['untampered']['reason'],
                'indicators': indicators,
            },
        },
    }
