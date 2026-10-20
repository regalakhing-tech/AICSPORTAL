"""Status decision and explainable application-first consistency findings."""
from datetime import date
from .consistency import build_consistency_check

REQUIRED = {
    'med_cert': ['patient_name', 'document_date'],
    'death_cert': ['deceased_name'],
    'funeral_contract': ['deceased_name'],
    'billing_statement': [],  # prescriptions are accepted and need no billing amount
    'valid_id': [],
    'report_police_med': [], 'school_assessment': [], 'registration_form': [], 'school_id': [], 'mdrrmo_cert': [],
    'eclip_cert': [], 'crisis_situation_cert': [], 'damaged_house_photo': [],
}
NAME_REQUIRED = {'valid_id', 'med_cert', 'billing_statement', 'death_cert', 'funeral_contract'}
DOCUMENT_LABELS = {
    'valid_id': 'Valid ID', 'med_cert': 'Medical Certificate',
    'billing_statement': 'Billing Statement / Prescription', 'death_cert': 'Death Certificate',
    'funeral_contract': 'Funeral Contract', 'report_police_med': 'Police / Medical / Legal Report',
    'crisis_situation_cert': 'Certificate of Person in Crisis Situation', 'school_assessment': 'School Assessment Form',
    'registration_form': 'Enrollment Form', 'school_id': 'School ID', 'mdrrmo_cert': 'MDRRMO Certification of Damage or Displacement',
    'eclip_cert': 'Former-Rebel Eligibility Certification', 'damaged_house_photo': 'Photographic Evidence of Damaged House',
}


def _human_value(field, value):
    if field == 'date_of_birth' and value:
        try:
            parsed = date.fromisoformat(str(value))
            return f'{parsed.strftime("%B")} {parsed.day}, {parsed.year}'
        except ValueError:
            return ', '.join(part.strip() for part in str(value).split(','))
    return str(value)


def _present(field):
    if not isinstance(field, dict):
        return False
    state = field.get('status', 'extracted' if field.get('value') not in (None, '') else 'not_present')
    return state == 'extracted' and field.get('value') not in (None, '', False)


def _context(beneficiary, beneficiary_birth_date, application_context):
    context = dict(application_context or {})
    context.setdefault('full_name', beneficiary or '')
    context.setdefault('date_of_birth', beneficiary_birth_date)
    context.setdefault('sex', None)
    context.setdefault('address', None)
    return context


def _structured_extraction(expected, fields, quality_blocked=False):
    return {
        'document_type': expected, 'fields': fields, 'quality_blocked': quality_blocked,
        'states': {key: value.get('status', 'extracted' if value.get('value') not in (None, '') else 'not_present')
                   for key, value in fields.items() if isinstance(value, dict)},
    }


def evaluate(expected, detected, type_confidence, quality, fields, beneficiary, prior,
             beneficiary_birth_date=None, classification=None, application_context=None,
             profile_calibrated=True):
    if expected == 'damaged_house_photo':
        checks = {'content_verification': {'status': 'manual_review_required', 'message': 'Image received; manual visual confirmation is required.'}, 'quality': {'status': quality.get('decision', 'staff_review'), 'result': quality}}
        return result('needs_staff_review', None, None, fields, {'summary': 'Photographic evidence received. PSWDO must visually confirm that it supports the reported disaster damage.', 'checks': checks, 'reasons': ['No image-classification model is used to determine whether a photograph shows damaged housing.']})
    reasons = []
    quality_decision = quality.get('decision', 'staff_review' if quality.get('blurred') else 'acceptable')
    wrong_type = ((classification and classification.get('decision') == 'wrong_slot') or
                  (classification is None and detected and detected != expected and type_confidence >= 70))
    # A nonblank file may have a readability problem yet still show enough
    # independent type evidence to prove it belongs in another upload slot.
    # Preserve both findings; never infer a wrong type from a blank page.
    if quality.get('blank') or (quality_decision == 'reupload' and not wrong_type):
        extraction = _structured_extraction(expected, fields, True)
        checks = {
            'quality': {'status': 'reupload', 'result': quality},
            'document_type': {'status': 'pass' if classification and classification.get('decision') == 'match' else 'review',
                              'expected': expected, 'detected': detected, 'classification': classification or {}},
            'structured_extraction': extraction,
        }
        return result('needs_reupload', detected, None, fields, {
            'summary': 'Document appears blank or has insufficient visible content.',
            'structured_extraction': extraction,
            'checks': checks,
            'reasons': [quality.get('reason', 'Insufficient visible content.')],
        })
    if wrong_type:
        reasons.append(classification['reason'] if classification else 'Uploaded document appears to be a different document type than the expected slot.')
        if quality_decision == 'reupload':
            reasons.append(quality.get('reason', 'A clearer copy is also needed.'))
        return result('flagged_inconsistency', detected, type_confidence, fields, {
            'summary': 'The uploaded document type does not match the required upload slot; staff must confirm the document and request the correct type.',
            'checks': {
                'document_type': {'status': 'fail', 'expected': expected, 'detected': detected, 'classification': classification or {}},
                'quality': {'status': quality_decision, 'result': quality},
            },
            'reasons': reasons,
        })

    if quality_decision == 'staff_review':
        reasons.append(quality.get('reason', 'Document quality requires staff review.'))
    if classification and classification.get('decision') == 'uncertain':
        reasons.append(classification['reason'])
    if not detected or type_confidence < 45:
        reasons.append('Document type could not be determined confidently.')

    missing = [key for key in REQUIRED.get(expected, []) if not _present(fields.get(key))]
    if missing:
        reasons.append('Important document fields could not be confidently extracted: ' + ', '.join(missing) + '.')

    context = _context(beneficiary, beneficiary_birth_date, application_context)
    identity_owner = 'applicant' if expected == 'valid_id' and context.get('reference_owner') == 'applicant' else 'beneficiary'
    consistency = build_consistency_check(expected, fields, context, prior)
    name_check = consistency['comparisons']['full_name']
    name_absent = name_check['status'] == 'not_present' and expected in NAME_REQUIRED
    if name_absent:
        reasons.append('The person name could not be confidently extracted; staff review is recommended.')
    elif name_check['status'] == 'uncertain':
        reasons.append('The extracted person name is uncertain; staff review is recommended.')

    for field in consistency['mismatched_fields']:
        label = {'full_name': 'Name', 'date_of_birth': 'Date of birth', 'sex': 'Sex'}[field]
        comparison = consistency['comparisons'][field]
        document_value = _human_value(field, comparison.get('document') or 'an extracted value')
        application_value = _human_value(field, comparison.get('application') or 'a different value')
        reference_subject = ('the submitted applicant information contains' if identity_owner == 'applicant'
                             else ('the submitted beneficiary information and trusted Valid ID contain'
                                   if comparison.get('valid_id') else 'the submitted beneficiary information contains'))
        reasons.append(
            f"{label} inconsistency detected. The {DOCUMENT_LABELS.get(expected, 'document')} contains {document_value}, "
            f"while {reference_subject} {application_value}. "
            "Staff review is recommended."
        )
    # Address is useful corroborating context, but it is mutable and must not
    # be treated as a stable identity contradiction (for example, an older ID).
    address_comparison = consistency['comparisons'].get('address', {})
    if address_comparison.get('status') == 'mismatch':
        reasons.append(f'{identity_owner.title()} address differs from the address extracted from the document. Staff confirmation is recommended.')
    elif address_comparison.get('status') == 'uncertain':
        reasons.append(f'The document does not show enough barangay and municipality information to confirm the {identity_owner} address. Staff confirmation is recommended.')
    if quality.get('blurred'):
        reasons.append('Possible unreadable or low-quality areas detected.')

    uncertain_important = any(key in ('full_name', 'date_of_birth', 'sex') for key in consistency['uncertain_fields'])
    # A reliable core identity mismatch remains material even when document
    # type confirmation is also required; both findings are retained below.
    can_flag = quality_decision == 'acceptable'
    if consistency['mismatched_fields'] and can_flag:
        status = 'flagged_inconsistency'
    elif reasons or uncertain_important or name_absent:
        status = 'needs_staff_review'
    else:
        status = 'completed'

    if status == 'completed' and not profile_calibrated:
        status = 'needs_staff_review'
        reasons.append('This document profile has not yet met its local validation threshold; staff confirmation is required.')

    confidence = max(0, min(100, round(type_confidence - (15 if quality.get('blurred') else 0) - (10 * len(missing)), 2)))
    extraction = _structured_extraction(expected, fields)
    checks = {
        'document_type': {'status': 'pass' if classification and classification.get('decision') == 'match' else 'review',
                          'expected': expected, 'detected': detected, 'classification': classification or {}},
        'quality': {'status': quality_decision, 'result': quality},
        'readability': {'status': 'review' if quality.get('blurred') else 'pass', 'blur_variance': quality.get('blur_variance')},
        'identity_check': {
            'status': name_check['status'], 'beneficiary_name': (context.get('full_name') or '') if identity_owner == 'beneficiary' else '',
            'extracted_name': name_check.get('document') or '', 'match_confidence': name_check.get('match_confidence'),
        },
        'name_match': {'status': name_check['status']},
        'birth_date_consistency': {'status': ('fail' if consistency['comparisons']['date_of_birth']['status'] == 'mismatch' else consistency['comparisons']['date_of_birth']['status']) if 'date_of_birth' in consistency['comparisons'] else 'not_applicable'},
        'required_fields': {'status': 'review' if missing else 'pass', 'missing': missing},
        'structured_extraction': extraction,
        'consistency_check': consistency,
    }
    if identity_owner == 'applicant':
        checks['identity_check'].update({'reference_person': 'applicant', 'applicant_name': context.get('full_name') or ''})
    return result(status, detected, confidence, fields, {
        'summary': 'Automated local OCR/rule checks completed; PSWDO must make the final decision.',
        'structured_extraction': extraction,
        'consistency_check': consistency,
        'checks': checks,
        'reasons': reasons,
    })


def result(status, detected, confidence, fields, findings):
    findings = dict(findings or {})
    checks = findings.get('checks', {}) if isinstance(findings.get('checks'), dict) else {}
    document_check = checks.get('document_type', {}) if isinstance(checks.get('document_type'), dict) else {}
    document_state = {'pass': 'pass', 'fail': 'fail'}.get(str(document_check.get('status')), 'review')
    quality_check = checks.get('quality', {}) if isinstance(checks.get('quality'), dict) else {}
    quality_state = {'acceptable': 'pass', 'pass': 'pass', 'reupload': 'fail'}.get(str(quality_check.get('status')), 'review')
    consistency = checks.get('consistency_check', findings.get('consistency_check', {}))
    consistency = consistency if isinstance(consistency, dict) else {}
    mismatches = list(consistency.get('mismatched_fields') or [])
    uncertainties = list(consistency.get('uncertain_fields') or [])
    comparisons = consistency.get('comparisons', {}) if isinstance(consistency.get('comparisons'), dict) else {}
    address_review = isinstance(comparisons.get('address'), dict) and comparisons['address'].get('status') == 'mismatch'
    identity_check = checks.get('identity_check', {}) if isinstance(checks.get('identity_check'), dict) else {}
    if mismatches:
        applicant_state, applicant_label = 'fail', 'Identity details differ'
    elif uncertainties or address_review or identity_check.get('status') in ('uncertain', 'not_present'):
        applicant_state, applicant_label = 'review', 'Confirm applicant details'
    elif comparisons or identity_check.get('status') == 'match':
        applicant_state, applicant_label = 'pass', 'Applicant details match'
    else:
        applicant_state, applicant_label = 'unavailable', 'Applicant comparison unavailable'
    findings['assessments'] = {
        'document_match': {
            'state': document_state,
            'label': {'pass': 'Document type matches', 'fail': 'Wrong document type', 'review': 'Confirm document type'}[document_state],
            'explanation': 'Compared with the required upload slot using local document evidence.',
            'diagnostics': {},
        },
        'readability': {
            'state': quality_state,
            'label': {'pass': 'Readable', 'fail': 'New upload needed', 'review': 'Check readability'}[quality_state],
            'explanation': 'Checks whether enough clear content is available for reliable review.',
            'diagnostics': {},
        },
        'applicant_match': {
            'state': applicant_state, 'label': applicant_label,
            'explanation': 'Uses stable identity fields when reliable; address differences only request confirmation.',
            'diagnostics': {},
        },
        'alteration_signal': {
            'state': 'unavailable', 'label': 'Alteration check pending',
            'explanation': 'This independent image signal does not decide approval or rejection.',
            'diagnostics': {},
        },
    }
    return {'status': status, 'detected_document_type': detected, 'confidence_score': confidence,
            'extracted_fields': fields, 'findings': findings}
