"""Sign-up ID check: AICS AI reads the front and back of an applicant's valid ID.

Usage: python -m ai_processor.id_check --front FRONT --back BACK
Prints one JSON object. No ID numbers are read or returned, and nothing
personal is logged. The result supports the sign-up form; PSWDO staff keep
the final say on identity.
"""
from __future__ import annotations

import argparse
import json
import logging
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from pathlib import Path

import cv2
import numpy as np

from .config import SETTINGS
from .document_loader import load_images
from .external_ai.images import ExternalAiImageError, prepare_image_data_url
from .field_normalization import normalize_date
from .gemini_scanner.client import GeminiScannerClient
from .gemini_scanner.service import gemini_scanner_configured

logger = logging.getLogger(__name__)

ID_TYPES = {
    'philsys_national_id': 'Philippine National ID (PhilSys)',
    'drivers_license': "Driver's License",
    'umid': 'UMID',
    'sss_id': 'SSS ID',
    'gsis_id': 'GSIS ID',
    'passport': 'Passport',
    'postal_id': 'Postal ID',
    'voters_id': "Voter's ID",
    'prc_id': 'PRC ID',
    'senior_citizen_id': 'Senior Citizen ID',
    'pwd_id': 'PWD ID',
    'philhealth_id': 'PhilHealth ID',
    'pagibig_id': 'Pag-IBIG ID',
    'owwa_id': 'OWWA ID',
    'dole_id': 'DOLE ID',
    'voters_certification': "Voter's Certification",
    'nbi_clearance': 'NBI Clearance',
    'four_ps_id': '4Ps ID',
    'solo_parent_id': 'Solo Parent ID',
    'city_municipal_id': 'City or Municipal Government ID',
    'police_clearance': 'Police Clearance',
    'school_id': 'School ID',
    'barangay_id': 'Barangay ID',
    'other_government_id': 'Other government ID',
    'unknown_id': 'Unknown ID',
    'unsupported_id': 'Unsupported ID',
    'not_an_id': 'Not an ID',
}
SUPPORTED_IDS = frozenset(ID_TYPES) - {'school_id', 'unknown_id', 'unsupported_id', 'not_an_id'}
ISSUER_MARKERS = {
    'philsys_national_id': ('philippine statistics authority', 'philsys', 'psa'),
    'drivers_license': ('land transportation office', 'lto'),
    'passport': ('department of foreign affairs', 'dfa'),
    'umid': ('social security system', 'sss', 'government service insurance system', 'gsis'),
    'sss_id': ('social security system', 'sss'),
    'gsis_id': ('government service insurance system', 'gsis'),
    'philhealth_id': ('philippine health insurance', 'philhealth'),
    'prc_id': ('professional regulation commission', 'prc'),
    'pagibig_id': ('pag-ibig', 'hdmf', 'home development mutual fund'),
    'owwa_id': ('overseas workers welfare administration', 'owwa'),
    'dole_id': ('department of labor and employment', 'dole'),
    'voters_id': ('commission on elections', 'comelec'),
    'voters_certification': ('commission on elections', 'comelec'),
    'postal_id': ('philippine postal', 'phlpost'),
    'nbi_clearance': ('national bureau of investigation', 'nbi'),
    'four_ps_id': ('department of social welfare', 'dswd'),
    'police_clearance': ('philippine national police', 'police station', 'pnp'),
    'senior_citizen_id': ('office of senior citizens affairs', 'osca', 'city government', 'municipal government'),
    'barangay_id': ('barangay ', 'barangay government'),
    'city_municipal_id': ('city government', 'municipal government', 'city hall', 'municipal hall'),
    'pwd_id': ('city government', 'municipal government', 'persons with disability affairs', 'pdao', 'dswd'),
    'solo_parent_id': ('city government', 'municipal government', 'dswd', 'department of social welfare'),
}
OTHER_GOVERNMENT_ISSUERS = frozenset({
    'department of agriculture', 'department of education', 'department of health',
    'department of justice', 'department of finance', 'department of public works and highways',
    'department of science and technology', 'department of trade and industry',
    'department of tourism', 'department of transportation', 'department of energy',
    'department of environment and natural resources', 'department of budget and management',
    'department of national defense', 'department of information and communications technology',
    'department of migrant workers', 'department of human settlements and urban development',
    'department of the interior and local government', 'office of civil defense',
    'office of the president', 'office of the vice president', 'civil service commission',
    'commission on human rights', 'bureau of immigration', 'land registration authority',
})
UNSUPPORTED_MARKERS = re.compile(r'\b(student|school|university|college|faculty|alumni|employee|company|membership|'
                                 r'library|club|loyalty|gym|bank|debit|credit|atm)\b|\binsurance card\b', re.I)
NON_ID_PAPERS = re.compile(r'\b(certificate|certification|medical abstract|prescription|billing statement|'
                           r'assessment|registration form|funeral contract|police report|mdrrmo)\b', re.I)
READABILITY = ('clear', 'partly', 'unreadable')
LOCAL_CHECK_MAX_EDGE = 1600     # phone photos are ~4000 px; OCR on them took 38 s per side
LOCAL_CHECK_TIMEOUT = 30        # seconds; a slow optional check never blocks sign-up

INSTRUCTION = """You inspect two uploaded photos for applicant registration in Antique, Philippines.
Image 1 is intended as the front and image 2 as the back. Never assume either is an ID.
Return JSON matching the response schema exactly. Classify the document BEFORE reading identity.
Set document_detected and is_id only from visible evidence. Identify the exact document title,
ID type, issuing agency, and classification confidence. Use unknown_id when uncertain.
School, student, university, college, faculty, and alumni IDs are unsupported even at public schools.
Company, employee, membership, library, financial, insurance, and organization cards are unsupported.
Certificates, medical papers, forms, selfies, random documents, and photos are not IDs, except the
explicitly listed voter certification, NBI clearance, and police clearance. A barangay certificate
is not a barangay ID. A generic 'Republic of the Philippines' heading is not issuer evidence.
Use other_government_id only if a specific Philippine government agency is visibly identified
and the document is clearly an identification document. Do not guess an issuer or a type.
Set is_supported_id true only for an approved government ID with high confidence and a clear issuer.
For a Philippine National ID, the official PhilSys fingerprint logo together with the printed
"Pambansang Pagkakakilanlan" or "Philippine Identification Card" title identifies the issuer as
the Philippine Statistics Authority even when the agency name is not printed. Set
philsys_logo_visible true only when that distinctive logo is clearly visible. The words
"Republic of the Philippines" by themselves never establish this evidence.
For unsupported or unknown documents, set all identity fields to null.

front:
- is_id: true if image 1 is an actual identification card, even a school or company ID,
  or one of the explicitly allowed voter certification, NBI clearance, or police clearance.
  This field does not mean the document is supported.
- id_type: choose from the schema list; use unsupported_id for non-government cards,
  unknown_id for an unidentified card, and not_an_id for unrelated images or documents.
- readable: clear, partly (some words hard to read), or unreadable.
- last_name, given_names, middle_name: copy exactly as printed, in capital letters. Philippine IDs
  often print labelled fields (Last Name / Given Names / Middle Name) or "LAST, GIVEN MIDDLE".
  given_names holds all first names (for example "JUAN SANTOS"). Use null when a part is not shown
  or not readable. Never guess.
- birth_date: YYYY-MM-DD when fully readable, else null. expiry_date the same. expired: true only
  when the expiry date is before today (today is given in the request).
- Never copy ID numbers, card numbers, or QR contents.

back:
- is_back_of_id: true if image 2 shows the back of an ID card (signature box, address, barcode,
  QR, terms, or issuing details).
- same_card_as_front: yes, no, or unsure. Answer no when the design, card type, or person clearly
  differs from the front, or when image 2 is the same front photo again.
- is_same_document_as_front: true only if image 2 is clearly another genuine photo of the same
  one-sided voter certification, NBI clearance, or police clearance. Compare the document's layout,
  markings, issuer and readable text. Do not mark true for an unrelated page or an uncertain match.

edited: pass, review, or fail with a short reason. On a phone photo every real printed mark shares
the photo's light, tint, blur, noise, and tilt. Typed-on or pasted text from an editing app looks
different: flat pure black or one even colour, razor-sharp edges, a different font or weight from
the other fields, a baseline that ignores the card's tilt, or a background patch behind it. A
pasted portrait has hard edges or different light. Use fail only when the evidence is clear;
review for weaker signs. Glare, shadows, and worn cards are normal.

sample_or_screenshot: detected true when the image is a sample or specimen card, a template, a
picture taken from a website, a photo of a screen, or an illustration rather than a real card.

Never use the words forged, forgery, fake, fraud, fraudulent, or authentic."""

def response_schema() -> dict:
    nullable = {'type': ['string', 'null']}
    return {
        'type': 'object',
        'properties': {
            'front': {
                'type': 'object',
                'properties': {
                    'is_id': {'type': 'boolean'},
                    'document_detected': {'type': 'boolean'},
                    'id_type': {'type': 'string', 'enum': list(ID_TYPES)},
                    'document_title': nullable, 'issuer': nullable,
                    'confidence': {'type': 'string', 'enum': ['high', 'medium', 'low']},
                    'is_supported_id': {'type': 'boolean'},
                    'philsys_logo_visible': {'type': 'boolean'},
                    'readable': {'type': 'string', 'enum': list(READABILITY)},
                    'last_name': nullable, 'given_names': nullable, 'middle_name': nullable,
                    'birth_date': nullable, 'expiry_date': nullable, 'expired': {'type': 'boolean'},
                },
                'required': ['document_detected', 'is_id', 'id_type', 'document_title', 'issuer',
                             'confidence', 'is_supported_id', 'philsys_logo_visible', 'readable',
                             'last_name', 'given_names', 'middle_name',
                             'birth_date', 'expiry_date', 'expired'],
                'propertyOrdering': ['document_detected', 'is_id', 'id_type', 'document_title', 'issuer',
                                     'confidence', 'is_supported_id', 'philsys_logo_visible', 'readable',
                                     'last_name', 'given_names', 'middle_name',
                                     'birth_date', 'expiry_date', 'expired'],
            },
            'back': {
                'type': 'object',
                'properties': {
                    'is_back_of_id': {'type': 'boolean'},
                    'same_card_as_front': {'type': 'string', 'enum': ['yes', 'no', 'unsure']},
                    'is_same_document_as_front': {'type': 'boolean'},
                    'readable': {'type': 'string', 'enum': list(READABILITY)},
                },
                'required': ['is_back_of_id', 'same_card_as_front', 'is_same_document_as_front', 'readable'],
                'propertyOrdering': ['is_back_of_id', 'same_card_as_front', 'is_same_document_as_front', 'readable'],
            },
            'edited': {
                'type': 'object',
                'properties': {'state': {'type': 'string', 'enum': ['pass', 'review', 'fail']}, 'reason': {'type': 'string'}},
                'required': ['state', 'reason'], 'propertyOrdering': ['state', 'reason'],
            },
            'sample_or_screenshot': {
                'type': 'object',
                'properties': {'detected': {'type': 'boolean'}, 'reason': {'type': 'string'}},
                'required': ['detected', 'reason'], 'propertyOrdering': ['detected', 'reason'],
            },
        },
        'required': ['front', 'back', 'edited', 'sample_or_screenshot'],
        'propertyOrdering': ['front', 'back', 'edited', 'sample_or_screenshot'],
    }


def _text(value, limit: int) -> str | None:
    if not isinstance(value, str):
        return None
    cleaned = re.sub(r'\s+', ' ', value).strip()
    return cleaned[:limit] if cleaned and cleaned.lower() not in {'null', 'none', 'n/a'} else None


def _choice(value, allowed, default):
    return value if value in allowed else default


def normalize_reading(raw, page_count: int = 2) -> dict:
    """Bound and validate the AI reading; raise ValueError for a non-object."""
    if not isinstance(raw, dict):
        raise ValueError('ID reading is not an object')
    front = raw.get('front') if isinstance(raw.get('front'), dict) else {}
    back = raw.get('back') if isinstance(raw.get('back'), dict) else {}
    edited = raw.get('edited') if isinstance(raw.get('edited'), dict) else {}
    sample = raw.get('sample_or_screenshot') if isinstance(raw.get('sample_or_screenshot'), dict) else {}
    birth = _text(front.get('birth_date'), 40)
    expiry = _text(front.get('expiry_date'), 40)
    return {
        'front': {
            'is_id': front.get('is_id') is True,
            'document_detected': front.get('document_detected') is True,
            'id_type': _choice(front.get('id_type'), ID_TYPES, 'unknown_id'),
            'document_title': _text(front.get('document_title'), 120),
            'issuer': _text(front.get('issuer'), 120),
            'confidence': _choice(front.get('confidence'), ('high', 'medium', 'low'), 'low'),
            'is_supported_id': front.get('is_supported_id') is True,
            'philsys_logo_visible': front.get('philsys_logo_visible') is True,
            'readable': _choice(front.get('readable'), READABILITY, 'partly'),
            'last_name': _text(front.get('last_name'), 80),
            'given_names': _text(front.get('given_names'), 80),
            'middle_name': _text(front.get('middle_name'), 80),
            'birth_date': normalize_date(birth, allow_ambiguous_numeric=False) if birth else None,
            'expiry_date': normalize_date(expiry, allow_ambiguous_numeric=False) if expiry else None,
            'expired': front.get('expired') is True,
        },
        'back': {
            'is_back_of_id': back.get('is_back_of_id') is True,
            'same_card_as_front': _choice(back.get('same_card_as_front'), ('yes', 'no', 'unsure'), 'unsure'),
            'is_same_document_as_front': back.get('is_same_document_as_front') is True,
            'readable': _choice(back.get('readable'), READABILITY, 'partly'),
        },
        'edited': {'state': _choice(edited.get('state'), ('pass', 'review', 'fail'), 'review'),
                   'reason': _text(edited.get('reason'), 200) or ''},
        'sample_or_screenshot': {'detected': sample.get('detected') is True,
                                 'reason': _text(sample.get('reason'), 200) or ''},
    }


def decide(reading: dict, local: dict) -> dict:
    """Turn the reading and local pixel checks into one sign-up decision."""
    front, back = reading['front'], reading['back']
    kind = front['id_type']
    raw_issuer = front['issuer'] or ''
    title = front['document_title'] or ''
    philsys_title = bool(re.search(r'\b(philippine identification card|pambansang pagkakakilanlan)\b', title, re.I))
    philsys_evidence = (kind == 'philsys_national_id' and philsys_title and
                        front['philsys_logo_visible'] and
                        raw_issuer.lower() in {'', 'republic of the philippines', 'republika ng pilipinas'})
    issuer = 'Philippine Statistics Authority' if philsys_evidence else raw_issuer
    classification = {
        'document_detected': front['document_detected'], 'is_identification_card': front['is_id'],
        'id_type': kind, 'id_type_label': ID_TYPES[kind], 'issuer': issuer or None,
        'confidence': front['confidence'], 'is_supported_id': False,
    }
    def reject(decision: str, message: str) -> dict:
        return {**classification, 'decision': decision, 'message': message, 'rejection_reason': message,
                'last_name': None, 'given_names': None, 'middle_name': None, 'full_name': None,
                'birth_date': None, 'expiration_date': None, 'expired': False, 'flags': []}

    if not front['document_detected']:
        return reject('retake', 'The uploaded file does not appear to be a valid identification document.')
    if kind == 'school_id' or UNSUPPORTED_MARKERS.search(title) or re.search(r'\b(school|student|university|college|faculty|alumni|company|employee)\b', raw_issuer, re.I):
        if re.search(r'\b(school|student|university|college|faculty|alumni)\b', title + ' ' + raw_issuer, re.I) or kind == 'school_id':
            return reject('unsupported_id', 'School or student IDs are not accepted. Please upload a valid government-issued ID.')
        if re.search(r'\b(company|employee)\b', title + ' ' + raw_issuer, re.I):
            return reject('unsupported_id', 'Company or employee IDs are not accepted. Please upload a valid government-issued ID.')
        return reject('unsupported_id', 'This type of identification is not accepted. Please upload a supported government-issued ID.')
    allowed_paper = ((kind == 'voters_certification' and 'voter' in title.lower()) or
                     (kind == 'nbi_clearance' and 'nbi' in title.lower() and 'clearance' in title.lower()) or
                     (kind == 'police_clearance' and 'police' in title.lower() and 'clearance' in title.lower()))
    if NON_ID_PAPERS.search(title) and not allowed_paper:
        return reject('unsupported_id', 'This type of identification is not accepted. Please upload a supported government-issued ID.')
    if not front['is_id'] or kind == 'not_an_id':
        return reject('retake', 'The uploaded file does not appear to be a valid identification document.')
    if kind == 'unknown_id':
        return reject('unknown_id', "We couldn't verify this as a supported government-issued ID. Please upload another valid ID.")
    if kind not in SUPPORTED_IDS:
        return reject('unsupported_id', 'This type of identification is not accepted. Please upload a supported government-issued ID.')
    if kind == 'other_government_id' and (title.lower() in {'', 'other government id', 'government id'} or
                                          not re.search(r'\b(id|identification|identity card)\b', title, re.I)):
        return reject('unknown_id', "We couldn't verify this as a supported government-issued ID. Please upload another valid ID.")
    markers = ISSUER_MARKERS.get(kind, ())
    issuer_valid = (issuer.lower() in OTHER_GOVERNMENT_ISSUERS if kind == 'other_government_id'
                    else any(marker in issuer.lower() for marker in markers))
    if (front['confidence'] != 'high' or not issuer or not issuer_valid or
            not (front['is_supported_id'] or philsys_evidence)):
        return reject('unknown_id', "We couldn't verify this as a supported government-issued ID. Please upload another valid ID.")
    if front['readable'] != 'clear':
        return reject('retake', "We couldn't clearly read the ID. Please upload a clear photo showing the entire ID.")
    names = {key: front[key] for key in ('last_name', 'given_names', 'middle_name')}
    result = {
        **classification, 'is_supported_id': True, 'rejection_reason': None,
        **names, 'full_name': ' '.join(part for part in (names['given_names'], names['middle_name'], names['last_name']) if part),
        'birth_date': front['birth_date'], 'expiration_date': front['expiry_date'],
        'expired': front['expired'], 'flags': [],
    }
    if not names['last_name'] or not names['given_names']:
        return reject('retake', "We couldn't clearly read the ID. Please upload a clear photo showing the entire ID.")
    single_sided = kind in {'voters_certification', 'nbi_clearance', 'police_clearance'}
    matched_second_view = single_sided and back['is_same_document_as_front']
    matched_card_back = back['is_back_of_id'] and back['same_card_as_front'] == 'yes'
    if not (matched_second_view or matched_card_back):
        if single_sided:
            return reject('retake', 'The second photo must show the same clearance or certification. Please upload another clear view of that document.')
        return reject('retake', "The second photo doesn't look like the back of the same ID. Upload the back of the same card.")
    if back['readable'] == 'unreadable':
        return reject('retake', "We couldn't clearly read the ID. Please upload a clear photo showing the entire ID.")
    if local.get('typed_on_text') or reading['edited']['state'] == 'fail' or reading['sample_or_screenshot']['detected']:
        return reject('edited', 'This ID photo looks edited, or it is not a photo of a real card. Take a new photo of your original ID.')
    if reading['edited']['state'] == 'review':
        return reject('retake', "We couldn't clearly verify this ID photo. Please upload a clear photo of the original ID.")
    if front['expired']:
        result['flags'].append('expired')
    if local.get('design_app'):
        result['flags'].append('saved_with_editing_app')
    return {**result, 'decision': 'ok', 'message': 'We read your ID. Please check that the details below are correct.'}


def _shrink(image):
    height, width = image.shape[:2]
    scale = LOCAL_CHECK_MAX_EDGE / max(height, width)
    if scale >= 1:
        return image
    return cv2.resize(image, (round(width * scale), round(height * scale)), interpolation=cv2.INTER_AREA)


def _text_line_boxes(image) -> list[list[int]]:
    """One fast Tesseract pass: boxes of lines read confidently as real words."""
    import pytesseract
    from .ocr_engine import _configure_tesseract
    _configure_tesseract(pytesseract, SETTINGS.tesseract_path)
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    data = pytesseract.image_to_data(gray, config='--psm 11', output_type=pytesseract.Output.DICT, timeout=15)
    lines: dict = {}
    for index, word in enumerate(data.get('text', [])):
        confidence = float(data['conf'][index])
        if not str(word).strip() or confidence < 0:
            continue
        key = (data['block_num'][index], data['par_num'][index], data['line_num'][index])
        x, y, w, h = data['left'][index], data['top'][index], data['width'][index], data['height'][index]
        line = lines.setdefault(key, {'box': [x, y, x + w, y + h], 'text': '', 'conf': []})
        line['box'] = [min(line['box'][0], x), min(line['box'][1], y), max(line['box'][2], x + w), max(line['box'][3], y + h)]
        line['text'] += str(word)
        line['conf'].append(confidence)
    return [line['box'] for line in lines.values()
            if float(np.mean(line['conf'])) >= 70 and sum(character.isalnum() for character in line['text']) >= 4]


def _upright_with_text_lines(image):
    """Phones often photograph a landscape card sideways; try both quarter turns
    when the upright pass finds little text, and keep the best orientation."""
    best_image, best_boxes = image, _text_line_boxes(image)
    if len(best_boxes) >= 3:
        return best_image, best_boxes
    for rotation in (cv2.ROTATE_90_COUNTERCLOCKWISE, cv2.ROTATE_90_CLOCKWISE):
        turned = cv2.rotate(image, rotation)
        boxes = _text_line_boxes(turned)
        if len(boxes) > len(best_boxes):
            best_image, best_boxes = turned, boxes
    return best_image, best_boxes


def _local_checks(paths: list[Path], images: list | None = None) -> dict:
    """Pixel and metadata checks shared with document review (no network)."""
    from .tamper_detection.file_inspection import inspect_file
    from .tamper_detection.overlay import detect_digital_text_overlay
    typed_on, design_app = False, None
    for index, path in enumerate(paths):
        try:
            image = images[index] if images else load_images(path, 1, '', 60, 0)[0]
            upright, boxes = _upright_with_text_lines(_shrink(image))
            typed_on = typed_on or bool(detect_digital_text_overlay(upright, 1, boxes))
            facts = inspect_file(path).get('facts') or {}
            if facts.get('design_app'):
                design_app = facts.get('editing_software')
        except Exception:  # an optional check must never block sign-up
            logger.warning('ID local check unavailable')
    return {'typed_on_text': typed_on, 'design_app': design_app}


def _unavailable(error_code: str) -> dict:
    return {'status': 'unavailable', 'decision': 'unavailable', 'error_code': error_code,
            'message': "We couldn't read your ID right now. Please try again in a moment.",
            'flags': []}


def check_id(settings, front: Path, back: Path, client=None, local_checks=None) -> dict:
    started = time.monotonic()
    if not gemini_scanner_configured(settings):
        return _unavailable('AICS_AI_NOT_CONFIGURED')
    try:
        images = [load_images(Path(path), 1, '', 60, 0)[0] for path in (front, back)]
        urls = [prepare_image_data_url(image) for image in images]
    except (ExternalAiImageError, RuntimeError, IndexError):
        return {**_unavailable('ID_IMAGE_INVALID'), 'decision': 'retake',
                'message': "We couldn't open one of the photos. Upload JPG or PNG photos of the front and back of your ID."}
    paths = [Path(front), Path(back)]
    run_local = local_checks or (lambda checked_paths: _local_checks(checked_paths, images))
    request = json.dumps({'task': 'Read this ID for account sign-up.',
                          'today': datetime.now(timezone(timedelta(hours=8))).strftime('%Y-%m-%d')})
    provider = client or GeminiScannerClient(settings)
    with ThreadPoolExecutor(max_workers=1) as pool:
        local_future = pool.submit(run_local, paths)  # runs while AICS AI reads the ID
        reading = provider.scan(request, urls, 2, instruction=INSTRUCTION, schema=response_schema(), normalizer=normalize_reading)
        try:
            local = local_future.result(timeout=LOCAL_CHECK_TIMEOUT)
        except Exception:
            local = {'typed_on_text': False, 'design_app': None}
    if reading.get('status') != 'completed':
        return _unavailable(str(reading.get('error_code') or 'AICS_AI_UNAVAILABLE'))
    result = decide(reading['scan'], local)
    return {'status': 'completed', **result, 'model': str(reading.get('model') or ''),
            'elapsed_ms': max(0, round((time.monotonic() - started) * 1000))}


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description='Read the front and back of an ID for sign-up.')
    parser.add_argument('--front', required=True)
    parser.add_argument('--back', required=True)
    args = parser.parse_args(argv)
    logging.basicConfig(level=logging.WARNING, stream=sys.stderr)
    print(json.dumps(check_id(SETTINGS, Path(args.front), Path(args.back)), ensure_ascii=False))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
