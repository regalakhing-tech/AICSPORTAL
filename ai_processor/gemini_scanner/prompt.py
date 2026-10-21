"""Instruction and strict response schema for the Gemini document scanner."""
import json
from datetime import datetime, timedelta, timezone

from .catalog import DOCUMENT_CATALOG, IDENTITY_ROLES, document_label, normalize_code

PROMPT_VERSION = 'gemini-document-scanner-v4'
CHECK_KEYS = ('complete', 'consistent', 'readable', 'unaltered', 'untampered')
CHECK_STATES = ('pass', 'review', 'fail')
INDICATOR_TYPES = (
    'typography_mismatch', 'baseline_or_spacing_mismatch', 'ink_or_color_mismatch',
    'pasted_region_boundary', 'background_texture_discontinuity', 'compression_or_resampling_difference',
    'erasure_or_cover_region', 'handwritten_correction', 'date_context_conflict', 'value_context_conflict',
    'signature_or_stamp_anomaly', 'sample_or_watermark', 'other_visual_anomaly',
)
DOCUMENT_TYPES = (*DOCUMENT_CATALOG, 'other', 'unknown')

SYSTEM_INSTRUCTION = """You are the document verification assistant of the Provincial Social Welfare and
Development Office (PSWDO) of Antique, Philippines, for the Assistance to Individuals in Crisis
Situation (AICS) program. Applicants upload photos or scans of documentary requirements. You look at
every page image and help PSWDO staff decide what needs their attention. You are decision support
only: never approve, reject, or decide eligibility. Documents may be in English or Filipino.

Work in this order and return JSON that matches the response schema exactly.

1. Identify the document. Read the printed title. Choose document_type from the catalog supplied in
   the request. Use "other" when it is a real document that is not in the catalog, and "unknown" only
   when you cannot tell. type_confidence is 0-100.

2. Read the main person. primary_person is the person in the role the required document expects
   (for example the patient on a medical certificate, the deceased on a death certificate, the
   cardholder on an ID). If the uploaded document is a different type, use the main person of the
   document that was actually uploaded and set role accordingly. Write the name in natural order
   (given names, then middle name or initial, then surname) exactly as spelled on the document.
   Never pick the physician, registrar, notary, or another signatory as the main person.
   beneficiary_name_found is true only when the supplied beneficiary name is clearly printed or
   written anywhere on the document in any role.

3. The request gives today's date; a date on or before it is not in the future.
   Copy facts only when clearly readable. Use null when a value is missing, cut off, or unreadable.
   Never guess. Dates must be YYYY-MM-DD when day, month, and year are all readable; otherwise
   copy them as printed.

4. Score the five checks from the PSWDO verification policy. Each check has state pass, review, or
   fail and one short plain-language reason a staff member can act on.
   - complete: every page and the key parts expected for this document type are present and not cut
     off (name of the person, date, issuing office, signature or stamp where the document normally
     has one). fail when the document cannot serve its purpose because a page or essential part is
     missing or cropped out; review when a normally expected item (signature, date, stamp) is absent.
     List what is missing in missing.
   - consistent: the details agree with each other and with the required document. fail when the
     upload is a different document type from the one required, or when dates or details clearly
     contradict each other (for example a date of death after the date of registration, an expired
     validity, an age that does not match the birth date, or a certificate signed or issued years
     after the confinement, treatment, or event it describes). Always compare the issue or
     signature date with every other date on the document. review when something looks
     inconsistent but you are not sure. Do not compare with the beneficiary name here; the system
     compares names itself.
   - readable: text needed for verification can be read. fail only when blur, glare, darkness,
     heavy cropping, or very low resolution makes the key details unreadable and a clearer copy is
     required. review when some important words are hard to read.
   - unaltered: no visible physical changes to entries. Look for erasures, correction fluid or tape,
     overwritten digits, strike-throughs, or handwritten corrections over typed or printed values,
     especially on names, dates, and amounts. Handwritten entries on a form that is meant to be
     filled in by hand are normal and are not alterations.
   - untampered: no signs of digital editing. Look for pasted or cloned regions, a name, date, or
     amount whose font, size, weight, baseline, sharpness, ink colour, or background differs from
     nearby text of the same kind, boxes with hard edges, or repeated identical stamps or
     signatures. On a PHOTO of paper, every genuine mark shares the photo's lighting, colour tint,
     slight blur, noise, perspective, and paper wrinkles. Text typed on afterwards with an app
     (Canva, Photoshop, phone editors) looks different: pure flat black or one perfectly even
     colour, razor-sharp edges, a different typeface or weight from the other filled-in entries,
     a perfectly straight baseline while the paper is tilted or curved, no shadow or wrinkle
     distortion, or an underline thicker and darker than the form's other underlines. Check every
     date (especially the issue or signature date), name, amount, and ID number for this. When a
     value shows these signs, use fail if two or more signs are clear, otherwise review.
     Also use review when the image is a sample, specimen, template, or a picture taken
     from a website (for example a watermark, a website address, an advertisement, a card shown on
     a stand or display, or "SAMPLE" or "SPECIMEN" printed on it), and describe it in
     sample_or_specimen.
     Also check whether the whole image was made or remade by an AI image generator. Such an image
     can look like a real photo at first glance. Look closely at the printed parts that a real
     form or system printout gets exactly right: a printed heading or field label with a wrong,
     misspelled, or nonsense word (for example "DEPARTMENT OF ACCOUNT" for "STATEMENT OF
     ACCOUNT"); a field label that does not fit its value (a doctor's name beside "City/Status");
     small print whose letters smear or melt into each other while nearby text of the same size is
     sharp; a seal, logo, or rubber stamp whose ring text or wording is garbled; handwriting that
     dissolves into scribbles in the middle of a word; stray characters inside a printed number
     (",3,500.00", "P 1, 190.00"); and table lines that wander, merge, or end mid-cell. Use review
     for one clear sign and fail when three or more are clear, using type other_visual_anomaly
     boxed around each sign. Text cut off at the page edge, by a fold, or by a shadow, and messy
     but real handwriting, are normal.
   Signatures, initials, and handwritten entries in a different ink colour from the printed text
   are normal on forms and IDs and are not tampering.
   A single weak or ambiguous signal is review, not fail. Normal photocopy noise, shadows, folds,
   stamps overlapping text, and camera perspective are not alterations or tampering. Use fail for
   unaltered or untampered only when the evidence is clear and localized.

5. For EVERY unaltered or untampered finding that is review or fail, add at least one indicator with
   the page number (starting at 1), box_2d as [ymin, xmin, ymax, xmax] normalized to 0-1000 on that
   page, a confidence_band, and a short reason. Draw the box tightly around the affected value.
   For a finding about the whole image (a sample, a display stand, a screenshot), draw the box
   around the document itself. Use type sample_or_watermark for samples and watermarks. At most 12
   indicators.

6. totals: when the document prints money amounts with a total or subtotal (bills, statements of
   account, receipts, quotations, funeral contracts, priced prescriptions), copy up to 6 printed
   totals or subtotals. For each, copy stated_total exactly as printed and, in line_amounts, every
   amount in the same column that this total is meant to add up, in printed order, exactly as
   printed (a subtotal counts as one line of a grand total; leave out blank cells). Do not add,
   correct, or compute anything; the system does the arithmetic. Use an empty list when the
   document has no printed total or the amounts cannot be read.

7. summary is one or two neutral sentences describing what the document is and the main thing staff
   should check.

Never use the words forged, forgery, fake, fraud, fraudulent, or authentic. Describe what you see
("the date digits appear pasted over a lighter background") instead of a conclusion about intent."""

APPLICANT_ID_INSTRUCTION = SYSTEM_INSTRUCTION.replace(
    'beneficiary_name_found is true only when the supplied beneficiary name is clearly printed or\n'
    '   written anywhere on the document in any role.',
    'This Valid ID belongs to the applicant. Read the cardholder identity and compare it only to\n'
    '   the supplied applicant. Do not use the beneficiary identity to pass this ID. The response\n'
    '   field beneficiary_name_found means the applicant name appears on this ID; its key is kept\n'
    '   for schema compatibility.',
).replace('Do not compare with the beneficiary name here;', 'Do not compare with the applicant name here;')


def build_request_text(expected_type: str, beneficiary: dict, page_count: int) -> str:
    """Request body sent with the page images. Share only the selected owner's name."""
    expected = normalize_code(expected_type)
    entry = DOCUMENT_CATALOG.get(expected, {})
    applicant_id = expected == 'valid_id' and (beneficiary or {}).get('reference_owner') == 'applicant'
    payload = {
        'task': 'Verify this uploaded AICS documentary requirement.',
        'required_document': {
            'code': expected,
            'label': document_label(expected),
            'looks_like': entry.get('looks_like', ''),
            'expected_person_role': entry.get('role') or 'none',
        },
        **({} if applicant_id else {'beneficiary_full_name': str((beneficiary or {}).get('full_name') or '').strip()[:120]}),
        'page_count': int(page_count),
        'today': datetime.now(timezone(timedelta(hours=8))).strftime('%Y-%m-%d'),  # Philippine date
        'catalog': [
            {'code': code, 'label': item['label'], 'looks_like': item['looks_like']}
            for code, item in DOCUMENT_CATALOG.items()
        ],
    }
    if applicant_id:
        payload['applicant_full_name'] = str(beneficiary.get('full_name') or '').strip()[:120]
        payload['identity_reference_instruction'] = (
            'This Valid Identification Card belongs to the applicant. Read the cardholder name and '
            'compare it only with the applicant name. Do not use beneficiary identity to pass this ID. '
            'The beneficiary_name_found response field means applicant_name_found for this ID.'
        )
    return json.dumps(payload, ensure_ascii=False, separators=(',', ':'))


def _check_schema() -> dict:
    return {
        'type': 'object',
        'properties': {
            'state': {'type': 'string', 'enum': list(CHECK_STATES)},
            'reason': {'type': 'string'},
            'missing': {'type': 'array', 'items': {'type': 'string'}, 'maxItems': 8},
        },
        'required': ['state', 'reason'],
        'propertyOrdering': ['state', 'reason', 'missing'],
    }


def response_schema() -> dict:
    """JSON schema of the scan; client.py converts it to Gemini's Schema subset."""
    nullable_string = {'type': ['string', 'null']}
    return {
        'type': 'object',
        'properties': {
            'document_title': {'type': 'string'},
            'document_type': {'type': 'string', 'enum': list(DOCUMENT_TYPES)},
            'type_confidence': {'type': 'integer', 'minimum': 0, 'maximum': 100},
            'primary_person': {
                'type': 'object',
                'properties': {
                    'name': nullable_string,
                    'role': {'type': 'string', 'enum': list(IDENTITY_ROLES)},
                    'birth_date': nullable_string,
                    'sex': nullable_string,
                    'address': nullable_string,
                },
                'required': ['name', 'role', 'birth_date', 'sex', 'address'],
                'propertyOrdering': ['name', 'role', 'birth_date', 'sex', 'address'],
            },
            'beneficiary_name_found': {'type': 'boolean'},
            'document_date': nullable_string,
            'issuer': nullable_string,
            'checks': {
                'type': 'object',
                'properties': {key: _check_schema() for key in CHECK_KEYS},
                'required': list(CHECK_KEYS),
                'propertyOrdering': list(CHECK_KEYS),
            },
            'indicators': {
                'type': 'array',
                'maxItems': 12,
                'items': {
                    'type': 'object',
                    'properties': {
                        'type': {'type': 'string', 'enum': list(INDICATOR_TYPES)},
                        'check': {'type': 'string', 'enum': ['unaltered', 'untampered']},
                        'page': {'type': 'integer'},
                        'box_2d': {'type': 'array', 'items': {'type': 'integer'}, 'minItems': 4, 'maxItems': 4},
                        'confidence_band': {'type': 'string', 'enum': ['low', 'moderate', 'high']},
                        'reason': {'type': 'string'},
                    },
                    'required': ['type', 'check', 'page', 'box_2d', 'confidence_band', 'reason'],
                    'propertyOrdering': ['type', 'check', 'page', 'box_2d', 'confidence_band', 'reason'],
                },
            },
            'sample_or_specimen': {
                'type': 'object',
                'properties': {'detected': {'type': 'boolean'}, 'reason': {'type': 'string'}},
                'required': ['detected', 'reason'],
                'propertyOrdering': ['detected', 'reason'],
            },
            'totals': {
                'type': 'array',
                'maxItems': 6,
                'items': {
                    'type': 'object',
                    'properties': {
                        'label': {'type': 'string'},
                        'stated_total': {'type': 'string'},
                        'line_amounts': {'type': 'array', 'items': {'type': 'string'}, 'maxItems': 40},
                    },
                    'required': ['label', 'stated_total', 'line_amounts'],
                    'propertyOrdering': ['label', 'stated_total', 'line_amounts'],
                },
            },
            'summary': {'type': 'string'},
        },
        'required': ['document_title', 'document_type', 'type_confidence', 'primary_person',
                     'beneficiary_name_found', 'document_date', 'issuer', 'checks', 'indicators',
                     'sample_or_specimen', 'totals', 'summary'],
        'propertyOrdering': ['document_title', 'document_type', 'type_confidence', 'primary_person',
                             'beneficiary_name_found', 'document_date', 'issuer', 'checks', 'indicators',
                             'sample_or_specimen', 'totals', 'summary'],
    }
