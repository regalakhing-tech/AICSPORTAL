"""Content-aware Phase 2 document quality assessment."""
import re


def assess(image, blur_threshold: float) -> dict:
    import cv2
    import numpy as np
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
    variance = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    blank = float(np.std(gray)) < 4 or float(np.mean(gray < 245)) < .003
    return {'blank': blank, 'blur_variance': round(variance, 2), 'blurred': variance < blur_threshold}


def _meaningful_words(text: str) -> list[str]:
    return re.findall(r"[A-Za-z0-9][A-Za-z0-9.-]+", text or '')


def _structured_evidence(fields: dict | None) -> tuple[int, int]:
    reliable = contextual = 0
    for field in (fields or {}).values():
        if not isinstance(field, dict) or field.get('status') != 'extracted' or field.get('value') in (None, '', False):
            continue
        try:
            confidence = float(field.get('confidence', 0))
        except (TypeError, ValueError):
            confidence = 0
        if confidence >= .68:
            reliable += 1
            if field.get('context'):
                contextual += 1
    return reliable, contextual


def analyze_document_quality(images, blur_threshold: float, ocr_text: str = '', ocr_confidence: float = 0,
                             structured_fields: dict | None = None) -> dict:
    """Combine visual severity, OCR readability, and contextual extraction evidence."""
    import cv2
    import numpy as np

    words = _meaningful_words(ocr_text)
    word_count = len(words)
    raw_tokens = re.findall(r'\S+', ocr_text or '')
    short_tokens = sum(len(re.sub(r'[^A-Za-z0-9]', '', token)) <= 1 for token in raw_tokens)
    symbol_count = len(re.findall(r'[^A-Za-z0-9\s,./:#-]', ocr_text or ''))
    coherent_lines = sum(
        1 for line in (ocr_text or '').splitlines()
        if 1 <= len(_meaningful_words(line)) <= 40 and re.search(r'[A-Za-z]{3,}', line)
    )
    logical_lines = max(1, len([line for line in (ocr_text or '').splitlines() if line.strip()]))
    coherent_ratio = coherent_lines / logical_lines
    noise_ratio = symbol_count / max(1, len(ocr_text or ''))
    reliable_fields, contextual_fields = _structured_evidence(structured_fields)
    strong_ocr = word_count >= 8 and ocr_confidence >= 50 and coherent_ratio >= .6 and noise_ratio <= .08
    useful_ocr = word_count >= 4 and ocr_confidence >= 30 and coherent_ratio >= .4 and noise_ratio <= .14
    strong_document_evidence = strong_ocr and reliable_fields >= 2 and contextual_fields >= 2
    weak_ocr = word_count < 4 or ocr_confidence < 25

    pages = []
    for image in images:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if len(image.shape) == 3 else image
        height, width = gray.shape[:2]
        variance = float(cv2.Laplacian(gray, cv2.CV_64F).var())
        mean, spread = float(np.mean(gray)), float(np.std(gray))
        p05, p10, p90, p95 = (float(value) for value in np.percentile(gray, (5, 10, 90, 95)))
        dark_ratio = float(np.mean(gray < 30))
        bright_ratio = float(np.mean(gray > 250))
        content_ratio = float(np.mean(gray < 245))
        contrast = p95 - p05
        blank = spread < 4 or content_ratio < .003
        severe_blur = variance < max(8, blur_threshold * .25)
        borderline_blur = not severe_blur and variance < blur_threshold
        low_resolution = min(width, height) < 180

        # Whole-page brightness remains diagnostic, but sparse black text on
        # white paper is protected by foreground/content and OCR evidence.
        severe_underexposure = mean < 30 and p90 < 70 and bright_ratio < .002 and contrast < 45
        severe_overexposure = mean > 248 and p10 > 225 and dark_ratio < .002 and content_ratio < .003 and contrast < 35
        moderate_underexposure = not severe_underexposure and mean < 55
        moderate_overexposure = not severe_overexposure and mean > 235
        hard_blur = severe_blur and weak_ocr
        hard_visual_failure = blank or low_resolution or severe_underexposure or severe_overexposure or hard_blur
        pages.append({
            'blank': blank, 'severe_blur': severe_blur, 'borderline_blur': borderline_blur,
            'low_resolution': low_resolution, 'severe_underexposure': severe_underexposure,
            'severe_overexposure': severe_overexposure, 'moderate_underexposure': moderate_underexposure,
            'moderate_overexposure': moderate_overexposure, 'hard_visual_failure': hard_visual_failure,
            'mean_brightness': round(mean, 2), 'contrast': round(contrast, 2),
            'dark_pixel_ratio': round(dark_ratio, 4), 'bright_pixel_ratio': round(bright_ratio, 4),
            'content_pixel_ratio': round(content_ratio, 4), 'blur_variance': round(variance, 2),
            'width': width, 'height': height,
        })

    usable = [page for page in pages if not page['hard_visual_failure']]
    severe = bool(pages) and not usable
    moderate_exposure = any(page['moderate_underexposure'] or page['moderate_overexposure'] for page in pages)
    moderate_visual = any(page['borderline_blur'] for page in pages) or moderate_exposure

    if severe:
        decision = 'reupload'
        if all(page['low_resolution'] for page in pages):
            issue = 'low_resolution'
            reason = 'The uploaded document resolution is too low to read reliably. Please upload a clearer copy.'
        elif any(page['severe_underexposure'] for page in pages):
            issue = 'underexposure'
            reason = 'The document is severely underexposed, and important content cannot be read reliably. Please upload a clearer copy.'
        elif any(page['severe_overexposure'] for page in pages):
            issue = 'overexposure'
            reason = 'The document is severely overexposed, and important content appears washed out. Please upload a clearer copy.'
        elif any(page['severe_blur'] and weak_ocr for page in pages):
            issue = 'blur'
            reason = 'The uploaded document is severely blurred and cannot be reliably read. Please upload a clearer copy.'
        else:
            issue = 'blank'
            reason = 'The uploaded document appears blank or contains too little readable content. Please upload a clearer copy.'
    elif moderate_visual and not strong_document_evidence:
        decision, issue = 'staff_review', 'readability_concern'
        if any(page['moderate_underexposure'] for page in pages):
            reason = 'The document appears dark in some important areas, which may reduce readability. Staff review is recommended.'
        elif any(page['moderate_overexposure'] for page in pages):
            reason = 'The document appears washed out in some important areas, which may reduce readability. Staff review is recommended.'
        else:
            reason = 'The document has a readability concern. Staff review is recommended.'
    elif weak_ocr:
        decision, issue = 'staff_review', 'weak_ocr'
        reason = 'OCR readability is limited even though no severe visual failure was found. Staff review is recommended.'
    else:
        decision, issue = 'acceptable', None
        reason = 'Document quality appears acceptable for verification.'

    return {
        'decision': decision, 'severity': 'severe' if decision == 'reupload' else ('moderate' if decision == 'staff_review' else 'minor_or_none'),
        'issue': issue, 'reason': reason, 'pages': pages, 'usable_pages': len(usable), 'total_pages': len(pages),
        'ocr_readability': 'strong' if strong_ocr else ('usable' if useful_ocr else 'weak'),
        'ocr_confidence': round(float(ocr_confidence or 0), 2), 'meaningful_word_count': word_count,
        'coherent_line_ratio': round(coherent_ratio, 3), 'ocr_noise_ratio': round(noise_ratio, 3),
        'short_token_count': short_tokens,
        'reliable_extracted_field_count': reliable_fields, 'contextual_extracted_field_count': contextual_fields,
        'strong_document_evidence': strong_document_evidence,
        'blank': bool(pages) and all(page['blank'] for page in pages),
        'blurred': any(page['borderline_blur'] or page['severe_blur'] for page in pages),
        'blur_variance': min((page['blur_variance'] for page in pages), default=0),
    }
