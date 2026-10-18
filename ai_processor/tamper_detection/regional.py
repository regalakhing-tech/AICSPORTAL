"""Local regional typography and pixel-consistency measurements."""
from __future__ import annotations

import re

import cv2
import numpy as np


_SENSITIVE_TEXT = re.compile(
    r'(?i)\b(date|issued|valid|expires|name|patient|deceased|amount|total|reference|signature|stamp)\b'
)


def _clip(box, width: int, height: int) -> tuple[int, int, int, int] | None:
    if not isinstance(box, list) or len(box) != 4:
        return None
    x1, y1, x2, y2 = (int(value) for value in box)
    x1, x2 = max(0, x1), min(width, x2)
    y1, y2 = max(0, y1), min(height, y2)
    return (x1, y1, x2, y2) if x2 > x1 and y2 > y1 else None


def _metrics(image, box) -> dict[str, float] | None:
    height, width = image.shape[:2]
    clipped = _clip(box, width, height)
    if clipped is None:
        return None
    x1, y1, x2, y2 = clipped
    region = image[y1:y2, x1:x2]
    gray = cv2.cvtColor(region, cv2.COLOR_BGR2GRAY) if region.ndim == 3 else region
    threshold = min(210, max(60, int(np.percentile(gray, 45))))
    foreground = (gray < threshold).astype(np.uint8)
    dark = float(np.mean(foreground > 0))
    sharpness = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    distances = cv2.distanceTransform(foreground * 255, cv2.DIST_L2, 3)
    strokes = distances[distances > 0]
    stroke_width = float(np.median(strokes) * 2) if strokes.size else 0.0
    residual = gray.astype(np.float32) - cv2.GaussianBlur(gray, (0, 0), 3)
    background_residual = float(np.std(residual))
    border = np.concatenate((gray[0, :], gray[-1, :], gray[:, 0], gray[:, -1])).astype(np.float32)
    inner = gray[max(1, gray.shape[0] // 4):max(2, gray.shape[0] * 3 // 4),
                 max(1, gray.shape[1] // 8):max(2, gray.shape[1] * 7 // 8)]
    boundary_contrast = abs(float(np.mean(border)) - float(np.mean(inner))) if inner.size else 0.0
    if region.ndim == 3 and np.count_nonzero(foreground):
        ink = np.median(region[foreground > 0], axis=0)
        ink_chroma = float(np.max(ink) - np.min(ink))
    else:
        ink_chroma = 0.0
    columns = np.any(foreground > 0, axis=0)
    occupied = np.flatnonzero(columns)
    gaps = np.diff(occupied) - 1 if len(occupied) > 1 else np.array([])
    wide_gap = float(np.percentile(gaps[gaps > 0], 90)) if np.any(gaps > 0) else 0.0
    occupied_rows = np.flatnonzero(np.any(foreground > 0, axis=1))
    baseline_ratio = float(occupied_rows[-1] / max(1, gray.shape[0])) if len(occupied_rows) else 0.0
    return {'height': float(y2 - y1), 'dark_ratio': dark, 'sharpness': sharpness,
            'stroke_width': stroke_width, 'background_residual': background_residual,
            'boundary_contrast': boundary_contrast, 'ink_chroma': ink_chroma,
            'wide_gap': wide_gap, 'baseline_ratio': baseline_ratio}


def _ratio_outliers(candidate: dict, peers: list[dict]) -> list[str]:
    if len(peers) < 2:
        return []
    outliers = []
    for key in ('height', 'dark_ratio', 'sharpness', 'stroke_width', 'background_residual', 'boundary_contrast', 'ink_chroma', 'wide_gap', 'baseline_ratio'):
        median = float(np.median([peer[key] for peer in peers]))
        value = float(candidate[key])
        if key == 'ink_chroma':
            if value - median > 45:
                outliers.append(key)
            continue
        if median <= 1e-9:
            continue
        ratio = value / median
        if (key == 'height' and (ratio > 1.28 or ratio < .72)) or \
           (key == 'dark_ratio' and (ratio > 1.35 or ratio < .60)) or \
           (key == 'sharpness' and (ratio > 1.60 or ratio < .45)) or \
           (key == 'stroke_width' and (ratio > 1.45 or ratio < .60)) or \
           (key == 'background_residual' and (ratio > 1.70 or ratio < .40)) or \
           (key == 'boundary_contrast' and (ratio > 2.00 or ratio < .30)) or \
           (key == 'wide_gap' and ratio > 1.6 and value - median > 4) or \
           (key == 'baseline_ratio' and abs(value - median) > .18):
            outliers.append(key)
    return outliers


def _normalized_box(box, width: int, height: int) -> list[int]:
    x1, y1, x2, y2 = box
    return [round(y1 * 1000 / height), round(x1 * 1000 / width),
            round(y2 * 1000 / height), round(x2 * 1000 / width)]


def _render_mode(text: str) -> str:
    if ':' in text:
        return 'labeled_field'
    if re.search(r'[_]{3,}|[-]{4,}', text):
        return 'filled_line'
    if text.isupper():
        return 'uppercase_block'
    return 'prose'


def _semantic_role(text: str) -> str:
    lowered = text.lower()
    if re.search(r'\b(?:date|issued|valid|expires?)\b', lowered):
        return 'date'
    if re.search(r'\b(?:name|patient|deceased)\b', lowered):
        return 'person'
    if re.search(r'\b(?:amount|total)\b', lowered):
        return 'financial'
    if 'reference' in lowered:
        return 'reference'
    return 'signature'


def analyze_regional_inconsistency(images: list, ocr_metadata: dict) -> dict:
    base = {'status': 'completed', 'family': 'local_regional', 'decision': 'clear', 'indicators': []}
    pages = ocr_metadata.get('pages', []) if isinstance(ocr_metadata, dict) else []
    indicators = []
    for page_index, image in enumerate(images):
        if image is None or page_index >= len(pages) or not isinstance(pages[page_index], dict):
            continue
        height, width = image.shape[:2]
        records = []
        for line in pages[page_index].get('lines', []):
            if not isinstance(line, dict) or not _SENSITIVE_TEXT.search(str(line.get('text') or '')):
                continue
            clipped = _clip(line.get('box'), width, height)
            metrics = _metrics(image, line.get('box'))
            if clipped and metrics:
                text = str(line.get('text') or '')
                records.append((line, clipped, metrics, _render_mode(text), _semantic_role(text)))
        for line, clipped, metrics, mode, role in records:
            peers = [other_metrics for other_line, _other_box, other_metrics, other_mode, other_role in records
                     if other_line is not line and other_mode == mode and other_role == role]
            outliers = _ratio_outliers(metrics, peers)
            if len(outliers) < 2:
                continue
            indicators.append({
                'type': 'typography_mismatch', 'page': page_index + 1,
                'box_2d': _normalized_box(clipped, width, height),
                'confidence_band': 'moderate',
                'reason': 'Text size, stroke density, or edge sharpness differs from nearby peer fields.',
                'metrics': outliers,
            })
    if indicators:
        base['decision'] = 'review'
        base['indicators'] = indicators[:8]
    return base
