"""Validation-only benchmark for weak but human-readable fictional camera photos.

The runner creates clean/weak pairs in a temporary directory, runs the current
shared OCR pipeline unchanged, and removes the images afterwards unless
``--keep-fixtures`` is supplied.  It never reads an applicant upload, database,
queue, or production review.  Expected values are held separately from the
rendered page and are used only after OCR for measurement.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import tempfile
import time
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont

from ai_processor.document_classifier import classify_document
from ai_processor.document_loader import load_images
from ai_processor.field_extractor import extract_document_fields
from ai_processor.image_quality import analyze_document_quality
from ai_processor.ocr_engine import (
    ROI_EXTRACTION_PROFILES,
    _ocr_candidates,
    _page_from_data,
    extract_document_text,
    resolve_safe_roi_fields,
    retry_philid_field_rois,
)


FONT = Path(r"C:\Windows\Fonts\arial.ttf")
TESSERACT = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
POPLER = r"C:\ProgramData\miniforge3\Library\bin"


# These values are deliberately fictional and are not part of the rendered
# source text parser.  They are compared only after each OCR pass finishes.
FIXTURES = (
    {
        "id": "valid_id_poblacion", "profile": "Valid ID", "expected_type": "valid_id",
        "fields": {"name": "ANA REYES", "birth_date": "1992-03-14", "address": "POBLACION 4, HAMTIC, ANTIQUE"},
        "lines": ("PHILIPPINE IDENTIFICATION CARD", "REPUBLIC OF THE PHILIPPINES", "PHILSYS SAMPLE CARD", "Given Names: ANA", "Last Name: REYES", "Date of Birth: March 14, 1992", "Address: POBLACION 4, HAMTIC, ANTIQUE", "Sex: Female", "Valid Until: December 31, 2030"),
    },
    {
        "id": "valid_id_purok", "profile": "Valid ID", "expected_type": "valid_id",
        "fields": {"name": "BEN SANTOS", "birth_date": "1988-11-05", "address": "PUROK 2, BRGY. CALALA HAMTIC, ANTIQUE"},
        "lines": ("PHILIPPINE IDENTIFICATION CARD", "REPUBLIC OF THE PHILIPPINES", "PHILSYS SAMPLE CARD", "Given Names: BEN", "Last Name: SANTOS", "Date of Birth: November 5, 1988", "Address: PUROK 2, BRGY. CALALA", "HAMTIC, ANTIQUE", "Sex: Male", "Valid Until: December 31, 2030"),
    },
    {
        "id": "valid_id_rizal", "profile": "Valid ID", "expected_type": "valid_id",
        "fields": {"name": "CARA RAMOS", "birth_date": "1995-07-21", "address": "123 RIZAL STREET SAN JOSE DE BUENAVISTA, ANTIQUE"},
        "lines": ("PHILIPPINE IDENTIFICATION CARD", "REPUBLIC OF THE PHILIPPINES", "PHILSYS SAMPLE CARD", "Given Names: CARA", "Last Name: RAMOS", "Date of Birth: July 21, 1995", "Address: 123 RIZAL STREET", "SAN JOSE DE BUENAVISTA, ANTIQUE", "Sex: Female", "Valid Until: December 31, 2030"),
    },
    {
        "id": "medical_certificate", "profile": "Medical", "expected_type": "med_cert",
        "fields": {"patient_name": "DINA FLORES"},
        "lines": ("MEDICAL CERTIFICATE", "SAMPLE PROVINCIAL HOSPITAL", "Patient Name: DINA FLORES", "Date of Birth: April 9, 1990", "Diagnosis: SYNTHETIC CONDITION", "This is to certify that the patient was examined.", "Attending Physician: DR SAMPLE DOCTOR", "Date Issued: September 10, 2026"),
    },
    {
        "id": "death_certificate", "profile": "Death", "expected_type": "death_cert",
        "fields": {"deceased_name": "ELENA CRUZ"},
        "lines": ("CERTIFICATE OF DEATH", "OFFICE OF THE MUNICIPAL CIVIL REGISTRAR", "Name of Deceased: ELENA CRUZ", "Date of Birth: January 4, 1955", "Sex: Female", "Date of Death: September 3, 2026", "Place of Death: SAMPLE MUNICIPALITY", "Medical Certification of Death"),
    },
    {
        "id": "police_report", "profile": "Police", "expected_type": "report_police_med",
        "fields": {"subject_name": "FELIX REYES"},
        "lines": ("PHILIPPINE NATIONAL POLICE", "POLICE REPORT", "Victim Name: FELIX REYES", "Reporting Person: SAMPLE REPORTER", "Incident Date: September 7, 2026", "Police Station: SAMPLE STATION", "Brief Facts of the Incident", "Investigating Officer: SAMPLE OFFICER"),
    },
    {
        "id": "school_assessment", "profile": "Education", "expected_type": "school_assessment",
        "fields": {"client_name": "GINA SANTOS"},
        "lines": ("SAMPLE NATIONAL HIGH SCHOOL", "SCHOOL ASSESSMENT FORM", "Student Name: GINA SANTOS", "School Year: 2026 2027", "Grade Level: 10", "Assessment Details", "Date Assessed: September 8, 2026", "School: SAMPLE NATIONAL HIGH SCHOOL"),
    },
    {
        "id": "drrm_certification", "profile": "DRRM", "expected_type": "mdrrmo_cert",
        "fields": {"client_name": "HUGO RAMOS"},
        "lines": ("MUNICIPAL DISASTER RISK REDUCTION AND MANAGEMENT OFFICE", "CERTIFICATION OF DAMAGE", "Name of Affected Person: HUGO RAMOS", "Residential House Affected by Typhoon Sample", "Based on Field Validation", "Date Issued: September 9, 2026", "Certified by: SAMPLE MDRRMO OFFICER"),
    },
    {
        "id": "eclip_certification", "profile": "Former Rebel", "expected_type": "eclip_cert",
        "fields": {"client_name": "IVY DELA CRUZ"},
        "lines": ("ENHANCED COMPREHENSIVE LOCAL INTEGRATION PROGRAM", "FORMER REBEL ELIGIBILITY CERTIFICATION", "Beneficiary: IVY DELA CRUZ", "This is to certify that the beneficiary is eligible.", "Certification Date: September 10, 2026", "Certifying Officer: SAMPLE OFFICER"),
    },
    {
        "id": "crisis_certification", "profile": "Crisis Certificate", "expected_type": "crisis_situation_cert",
        "fields": {"client_name": "JAY VILLANUEVA"},
        "lines": ("CERTIFICATE OF PERSON IN CRISIS SITUATION", "MSWDO SOCIAL WELFARE CERTIFICATION", "Beneficiary: JAY VILLANUEVA", "This is to certify that the beneficiary needs assistance.", "Certification Date: September 11, 2026", "Social Worker: SAMPLE WORKER"),
    },
)


def _font(size: int):
    return ImageFont.truetype(str(FONT), size) if FONT.is_file() else ImageFont.load_default()


def _render_clean(spec: dict) -> np.ndarray:
    """Render a legible government-style fictional form with deterministic text."""
    page = Image.new("RGB", (980, 1380), "#faf9f4")
    draw = ImageDraw.Draw(page)
    draw.rectangle((34, 34, 946, 1346), outline="#222222", width=3)
    draw.text((74, 68), "SYNTHETIC VALIDATION DOCUMENT - NOT VALID", fill="#333333", font=_font(18))
    y = 138
    for index, line in enumerate(spec["lines"]):
        size = 29 if index == 0 else 24
        if len(line) > 53:
            size = 18
        elif len(line) > 42:
            size = 21
        draw.text((75, y), line, fill="#101010", font=_font(size))
        y += 82 if index == 0 else 64
    draw.line((75, 1210, 445, 1210), fill="#222222", width=2)
    draw.text((75, 1225), "Synthetic signature line", fill="#333333", font=_font(17))
    return cv2.cvtColor(np.asarray(page), cv2.COLOR_RGB2BGR)


def _weak_camera_page(clean: np.ndarray, seed: int) -> np.ndarray:
    """Create a deterministic 53%-of-frame camera scene, still human-readable."""
    rng = np.random.default_rng(seed)
    height, width = 1800, 1500
    texture = rng.normal(0, 6, (height, width, 1)).astype(np.int16)
    background = np.full((height, width, 3), (119, 125, 122), dtype=np.int16)
    background += texture
    background = np.clip(background, 0, 255).astype(np.uint8)
    source_h, source_w = clean.shape[:2]
    source = np.float32(((0, 0), (source_w - 1, 0), (source_w - 1, source_h - 1), (0, source_h - 1)))
    destination = np.float32(((215, 165), (1190, 205), (1275, 1640), (170, 1600)))
    matrix = cv2.getPerspectiveTransform(source, destination)
    warped = cv2.warpPerspective(clean, matrix, (width, height), borderValue=(0, 0, 0))
    mask = cv2.warpPerspective(np.full((source_h, source_w), 255, dtype=np.uint8), matrix, (width, height))
    background[mask > 0] = warped[mask > 0]
    illumination = (np.linspace(.70, 1.0, width, dtype=np.float32)[None, :, None]
                    * np.linspace(.92, 1.0, height, dtype=np.float32)[:, None, None])
    camera = np.clip(background.astype(np.float32) * illumination, 0, 255).astype(np.uint8)
    shadow = np.zeros((height, width), dtype=np.uint8)
    cv2.fillConvexPoly(shadow, np.array(((150, 630), (1280, 460), (1330, 740), (205, 960)), dtype=np.int32), 64)
    camera = np.clip(camera.astype(np.int16) - shadow[:, :, None], 0, 255).astype(np.uint8)
    camera = cv2.GaussianBlur(camera, (3, 3), .65)
    camera = np.clip((camera.astype(np.float32) - 128) * .88 + 128, 0, 255).astype(np.uint8)
    # JPEG round-trip produces moderate, realistic compression without making
    # target lines unreadable to a person at normal viewing size.
    ok, encoded = cv2.imencode(".jpg", camera, (cv2.IMWRITE_JPEG_QUALITY, 60))
    return cv2.imdecode(encoded, cv2.IMREAD_COLOR) if ok else camera


def _compact(value: object) -> str:
    return re.sub(r"[^A-Z0-9]", "", str(value or "").upper())


def _status(field: dict, expected: str) -> str:
    value = field.get("normalized") if isinstance(field, dict) else None
    if value not in (None, "") and field.get("reliable") and _compact(value) == _compact(expected):
        return "CORRECT"
    if value not in (None, ""):
        return "WRONG"
    return "SAFE UNCERTAIN"


def _raw_label_value(text: str, field: str) -> str | None:
    labels = {
        "name": ("given names", "full name", "name"),
        "patient_name": ("patient name", "name of patient", "patient"),
        "deceased_name": ("name of deceased", "deceased name", "deceased"),
        "subject_name": ("victim name", "victim", "person served"),
        "client_name": ("student name", "learner name", "name of affected person", "beneficiary"),
        "birth_date": ("date of birth", "birth date", "dob"),
        "address": ("address", "tirahan"),
    }.get(field, ())
    for line in text.splitlines():
        compact = re.sub(r"\s+", " ", line).strip()
        for label in labels:
            match = re.search(r"\b" + re.escape(label) + r"\s*[:\-]\s*(.+)$", compact, re.I)
            if match and match.group(1).strip():
                return match.group(1).strip()
    return None


def _cached_candidate_fields(metadata: dict, expected_type: str, candidate_name: str = "original") -> tuple[str, dict]:
    """Read a candidate already OCRed by the normal bounded page stage."""
    candidate = next((item for item in metadata.get("field_candidates", []) if item.get("candidate") == candidate_name), None)
    if not candidate:
        return "", {}
    text = str(candidate.get("text", ""))
    candidate_metadata = {"mean_confidence": candidate.get("mean_confidence", 0.0), "pages": candidate.get("pages", [])}
    return text, extract_document_fields(expected_type, text, candidate_metadata, _field_candidates=False)["fields"]


def _candidate_observations(metadata: dict, expected_type: str, expected_fields: dict) -> list[dict]:
    """Report cached normal-stage candidates without any diagnostic re-OCR."""
    observations = []
    for candidate in metadata.get("field_candidates", []):
        name = str(candidate.get("candidate", "unknown"))
        text, fields = _cached_candidate_fields(metadata, expected_type, name)
        page = (candidate.get("pages") or [{}])[0]
        observations.append({
            "candidate": name, "transform": candidate.get("transform"), "crop_applied": bool((page.get("document_region") or {}).get("applied")),
            "ocr_seconds": round(sum(float(item.get("ocr_seconds", 0.0)) for item in candidate.get("pages", [])), 4),
            "field_outcomes": {key: _status(fields.get(key, {}), expected) for key, expected in expected_fields.items()},
        })
    return observations


def _run_one(path: Path, spec: dict, variant: str) -> dict:
    started = time.perf_counter()
    loaded = load_images(path, 1, POPLER, 45)
    load_seconds = time.perf_counter() - started
    image = loaded[0]
    started = time.perf_counter()
    text, confidence, metadata = extract_document_text(loaded, TESSERACT, 45)
    ocr_seconds = time.perf_counter() - started
    classification = classify_document(text, spec["expected_type"])
    fields = extract_document_fields(spec["expected_type"], text, metadata)["fields"]
    initial_fields = {key: dict(fields.get(key, {})) for key in spec["fields"]}
    profile, roi_weak, roi_eligibility = resolve_safe_roi_fields(spec["expected_type"], classification, metadata, weak_fields := {key for key in spec["fields"] if fields.get(key, {}).get("status") != "extracted"})
    started = time.perf_counter()
    if profile and roi_weak:
        metadata = retry_philid_field_rois(loaded, metadata, TESSERACT, roi_weak, 45, profile)
        fields = extract_document_fields(spec["expected_type"], text, metadata)["fields"]
    roi_seconds = time.perf_counter() - started if profile and roi_weak else 0.0
    quality = analyze_document_quality(loaded, 80, text, confidence, fields)
    page = metadata.get("pages", [{}])[0]
    target = {}
    for key, expected in spec["fields"].items():
        field = fields.get(key, {})
        target[key] = {
            "expected": expected,
            "raw_label_ocr": _raw_label_value(text, key),
            "final_value": field.get("normalized"),
            "outcome": _status(field, expected),
            "reliable": bool(field.get("reliable")),
            "status": field.get("status"),
            "source": field.get("context") or "not_persisted",
            "field_candidate": field.get("field_candidate"),
            "field_candidate_score": field.get("field_candidate_score"),
            "initial_outcome": _status(initial_fields.get(key, {}), expected),
        }
    row = {
        "fixture": spec["id"], "variant": variant, "profile": spec["profile"], "expected_type": spec["expected_type"],
        "image_dimensions": [int(image.shape[1]), int(image.shape[0])], "document_crop": page.get("document_region"),
        "crop_accepted": bool((page.get("document_region") or {}).get("applied")),
        "selected_candidate": page.get("selected_candidate"), "transform": page.get("transform"),
        "detected_type": classification.get("detected_type"), "subtype": classification.get("document_subtype"),
        "classification_band": classification.get("confidence_band"), "roi_calls": metadata.get("roi_retry_calls", 0),
        "roi_eligibility": roi_eligibility,
        "text_readability": quality.get("ocr_readability"), "ocr_confidence": round(float(confidence), 2),
        "fields": target, "load_seconds": round(load_seconds, 3), "ocr_seconds": round(ocr_seconds, 3),
        "roi_seconds": round(roi_seconds, 3), "total_seconds": round(load_seconds + ocr_seconds + roi_seconds, 3),
        "ocr_call_metrics": metadata.get("ocr_call_metrics", []), "roi_call_metrics": metadata.get("roi_call_metrics", []),
        "duplicate_full_page_calls": False,
    }
    if variant == "weak":
        plain_text, plain_fields = _cached_candidate_fields(metadata, spec["expected_type"], "original")
        row["plain_tesseract"] = {
            key: {"raw_label_ocr": _raw_label_value(plain_text, key), "outcome": _status(plain_fields.get(key, {}), expected)}
            for key, expected in spec["fields"].items()
        }
        row["candidate_observations"] = _candidate_observations(metadata, spec["expected_type"], spec["fields"])
    return row


def _summary(rows: list[dict]) -> dict:
    weak = [row for row in rows if row["variant"] == "weak"]
    fields = ("name", "patient_name", "deceased_name", "subject_name", "client_name", "birth_date", "address")
    grouped = {"identity": ["name", "patient_name", "deceased_name", "subject_name", "client_name"], "DOB": ["birth_date"], "address": ["address"]}
    result = {"weak_fixture_count": len(weak), "all_fictional": True, "groups": {}, "by_profile": {}, "candidate_help": {}, "privacy_pass": True}
    for title, keys in grouped.items():
        counts = {"CORRECT": 0, "SAFE UNCERTAIN": 0, "WRONG": 0}
        for row in weak:
            for key in keys:
                if key in row["fields"]:
                    counts[row["fields"][key]["outcome"]] += 1
        result["groups"][title] = counts
    for row in weak:
        profile = result["by_profile"].setdefault(row["profile"], {"CORRECT": 0, "SAFE UNCERTAIN": 0, "WRONG": 0})
        for field in row["fields"].values():
            profile[field["outcome"]] += 1
        for field in row["fields"].values():
            if field["outcome"] == "WRONG":
                result["privacy_pass"] = False
    for name in ("original", "clahe", "sharpened", "deskewed", "rectified"):
        result["candidate_help"][name] = sum(
            1 for row in weak if row.get("selected_candidate") == name
            and any(field["outcome"] == "CORRECT" and field["initial_outcome"] == "CORRECT" for field in row["fields"].values()))
    result["crop_helped_over_plain"] = sum(
        1 for row in weak if row["crop_accepted"] and any(
            field["outcome"] == "CORRECT" and row["plain_tesseract"].get(key, {}).get("outcome") != "CORRECT"
            for key, field in row["fields"].items()))
    result["roi_helped"] = sum(
        1 for row in weak if row["roi_calls"] and any(
            field["outcome"] == "CORRECT" and field["initial_outcome"] != "CORRECT" for field in row["fields"].values()))
    result["recovered_over_plain"] = sum(
        1 for row in weak for key, field in row["fields"].items()
        if field["outcome"] == "CORRECT" and row["plain_tesseract"].get(key, {}).get("outcome") != "CORRECT")
    result["weak_mean_seconds"] = round(sum(row["total_seconds"] for row in weak) / max(1, len(weak)), 3)
    return result


def _roi_candidate_diagnostic(image: np.ndarray, page: dict, field: str, profile: str) -> dict:
    """Observe the existing label-relative ROI math without changing it."""
    import pytesseract
    pytesseract.pytesseract.tesseract_cmd = TESSERACT
    anchors = ROI_EXTRACTION_PROFILES[profile]["anchors"].get(field, ())
    image_h, image_w = image.shape[:2]
    for line in page.get("lines", []):
        line_text = str(line.get("text", "")).lower()
        if not any(anchor in line_text for anchor in anchors):
            continue
        box = line.get("box", [])
        if len(box) != 4:
            continue
        x1, y1, x2, y2 = [int(value) for value in box]
        height = max(1, y2 - y1)
        top, bottom = max(0, y2), min(image_h, y2 + height * (8 if field == "address" else 3))
        left, right = max(0, x1 - height), min(image_w, max(x2 + height * 10, int(image_w * .75)))
        if right - left < 24 or bottom - top < 12:
            return {"anchor": line.get("text"), "roi": None, "candidates": []}
        def observe(region, scale):
            enlarged = cv2.resize(region, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
            gray = cv2.cvtColor(enlarged, cv2.COLOR_BGR2GRAY)
            clahe = cv2.createCLAHE(clipLimit=1.5, tileGridSize=(8, 8)).apply(gray)
            sharp = cv2.addWeighted(clahe, 1.3, cv2.GaussianBlur(clahe, (0, 0), 1.0), -0.3, 0)
            config = "--psm 6" if field == "address" else "--psm 7"
            results = []
            for name, candidate in (("CLAHE", clahe), ("sharpened", sharp)):
                data = pytesseract.image_to_data(candidate, output_type=pytesseract.Output.DICT, timeout=45, config=config)
                words = [str(word).strip() for word in data.get("text", []) if str(word).strip()]
                confidences = [float(value) for value in data.get("conf", []) if str(value).replace(".", "", 1).replace("-", "", 1).isdigit() and float(value) >= 0]
                results.append({"candidate": name, "text": " ".join(words), "mean_confidence": round(sum(confidences) / len(confidences), 2) if confidences else 0.0})
            return results
        below = image[top:bottom, left:right]
        # Diagnostic only: the current production retry uses ``below``.  A
        # same-line pass tells us whether that geometry misses a printed
        # label/value row; it does not change the current extraction path.
        same_top, same_bottom = max(0, y1 - height), min(image_h, y2 + height)
        same_left, same_right = max(0, x1 - height), min(image_w, max(x2 + height * 6, int(image_w * .75)))
        same_line = image[same_top:same_bottom, same_left:same_right]
        return {"anchor": line.get("text"), "roi": [left, top, right, bottom], "character_height_before_scale": height, "scale": 2, "candidates": observe(below, 2), "same_line_diagnostic": {"roi": [same_left, same_top, same_right, same_bottom], "scale": 3, "candidates": observe(same_line, 3)}}
    return {"anchor": None, "roi": None, "candidates": []}


def diagnose_valid_ids(output: Path) -> list[dict]:
    """Targeted post-run evidence for the three weak fictional Valid IDs only."""
    results = []
    for index, spec in enumerate(FIXTURES[:3]):
        image = _weak_camera_page(_render_clean(spec), 100 + index)
        text, confidence, metadata = extract_document_text([image], TESSERACT, 45)
        page = metadata.get("pages", [{}])[0]
        classification = classify_document(text, spec["expected_type"])
        before = extract_document_fields("valid_id", text, metadata)["fields"]
        roi_details = {field: _roi_candidate_diagnostic(image, page, field, "valid_id") for field in ("name", "birth_date", "address")}
        forced = retry_philid_field_rois([image], metadata, TESSERACT, {"name", "birth_date", "address"}, 45, "valid_id")
        forced_fields = extract_document_fields("valid_id", text, forced)["fields"]
        plain_text, plain_fields = _cached_candidate_fields(metadata, "valid_id", "original")
        selected_name_lines = [line.get("text") for line in page.get("lines", []) if re.search(r"given names|last name|full name|name", str(line.get("text", "")), re.I)]
        row = {
            "fixture": spec["id"], "expected": spec["fields"], "dimensions": [int(image.shape[1]), int(image.shape[0])],
            "selected_candidate": page.get("selected_candidate"), "document_region": page.get("document_region"),
            "ocr_confidence": round(float(confidence), 2), "classification": {"detected_type": classification.get("detected_type"), "band": classification.get("confidence_band")},
            "selected_name_lines": selected_name_lines,
            "plain_name_region": _raw_label_value(plain_text, "name"),
            "plain_name_outcome": _status(plain_fields.get("name", {}), spec["fields"]["name"]),
            "pipeline_name_components": selected_name_lines,
            "before_reliability_candidate": " ".join(re.sub(r"^.*?:\s*", "", line) for line in selected_name_lines if ":" in line),
            "pipeline_fields": {key: {"value": field.get("normalized"), "status": field.get("status"), "reliable": field.get("reliable"), "reason": field.get("reason_if_unreliable")} for key, field in before.items() if key in spec["fields"]},
            "forced_roi_calls": forced.get("roi_retry_calls", 0), "roi": roi_details,
            "after_forced_roi": {key: {"value": field.get("normalized"), "status": field.get("status"), "reliable": field.get("reliable"), "reason": field.get("reason_if_unreliable")} for key, field in forced_fields.items() if key in spec["fields"]},
        }
        results.append(row)
        print(json.dumps(row), flush=True)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(results, indent=2), encoding="utf-8")
    return results


def run(output: Path, keep_fixtures: Path | None = None, selected: set[str] | None = None) -> tuple[list[dict], dict]:
    root = keep_fixtures if keep_fixtures else Path(tempfile.mkdtemp(prefix="aics-weak-camera-fixtures-"))
    root.mkdir(parents=True, exist_ok=True)
    rows = []
    try:
        for index, spec in enumerate(FIXTURES):
            if selected and spec['id'] not in selected:
                continue
            clean_path = root / f"{spec['id']}_clean.png"
            weak_path = root / f"{spec['id']}_weak_camera.jpg"
            clean = _render_clean(spec)
            cv2.imwrite(str(clean_path), clean)
            cv2.imwrite(str(weak_path), _weak_camera_page(clean, 100 + index), (cv2.IMWRITE_JPEG_QUALITY, 60))
            for path, variant in ((clean_path, "clean"), (weak_path, "weak")):
                row = _run_one(path, spec, variant)
                rows.append(row)
                print(json.dumps(row), flush=True)
        summary = _summary(rows)
        print(json.dumps({"summary": summary}), flush=True)
        output.parent.mkdir(parents=True, exist_ok=True)
        with output.open("w", encoding="utf-8") as handle:
            for row in rows:
                handle.write(json.dumps(row) + "\n")
            handle.write(json.dumps({"summary": summary}) + "\n")
        return rows, summary
    finally:
        if not keep_fixtures:
            shutil.rmtree(root, ignore_errors=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path(os.environ.get("TEMP", tempfile.gettempdir())) / "aics-weak-camera-ocr-results.jsonl")
    parser.add_argument("--keep-fixtures", type=Path, help="Optional validation-only folder; omitted means temporary fixtures are removed.")
    parser.add_argument("--diagnose-valid-id", action="store_true", help="Run targeted in-memory diagnostics for the three weak fictional Valid IDs only.")
    parser.add_argument("--fixture", action="append", dest="fixtures", help="Run only a named fictional fixture pair.")
    args = parser.parse_args()
    if args.diagnose_valid_id:
        diagnose_valid_ids(args.output)
    else:
        run(args.output, args.keep_fixtures, set(args.fixtures or ()))
