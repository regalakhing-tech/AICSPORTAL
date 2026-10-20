import argparse, logging, os, sys, time
from dataclasses import replace
from datetime import datetime
from pathlib import Path
from .config import SETTINGS, validate_worker_configuration
from .gate_trigger import trigger_gate_sync
from .database import connect, claim_one, claim_document, finish, fail, prior_fields, get_request_verification_context, update_stage
from .document_loader import resolve_path, load_images
from .image_quality import analyze_document_quality
from .ocr_engine import OcrResult, extract_document_text, retry_philid_field_rois, resolve_safe_roi_fields, select_ocr_evidence
from .document_classifier import classify_document, classify_ocr_candidates
from .field_extractor import extract
from .verification import evaluate
from .calibration import load_calibration_report, profile_is_calibrated
from .document_profiles import get_profile
from .document_ownership import select_reference_context
from .tamper_detection.adapter import analyze_tampering
from .tamper_detection.activation import adcd_service_ready
from .tamper_detection.aggregation import aggregate_tamper_evidence, apply_tamper_recommendation
from .tamper_detection.chronology import analyze_chronology
from .tamper_detection.postprocessing import attach_combined_finding
from .tamper_detection.regional import analyze_regional_inconsistency
from .tamper_detection.artifacts import persist_private_artifacts, persist_review_pages
from .tamper_detection.file_inspection import inspect_file
from .tamper_detection.local_visual import inspect_qr, analyze_image_forensics
from .external_ai.postprocessing import apply_external_recommendation, attach_external_assessment, enforce_beneficiary_comparisons
from .external_ai.service import review_document
from .gemini_scanner.service import scan_document
from .gemini_scanner.verdict import apply_verdict, as_visual_result
from .timing import StageTimings

CALIBRATION_REPORT = load_calibration_report(Path(SETTINGS.calibration_report_path))

def _record_failure(db, review_id, error_code):
    """Failure updates use a fresh transaction even if an earlier stage failed."""
    try:
        fail(db, review_id, error_code)
    except Exception:
        # Reconnect once if the processing connection itself became unusable.
        recovery_db = None
        try:
            recovery_db = connect(SETTINGS)
            fail(recovery_db, review_id, error_code)
        except Exception:
            # A database outage is the only remaining case where no state write is possible.
            print('Failed: AI_PROCESSOR_ERROR')
        finally:
            if recovery_db:
                recovery_db.close()

def _configure_processor_logging():
    """Create the server log target before worker logging begins."""
    log_directory = Path(SETTINGS.project_root) / 'ai_processor' / 'logs'
    formatter = logging.Formatter('%(asctime)s %(levelname)s pid=%(process)s %(name)s %(message)s')
    handlers = []
    try:
        os.makedirs(log_directory, exist_ok=True)
        log_name = 'processor-' + datetime.now().strftime('%Y%m%d-%H%M%S') + '-' + str(os.getpid()) + '.log'
        file_handler = logging.FileHandler(log_directory / log_name, encoding='utf-8')
        file_handler.setFormatter(formatter)
        handlers.append(file_handler)
    except Exception as exc:
        # Apache redirects stderr to its worker log when the file cannot open.
        print(f'AICS processor file logging unavailable: {exc}', file=sys.stderr)
    stderr_handler = logging.StreamHandler(sys.stderr)
    stderr_handler.setFormatter(formatter)
    handlers.append(stderr_handler)
    logging.basicConfig(level=logging.INFO, handlers=handlers, force=True)

_configure_processor_logging()
logger = logging.getLogger(__name__)

def process_one(dry_run=False, document_id=None):
    timings = StageTimings()
    db = connect(SETTINGS)
    job = claim_document(db, document_id) if document_id else claim_one(db, SETTINGS.stale_minutes)
    if not job:
        logger.info('No eligible AI review found%s.', f' for document {document_id}' if document_id else '')
        db.close()
        return False
    review_id = job['id']
    document_id = job.get('document_id')
    logger.info('AI worker started review_id=%s document_id=%s', review_id, document_id)
    try:
        update_stage(db, review_id, 'preprocessing', 10)
        logger.info('Document resolving review_id=%s document_id=%s', review_id, document_id)
        path = resolve_path(SETTINGS.project_root, SETTINGS.upload_root, job['file_path'], document_id)
        logger.info('Document resolved review_id=%s document_id=%s', review_id, document_id)
        if path.suffix.lower() == '.pdf': logger.info('PDF rendering started review_id=%s document_id=%s', review_id, document_id)
        page_loading_started = timings.start()
        images = load_images(path, SETTINGS.max_pdf_pages, SETTINGS.poppler_path, SETTINGS.pdf_timeout_seconds, document_id)
        timings.finish('page_loading', page_loading_started)
        if path.suffix.lower() == '.pdf': logger.info('PDF rendered review_id=%s document_id=%s', review_id, document_id)
        expected_type = str(job['expected_document_type'])
        ocr_started = timings.start()
        photo_evidence = expected_type == 'damaged_house_photo'
        if photo_evidence:
            logger.info('Photo evidence handling review_id=%s document_id=%s', review_id, document_id)
            text, ocr_conf, ocr_metadata = '', 0.0, {'ocr_boxes_by_page': []}
            classification, detected, type_conf, fields = {'decision': 'not_applicable'}, None, 0, {}
        else:
            update_stage(db, review_id, 'ocr', 35)
            logger.info('OCR started review_id=%s document_id=%s', review_id, document_id)
            text, ocr_conf, ocr_metadata = extract_document_text(images, SETTINGS.tesseract_path, SETTINGS.ocr_timeout_seconds)
            profile_definition = get_profile(expected_type)
            consensus_evidence = None
            if profile_definition is not None:
                candidate_results = []
                for candidate in (ocr_metadata or {}).get('field_candidates', []):
                    if not isinstance(candidate, dict) or not str(candidate.get('text') or '').strip():
                        continue
                    boxes = [box for page in candidate.get('pages', []) if isinstance(page, dict)
                             for box in page.get('ocr_boxes', []) if isinstance(box, list)]
                    candidate_results.append(OcrResult(str(candidate.get('candidate') or 'bounded'),
                                                       str(candidate.get('text') or ''),
                                                       float(candidate.get('mean_confidence') or 0),
                                                       candidate, boxes))
                if candidate_results:
                    consensus_evidence = select_ocr_evidence(candidate_results, profile_definition)
                    text, ocr_conf = consensus_evidence.text, consensus_evidence.confidence
                    ocr_metadata['selected_document_candidate'] = consensus_evidence.candidate_name
                    ocr_metadata['candidate_score'] = {
                        'anchor_coverage': consensus_evidence.score.anchor_coverage,
                        'required_field_coverage': consensus_evidence.score.required_field_coverage,
                        'coherent_line_ratio': consensus_evidence.score.coherent_line_ratio,
                    }
            logger.info('OCR completed review_id=%s document_id=%s', review_id, document_id)
            classification = classify_document(text, expected_type)
            classification = classify_ocr_candidates(text, expected_type, ocr_metadata, primary=classification)
            detected = classification['detected_type']
            type_conf = 90 if classification['confidence_band'] == 'high' else (55 if classification['confidence_band'] == 'moderate' else 20)
            fields = extract(text, expected_type, ocr_metadata)
            if consensus_evidence is not None:
                for key, evidence_field in consensus_evidence.fields.items():
                    if isinstance(evidence_field, dict) and evidence_field.get('reason_if_unreliable') == 'conflicting_ocr_candidates':
                        fields[key] = evidence_field
            # A bounded, safe-field fallback may use a high-confidence profile;
            # Valid IDs also require independent family-marker and anchor proof.
            profile_fields = {'valid_id': ('name', 'birth_date', 'address'), 'med_cert': ('patient_name', 'birth_date'), 'billing_statement': ('patient_name',), 'death_cert': ('deceased_name',), 'funeral_contract': ('deceased_name',), 'report_police_med': ('subject_name',), 'school_assessment': ('client_name',), 'registration_form': ('client_name',), 'school_id': ('client_name',), 'mdrrmo_cert': ('client_name',), 'eclip_cert': ('client_name',), 'crisis_situation_cert': ('client_name',)}.get(expected_type, ())
            weak = {key for key in profile_fields if fields.get(key, {}).get('status') != 'extracted'}
            profile, eligible_weak, roi_eligibility = resolve_safe_roi_fields(expected_type, classification, ocr_metadata, weak)
            ocr_metadata['roi_eligibility'] = roi_eligibility
            if profile and eligible_weak:
                ocr_metadata = retry_philid_field_rois(images, ocr_metadata, SETTINGS.tesseract_path, eligible_weak, SETTINGS.ocr_timeout_seconds, profile)
                fields = extract(text, expected_type, ocr_metadata)
        timings.finish('ocr_and_extraction', ocr_started)
        verification_started = timings.start()
        quality = analyze_document_quality(images, SETTINGS.blur_threshold, text, ocr_conf, fields)
        update_stage(db, review_id, 'verifying', 65)
        logger.info('Verification evaluated review_id=%s document_id=%s', review_id, document_id)
        verification_context = get_request_verification_context(job)
        reference_context = select_reference_context(expected_type, verification_context, SETTINGS.valid_id_owner_mode)
        sibling_fields = prior_fields(db, job['request_id'], job['document_id'])
        if SETTINGS.valid_id_owner_mode != 'legacy' and expected_type != 'valid_id':
            # The applicant's ID must not become a beneficiary identity anchor.
            sibling_fields = [item for item in sibling_fields if item.get('_document_type') != 'valid_id']
        result = evaluate(expected_type, detected, type_conf, quality, fields,
                          reference_context.get('full_name'), sibling_fields,
                          reference_context.get('date_of_birth'), classification, reference_context,
                          profile_calibrated=profile_is_calibrated(get_profile(expected_type), CALIBRATION_REPORT))
        timings.finish('local_verification', verification_started)
        update_stage(db, review_id, 'external_review', 75)
        # Gemini vision is the primary reader of the document images.
        gemini_started = timings.start()
        try:
            gemini_result = scan_document(SETTINGS, path, images, expected_type, reference_context)
        except Exception:
            logger.exception('Gemini document scan unavailable review_id=%s document_id=%s', review_id, document_id)
            gemini_result = {'status': 'unavailable', 'error_code': 'GEMINI_INTERNAL_ERROR'}
        timings.finish('gemini_scan', gemini_started)
        logger.info('Gemini scan finished review_id=%s document_id=%s status=%s error=%s', review_id, document_id,
                    gemini_result.get('status'), gemini_result.get('error_code'))
        external_started = timings.start()
        if gemini_result.get('status') == 'completed':
            external_result = {'status': 'not_needed', 'reason': 'gemini_primary_completed',
                               'payload_mode': 'image', 'elapsed_ms': 0, 'attempts': 0}
        else:
            try:
                external_result = review_document(
                    SETTINGS, path, images, expected_type, text, fields, result,
                    classification, reference_context,
                )
            except Exception:
                logger.exception('External AI advisory unavailable review_id=%s document_id=%s', review_id, document_id)
                external_result = {
                    'status': 'unavailable',
                    'reason': 'provider_internal_error',
                    'error_code': 'EXTERNAL_AI_INTERNAL_ERROR',
                    'payload_mode': 'text',
                    'elapsed_ms': 0,
                    'attempts': 0,
                }
        timings.finish('external_ai', external_started)
        external_result = enforce_beneficiary_comparisons(
            external_result, reference_context,
            document_type_matches=classification.get('decision') != 'wrong_slot',
        )
        result['findings'] = attach_external_assessment(
            result.get('findings'), external_result, str(external_result.get('reason') or '')
        )
        result = apply_external_recommendation(result, external_result,
                                               reference_context.get('reference_owner', 'beneficiary'))
        if external_result.get('status') == 'completed' and external_result.get('payload_mode') == 'image':
            visual_result = external_result
        else:
            visual_result = as_visual_result(gemini_result)
        if external_result.get('status') == 'completed':
            result['model_service'] = 'hybrid_local_external'
            result['model_version'] = 'component-assessments-v2+external-v1'
        update_stage(db, review_id, 'tamper_scan', 88)
        tamper_started = timings.start()
        chronology_started = timings.start()
        try:
            chronology_result = analyze_chronology(expected_type, ocr_metadata, fields, images)
        except Exception:
            logger.exception('Chronology analysis unavailable review_id=%s document_id=%s', review_id, document_id)
            chronology_result = {'status': 'unavailable', 'family': 'semantic_chronology',
                                 'decision': 'clear', 'indicators': []}
        timings.finish('chronology_analysis', chronology_started)
        regional_started = timings.start()
        try:
            regional_result = analyze_regional_inconsistency(images, ocr_metadata)
        except Exception:
            logger.exception('Regional image analysis unavailable review_id=%s document_id=%s', review_id, document_id)
            regional_result = {'status': 'unavailable', 'family': 'local_regional',
                               'decision': 'clear', 'indicators': []}
        timings.finish('regional_analysis', regional_started)
        local_results = []
        for family, check in (('metadata', lambda: inspect_file(path)),
                              ('qr', lambda: inspect_qr(images)),
                              ('local_visual', lambda: analyze_image_forensics(images, ocr_metadata))):
            try:
                local_results.append(check())
            except Exception:
                logger.exception('Optional forensic check unavailable review_id=%s family=%s', review_id, family)
                local_results.append({'status': 'unavailable', 'family': family, 'decision': 'clear',
                                      'indicators': [], 'facts': {}})
        adcd_settings = SETTINGS
        if not SETTINGS.adcd_enabled and adcd_service_ready(SETTINGS):
            adcd_settings = replace(SETTINGS, adcd_enabled=True)
        logger.info('ADCD analysis starting review_id=%s document_id=%s enabled=%s service_url=%s pages=%s ocr_pages=%s', review_id, document_id, adcd_settings.adcd_enabled, SETTINGS.adcd_service_url, len(images), len((ocr_metadata or {}).get('ocr_boxes_by_page', [])))
        try:
            tamper_result = analyze_tampering(images, adcd_settings, ocr_metadata)
            tamper_result = persist_private_artifacts(tamper_result, review_id, SETTINGS)
        except Exception:
            logger.exception('ADCD analysis unavailable review_id=%s document_id=%s', review_id, document_id)
            tamper_result = {'providers': {'adcd_net': {'status': 'unavailable',
                                                        'error': 'ADCD_SERVICE_UNAVAILABLE',
                                                        'pages': [], 'mask_available': False}}}
        timings.finish('alteration_analysis', tamper_started)
        tamper_provider = tamper_result.get('providers', {}).get('adcd_net', {}) if isinstance(tamper_result, dict) else {}
        logger.info('ADCD analysis finished review_id=%s document_id=%s status=%s error=%s page_count=%s', review_id, document_id, tamper_provider.get('status'), tamper_provider.get('error'), len(tamper_provider.get('pages', [])) if isinstance(tamper_provider.get('pages', []), list) else 0)
        combined_tamper = aggregate_tamper_evidence(
            visual_result, chronology_result, regional_result, tamper_result,
            local_results=local_results,
        )
        combined_tamper['original_pages'] = persist_review_pages(images, review_id, SETTINGS)
        result['findings'] = attach_combined_finding(result.get('findings'), combined_tamper)
        result = apply_tamper_recommendation(result, combined_tamper)
        result = apply_verdict(result, expected_type, gemini_result, combined_tamper, reference_context)
        performance = dict(result['findings'].get('performance') or {})
        performance['stage_ms'] = timings.as_dict()
        performance['total_ms'] = timings.total_ms()
        performance['external_cache_hit'] = bool(external_result.get('cache_hit', False))
        performance['external_payload_mode'] = str(external_result.get('payload_mode') or 'text')
        result['findings']['performance'] = performance
        if dry_run: print({'review_id':review_id, 'document_id':job['document_id'], 'expected':job['expected_document_type'], 'detected':detected, 'status':result['status']})
        else:
            logger.info('Saving AI result review_id=%s document_id=%s', review_id, document_id)
            finish(db, review_id, result)
            logger.info('AI result saved review_id=%s document_id=%s', review_id, document_id)
        logger.info('AI worker completed review_id=%s document_id=%s status=%s', review_id, document_id, result['status'])
    except RuntimeError as exc:
        if not dry_run: _record_failure(db, review_id, str(exc))
        logger.error('AI worker failed review_id=%s document_id=%s code=%s', review_id, document_id, exc)
    except Exception:
        if not dry_run: _record_failure(db, review_id, 'AI_PROCESSOR_ERROR')
        logger.exception('AI worker failed review_id=%s document_id=%s', review_id, document_id)
    finally: db.close()
    return True

def _release_request_lease(request_id: int, lease_token: str | None):
    if not lease_token:
        return
    db = connect(SETTINGS)
    try:
        with db.cursor() as cur:
            cur.execute('DELETE FROM document_ai_request_worker_leases WHERE request_id = %s AND lease_token = %s', (request_id, lease_token))
        db.commit()
    finally:
        db.close()

def process_request(request_id: int, dry_run=False, lease_token: str | None = None):
    """Process only this request's already-queued reviews, sequentially."""
    try:
        db = connect(SETTINGS)
        try:
            with db.cursor() as cur:
                # Request batches claim only rows newly queued by the request
                # transaction.  A pre-existing worker has already incremented its
                # attempt count, so it remains exclusively owned by that worker.
                cur.execute("SELECT d.id FROM aics_request_documents d INNER JOIN document_ai_reviews ai ON ai.request_document_id = d.id WHERE d.request_id = %s AND ai.status = 'processing' AND ai.attempt_count = 0 ORDER BY d.id", (request_id,))
                document_ids = [int(row['id']) for row in cur.fetchall()]
        finally:
            db.close()
        for document_id in document_ids:
            process_one(dry_run, document_id)
    finally:
        _release_request_lease(request_id, lease_token)
    return bool(document_ids)

if __name__ == '__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--once', action='store_true'); parser.add_argument('--batch', type=int, default=1); parser.add_argument('--document-id', type=int); parser.add_argument('--request-id', type=int); parser.add_argument('--request-lease'); parser.add_argument('--dry-run', action='store_true'); args=parser.parse_args()
    if args.document_id is not None and args.document_id <= 0: parser.error('--document-id must be a positive integer')
    if args.request_id is not None and args.request_id <= 0: parser.error('--request-id must be a positive integer')
    if args.document_id is not None and args.request_id is not None: parser.error('use only one of --document-id or --request-id')
    if args.request_lease is not None and (args.request_id is None or not __import__('re').fullmatch(r'[a-f0-9]{32}', args.request_lease)): parser.error('--request-lease requires --request-id and a valid lease token')
    try:
        validate_worker_configuration()
    except RuntimeError as exc:
        logger.error('AI worker configuration rejected code=%s', exc)
        raise SystemExit(2)
    if args.request_id is not None:
        try:
            process_request(args.request_id, args.dry_run, args.request_lease)
        finally:
            trigger_gate_sync(SETTINGS.php_executable, SETTINGS.project_root)
        raise SystemExit(0)
    if args.document_id is not None:
        try:
            process_one(args.dry_run, args.document_id)
        finally:
            trigger_gate_sync(SETTINGS.php_executable, SETTINGS.project_root)
        raise SystemExit(0)
    for _ in range(1 if args.once else max(1,args.batch)):
        if not process_one(args.dry_run): break
