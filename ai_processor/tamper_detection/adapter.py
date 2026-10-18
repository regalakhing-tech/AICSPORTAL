"""HTTP boundary to the persistent localhost ADCD-Net service."""
from __future__ import annotations
import json, logging, socket, uuid
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen
from .postprocessing import completed, normalize_page, unavailable

logger = logging.getLogger(__name__)

def _page_boxes(metadata: dict | None, index: int) -> list:
    pages = (metadata or {}).get('ocr_boxes_by_page', [])
    return pages[index] if isinstance(pages, list) and index < len(pages) and isinstance(pages[index], list) else []

def _image_bytes(image) -> bytes:
    import numpy as np
    try:
        import cv2
        success, encoded = cv2.imencode('.png', image if isinstance(image, np.ndarray) else np.asarray(image))
        if success: return encoded.tobytes()
    except ImportError:
        pass
    # The worker normally supplies OpenCV arrays. Pillow keeps the boundary
    # usable by isolated OCR/calibration environments without a new dependency.
    from io import BytesIO
    from PIL import Image
    stream = BytesIO(); Image.fromarray(np.asarray(image)).save(stream, format='PNG')
    return stream.getvalue()

def _multipart(image_bytes: bytes, boxes: list, ocr_mask_bytes: bytes | None = None) -> tuple[bytes, str]:
    boundary = '----aics-adcd-' + uuid.uuid4().hex; body = bytearray()
    body.extend(f'--{boundary}\r\n'.encode()); body.extend(b'Content-Disposition: form-data; name="document"; filename="page.png"\r\nContent-Type: image/png\r\n\r\n'); body.extend(image_bytes); body.extend(b'\r\n')
    body.extend(f'--{boundary}\r\n'.encode()); body.extend(b'Content-Disposition: form-data; name="ocr_boxes"\r\n\r\n'); body.extend(json.dumps(boxes, separators=(',', ':')).encode()); body.extend(b'\r\n')
    if ocr_mask_bytes is not None:
        body.extend(f'--{boundary}\r\n'.encode()); body.extend(b'Content-Disposition: form-data; name="ocr_mask"; filename="ocr-mask.png"\r\nContent-Type: image/png\r\n\r\n'); body.extend(ocr_mask_bytes); body.extend(b'\r\n')
    body.extend(f'--{boundary}--\r\n'.encode())
    return bytes(body), f'multipart/form-data; boundary={boundary}'

def _service_error(exc: Exception) -> str:
    if isinstance(exc, HTTPError): return f'ADCD_SERVICE_HTTP_{exc.code}'
    if isinstance(exc, (TimeoutError, socket.timeout)): return 'ADCD_SERVICE_TIMEOUT'
    if isinstance(exc, URLError): return 'ADCD_SERVICE_UNAVAILABLE'
    if isinstance(exc, (json.JSONDecodeError, ValueError, TypeError)): return 'ADCD_SERVICE_MALFORMED_RESPONSE'
    return 'ADCD_SERVICE_ERROR'

def _analyze_page(image, boxes: list, settings, ocr_mask_image=None) -> dict:
    payload, content_type = _multipart(_image_bytes(image), boxes, _image_bytes(ocr_mask_image) if ocr_mask_image is not None else None)
    url = str(getattr(settings, 'adcd_service_url', 'http://127.0.0.1:5001')).rstrip('/') + '/analyze'
    parsed = urlparse(url)
    if parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or parsed.username or parsed.password or parsed.port is None:
        raise ValueError('ADCD_SERVICE_INVALID_URL')
    timeout = max(float(getattr(settings, 'adcd_connect_timeout_seconds', 3)), float(getattr(settings, 'adcd_read_timeout_seconds', 90)))
    request = Request(url, data=payload, headers={'Content-Type': content_type, 'Content-Length': str(len(payload))}, method='POST')
    with urlopen(request, timeout=timeout) as response: return json.loads(response.read().decode('utf-8'))

def analyze_tampering(images, settings, ocr_metadata: dict | None = None, *, include_calibration_artifacts: bool = False, calibration_ocr_masks: list | None = None) -> dict:
    """Send image bytes plus same-pass Tesseract boxes, one request per page.

    The service is never started or loaded here; every error remains a neutral
    unavailable finding so Phase 1 processing can finish.
    """
    if not getattr(settings, 'adcd_enabled', False): return unavailable('ADCD_DISABLED')
    pages = []
    for index, image in enumerate(images):
        try:
            mask = calibration_ocr_masks[index] if isinstance(calibration_ocr_masks, list) and index < len(calibration_ocr_masks) else None
            payload = _analyze_page(image, _page_boxes(ocr_metadata, index), settings, mask)
            page = normalize_page(payload, index + 1)
            if isinstance(payload.get('artifacts'), dict):
                # Calibration keeps its explicit, backwards-compatible opt-in
                # channel.  The worker uses a separate internal-only channel
                # that Stage A consumes before database persistence.
                if include_calibration_artifacts:
                    page['_calibration_artifacts'] = dict(payload['artifacts'])
                if getattr(settings, 'ai_review_artifact_root', ''):
                    page['_internal_artifacts'] = dict(payload['artifacts'])
            pages.append(page)
        except Exception as exc:
            code = _service_error(exc)
            logger.warning('ADCD-Net page analysis unavailable page=%s code=%s', index + 1, code)
            return unavailable(code, pages)
    return completed(pages)
