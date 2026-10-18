"""One Stage 5 synthetic, database-free Phase 1 + ADCD integration proof."""
from __future__ import annotations
import json, os, sys, time
from pathlib import Path
from types import SimpleNamespace
from urllib.request import urlopen

PROJECT_ROOT = Path(__file__).resolve().parents[2]
if str(PROJECT_ROOT) not in sys.path: sys.path.insert(0, str(PROJECT_ROOT))
from ai_processor.document_loader import load_images
from ai_processor.ocr_engine import extract_document_text
from ai_processor.document_classifier import classify_document
from ai_processor.field_extractor import extract
from ai_processor.image_quality import analyze_document_quality
from ai_processor.verification import evaluate
from ai_processor.tamper_detection.adapter import analyze_tampering

ROOT = Path(os.getenv('AICS_ADCD_NET_ROOT', ''))
IMAGE = ROOT / 'test_inputs' / 'synthetic_aics_document.png'
OUTPUT = ROOT / 'test_outputs' / 'stage5' / 'pipeline-result.json'

def main() -> int:
    if not ROOT.is_dir(): raise RuntimeError('AICS_ADCD_NET_ROOT is unavailable.')
    with urlopen('http://127.0.0.1:5001/health', timeout=3) as response: health = json.loads(response.read())
    if health.get('status') != 'ready' or health.get('model_loaded') is not True: raise RuntimeError('ADCD-Net service is not ready.')
    started = time.perf_counter()
    images = load_images(IMAGE, max_pages=3)
    text, confidence, metadata = extract_document_text(images, os.getenv('AICS_TESSERACT_PATH', ''))
    expected = 'valid_id'; classification = classify_document(text, expected)
    type_confidence = 90 if classification['confidence_band'] == 'high' else (55 if classification['confidence_band'] == 'moderate' else 20)
    fields = extract(text, expected, metadata)
    quality = analyze_document_quality(images, 80, text, confidence, fields)
    phase_one = evaluate(expected, classification['detected_type'], type_confidence, quality, fields, 'TEST APPLICANT 001', [], None, classification, {'full_name': 'TEST APPLICANT 001'})
    settings = SimpleNamespace(adcd_enabled=True, adcd_service_url='http://127.0.0.1:5001', adcd_connect_timeout_seconds=3, adcd_read_timeout_seconds=90)
    tampering = analyze_tampering(images, settings, metadata)
    phase_one.setdefault('findings', {})['tampering'] = tampering
    provider = tampering['providers']['adcd_net']
    result = {'service_health': health, 'ocr_box_count': len(metadata['ocr_boxes_by_page'][0]), 'phase_one_status': phase_one['status'], 'tampering': provider, 'total_processing_time_seconds': time.perf_counter() - started}
    OUTPUT.parent.mkdir(parents=True, exist_ok=True); OUTPUT.write_text(json.dumps(result, indent=2), encoding='utf-8')
    print(json.dumps({'success': True, 'ocr_box_count': result['ocr_box_count'], 'phase_one_status': result['phase_one_status'], 'adcd_status': provider['status'], 'adcd_processing_time_seconds': provider.get('processing_time_seconds'), 'total_processing_time_seconds': result['total_processing_time_seconds'], 'result_path': str(OUTPUT)}))
    return 0

if __name__ == '__main__': raise SystemExit(main())
