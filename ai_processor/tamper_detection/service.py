"""Stage 3 local-only ADCD-Net HTTP service.

Run this module with ADCD-Net's dedicated virtual environment.  The process
keeps one CPU model instance in memory and serializes inference with a global
lock; it is deliberately not connected to PHP or the AICS worker yet.
"""
from __future__ import annotations

import cgi
import importlib.util
import json
import logging
import os
import sys
import tempfile
import threading
import time
import uuid
from http import HTTPStatus
from pathlib import Path
from typing import Any, Callable

from waitress import serve


HOST, PORT, MAX_UPLOAD_BYTES = "127.0.0.1", 5001, 25 * 1024 * 1024
def _configured_directory(name: str, default: Path | None = None) -> Path:
    raw = str(os.getenv(name, '')).strip()
    path = Path(raw).expanduser().resolve() if raw else default
    if path is None or not path.is_dir():
        raise RuntimeError('ADCD deployment configuration is invalid.')
    return path


ADCD_ROOT = _configured_directory("AICS_ADCD_NET_ROOT")
OUTPUT_ROOT = _configured_directory("AICS_ADCD_OUTPUT_ROOT", ADCD_ROOT / "test_outputs" / "service")
ALLOWED_SUFFIXES = {".jpg", ".jpeg", ".png"}

LOG = logging.getLogger("aics_adcd_service")
LOG.setLevel(logging.INFO)
if not LOG.handlers:
    _formatter = logging.Formatter("%(asctime)s %(levelname)s %(name)s %(message)s")
    _stream = logging.StreamHandler()
    _stream.setFormatter(_formatter)
    LOG.addHandler(_stream)
    try:
        _log_dir = Path(__file__).resolve().parents[1] / "logs"
        _log_dir.mkdir(parents=True, exist_ok=True)
        _file = logging.FileHandler(_log_dir / "adcd-service.log", encoding="utf-8")
        _file.setFormatter(_formatter)
        LOG.addHandler(_file)
    except OSError:
        # Console logging remains available when a host denies log-file writes.
        pass
MODEL_LOCK = threading.Lock()
INFERENCE_LOCK = threading.Lock()  # One CPU inference at a time by design.
STATE: dict[str, Any] = {"model": None, "report": None, "status": "not_loaded", "error": None}


def _inference_module():
    path = ADCD_ROOT / "aics_inference.py"
    if not path.is_file():
        raise RuntimeError("ADCD-Net standalone inference module is unavailable.")
    module_name = "aics_adcd_standalone"
    module = sys.modules.get(module_name)
    if module is not None:
        return module
    spec = importlib.util.spec_from_file_location(module_name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError("ADCD-Net standalone inference module cannot be loaded.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


def _json(start_response: Callable, status: HTTPStatus, payload: dict[str, Any]):
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    start_response(f"{status.value} {status.phrase}", [("Content-Type", "application/json"), ("Content-Length", str(len(body)))])
    return [body]


def _safe_error(start_response: Callable, status: HTTPStatus, message: str):
    return _json(start_response, status, {"success": False, "error": message})


def _load_once() -> tuple[bool, dict[str, Any]]:
    with MODEL_LOCK:
        if STATE["model"] is not None:
            return False, {"success": True, "model_loaded": True, "already_loaded": True, "device": "cpu", **STATE["report"]}
        started = time.perf_counter()
        STATE.update(status="loading", error=None)
        LOG.info("model load started")
        try:
            module = _inference_module()
            model, report = module._load_model(ADCD_ROOT)
            duration = time.perf_counter() - started
            STATE.update(model=model, report=report, status="ready", error=None)
            LOG.info("model load complete duration_seconds=%.3f", duration)
            return True, {"success": True, "model_loaded": True, "already_loaded": False, "device": "cpu", "load_time_seconds": duration, **report}
        except Exception as exc:
            STATE.update(model=None, report=None, status="error", error=type(exc).__name__)
            LOG.exception("model load failed")
            raise RuntimeError("ADCD-Net model could not be loaded.") from exc


def _health() -> dict[str, Any]:
    payload = {"service": "aics-adcd-net", "status": STATE["status"], "model_loaded": STATE["model"] is not None, "device": "cpu", "model": "ADCD-Net"}
    if STATE["status"] == "error":
        payload["error"] = "Model load failed. Check the local service log."
    return payload


def _parse_form(environ: dict[str, Any]) -> tuple[Any, str, Any | None]:
    try:
        length = int(environ.get("CONTENT_LENGTH") or "0")
    except ValueError as exc:
        raise ValueError("Invalid request length.") from exc
    if length <= 0:
        raise ValueError("A document upload is required.")
    if length > MAX_UPLOAD_BYTES:
        raise OverflowError("Document exceeds the 25 MB limit.")
    form = cgi.FieldStorage(fp=environ["wsgi.input"], environ=environ, keep_blank_values=True)
    if "document" not in form:
        raise ValueError("A document upload is required.")
    uploaded = form["document"]
    if not getattr(uploaded, "file", None) or not getattr(uploaded, "filename", ""):
        raise ValueError("A document upload is required.")
    mask_upload = form["ocr_mask"] if "ocr_mask" in form else None
    if mask_upload is not None and (not getattr(mask_upload, "file", None) or Path(str(getattr(mask_upload, "filename", ""))).suffix.lower() != ".png"):
        raise ValueError("OCR mask must be an uploaded PNG image.")
    return uploaded, str(form.getfirst("ocr_boxes", "[]")), mask_upload


def _analyze(environ: dict[str, Any], start_response: Callable):
    if STATE["model"] is None:
        return _safe_error(start_response, HTTPStatus.SERVICE_UNAVAILABLE, "ADCD-Net model is not loaded.")
    try:
        uploaded, boxes_raw, mask_upload = _parse_form(environ)
        suffix = Path(str(uploaded.filename)).suffix.lower()
        if suffix not in ALLOWED_SUFFIXES:
            return _safe_error(start_response, HTTPStatus.UNSUPPORTED_MEDIA_TYPE, "Unsupported document type. Use JPG, JPEG, or PNG.")
        module = _inference_module()
        boxes = module._parse_boxes(boxes_raw)
    except OverflowError as exc:
        return _safe_error(start_response, HTTPStatus.REQUEST_ENTITY_TOO_LARGE, str(exc))
    except ValueError as exc:
        return _safe_error(start_response, HTTPStatus.BAD_REQUEST, str(exc))
    except Exception:
        LOG.exception("invalid analysis request")
        return _safe_error(start_response, HTTPStatus.BAD_REQUEST, "Invalid analysis request.")

    temp_name = ""
    temp_mask_name = ""
    request_id = uuid.uuid4().hex
    try:
        with tempfile.NamedTemporaryFile(prefix="aics-adcd-", suffix=suffix, delete=False) as temporary:
            temp_name = temporary.name
            uploaded_bytes = 0
            while True:
                chunk = uploaded.file.read(1024 * 1024)
                if not chunk:
                    break
                temporary.write(chunk)
                uploaded_bytes += len(chunk)
                if uploaded_bytes > MAX_UPLOAD_BYTES:
                    raise OverflowError("Document exceeds the 25 MB limit.")
        if mask_upload is not None:
            with tempfile.NamedTemporaryFile(prefix="aics-adcd-mask-", suffix=".png", delete=False) as temporary_mask:
                temp_mask_name = temporary_mask.name
                temporary_mask.write(mask_upload.file.read(MAX_UPLOAD_BYTES + 1))
                if temporary_mask.tell() > MAX_UPLOAD_BYTES:
                    raise OverflowError("OCR mask exceeds the 25 MB limit.")
        output_dir = OUTPUT_ROOT / request_id
        LOG.info("analysis start request_id=%s input_type=%s input_bytes=%s ocr_boxes=%s", request_id, suffix, uploaded_bytes, len(boxes))
        with INFERENCE_LOCK:
            result = module.analyze_loaded(STATE["model"], STATE["report"], Path(temp_name), boxes, output_dir, Path(temp_mask_name) if temp_mask_name else None)
        output = result["output"]
        response = {
            "success": True, "model": "ADCD-Net", "device": "cpu",
            "processing_time_seconds": result["processing_time_seconds"],
            "tampered_pixel_ratio": output["binary_tampered_pixel_ratio"],
            "max_tamper_probability": output["max_probability"],
            "mean_tamper_probability": output["mean_probability"],
            "output_width": output["probability_map_shape"][1], "output_height": output["probability_map_shape"][0],
            "artifacts": result["artifacts"],
        }
        LOG.info("analysis complete request_id=%s duration_seconds=%.3f ratio=%.8f", request_id, result["processing_time_seconds"], output["binary_tampered_pixel_ratio"])
        return _json(start_response, HTTPStatus.OK, response)
    except OverflowError as exc:
        return _safe_error(start_response, HTTPStatus.REQUEST_ENTITY_TOO_LARGE, str(exc))
    except Exception as exc:
        LOG.exception("analysis failed request_id=%s category=%s", request_id, type(exc).__name__)
        return _safe_error(start_response, HTTPStatus.INTERNAL_SERVER_ERROR, "ADCD-Net analysis failed. Check the local service log.")
    finally:
        if temp_name:
            Path(temp_name).unlink(missing_ok=True)
        if temp_mask_name:
            Path(temp_mask_name).unlink(missing_ok=True)


def application(environ: dict[str, Any], start_response: Callable):
    method, path = environ.get("REQUEST_METHOD", ""), environ.get("PATH_INFO", "")
    if method == "GET" and path == "/health":
        return _json(start_response, HTTPStatus.OK, _health())
    if method == "POST" and path == "/load":
        try:
            _, payload = _load_once()
            return _json(start_response, HTTPStatus.OK, payload)
        except RuntimeError as exc:
            return _safe_error(start_response, HTTPStatus.INTERNAL_SERVER_ERROR, str(exc))
    if method == "POST" and path == "/analyze":
        return _analyze(environ, start_response)
    return _safe_error(start_response, HTTPStatus.NOT_FOUND, "Endpoint not found.")


if __name__ == "__main__":
    LOG.info("service startup host=%s port=%s", HOST, PORT)
    serve(application, host=HOST, port=PORT, threads=1)
