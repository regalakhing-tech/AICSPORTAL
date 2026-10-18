"""Stage 4-only proof that AICS Tesseract boxes work with local ADCD-Net."""
from __future__ import annotations

import json
import os
import sys
import uuid
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

import cv2

PROJECT_ROOT = Path(__file__).resolve().parents[2]
if str(PROJECT_ROOT) not in sys.path:
    sys.path.insert(0, str(PROJECT_ROOT))
from ai_processor.ocr_engine import extract_document_text


ADCD_ROOT = Path(os.getenv('AICS_ADCD_NET_ROOT', ''))
IMAGE_PATH = ADCD_ROOT / "test_inputs" / "synthetic_aics_document.png"
OUTPUT_ROOT = ADCD_ROOT / "test_outputs" / "stage4"
SERVICE_URL = os.getenv('AICS_ADCD_SERVICE_URL', 'http://127.0.0.1:5001')
TESSERACT_PATH = os.getenv('AICS_TESSERACT_PATH', '')


def _request_json(url: str, data: bytes | None = None, content_type: str | None = None) -> tuple[int, dict]:
    headers = {"Content-Type": content_type} if content_type else {}
    request = Request(url, data=data, headers=headers, method="POST" if data is not None else "GET")
    try:
        with urlopen(request, timeout=90) as response:
            return response.status, json.loads(response.read().decode("utf-8"))
    except HTTPError as exc:
        return exc.code, json.loads(exc.read().decode("utf-8"))
    except URLError as exc:
        raise RuntimeError("ADCD-Net service is unavailable.") from exc


def _multipart(document: Path, boxes: list[list[int]]) -> tuple[bytes, str]:
    boundary = "----aics-stage4-" + uuid.uuid4().hex
    payload = bytearray()
    def append(value: bytes) -> None:
        payload.extend(value)
    append(f"--{boundary}\r\n".encode())
    append(b'Content-Disposition: form-data; name="document"; filename="synthetic_aics_document.png"\r\n')
    append(b"Content-Type: image/png\r\n\r\n")
    append(document.read_bytes()); append(b"\r\n")
    append(f"--{boundary}\r\n".encode())
    append(b'Content-Disposition: form-data; name="ocr_boxes"\r\n\r\n')
    append(json.dumps(boxes, separators=(",", ":")).encode()); append(b"\r\n")
    append(f"--{boundary}--\r\n".encode())
    return bytes(payload), f"multipart/form-data; boundary={boundary}"


def main() -> int:
    if not ADCD_ROOT.is_dir():
        raise RuntimeError('AICS_ADCD_NET_ROOT is unavailable.')
    if not IMAGE_PATH.is_file():
        raise RuntimeError("Synthetic Stage 4 test image is unavailable.")
    health_status, health = _request_json(SERVICE_URL + "/health")
    if health_status != 200 or health.get("status") != "ready" or health.get("model_loaded") is not True:
        raise RuntimeError("ADCD-Net service is not ready; no inference was sent.")
    image = cv2.imread(str(IMAGE_PATH))
    if image is None:
        raise RuntimeError("Synthetic Stage 4 test image could not be read.")
    text, confidence, metadata = extract_document_text([image], TESSERACT_PATH)
    del text  # Never print or persist OCR text in this test harness.
    boxes = metadata["ocr_boxes_by_page"][0]

    # Mirror the tested Stage 2 spatial preparation only for safe reporting.
    # The ADCD service remains the sole inference owner.
    source_height, source_width = image.shape[:2]
    scale = min(1.0, 512 / max(source_width, source_height))
    resized_width = max(8, int(round(source_width * scale)) // 8 * 8)
    resized_height = max(8, int(round(source_height * scale)) // 8 * 8)
    model_size = ((max(resized_width, resized_height) + 15) // 16) * 16
    ocr_mask = cv2.resize(_boxes_to_mask(source_width, source_height, boxes), (resized_width, resized_height), interpolation=cv2.INTER_NEAREST)
    mask_nonzero = int(cv2.countNonZero(ocr_mask))
    input_metadata = {
        "model_width": model_size, "model_height": model_size,
        "tensor_shapes": {"img": [1, 3, model_size, model_size], "dct": [1, model_size, model_size], "qt": [1, 8, 8], "mask": [1, 1, model_size, model_size], "ocr_mask": [1, 1, model_size, model_size]},
        "tensor_dtypes": {"img": "float32", "dct": "int64", "qt": "int64", "mask": "int64", "ocr_mask": "int64"},
    }
    body, content_type = _multipart(IMAGE_PATH, boxes)
    response_status, response = _request_json(SERVICE_URL + "/analyze", body, content_type)
    if response_status != 200 or response.get("success") is not True:
        raise RuntimeError("ADCD-Net analysis did not succeed.")
    OUTPUT_ROOT.mkdir(parents=True, exist_ok=True)
    result = {
        "source_image": {"width": int(image.shape[1]), "height": int(image.shape[0])},
        "tesseract_box_count": len(boxes), "accepted_box_count": len(boxes),
        "mean_confidence": confidence, "model_input": input_metadata,
        "ocr_mask_nonzero_pixels": mask_nonzero, "service_response": response,
    }
    result_path = OUTPUT_ROOT / "stage4-result.json"
    result_path.write_text(json.dumps(result, indent=2), encoding="utf-8")
    print(json.dumps({
        "success": True, "tesseract_box_count": len(boxes), "accepted_box_count": len(boxes),
        "source_width": int(image.shape[1]), "source_height": int(image.shape[0]),
        "ocr_mask_nonzero_pixels": mask_nonzero, "result_path": str(result_path),
        "processing_time_seconds": response["processing_time_seconds"],
    }))
    return 0


def _boxes_to_mask(width: int, height: int, boxes: list[list[int]]):
    import numpy as np
    mask = np.zeros((height, width), dtype=np.uint8)
    for x1, y1, x2, y2 in boxes:
        cv2.rectangle(mask, (x1, y1), (x2, y2), color=1, thickness=-1)
    return mask


if __name__ == "__main__":
    raise SystemExit(main())
