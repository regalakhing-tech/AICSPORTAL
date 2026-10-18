"""Conservative local image checks; findings are advisory to staff."""
from __future__ import annotations

import re
from collections import defaultdict
from hashlib import sha256
from urllib.parse import urlsplit

import cv2
import numpy as np

from .overlay import MIN_PAPER_NOISE, _paper_noise, detect_digital_text_overlay


def _box(points: list[tuple[float, float]], width: int, height: int) -> list[int]:
    xs, ys = [point[0] for point in points], [point[1] for point in points]
    padding = 12
    return [max(0, round((min(ys) - padding) * 1000 / height)),
            max(0, round((min(xs) - padding) * 1000 / width)),
            min(1000, round((max(ys) + padding) * 1000 / height)),
            min(1000, round((max(xs) + padding) * 1000 / width))]


def _qr_preview(value: str) -> str:
    parsed = urlsplit(value)
    if parsed.scheme in {'http', 'https'} and parsed.netloc:
        return f'{parsed.scheme}://{parsed.hostname or "unknown host"}'
    return 'Non-URL QR content (redacted)'


def inspect_qr(images: list) -> dict:
    facts = []
    detector = cv2.QRCodeDetector()
    for page_number, image in enumerate(images, 1):
        if image is None:
            continue
        height, width = image.shape[:2]
        try:
            found, values, points, _ = detector.detectAndDecodeMulti(image)
            if not found:
                value, single_points, _ = detector.detectAndDecode(image)
                values = [value] if value else []
                points = np.asarray([single_points]) if value and single_points is not None else None
            for index, value in enumerate(values[:8]):
                if not isinstance(value, str) or not value:
                    continue
                corners = points[index].reshape(-1, 2).tolist() if points is not None else []
                facts.append({'page': page_number, 'box_2d': _box(corners, width, height) if corners else None,
                              'content_preview': _qr_preview(value), 'content_sha256': sha256(value.encode()).hexdigest(),
                              'verification': 'not_authenticated'})
        except (cv2.error, ValueError, TypeError):
            continue
    return {'status': 'completed', 'family': 'qr', 'decision': 'clear', 'indicators': [], 'facts': facts[:12]}


def _semantic_region(points: list[tuple[float, float]], lines: list[dict]) -> bool:
    center_x = float(np.median([point[0] for point in points]))
    center_y = float(np.median([point[1] for point in points]))
    for line in lines:
        if not isinstance(line, dict) or not any(word in str(line.get('text') or '').lower()
                                                 for word in ('signature', 'stamp', 'seal', 'logo')):
            continue
        box = line.get('box')
        if isinstance(box, list) and len(box) == 4 and box[0] <= center_x <= box[2] and box[1] <= center_y <= box[3]:
            return True
    return False


def _line_words(point: tuple[float, float], lines: list[dict]) -> set[str]:
    for line in lines:
        box = line.get('box') if isinstance(line, dict) else None
        if isinstance(box, list) and len(box) == 4 and box[0] <= point[0] <= box[2] and box[1] <= point[1] <= box[3]:
            return {word for word in re.findall(r'[a-z0-9]+', str(line.get('text') or '').lower()) if len(word) > 1}
    return set()


def _repeated_print(sources, destinations, lines: list[dict]) -> bool:
    """Both ends sit on the same printed line, or on lines sharing several words:
    a legitimately repeated phrase, not a pixel copy. A single shared token is
    not enough (OCR reads a copied signature as the same "word" twice)."""
    source_words = _line_words(tuple(np.median(np.array(sources), axis=0)), lines)
    destination_words = _line_words(tuple(np.median(np.array(destinations), axis=0)), lines)
    if not source_words or not destination_words:
        return False
    shared = source_words & destination_words
    same_line = source_words == destination_words and len(shared) >= 2
    return same_line or (len(shared) >= 3 and len(shared) / min(len(source_words), len(destination_words)) >= 0.5)


def _noise_fingerprint(gray, sources, shift) -> float | None:
    """Correlation of the paper's camera noise between the two regions. A pixel
    copy duplicates the exact noise (about 0.2+ even after JPEG re-saving); the
    same text genuinely printed twice has independent noise (about 0)."""
    points = np.array(sources)
    pad, search = 14, 8
    x1, y1 = (points.min(axis=0) - pad).astype(int)
    x2, y2 = (points.max(axis=0) + pad).astype(int)
    dx, dy = int(round(shift[0])), int(round(shift[1]))
    height, width = gray.shape[:2]
    if min(x1, y1, x1 + dx - search, y1 + dy - search) < 0 or max(x2, x2 + dx + search) > width or max(y2, y2 + dy + search) > height:
        return None
    source = gray[y1:y2, x1:x2].astype(np.float32)
    window = gray[y1 + dy - search:y2 + dy + search, x1 + dx - search:x2 + dx + search].astype(np.float32)
    _, _, _, (offset_x, offset_y) = cv2.minMaxLoc(cv2.matchTemplate(window, source, cv2.TM_CCOEFF_NORMED))
    target = window[offset_y:offset_y + source.shape[0], offset_x:offset_x + source.shape[1]]
    source_noise = source - cv2.GaussianBlur(source, (0, 0), 1.0)
    target_noise = target - cv2.GaussianBlur(target, (0, 0), 1.0)
    source_smooth, target_smooth = cv2.GaussianBlur(source, (0, 0), 2), cv2.GaussianBlur(target, (0, 0), 2)
    paper = (source_smooth > np.percentile(source_smooth, 55)) & (target_smooth > np.percentile(target_smooth, 55))
    if paper.sum() < 150:
        return None
    return float(np.corrcoef(source_noise[paper], target_noise[paper])[0, 1])


def _copy_move(image, page_number: int, lines: list[dict]) -> list[dict]:
    height, width = image.shape[:2]
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    # Enough points that handwriting and stamps get their own, not only printed text.
    detector = cv2.ORB_create(nfeatures=6000, fastThreshold=12)
    keypoints, descriptors = detector.detectAndCompute(gray, None)
    if descriptors is None or len(keypoints) < 20:
        return []
    # Camera-noise fingerprints only mean something on photos; screenshots and
    # generated files repeat identical pixels legitimately.
    photographed = _paper_noise(gray) >= MIN_PAPER_NOISE
    matcher = cv2.BFMatcher(cv2.NORM_HAMMING)
    clusters = defaultdict(list)
    for neighbors in matcher.knnMatch(descriptors, descriptors, k=min(5, len(descriptors))):
        first = keypoints[neighbors[0].queryIdx].pt
        for candidate in neighbors[1:]:
            second = keypoints[candidate.trainIdx].pt
            dx, dy = second[0] - first[0], second[1] - first[1]
            if candidate.distance > 24 or abs(dx) + abs(dy) < 100 or dx < 0 or (dx == 0 and dy < 0):
                continue
            clusters[(round(dx / 16), round(dy / 16))].append((first, second))
            break
    indicators = []
    for pairs in sorted(clusters.values(), key=len, reverse=True)[:6]:
        if len(pairs) < 5:
            continue
        sources = [pair[0] for pair in pairs]
        destinations = [pair[1] for pair in pairs]
        span_x = max(p[0] for p in sources) - min(p[0] for p in sources)
        span_y = max(p[1] for p in sources) - min(p[1] for p in sources)
        if span_x < 25 or span_y < 20 or _repeated_print(sources, destinations, lines):
            continue
        shift = np.median(np.array([[b[0] - a[0], b[1] - a[1]] for a, b in pairs]), axis=0)
        fingerprint = _noise_fingerprint(gray, sources, shift) if photographed else None
        box = _box(destinations, width, height)
        compact = (box[2] - box[0]) * (box[3] - box[1]) <= 120_000  # under 12% of the page (0-1000 units)
        copied = compact and fingerprint is not None and fingerprint >= 0.15
        if not copied and len(pairs) < 9:
            continue
        repeated_mark = _semantic_region(sources, lines) or _semantic_region(destinations, lines)
        if copied:
            reason = "The same pixels, including the paper's camera noise, appear in two places, so this area was likely copied."
        elif repeated_mark:
            reason = 'Similar signature, stamp, seal, or logo regions require comparison with the original.'
        else:
            reason = 'Similar non-adjacent image regions require comparison with the original.'
        indicators.append({'type': 'repeated_visual_region' if repeated_mark else 'copy_move_region',
                           'page': page_number,
                           'box_2d': _box(destinations, width, height),
                           'source_box_2d': _box(sources, width, height),
                           'confidence_band': 'high' if copied else 'moderate',
                           'reason': reason})
        if len(indicators) >= 3:
            break
    return indicators


def _covered_fields(image, page_number: int, lines: list[dict]) -> list[dict]:
    height, width = image.shape[:2]
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    output = []
    for line in lines:
        if not isinstance(line, dict) or not any(word in str(line.get('text') or '').lower()
                                                 for word in ('date', 'name', 'amount', 'reference', 'signature')):
            continue
        box = line.get('box')
        if not isinstance(box, list) or len(box) != 4:
            continue
        x1, y1, x2, y2 = [int(value) for value in box]
        if x2 - x1 < 60 or y2 - y1 < 24 or x1 < 0 or y1 < 0 or x2 > width or y2 > height:
            continue
        region = gray[y1:y2, x1:x2]
        margin = max(8, min(16, min(region.shape) // 5))
        inner = region[margin:-margin, margin:-margin]
        border = np.concatenate((region[:margin, :].ravel(), region[-margin:, :].ravel(),
                                 region[:, :margin].ravel(), region[:, -margin:].ravel()))
        if inner.size == 0 or float(np.std(inner)) > 2.5 or float(np.std(border)) < 3:
            continue
        difference = float(np.mean(inner)) - float(np.mean(border))
        if abs(difference) < 8:
            continue
        kind = 'erasure_or_cover_region' if difference > 0 else 'pasted_region_boundary'
        output.append({'type': kind, 'page': page_number, 'box_2d': _box([(x1, y1), (x2, y2)], width, height),
                       'confidence_band': 'moderate',
                       'reason': 'A uniform region around a sensitive field differs from surrounding paper texture.'})
    return output[:4]


def _pixel_field_anomalies(image, page_number: int, lines: list[dict]) -> list[dict]:
    """Compare a sensitive field with immediately adjacent paper, not a template."""
    height, width = image.shape[:2]
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    output = []
    for line in lines:
        if not isinstance(line, dict) or not any(word in str(line.get('text') or '').lower()
                                                 for word in ('date', 'name', 'amount', 'reference', 'signature')):
            continue
        box = line.get('box')
        if not isinstance(box, list) or len(box) != 4:
            continue
        x1, y1, x2, y2 = [int(value) for value in box]
        if x2 - x1 < 80 or y2 - y1 < 35 or min(x1, y1) < 12 or x2 + 12 > width or y2 + 12 > height:
            continue
        inner_edges = (gray[y1:y1 + 6, x1:x2], gray[y2 - 6:y2, x1:x2],
                       gray[y1:y2, x1:x1 + 6], gray[y1:y2, x2 - 6:x2])
        outer_edges = (gray[y1 - 6:y1, x1:x2], gray[y2:y2 + 6, x1:x2],
                       gray[y1:y2, x1 - 6:x1], gray[y1:y2, x2:x2 + 6])
        side_contrasts = [abs(float(np.median(inside)) - float(np.median(outside)))
                          for inside, outside in zip(inner_edges, outer_edges)]
        if sum(value > 14 for value in side_contrasts) >= 3:
            output.append({'type': 'pasted_region_boundary', 'page': page_number,
                           'box_2d': _box([(x1, y1), (x2, y2)], width, height),
                           'confidence_band': 'moderate',
                           'reason': 'Several sides of this field have a different paper tone; inspect the original for a pasted area or printing variation.'})
        inner = gray[y1 + 10:y2 - 10, x1 + 10:x2 - 10]
        neighbors = (gray[y1 - 10:y1, x1:x2], gray[y2:y2 + 10, x1:x2],
                     gray[y1:y2, x1 - 10:x1], gray[y1:y2, x2:x2 + 10])
        if inner.size < 500:
            continue
        inner_edges_var = float(cv2.Laplacian(inner, cv2.CV_32F).var())
        neighboring_variances = [float(cv2.Laplacian(part, cv2.CV_32F).var()) for part in neighbors]
        surrounding = float(np.median(neighboring_variances))
        if surrounding > 20 and inner_edges_var < surrounding * .22:
            output.append({'type': 'compression_or_resampling_difference', 'page': page_number,
                           'box_2d': _box([(x1, y1), (x2, y2)], width, height),
                           'confidence_band': 'moderate',
                           'reason': 'Image texture in this field is much smoother than adjacent paper; resampling, compression, or capture variation may explain it.'})
    return output[:4]


def _cross_page_repeats(images: list, pages: list[dict]) -> list[dict]:
    """Compare marks on different pages; shared forms alone are not suspicious."""
    observations = []
    orb = cv2.ORB_create(nfeatures=1600, fastThreshold=12)
    for image in images[:3]:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
        observations.append(orb.detectAndCompute(gray, None))
    output = []
    matcher = cv2.BFMatcher(cv2.NORM_HAMMING, crossCheck=True)
    for first in range(len(observations)):
        for second in range(first + 1, len(observations)):
            key_a, descriptors_a = observations[first]
            key_b, descriptors_b = observations[second]
            if descriptors_a is None or descriptors_b is None:
                continue
            matches = [item for item in matcher.match(descriptors_a, descriptors_b) if item.distance <= 24]
            if len(matches) < 9:
                continue
            sources = [key_a[item.queryIdx].pt for item in matches]
            targets = [key_b[item.trainIdx].pt for item in matches]
            if max(point[0] for point in sources) - min(point[0] for point in sources) < 25:
                continue
            if max(point[1] for point in sources) - min(point[1] for point in sources) < 20:
                continue
            lines_a = pages[first].get('lines', []) if first < len(pages) and isinstance(pages[first], dict) else []
            lines_b = pages[second].get('lines', []) if second < len(pages) and isinstance(pages[second], dict) else []
            if not (_semantic_region(sources, lines_a) or _semantic_region(targets, lines_b)):
                continue
            height_a, width_a = images[first].shape[:2]
            height_b, width_b = images[second].shape[:2]
            output.append({'type': 'repeated_visual_region', 'page': second + 1,
                           'source_page': first + 1,
                           'box_2d': _box(targets, width_b, height_b),
                           'source_box_2d': _box(sources, width_a, height_a),
                           'confidence_band': 'moderate',
                           'reason': 'A similar signature, stamp, seal, or logo appears on another page. Compare both locations; repeated forms can be legitimate.'})
    return output[:3]


def analyze_image_forensics(images: list, ocr_metadata: dict | None = None) -> dict:
    indicators = []
    pages = ocr_metadata.get('pages', []) if isinstance(ocr_metadata, dict) else []
    for number, image in enumerate(images, 1):
        if image is None or image.shape[0] < 80 or image.shape[1] < 80:
            continue
        page = pages[number - 1] if len(pages) >= number and isinstance(pages[number - 1], dict) else {}
        lines = page.get('lines', []) if isinstance(page.get('lines', []), list) else []
        # Only lines OCR read confidently as real words, so foliage or fabric is never judged as text.
        text_boxes = [line.get('box') for line in lines
                      if isinstance(line, dict) and float(line.get('confidence') or 0) >= 70
                      and sum(character.isalnum() for character in str(line.get('text') or '')) >= 4]
        indicators.extend(detect_digital_text_overlay(image, number, text_boxes))
        indicators.extend(_copy_move(image, number, lines))
        indicators.extend(_covered_fields(image, number, lines))
        indicators.extend(_pixel_field_anomalies(image, number, lines))
    valid_pages = [image for image in images if image is not None and image.shape[0] >= 80 and image.shape[1] >= 80]
    if len(valid_pages) == len(images) and len(images) > 1:
        indicators.extend(_cross_page_repeats(images, pages))
    return {'status': 'completed', 'family': 'local_visual',
            'decision': 'review' if indicators else 'clear', 'indicators': indicators[:8], 'facts': {}}
