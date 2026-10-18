"""Format raw ADCD-Net service metrics without making a tamper decision."""
from __future__ import annotations
import math
import re
from urllib.parse import urlsplit

def unavailable(error: str = 'ADCD_SERVICE_UNAVAILABLE', pages: list[dict] | None = None) -> dict:
    return {'providers': {'adcd_net': {'status': 'unavailable', 'error': error, 'pages': pages or [], 'mask_available': False}}}

def normalize_page(payload: object, page_number: int) -> dict:
    if not isinstance(payload, dict) or payload.get('success') is not True:
        raise ValueError('ADCD_SERVICE_MALFORMED_RESPONSE')
    values = {}
    for source, target in (('tampered_pixel_ratio', 'tampered_pixel_ratio'), ('max_tamper_probability', 'max_tamper_probability'), ('mean_tamper_probability', 'mean_tamper_probability'), ('processing_time_seconds', 'processing_time_seconds')):
        value = float(payload.get(source))
        if not math.isfinite(value) or value < 0: raise ValueError('ADCD_SERVICE_MALFORMED_RESPONSE')
        values[target] = value
    width, height = int(payload.get('output_width')), int(payload.get('output_height'))
    if width <= 0 or height <= 0: raise ValueError('ADCD_SERVICE_MALFORMED_RESPONSE')
    return {'page': page_number, 'output_width': width, 'output_height': height, **values}

def completed(pages: list[dict]) -> dict:
    if not pages: return unavailable('ADCD_SERVICE_NO_PAGES')
    weights = [page['output_width'] * page['output_height'] for page in pages]
    total_weight = sum(weights)
    weighted = lambda key: sum(page[key] * weight for page, weight in zip(pages, weights)) / total_weight
    pixel_ratio = weighted('tampered_pixel_ratio')
    maximum = max(page['max_tamper_probability'] for page in pages)
    decision = 'review' if maximum >= .75 and pixel_ratio >= .005 else 'clear'
    return {'providers': {'adcd_net': {'status': 'completed', 'model': 'ADCD-Net', 'model_version': 'local-service-v1', 'threshold_id': 'adcd-review-v1', 'decision': decision, 'pages': pages, 'tampered_pixel_ratio': pixel_ratio, 'max_tamper_probability': maximum, 'mean_tamper_probability': weighted('mean_tamper_probability'), 'processing_time_seconds': sum(page['processing_time_seconds'] for page in pages), 'mask_available': False}}}

# Legacy compatibility name; ADCD service metrics intentionally have no region threshold.
def normalize_result(payload: object) -> dict:
    try: return completed([normalize_page(payload, 1)])
    except (TypeError, ValueError): return unavailable('ADCD_SERVICE_MALFORMED_RESPONSE')

def persisted_finding(result: object) -> dict:
    """Return the sole database-safe tamper branch; never include artifacts."""
    provider = result.get('providers', {}).get('adcd_net', {}) if isinstance(result, dict) else {}
    status = str(provider.get('status') or 'unavailable')
    base = {'model': 'ADCD-Net', 'integration_version': 1, 'status': status}
    if status != 'completed':
        error = str(provider.get('error') or 'ADCD_SERVICE_MALFORMED_RESPONSE').lower()
        error_map = {
            'adcd_service_unavailable': 'service_unavailable', 'adcd_service_http_503': 'service_not_loaded',
            'adcd_service_timeout': 'timeout', 'adcd_service_malformed_response': 'invalid_response',
            'adcd_service_http_400': 'unsupported_input', 'adcd_service_http_415': 'unsupported_input',
            'adcd_disabled': 'service_disabled',
        }
        base['status'] = 'unavailable'; base['error_code'] = error_map.get(error, 'inference_error')
        return base
    pages = []
    for page in provider.get('pages', []):
        if not isinstance(page, dict): continue
        try:
            saved = {key: (int(page[key]) if key == 'page' else float(page[key])) for key in ('page', 'tampered_pixel_ratio', 'max_tamper_probability', 'mean_tamper_probability', 'processing_time_seconds')}
            if isinstance(page.get('artifacts'), dict): saved['artifacts'] = page['artifacts']
            pages.append(saved)
        except (KeyError, TypeError, ValueError): return persisted_finding(unavailable('ADCD_SERVICE_MALFORMED_RESPONSE'))
    try:
        aggregate = {key: float(provider[key]) for key in ('tampered_pixel_ratio', 'max_tamper_probability', 'mean_tamper_probability', 'processing_time_seconds')}
    except (KeyError, TypeError, ValueError): return persisted_finding(unavailable('ADCD_SERVICE_MALFORMED_RESPONSE'))
    if not pages or any(not math.isfinite(value) for value in aggregate.values()): return persisted_finding(unavailable('ADCD_SERVICE_MALFORMED_RESPONSE'))
    base.update({'decision': 'review' if str(provider.get('decision')) == 'review' else 'clear',
                 'threshold_id': str(provider.get('threshold_id') or 'adcd-review-v1'),
                 'aggregate': aggregate, 'pages': pages,
                 'service': {'provider': 'local_adcd_service', 'model_version': str(provider.get('model_version') or 'local-service-v1'), 'device': 'cpu'}})
    return base


def alteration_assessment(provider: dict) -> dict:
    if not isinstance(provider, dict) or provider.get('status') != 'completed':
        return {'state': 'unavailable', 'label': 'Alteration check unavailable',
                'explanation': 'Staff can continue reviewing the original document.', 'diagnostics': {}}
    review = provider.get('decision') == 'review'
    return {
        'state': 'review' if review else 'pass',
        'label': 'Review highlighted areas' if review else 'No strong alteration signal',
        'explanation': 'This image signal supports staff inspection and is not proof of document fraud.',
        'diagnostics': {'threshold_id': str(provider.get('threshold_id') or 'adcd-review-v1')},
    }

def attach_persisted_finding(findings: object, result: object) -> dict:
    """Copy existing Phase 1 findings and update only the canonical branch."""
    merged = dict(findings) if isinstance(findings, dict) else {}
    merged.pop('tampering', None)  # Stage 5 transitional key; never persist both forms.
    merged['tamper_detection'] = persisted_finding(result)
    assessments = dict(merged.get('assessments') or {})
    assessments['alteration_signal'] = alteration_assessment(merged['tamper_detection'])
    merged['assessments'] = assessments
    return merged


def _safe_float(value: object) -> float | None:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    return number if math.isfinite(number) and number >= 0 else None


def sanitize_combined_finding(result: object) -> dict:
    """Return the public, database-safe multi-signal alteration finding."""
    if not isinstance(result, dict):
        return {'integration_version': 2, 'status': 'unavailable', 'decision': 'clear',
                'label': 'Alteration check unavailable', 'confidence_band': 'low',
                'evidence_families': [], 'indicators': [], 'providers': {}}
    status = str(result.get('status') or 'unavailable')
    decision = 'review' if status == 'completed' and result.get('decision') == 'review' else 'clear'
    safe = {
        'integration_version': 2,
        'status': status if status in {'completed', 'unavailable'} else 'unavailable',
        'decision': decision,
        'label': ('Suspected alteration — staff review required' if decision == 'review'
                  else ('No corroborated alteration signal' if status == 'completed'
                        else 'Alteration check unavailable')),
        'confidence_band': str(result.get('confidence_band') or 'low')
        if result.get('confidence_band') in {'low', 'moderate', 'high'} else 'low',
        'evidence_families': [str(value) for value in result.get('evidence_families', [])
                              if str(value) in {'gemini_visual', 'semantic_chronology', 'local_regional', 'local_visual', 'adcd_net'}][:5],
        'indicators': [],
        'providers': {},
    }
    review_basis = str(result.get('review_basis') or 'none')
    if review_basis not in {'none', 'deterministic_chronology_conflict', 'corroborated_visual_evidence', 'cryptographic_signature_problem'}:
        review_basis = 'none'
    safe['review_basis'] = review_basis
    if decision == 'review' and review_basis == 'cryptographic_signature_problem':
        safe['label'] = 'PDF signature requires staff review'
    if decision == 'review' and review_basis == 'deterministic_chronology_conflict':
        safe['label'] = 'Document date inconsistency — staff review required'
        conflict = result.get('date_conflict')
        if isinstance(conflict, dict):
            document_date = str(conflict.get('document_date') or '')
            years = sorted({year for year in conflict.get('conflicting_years', [])
                            if isinstance(year, int) and not isinstance(year, bool)
                            and 1900 <= year <= 2100})
            if re.fullmatch(r'\d{4}-\d{2}-\d{2}', document_date) and years:
                safe['date_conflict'] = {
                    'document_date': document_date,
                    'conflicting_years': years[:5],
                }
    allowed_types = {
        'typography_mismatch', 'baseline_or_spacing_mismatch', 'ink_or_color_mismatch',
        'pasted_region_boundary', 'background_texture_discontinuity',
        'compression_or_resampling_difference', 'signature_or_stamp_anomaly',
        'other_visual_anomaly', 'date_context_conflict',
        'value_context_conflict', 'copy_move_region', 'repeated_visual_region',
        'erasure_or_cover_region', 'handwritten_correction', 'sample_or_watermark', 'digital_text_overlay',
        'file_format_mismatch', 'metadata_date_conflict',
        'invalid_pdf_signature', 'pdf_changed_after_signing',
    }
    for item in result.get('indicators', [])[:20]:
        if not isinstance(item, dict) or item.get('type') not in allowed_types:
            continue
        indicator = {'type': str(item['type'])}
        if isinstance(item.get('page'), int) and item['page'] > 0:
            indicator['page'] = item['page']
        if isinstance(item.get('source_page'), int) and not isinstance(item['source_page'], bool) and 1 <= item['source_page'] <= 10:
            indicator['source_page'] = item['source_page']
        box = item.get('box_2d')
        if (isinstance(box, list) and len(box) == 4
                and all(isinstance(value, int) and not isinstance(value, bool) and 0 <= value <= 1000 for value in box)
                and box[0] < box[2] and box[1] < box[3]):
            indicator['box_2d'] = list(box)
        source_box = item.get('source_box_2d')
        if (isinstance(source_box, list) and len(source_box) == 4
                and all(isinstance(value, int) and not isinstance(value, bool) and 0 <= value <= 1000 for value in source_box)
                and source_box[0] < source_box[2] and source_box[1] < source_box[3]):
            indicator['source_box_2d'] = list(source_box)
        if item.get('confidence_band') in {'low', 'moderate', 'high'}:
            indicator['confidence_band'] = item['confidence_band']
        if item.get('source') in {'gemini', 'chronology', 'local_regional', 'local_visual', 'metadata'}:
            indicator['source'] = item['source']
        reason = str(item.get('reason') or '').strip()
        if reason:
            indicator['reason'] = reason[:300]
        safe['indicators'].append(indicator)
    for name in ('gemini', 'semantic_chronology', 'local_regional', 'local_visual', 'metadata', 'qr', 'adcd_net'):
        provider = result.get('providers', {}).get(name, {}) if isinstance(result.get('providers'), dict) else {}
        if not isinstance(provider, dict):
            continue
        summary = {'status': str(provider.get('status') or 'unavailable')}
        if name == 'adcd_net' and provider.get('decision') in {'clear', 'review'}:
            summary['decision'] = provider['decision']
        safe['providers'][name] = summary
    auxiliary = result.get('auxiliary') if isinstance(result.get('auxiliary'), dict) else {}
    file_facts = auxiliary.get('file') if isinstance(auxiliary.get('file'), dict) else {}
    allowed_file = {key: str(file_facts[key])[:80] for key in ('creation_date', 'modification_date', 'creator', 'editing_software', 'image_format',
                                                              'date_taken', 'camera', 'pixel_size')
                    if file_facts.get(key) is not None}
    if file_facts.get('camera_metadata') in {'present', 'missing'}:
        allowed_file['camera_metadata'] = file_facts['camera_metadata']
    if isinstance(file_facts.get('saved_with_adobe'), bool):
        allowed_file['saved_with_adobe'] = file_facts['saved_with_adobe']
    if isinstance(file_facts.get('design_app'), bool):
        allowed_file['design_app'] = file_facts['design_app']
    signature = file_facts.get('pdf_signature') if isinstance(file_facts.get('pdf_signature'), dict) else None
    if signature:
        allowed_file['pdf_signature'] = {'state': signature.get('state') if signature.get('state') in
                                          {'unsigned', 'unavailable', 'invalid_structure', 'invalid_signature', 'intact', 'changed_after_signing'} else 'unavailable'}
    qr_facts = []
    for qr in auxiliary.get('qr', [])[:12] if isinstance(auxiliary.get('qr'), list) else []:
        if not isinstance(qr, dict):
            continue
        raw_preview = str(qr.get('content_preview') or '')[:160]
        try:
            parsed_qr = urlsplit(raw_preview)
            preview = (f'{parsed_qr.scheme}://{parsed_qr.hostname}'
                       if parsed_qr.scheme in {'http', 'https'} and parsed_qr.hostname
                       else 'Non-URL QR content (redacted)')
        except ValueError:
            preview = 'Non-URL QR content (redacted)'
        digest = str(qr.get('content_sha256') or '')
        if not re.fullmatch(r'[a-f0-9]{64}', digest):
            continue
        item = {'page': max(1, int(qr.get('page') or 1)), 'content_preview': preview,
                'content_sha256': digest, 'verification': 'not_authenticated'}
        box = qr.get('box_2d')
        if (isinstance(box, list) and len(box) == 4
                and all(isinstance(value, int) and not isinstance(value, bool) and 0 <= value <= 1000 for value in box)
                and box[0] < box[2] and box[1] < box[3]):
            item['box_2d'] = list(box)
        qr_facts.append(item)
    safe['auxiliary'] = {'file': allowed_file, 'qr': qr_facts}
    original_pages = []
    for page in result.get('original_pages', [])[:10] if isinstance(result.get('original_pages'), list) else []:
        if isinstance(page, dict) and isinstance(page.get('page'), int) and 1 <= page['page'] <= 10:
            original_pages.append({'page': page['page'], 'available': page.get('available') is True})
    safe['original_pages'] = original_pages
    aggregate = {}
    for key in ('tampered_pixel_ratio', 'max_tamper_probability', 'mean_tamper_probability', 'processing_time_seconds'):
        number = _safe_float((result.get('aggregate') or {}).get(key)) if isinstance(result.get('aggregate'), dict) else None
        if number is not None:
            aggregate[key] = number
    if len(aggregate) == 4:
        safe['aggregate'] = aggregate
    pages = []
    for page in result.get('pages', []):
        if not isinstance(page, dict) or not isinstance(page.get('page'), int) or page['page'] <= 0:
            continue
        saved = {'page': page['page']}
        valid = True
        for key in ('tampered_pixel_ratio', 'max_tamper_probability', 'mean_tamper_probability', 'processing_time_seconds'):
            number = _safe_float(page.get(key))
            if number is None:
                valid = False
                break
            saved[key] = number
        artifacts = page.get('artifacts')
        if isinstance(artifacts, dict):
            signal = artifacts.get('signal') if isinstance(artifacts.get('signal'), dict) else {}
            threshold = artifacts.get('threshold') if isinstance(artifacts.get('threshold'), dict) else {}
            saved['artifacts'] = {
                'signal': {'available': bool(signal.get('available'))},
                'threshold': {
                    'available': bool(threshold.get('available')),
                    'regions_found': bool(threshold.get('regions_found')),
                },
            }
        if valid:
            pages.append(saved)
    if pages:
        safe['pages'] = pages
    if aggregate:
        safe['threshold_id'] = str(result.get('threshold_id') or 'adcd-review-v1')[:80]
    return safe


def combined_alteration_assessment(branch: dict) -> dict:
    if not isinstance(branch, dict) or branch.get('status') != 'completed':
        return {'state': 'unavailable', 'label': 'Alteration check unavailable',
                'explanation': 'Staff can continue reviewing the original document.', 'diagnostics': {}}
    if branch.get('decision') == 'review':
        date_conflict = branch.get('review_basis') == 'deterministic_chronology_conflict'
        return {'state': 'review',
                'label': ('Document date inconsistency — staff review required' if date_conflict
                          else 'Suspected alteration — staff review required'),
                'explanation': ('The signature or issue date conflicts with repeated event dates. Staff must inspect the original document before deciding.'
                                if date_conflict else
                                'Independent checks found possible document changes. Staff must inspect the original document before deciding.'),
                'diagnostics': {'evidence_families': list(branch.get('evidence_families') or [])}}
    return {'state': 'pass', 'label': 'No corroborated alteration signal',
            'explanation': 'The automated checks did not find corroborated signs of document changes.',
            'diagnostics': {'evidence_families': list(branch.get('evidence_families') or [])}}


def attach_combined_finding(findings: object, result: object) -> dict:
    """Replace legacy alteration output with the canonical multi-signal branch."""
    merged = dict(findings) if isinstance(findings, dict) else {}
    merged.pop('tampering', None)
    branch = sanitize_combined_finding(result)
    merged['tamper_detection'] = branch
    assessments = dict(merged.get('assessments') or {})
    assessments['alteration_signal'] = combined_alteration_assessment(branch)
    merged['assessments'] = assessments
    return merged
