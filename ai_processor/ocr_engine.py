import logging
from dataclasses import dataclass
from pathlib import Path
import re
import time

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class OcrResult:
    candidate_name: str
    text: str
    confidence: float
    metadata: dict
    boxes: list


@dataclass(frozen=True)
class CandidateScore:
    total: float
    ocr_confidence: float
    anchor_coverage: float
    negative_penalty: float
    required_field_coverage: float
    coherent_line_ratio: float


@dataclass(frozen=True)
class OcrEvidence:
    candidate_name: str
    text: str
    confidence: float
    boxes: list
    fields: dict
    score: CandidateScore


@dataclass(frozen=True)
class TransformMetadata:
    """Maps OCR candidate coordinates back to the uploaded image."""
    original_width: int
    original_height: int
    crop_box: tuple[int, int, int, int]
    rotation_degrees: int = 0
    scale: float = 1.0

    def to_original(self, box) -> tuple[int, int, int, int]:
        x1, y1, x2, y2 = [float(value) / max(self.scale, .0001) for value in box]
        left, top, right, bottom = self.crop_box
        crop_width, crop_height = right - left, bottom - top
        points = ((x1, y1), (x2, y1), (x2, y2), (x1, y2))
        if self.rotation_degrees == -90:
            points = tuple((crop_width - y, x) for x, y in points)
        elif self.rotation_degrees == 90:
            points = tuple((y, crop_height - x) for x, y in points)
        elif abs(self.rotation_degrees) == 180:
            points = tuple((crop_width - x, crop_height - y) for x, y in points)
        xs = [min(max(x + left, 0), self.original_width) for x, _ in points]
        ys = [min(max(y + top, 0), self.original_height) for _, y in points]
        return tuple(round(value) for value in (min(xs), min(ys), max(xs), max(ys)))


@dataclass(frozen=True)
class OcrCandidate:
    name: str
    image: object
    transform: TransformMetadata
    config: str = '--psm 11'

# Extraction-only ROI profiles.  They intentionally contain no identifiers,
# financial values, diagnoses, narratives, or other privacy-excluded fields.
ROI_EXTRACTION_PROFILES = {
    'valid_id': {'identity_fields': ('name', 'birth_date', 'address'), 'anchors': {'name': ('apelyido', 'mga pangalan', 'given names', 'gitnang apelyido', 'middle name', 'last name'), 'birth_date': ('petsa ng kapanganakan', 'date of birth', 'birth date'), 'address': ('tirahan', 'address')}},
    'medical_certificate': {'identity_fields': ('patient_name', 'birth_date'), 'anchors': {'patient_name': ('patient name', 'name of patient', 'patient'), 'birth_date': ('date of birth', 'birth date', 'dob')}},
    'medical_abstract': {'identity_fields': ('patient_name', 'birth_date'), 'anchors': {'patient_name': ('patient information', 'patient name', 'name of patient', 'patient'), 'birth_date': ('date of birth', 'birth date', 'dob')}},
    'billing_statement': {'identity_fields': ('patient_name',), 'anchors': {'patient_name': ('patient name', 'name of patient', 'patient')}},
    'prescription': {'identity_fields': ('patient_name',), 'anchors': {'patient_name': ('patient name', 'name of patient', 'patient')}},
    'death_certificate': {'identity_fields': ('deceased_name',), 'anchors': {'deceased_name': ('name of deceased', 'deceased name', 'name of the deceased')}},
    'funeral_contract': {'identity_fields': ('deceased_name',), 'anchors': {'deceased_name': ('name of deceased', 'name of decedent', 'deceased', 'for the funeral of', 'funeral services for')}},
    'police_report': {'identity_fields': ('subject_name',), 'anchors': {'subject_name': ('victim name', 'name of victim', 'victim', 'injured person', 'person assisted', 'person served')}},
    'medical_report': {'identity_fields': ('subject_name',), 'anchors': {'subject_name': ('patient name', 'name of patient', 'name of examinee', 'examinee', 'patient')}},
    'school_assessment': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('student name', 'name of student', 'learner name', 'name of learner')}},
    'school_id': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('student name', 'name of student', 'learner name', 'name')}},
    'registration_form': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('student name', 'name of student', 'learner name', 'name of learner', 'student')}},
    'drrm_damage_certification': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('name of affected person', 'affected person', 'name of beneficiary', 'beneficiary', 'name of displaced person')}},
    'drrm_displacement_certification': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('name of displaced person', 'displaced person', 'name of affected person', 'affected person', 'beneficiary')}},
    'death_cert': {'identity_fields': ('deceased_name',), 'anchors': {'deceased_name': ('name of deceased', 'deceased name')}},
    'funeral_contract': {'identity_fields': ('deceased_name',), 'anchors': {'deceased_name': ('name of deceased', 'deceased', 'for the funeral of')}},
    'report_police_med': {'identity_fields': ('subject_name',), 'anchors': {'subject_name': ('victim name', 'victim', 'injured person', 'patient name', 'name of examinee')}},
    'school_assessment': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('student name', 'learner name', 'name of student')}},
    'registration_form': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('student name', 'learner name', 'name of learner')}},
    'school_id': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('student name', 'learner name', 'name')}},
    'mdrrmo_cert': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('name of affected person', 'affected person', 'displaced person', 'beneficiary')}},
    # These are deliberately separate from the canonical upload-slot names.
    # Both slots expose only the certified beneficiary, never officer, case, or
    # narrative data.  A bare ``Name`` is intentionally not an ROI anchor.
    'eclip_certification': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('name of beneficiary', 'beneficiary name', 'beneficiary', 'client name')}},
    'person_in_crisis_certification': {'identity_fields': ('client_name',), 'anchors': {'client_name': ('name of beneficiary', 'beneficiary name', 'beneficiary', 'client name')}},
}


def resolve_roi_profile(expected_type: str, classification: dict) -> str | None:
    """Resolve only a classifier-confirmed, canonical extraction profile."""
    detected = str(classification.get('detected_type') or '')
    subtype = str(classification.get('document_subtype') or '')
    if classification.get('confidence_band') != 'high' or detected != expected_type:
        return None
    if detected == 'med_cert' and subtype in ('medical_certificate', 'medical_abstract'):
        return subtype
    if detected == 'billing_statement' and subtype in ('billing_statement', 'prescription'):
        return subtype
    if detected == 'death_cert' and subtype in ('municipal_form_103', 'psa_death_certificate', 'civil_registry_death_certificate', 'death_certificate'):
        return 'death_certificate'
    if detected == 'funeral_contract' and subtype in ('funeral_contract', 'funeral_service_contract'):
        return 'funeral_contract'
    if detected == 'report_police_med' and subtype in ('police_report', 'medical_report'):
        return subtype
    if detected == 'school_assessment' and subtype in ('school_assessment', 'enrollment_assessment', 'senior_high_school_assessment'):
        return 'school_assessment'
    if detected == 'school_id' and subtype == 'school_id':
        return 'school_id'
    if detected == 'registration_form' and subtype in ('deped_basic_education_enrollment_form', 'private_school_registration_form', 'enrollment_form'):
        return 'registration_form'
    if detected == 'mdrrmo_cert' and subtype in ('damage_certification', 'house_damage_certification'):
        return 'drrm_damage_certification'
    if detected == 'mdrrmo_cert' and subtype == 'displacement_certification':
        return 'drrm_displacement_certification'
    # The classifier's canonical Stage-5 identifiers are slot families, rather
    # than issuer-specific subtypes.  Keep their extraction geometry distinct.
    if detected == 'eclip_cert':
        return 'eclip_certification'
    if detected == 'crisis_situation_cert':
        return 'person_in_crisis_certification'
    if detected in ('med_cert', 'billing_statement'):
        return None
    return detected if detected in ROI_EXTRACTION_PROFILES else None


def _credible_roi_anchors(metadata: dict, profile_key: str, field: str) -> list[dict]:
    """Return exact, high-confidence safe-field anchors from bounded passes."""
    labels = ROI_EXTRACTION_PROFILES.get(profile_key, {}).get('anchors', {}).get(field, ())
    if not labels:
        return []
    pages = list((metadata or {}).get('pages', []))
    for candidate in (metadata or {}).get('field_candidates', []):
        if isinstance(candidate, dict):
            pages.extend(candidate.get('pages', []))
    matches = []
    seen = set()
    for page in pages:
        for line in page.get('lines', []):
            text = re.sub(r'\s+', ' ', str(line.get('text', '')).lower()).strip()
            confidence = float(line.get('confidence', 0.0) or 0.0)
            # A bounded ROI must begin at an unambiguous printed field label,
            # never a narrative mention of the same word.
            if confidence < 60 or not any(re.match(r'^' + re.escape(label) + r'\s*(?::|-|$)', text) for label in labels):
                continue
            box = line.get('box', [])
            marker = (tuple(box), text)
            if len(box) == 4 and marker not in seen:
                seen.add(marker)
                matches.append({'page': page, 'line': line})
    return matches


def resolve_safe_roi_fields(expected_type: str, classification: dict, metadata: dict,
                            weak_fields: set[str]) -> tuple[str | None, set[str], dict]:
    """Permit only anchored safe-field ROI when a Valid ID family is evident.

    The legacy high-confidence resolver remains unchanged.  The narrow
    fallback below exists for a weak value on an otherwise clearly labelled
    Valid ID; it never guesses an unrelated document profile.
    """
    profile = resolve_roi_profile(expected_type, classification)
    if profile:
        return profile, set(weak_fields), {'mode': 'classification_high', 'eligible_fields': sorted(weak_fields)}
    if expected_type != 'valid_id':
        return None, set(), {'mode': 'not_eligible', 'reason': 'classification_not_high'}
    all_text = '\n'.join([str(page.get('text', '')) for page in (metadata or {}).get('pages', [])] + [str(candidate.get('text', '')) for candidate in (metadata or {}).get('field_candidates', []) if isinstance(candidate, dict)]).lower()
    family_markers = ('philippine identification', 'philsys', 'republic of the philippines', 'philippine statistics authority', 'pambansang pagkakakilanlan')
    marker_count = sum(marker in all_text for marker in family_markers)
    if marker_count < 2:
        return None, set(), {'mode': 'not_eligible', 'reason': 'insufficient_valid_id_family_markers', 'family_marker_count': marker_count}
    allowed = set(ROI_EXTRACTION_PROFILES['valid_id']['identity_fields'])
    eligible = {field for field in weak_fields if field in allowed and _credible_roi_anchors(metadata, 'valid_id', field)}
    if not eligible:
        return None, set(), {'mode': 'not_eligible', 'reason': 'no_credible_safe_field_anchor', 'family_marker_count': marker_count}
    return 'valid_id', eligible, {'mode': 'valid_id_anchor_fallback', 'family_marker_count': marker_count, 'eligible_fields': sorted(eligible)}

def _configure_tesseract(pytesseract, tesseract_path: str):
    raw_tesseract_path = str(tesseract_path or '').strip()
    resolved_tesseract_path = Path(raw_tesseract_path).expanduser().resolve() if raw_tesseract_path else None
    tesseract_exists = bool(resolved_tesseract_path and resolved_tesseract_path.is_file())
    logger.info('AI OCR diagnostics: tesseract_configured=%s tesseract_exists=%s', 'yes' if raw_tesseract_path else 'no', 'yes' if tesseract_exists else 'no')
    if not raw_tesseract_path or not tesseract_exists:
        raise RuntimeError('AI_TESSERACT_CONFIG_INVALID')
    pytesseract.pytesseract.tesseract_cmd = str(resolved_tesseract_path)


def _page_from_data(data: dict, page_number: int, scale: float = 1.0, offset: tuple[int, int] = (0, 0)) -> tuple[str, list[float], dict]:
    lines, line_records, current_key, words, word_records, confidences, ocr_boxes = [], [], None, [], [], [], []
    for index, raw_word in enumerate(data.get('text', [])):
        word = str(raw_word).strip()
        if not word:
            continue
        confidence = 0.0
        key = tuple(data.get(part, [0] * len(data.get('text', [])))[index] for part in ('block_num', 'par_num', 'line_num'))
        if current_key is not None and key != current_key and words:
            lines.append(' '.join(words))
            boxes = [record['box'] for record in word_records]
            line_records.append({'text': ' '.join(words), 'box': [min(box[0] for box in boxes), min(box[1] for box in boxes), max(box[2] for box in boxes), max(box[3] for box in boxes)], 'confidence': round(sum(record['confidence'] for record in word_records) / len(word_records), 2)})
            words, word_records = [], []
        current_key = key
        words.append(word)
        try:
            confidence = float(data.get('conf', [])[index])
            if confidence >= 0:
                confidences.append(confidence)
        except (ValueError, TypeError, IndexError):
            pass
        # Tesseract's image_to_data coordinates are word-level and relative to
        # the original page image. Preserve them without changing OCR text or
        # confidence semantics; downstream preprocessing owns any resizing.
        try:
            left = int(data.get('left', [])[index]); top = int(data.get('top', [])[index])
            width = int(data.get('width', [])[index]); height = int(data.get('height', [])[index])
            if left >= 0 and top >= 0 and width > 0 and height > 0:
                offset_x, offset_y = offset
                box = [round(left / scale + offset_x), round(top / scale + offset_y), round((left + width) / scale + offset_x), round((top + height) / scale + offset_y)]
                ocr_boxes.append(box)
                word_records.append({'text': word, 'box': box, 'confidence': confidence if confidence >= 0 else 0.0})
        except (ValueError, TypeError, IndexError):
            pass
    if words:
        lines.append(' '.join(words))
        boxes = [record['box'] for record in word_records]
        if boxes:
            line_records.append({'text': ' '.join(words), 'box': [min(box[0] for box in boxes), min(box[1] for box in boxes), max(box[2] for box in boxes), max(box[3] for box in boxes)], 'confidence': round(sum(record['confidence'] for record in word_records) / len(word_records), 2)})
    text = '\n'.join(lines)
    return text, confidences, {'page_number': page_number, 'text': text, 'line_count': len(lines), 'ocr_boxes': ocr_boxes, 'lines': line_records}

def detect_document_boundary(image):
    """Return a conservative four-corner page boundary, or ``None``.

    This deliberately rejects ambiguous desk/background contours. It is an OCR
    candidate aid only: callers never replace the source image or ADCD input.
    """
    import cv2
    import numpy as np
    if image is None or len(image.shape) < 2:
        return None
    height, width = image.shape[:2]
    if height < 240 or width < 240:
        return None
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)
    edges = cv2.Canny(blurred, 45, 140)
    edges = cv2.morphologyEx(edges, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8), iterations=2)
    contours, _ = cv2.findContours(edges, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    image_area = float(height * width)
    for contour in sorted(contours, key=cv2.contourArea, reverse=True)[:12]:
        area = cv2.contourArea(contour)
        if area < image_area * .35 or area > image_area * .985:
            continue
        polygon = cv2.approxPolyDP(contour, .02 * cv2.arcLength(contour, True), True)
        if len(polygon) != 4 or not cv2.isContourConvex(polygon):
            continue
        points = polygon.reshape(4, 2).astype('float32')
        rect = cv2.minAreaRect(points)
        short, long = sorted(rect[1])
        if short < min(height, width) * .35 or not short or long / short > 2.35:
            continue
        return points
    return None


def _ordered_corners(points):
    import numpy as np
    ordered = np.zeros((4, 2), dtype='float32')
    sums, differences = points.sum(axis=1), np.diff(points, axis=1).reshape(-1)
    ordered[0], ordered[2] = points[sums.argmin()], points[sums.argmax()]
    ordered[1], ordered[3] = points[differences.argmin()], points[differences.argmax()]
    return ordered


def _rectified_or_deskewed(image):
    import cv2
    import numpy as np
    boundary = detect_document_boundary(image)
    if boundary is not None:
        corners = _ordered_corners(boundary)
        tl, tr, br, bl = corners
        top, bottom = np.linalg.norm(tr - tl), np.linalg.norm(br - bl)
        left, right = np.linalg.norm(bl - tl), np.linalg.norm(br - tr)
        # A plain rectangular scan (even one with a visible page border) gains
        # nothing from a perspective pass.  Reserve it for an actual trapezoid;
        # the bounded deskew path below handles ordinary slight rotation.
        perspective_ratio = max(top, bottom) / max(1.0, min(top, bottom))
        perspective_ratio = max(perspective_ratio, max(left, right) / max(1.0, min(left, right)))
        target_w = int(max(bottom, top))
        target_h = int(max(right, left))
        if perspective_ratio >= 1.08 and target_w >= 240 and target_h >= 240:
            matrix = cv2.getPerspectiveTransform(corners, np.array([[0, 0], [target_w - 1, 0], [target_w - 1, target_h - 1], [0, target_h - 1]], dtype='float32'))
            return cv2.warpPerspective(image, matrix, (target_w, target_h), borderValue=(255, 255, 255)), 'rectified'
    # Mild deskew only; text/photos with an unstable or large angle stay put.
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
    coords = np.column_stack(np.where(gray < 180))
    if len(coords) < 500:
        return None, None
    angle = cv2.minAreaRect(coords.astype('float32'))[-1]
    angle = angle + 90 if angle < -45 else angle
    if not (0.6 <= abs(angle) <= 8.0):
        return None, None
    h, w = image.shape[:2]
    matrix = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
    return cv2.warpAffine(image, matrix, (w, h), flags=cv2.INTER_CUBIC, borderValue=(255, 255, 255)), 'deskewed'


def _base_ocr_candidates(image):
    """A small deterministic set for ordinary forms and cards."""
    import cv2
    height, width = image.shape[:2]
    # Preserve native detail in the first pass, but bound the enhanced passes
    # so a large phone photo cannot spend the whole OCR budget in PSM 11.
    scale = 2.0 if max(height, width) < 2400 else min(1.0, 2400 / max(height, width))
    working = cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC) if scale != 1.0 else image
    gray = cv2.cvtColor(working, cv2.COLOR_BGR2GRAY)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
    sharp = cv2.addWeighted(clahe, 1.35, cv2.GaussianBlur(clahe, (0, 0), 1.0), -0.35, 0)
    # Keep exactly three bounded passes. The native original preserves camera
    # lettering that can degrade when enlarged; CLAHE and sharpened retain the
    # enlarged representation. This replaces—not adds to—the former enlarged
    # PSM-6 original pass.
    return [(image, 1.0, '--psm 6', 'original'), (clahe, scale, '--psm 11', 'clahe'), (sharp, scale, '--psm 11', 'sharpened')]


def _document_region(image):
    """Crop a credible rectangular document from a larger phone frame.

    The crop is OCR-only and every downstream box is translated back to the
    original image coordinates, so ROI and ADCD alignment remain unchanged.
    """
    import cv2
    boundary = detect_document_boundary(image)
    if boundary is None:
        return image, (0, 0), {'applied': False, 'reason': 'no_credible_boundary'}
    x, y, width, height = cv2.boundingRect(boundary.astype('int32'))
    image_h, image_w = image.shape[:2]
    area_ratio = (width * height) / max(1, image_h * image_w)
    if area_ratio < .35 or area_ratio > .94:
        return image, (0, 0), {'applied': False, 'reason': 'crop_not_material'}
    padding = max(4, int(min(width, height) * .015))
    left, top = max(0, x - padding), max(0, y - padding)
    right, bottom = min(image_w, x + width + padding), min(image_h, y + height + padding)
    if right - left < 240 or bottom - top < 240:
        return image, (0, 0), {'applied': False, 'reason': 'crop_too_small'}
    return image[top:bottom, left:right], (left, top), {
        'applied': True, 'box': [left, top, right, bottom], 'area_ratio': round(area_ratio, 3),
    }


def _quarter_turn_for_text_lines(image) -> int:
    """Return a conservative quarter-turn hint from strong line layout."""
    import cv2
    import numpy as np
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
    foreground = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)[1]
    height, width = foreground.shape[:2]
    horizontal_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (max(18, width // 18), 1))
    vertical_kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(18, height // 18)))
    horizontal = np.count_nonzero(cv2.morphologyEx(foreground, cv2.MORPH_OPEN, horizontal_kernel))
    vertical = np.count_nonzero(cv2.morphologyEx(foreground, cv2.MORPH_OPEN, vertical_kernel))
    return -90 if vertical > max(600, horizontal * 1.35) else 0


def prepare_document_candidates(image) -> list[OcrCandidate]:
    """Build at most five independent OCR candidates with inverse transforms."""
    import cv2
    if image is None or len(image.shape) < 2:
        return []
    original_height, original_width = image.shape[:2]
    working, offset, crop = _document_region(image)
    working = working.copy()
    left, top = offset
    crop_box = tuple(crop.get('box', (left, top, left + working.shape[1], top + working.shape[0])))

    def variants(source, rotation=0, prefix=''):
        height, width = source.shape[:2]
        scale = 2.0 if max(height, width) < 2400 else 1.0
        enlarged = cv2.resize(source, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC) if scale != 1.0 else source.copy()
        gray = cv2.cvtColor(enlarged, cv2.COLOR_BGR2GRAY) if len(enlarged.shape) == 3 else enlarged.copy()
        clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
        transform = TransformMetadata(original_width, original_height, crop_box, rotation, scale)
        sharpened = cv2.addWeighted(clahe, 1.35, cv2.GaussianBlur(clahe, (0, 0), 1.0), -.35, 0)
        return [
            OcrCandidate(prefix + 'original', enlarged.copy(), transform, '--psm 6'),
            OcrCandidate(prefix + 'clahe', clahe.copy(), transform, '--psm 11'),
            OcrCandidate(prefix + 'sharpened', sharpened.copy(), transform, '--psm 11'),
        ]

    candidates = variants(working)
    rotation = _quarter_turn_for_text_lines(working)
    if rotation:
        corrected = cv2.rotate(working, cv2.ROTATE_90_COUNTERCLOCKWISE)
        candidates.extend(variants(corrected, rotation, 'oriented_')[:2])
    return candidates[:5]


def _ocr_candidates(image):
    working, offset, crop = _document_region(image)
    candidates = [(candidate, scale, config, offset, name, None, crop)
                  for candidate, scale, config, name in _base_ocr_candidates(working)]
    transformed, kind = _rectified_or_deskewed(working)
    if transformed is not None:
        # One additional full-page candidate keeps camera-photo OCR bounded.
        transformed_candidates = _base_ocr_candidates(transformed)
        candidate, scale, _, _ = next(item for item in transformed_candidates if item[3] == 'clahe')
        # Perspective transforms change geometry, so keep their boxes local to
        # the crop. They are selected for text only; ROI stays on the original.
        candidates.append((candidate, scale, '--psm 6', offset, kind, kind, crop))
    return candidates

def _candidate_score(text: str, confidences: list[float]) -> float:
    normalized = re.sub(r'\s+', ' ', text.lower())
    markers = ('republika ng pilipinas', 'pambansang pagkakakilanlan', 'philippine identification', 'last name', 'given names', 'mga pangalan', 'tirahan', 'address', 'date of birth', 'medical certificate', 'medical abstract', 'billing statement', 'prescription', 'certificate of death', 'funeral contract', 'name of deceased', 'police report', 'medical report', 'student name', 'learner name', 'affected person', 'displaced person', 'beneficiary')
    confidence = sum(confidences) / len(confidences) if confidences else 0
    tokens = re.findall(r"[A-Za-z0-9]+", text)
    meaningful = [token for token in tokens if len(token) >= 2]
    noisy = len(re.findall(r'[^\w\s.,:/-]', text))
    short = sum(len(token) == 1 for token in tokens)
    repeated = len(tokens) - len(set(token.lower() for token in tokens))
    broken = sum(len(token) > 18 or (len(token) >= 6 and sum(char.isupper() for char in token) not in (0, len(token))) for token in tokens)
    coherent = sum(bool(re.search(r'[A-Za-z]{3,}', line)) and len(re.findall(r'[A-Za-z0-9]+', line)) <= 18 for line in text.splitlines())
    orphan_lines = sum(1 for line in text.splitlines() if len(re.findall(r'[A-Za-z0-9]+', line)) <= 1 and len(re.sub(r'[^A-Za-z0-9]', '', line)) < 8)
    lines = [line for line in text.splitlines() if re.search(r'[A-Za-z0-9]{2}', line)]
    return (confidence + min(len(meaningful), 80) * .35 + min(len(lines), 25) * .5
            + min(coherent, 20) * .45 + sum(marker in normalized for marker in markers) * 18
            - noisy * 1.3 - short * .8 - max(0, repeated - 3) * .4 - broken * 1.1 - orphan_lines * 2.5)


def score_ocr_candidate(text: str, confidence: float, profile, fields: dict) -> CandidateScore:
    """Score OCR evidence by its expected document profile, not confidence alone."""
    normalized = re.sub(r'\s+', ' ', (text or '').lower())
    groups = tuple(profile.positive_anchor_groups or ())
    matched_groups = sum(any(anchor in normalized for anchor in group) for group in groups)
    anchor_coverage = matched_groups / max(1, len(groups))
    negative_hits = sum(anchor in normalized for anchor in profile.negative_anchors)
    negative_penalty = min(1.0, negative_hits / max(1, len(profile.negative_anchors)))
    required = tuple(profile.required_fields or ())
    present = sum(
        isinstance(fields.get(field), dict)
        and fields[field].get('status') == 'extracted'
        and bool(fields[field].get('reliable'))
        for field in required
    )
    required_coverage = present / max(1, len(required)) if required else 1.0
    lines = [line.strip() for line in (text or '').splitlines() if line.strip()]
    coherent = sum(bool(re.search(r'[A-Za-z]{3,}', line)) and len(re.findall(r'\w+', line)) <= 24 for line in lines)
    coherent_ratio = coherent / max(1, len(lines))
    normalized_confidence = min(1.0, max(0.0, float(confidence) / 100.0))
    total = (normalized_confidence * .18 + anchor_coverage * .44
             + required_coverage * .25 + coherent_ratio * .13
             - negative_penalty * .40)
    return CandidateScore(round(total, 4), round(normalized_confidence, 4),
                          round(anchor_coverage, 4), round(negative_penalty, 4),
                          round(required_coverage, 4), round(coherent_ratio, 4))


def select_ocr_evidence(candidates: list[OcrResult], profile) -> OcrEvidence:
    """Select a supported OCR candidate and conservatively merge safe fields."""
    if not candidates:
        raise ValueError('At least one OCR candidate is required.')
    from .field_extractor import extract_document_fields

    assessed = []
    for candidate in candidates:
        extracted = extract_document_fields(profile.code, candidate.text, candidate.metadata or {})['fields']
        score = score_ocr_candidate(candidate.text, candidate.confidence, profile, extracted)
        assessed.append((score.total, candidate, extracted, score))
    _, winner, fields, winner_score = max(assessed, key=lambda item: item[0])
    merged = {key: dict(value) if isinstance(value, dict) else value for key, value in fields.items()}
    for field_name in profile.extractable_fields:
        values = []
        for _, candidate, candidate_fields, candidate_score in assessed:
            field = candidate_fields.get(field_name)
            if not isinstance(field, dict) or field.get('status') != 'extracted' or not field.get('reliable'):
                continue
            normalized_value = field.get('normalized')
            if normalized_value in (None, ''):
                continue
            values.append((str(normalized_value), candidate.candidate_name, candidate_score.total))
        distinct = {value for value, _, _ in values}
        if len(distinct) > 1:
            current = dict(merged.get(field_name) or {})
            current.update({'value': None, 'normalized': None, 'confidence': 0.0,
                            'status': 'uncertain', 'reliable': False,
                            'reason_if_unreliable': 'conflicting_ocr_candidates',
                            'provenance': sorted({name for _, name, _ in values})})
            merged[field_name] = current
        elif values:
            current = dict(merged.get(field_name) or {})
            current['provenance'] = sorted({name for _, name, _ in values})
            merged[field_name] = current
    return OcrEvidence(winner.candidate_name, winner.text, winner.confidence,
                       list(winner.boxes or []), merged, winner_score)


def retry_philid_field_rois(images, metadata: dict, tesseract_path: str, weak_fields: set[str], timeout_seconds: int = 45, profile_key: str = 'valid_id') -> dict:
    """Bounded label-relative retries for weak PhilID fields only.

    ROI results are extraction-only metadata: the original OCR boxes remain
    unchanged for ADCD alignment.
    """
    if not images or not weak_fields:
        return metadata
    try:
        import cv2, pytesseract
        _configure_tesseract(pytesseract, tesseract_path)
        labels = ROI_EXTRACTION_PROFILES.get(profile_key, {}).get('anchors', {
            'name': ('apelyido', 'mga pangalan', 'given names', 'gitnang apelyido', 'middle name', 'last name'),
            'birth_date': ('petsa ng kapanganakan', 'date of birth', 'birth date'),
            'address': ('tirahan', 'address'),
        })
        results = dict(metadata.get('roi_values') or metadata.get('philid_roi_values') or {})
        calls = 0
        call_metrics = list(metadata.get('roi_call_metrics') or [])
        roi_pages = list(metadata.get('pages', []))
        for candidate in metadata.get('field_candidates', []):
            if isinstance(candidate, dict):
                roi_pages.extend(candidate.get('pages', []))
        seen_anchor_regions = set()
        for page in roi_pages:
            page_index = max(0, int(page.get('page_number', 1)) - 1)
            if page_index >= len(images): continue
            image = images[page_index]; image_h, image_w = image.shape[:2]
            for field in weak_fields:
                if field in results or field not in labels: continue
                for line in page.get('lines', []):
                    text = str(line.get('text', '')).lower()
                    if not any(label in text for label in labels[field]): continue
                    # A certificate sentence can legitimately contain the word
                    # "beneficiary".  It is not a label region, so never crop
                    # it as one in the two Stage-5 certificate profiles.
                    if profile_key in ('eclip_certification', 'person_in_crisis_certification'):
                        compact = re.sub(r'\s+', ' ', text).strip()
                        if not any(re.match(r'^' + re.escape(label) + r'\s*(?::|-|$)', compact) for label in labels[field]):
                            continue
                    box = line.get('box', [])
                    if len(box) != 4: continue
                    x1, y1, x2, y2 = [int(v) for v in box]; height = max(1, y2 - y1)
                    anchor_region = (field, page_index, x1, y1, x2, y2)
                    if anchor_region in seen_anchor_regions:
                        continue
                    seen_anchor_regions.add(anchor_region)
                    top, bottom = max(0, y2), min(image_h, y2 + height * (8 if field == 'address' else 3))
                    left, right = max(0, x1 - height), min(image_w, max(x2 + height * 10, int(image_w * .75)))
                    if right - left < 24 or bottom - top < 12: continue
                    roi = image[top:bottom, left:right]
                    roi = cv2.resize(roi, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
                    gray = cv2.cvtColor(roi, cv2.COLOR_BGR2GRAY)
                    clahe = cv2.createCLAHE(clipLimit=1.5, tileGridSize=(8, 8)).apply(gray)
                    sharp = cv2.addWeighted(clahe, 1.3, cv2.GaussianBlur(clahe, (0, 0), 1.0), -0.3, 0)
                    config = '--psm 6' if field == 'address' else '--psm 7'
                    best = None
                    for candidate_name, roi_candidate in (('clahe', clahe), ('sharpened', sharp)):
                        started = time.perf_counter()
                        data = pytesseract.image_to_data(roi_candidate, output_type=pytesseract.Output.DICT, timeout=timeout_seconds, config=config)
                        elapsed = time.perf_counter() - started
                        words = [str(word).strip() for word in data.get('text', []) if str(word).strip()]
                        confidence = [float(value) for value in data.get('conf', []) if str(value).replace('.', '', 1).replace('-', '', 1).isdigit() and float(value) >= 0]
                        value = ' '.join(words)
                        score = _candidate_score(value, confidence)
                        if words and (best is None or score > best[0]):
                            best = (score, value, confidence)
                        calls += 1
                        call_metrics.append({'page_number': page.get('page_number'), 'field': field,
                                             'candidate': candidate_name, 'config': config,
                                             'seconds': round(elapsed, 4)})
                    if best is not None:
                        _, value, confidence = best
                        results[field] = {'value': value, 'confidence': round(sum(confidence) / len(confidence), 2) if confidence else 0.0, 'page_number': page.get('page_number')}
                    break
        metadata = dict(metadata); metadata['roi_values'] = results; metadata['philid_roi_values'] = results; metadata['roi_retry_calls'] = calls; metadata['philid_roi_retry_calls'] = calls; metadata['roi_call_metrics'] = call_metrics
        return metadata
    except Exception:
        return metadata


def extract_document_text(images, tesseract_path: str, timeout_seconds: int = 45) -> tuple[str, float, dict]:
    """OCR every rendered page once and retain page/line context for extraction."""
    try:
        import pytesseract
        _configure_tesseract(pytesseract, tesseract_path)
        page_texts, page_metadata, confidence, candidate_documents, call_metrics, candidate_cache = [], [], [], {}, [], {}
        for page_number, image in enumerate(images, 1):
            best = None
            for candidate, scale, config, offset, candidate_name, transform, crop in _ocr_candidates(image):
                cache_key = f'{page_number}:{candidate_name}:{config}:{transform or "none"}'
                cached = candidate_cache.get(cache_key)
                if cached is None:
                    started = time.perf_counter()
                    try:
                        data = pytesseract.image_to_data(candidate, output_type=pytesseract.Output.DICT, timeout=timeout_seconds, config=config)
                    except RuntimeError as exc:
                        if str(exc) != 'Tesseract process timeout':
                            raise
                        logger.warning('AI OCR candidate timed out page=%s candidate=%s', page_number, candidate_name)
                        continue
                    elapsed = time.perf_counter() - started
                    page_text, page_confidence, metadata = _page_from_data(data, page_number, scale, offset)
                    candidate_cache[cache_key] = (page_text, page_confidence, metadata, elapsed)
                    duplicate = False
                else:
                    page_text, page_confidence, metadata, elapsed = cached
                    duplicate = True
                    metadata = dict(metadata)
                metadata['selected_candidate'] = candidate_name
                metadata['transform'] = transform
                metadata['document_region'] = crop
                metadata['mean_confidence'] = sum(page_confidence) / len(page_confidence) if page_confidence else 0.0
                metadata['ocr_seconds'] = round(elapsed, 4)
                metadata['ocr_config'] = config
                metadata['ocr_cache_key'] = cache_key
                candidate_document = candidate_documents.setdefault(candidate_name, {'candidate': candidate_name, 'transform': transform, 'pages': []})
                candidate_document['pages'].append(metadata)
                call_metrics.append({'page_number': page_number, 'candidate': candidate_name, 'transform': transform,
                                     'config': config, 'seconds': round(elapsed, 4), 'cache_key': cache_key, 'duplicate': duplicate})
                scored = (_candidate_score(page_text, page_confidence), page_text, page_confidence, metadata)
                if best is None or scored[0] > best[0]: best = scored
            if best is None:
                raise RuntimeError('Tesseract process timeout')
            _, page_text, page_confidence, metadata = best
            page_texts.append(page_text); page_metadata.append(metadata); confidence.extend(page_confidence)
        mean_confidence = sum(confidence) / len(confidence) if confidence else 0
        return '\n\n'.join(page_texts), mean_confidence, {
            'mean_confidence': mean_confidence, 'page_count': len(page_metadata), 'pages': page_metadata,
            'ocr_boxes_by_page': [page['ocr_boxes'] for page in page_metadata],
            'ocr_call_metrics': call_metrics,
            # Retain bounded alternate readings for field extraction and
            # document-type cross-checks. The global winner supplies the
            # general text, but a clear type contradiction in another reading
            # must not be silently discarded.
            'field_candidates': [
                {**candidate, 'text': '\n\n'.join(page.get('text', '') for page in candidate['pages']),
                 'mean_confidence': round(sum(page.get('mean_confidence', 0.0) for page in candidate['pages']) / max(1, len(candidate['pages'])), 2)}
                for candidate in candidate_documents.values()
            ],
        }
    except ImportError as exc: raise RuntimeError('AI_OCR_UNAVAILABLE') from exc
    except RuntimeError as exc:
        if str(exc) == 'AI_TESSERACT_CONFIG_INVALID': raise
        raise RuntimeError('AI_OCR_UNAVAILABLE') from exc
    except pytesseract.TesseractError as exc: raise RuntimeError('AI_OCR_FAILED') from exc
    except pytesseract.TesseractNotFoundError as exc: raise RuntimeError('AI_OCR_UNAVAILABLE') from exc
    except Exception as exc: raise RuntimeError('AI_OCR_FAILED') from exc


def extract_text(image, tesseract_path: str, timeout_seconds: int = 45) -> tuple[str, float]:
    """Compatibility wrapper for callers that OCR one image."""
    text, confidence, _ = extract_document_text([image], tesseract_path, timeout_seconds)
    return text, confidence
