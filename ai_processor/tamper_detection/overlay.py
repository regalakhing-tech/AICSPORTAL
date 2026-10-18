"""Find text that was added digitally on top of a photographed document.

Every mark that was really on the paper passes through the camera: it takes on
the photo's colour cast, blur, and noise, so even bold print is dark grey with
the paper's tint. Text typed afterwards in an editor (Canva, Photoshop, phone
apps) keeps its pure digital colour, usually flat black. This module compares
the ink of every letter with the page's typical ink and reports runs of letters
that are clearly darker AND a different colour. Clean scans and born-digital
files, where all text is already pure black, produce no outliers.
"""
from __future__ import annotations

import cv2
import numpy as np

MIN_GLYPHS_ON_PAGE = 40      # too little text to know what the page's ink looks like
MIN_GLYPHS_IN_RUN = 5        # a word or value, not a stray speck
DARKER_BY = 20               # Lab lightness units darker than the page's typical ink
COLOUR_SHIFT = 6             # Lab a/b distance from the page's typical ink colour
MAX_SUSPICIOUS_SHARE = 0.35  # a high share means the whole page is like that, not a local edit
PURE_INK_MAX_L = 15          # flat digital black/navy; photographed ink is never this dark
PHOTO_INK_MIN_L = 40         # the page's typical ink must be camera-grey, i.e. a photographed page
BORDER = 0.015               # ignore runs touching the image edge (dark backgrounds, clipped borders)
MIN_PAPER_NOISE = 0.62       # fine camera grain on blank paper: digital files and screenshots measured <= 0.6,
                             # phone photos >= 0.69 (211 uploads + test photos); smooth watermarks do not count


def _paper_noise(gray) -> float:
    """Median fine-grain noise (pixel minus a lightly blurred copy) in blank,
    bright 16x16 blocks. Sensor grain is high-frequency; printed watermarks and
    background artwork are smooth and do not raise it."""
    values = gray.astype(np.float32)
    grain = values - cv2.GaussianBlur(values, (0, 0), 1.0)
    bright = float(np.percentile(values, 75))
    stds = []
    for y in range(0, values.shape[0] - 16, 16):
        for x in range(0, values.shape[1] - 16, 16):
            block = values[y:y + 16, x:x + 16]
            if block.mean() >= bright - 25 and block.std() < 12:
                stds.append(float(grain[y:y + 16, x:x + 16].std()))
    return float(np.median(stds)) if stds else 0.0


def _glyphs(image) -> list[tuple[int, int, int, int, float, float, float]]:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    colour = image if image.ndim == 3 else cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
    lab = cv2.cvtColor(colour, cv2.COLOR_BGR2LAB)
    ink = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY_INV, 31, 18)
    count, labels, stats, _ = cv2.connectedComponentsWithStats(ink, 8)
    height, width = gray.shape[:2]
    glyphs = []
    for index in range(1, count):
        x, y, w, h, area = (int(value) for value in stats[index])
        if not (6 <= h <= max(12, height * 0.06) and 2 <= w <= max(10, width * 0.1) and area >= 12):
            continue
        mask = labels[y:y + h, x:x + w] == index
        core = cv2.erode(mask.astype(np.uint8), np.ones((2, 2), np.uint8)).astype(bool)
        if core.sum() < 4:
            core = mask
        patch = lab[y:y + h, x:x + w]
        glyphs.append((x, y, w, h, float(np.median(patch[..., 0][core])),
                       float(np.median(patch[..., 1][core])), float(np.median(patch[..., 2][core]))))
    return glyphs


def _runs(glyphs: list[tuple]) -> list[list[tuple]]:
    """Group letters into lines, then split each line into runs at wide gaps."""
    lines: list[list[tuple]] = []
    for glyph in sorted(glyphs, key=lambda item: item[1] + item[3] / 2):
        centre = glyph[1] + glyph[3] / 2
        for line in lines:
            line_height = float(np.median([item[3] for item in line]))
            line_centre = float(np.median([item[1] + item[3] / 2 for item in line]))
            if abs(centre - line_centre) <= 0.6 * line_height:
                line.append(glyph)
                break
        else:
            lines.append([glyph])
    runs: list[list[tuple]] = []
    for line in lines:
        line.sort(key=lambda item: item[0])
        height = float(np.median([item[3] for item in line]))
        current = [line[0]]
        for glyph in line[1:]:
            if glyph[0] - max(item[0] + item[2] for item in current) > 2.5 * height:
                runs.append(current)
                current = []
            current.append(glyph)
        runs.append(current)
    return runs


def _inside_text(glyph: tuple, text_boxes) -> bool:
    x, y, w, h = glyph[:4]
    cx, cy = x + w / 2, y + h / 2
    return any(box[0] <= cx <= box[2] and box[1] <= cy <= box[3] for box in text_boxes)


def detect_digital_text_overlay(image, page_number: int, text_boxes=None) -> list[dict]:
    """text_boxes: OCR line boxes [x1, y1, x2, y2] in pixels. When given, only
    letters on recognised text lines are judged, so patterned backgrounds around
    a photographed page are ignored; an empty list means the page has no
    confidently read text, so nothing is judged. None judges the whole image."""
    if image is None or getattr(image, 'size', 0) == 0:
        return []
    glyphs = _glyphs(image)
    if text_boxes is not None:
        boxes = [box for box in text_boxes if isinstance(box, (list, tuple)) and len(box) == 4]
        glyphs = [glyph for glyph in glyphs if _inside_text(glyph, boxes)]
    if len(glyphs) < MIN_GLYPHS_ON_PAGE:
        return []
    lightness = np.array([item[4] for item in glyphs])
    page_l = float(np.median(lightness))
    page_a = float(np.median([item[5] for item in glyphs]))
    page_b = float(np.median([item[6] for item in glyphs]))
    # Darker than the page's ink AND either a different colour tone, or flat
    # neutral black on a page whose ink the camera turned grey.
    suspicious = [item for item in glyphs
                  if item[4] <= page_l - DARKER_BY
                  and (float(np.hypot(item[5] - page_a, item[6] - page_b)) >= COLOUR_SHIFT
                       or (float(np.hypot(item[5] - 128, item[6] - 128)) <= 4 and page_l - item[4] >= 40))]
    if not suspicious or len(suspicious) > MAX_SUSPICIOUS_SHARE * len(glyphs):
        return []
    if page_l < PHOTO_INK_MIN_L:
        return []  # a scan or born-digital file: its text is already pure black
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    if _paper_noise(gray) < MIN_PAPER_NOISE:
        return []  # a screenshot or digital file, not a photo: mixed black and grey text is normal
    height, width = image.shape[:2]
    indicators = []
    for run in _runs(suspicious):
        if len(run) < MIN_GLYPHS_IN_RUN:
            continue
        x1 = min(item[0] for item in run)
        y1 = min(item[1] for item in run)
        x2 = max(item[0] + item[2] for item in run)
        y2 = max(item[1] + item[3] for item in run)
        if x1 < BORDER * width or y1 < BORDER * height or x2 > (1 - BORDER) * width or y2 > (1 - BORDER) * height:
            continue
        run_l = float(np.median([item[4] for item in run]))
        run_a = float(np.median([item[5] for item in run]))
        run_b = float(np.median([item[6] for item in run]))
        colour_shift = float(np.hypot(run_a - page_a, run_b - page_b))
        neutral = float(np.hypot(run_a - 128, run_b - 128)) <= 4
        if run_l > PURE_INK_MAX_L or not (colour_shift >= COLOUR_SHIFT or (neutral and page_l - run_l >= 40)):
            continue
        pad = int(np.median([item[3] for item in run]) * 0.4)
        indicators.append({
            'type': 'digital_text_overlay', 'page': page_number,
            'box_2d': [max(0, round((y1 - pad) * 1000 / height)), max(0, round((x1 - pad) * 1000 / width)),
                       min(1000, round((y2 + pad) * 1000 / height)), min(1000, round((x2 + pad) * 1000 / width))],
            'confidence_band': 'high',
            'reason': ('This text is flat digital black and much darker than the rest of the photographed '
                       'document, with a different colour tone, so it may have been typed on with an editing app.'),
        })
    return indicators[:4]
