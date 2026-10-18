"""Run the Gemini document scan with caching and a failure circuit."""
from hashlib import sha256
from pathlib import Path

from ..external_ai.images import ExternalAiImageError, prepare_image_data_url
from ..external_ai.state import ExternalAiStateStore, build_cache_key
from .catalog import normalize_code
from .client import GEMINI_BASE_URL, GeminiScannerClient
from .prompt import APPLICANT_ID_INSTRUCTION, PROMPT_VERSION, build_request_text

_TRANSIENT = frozenset({'GEMINI_TIMEOUT', 'GEMINI_RATE_LIMITED', 'GEMINI_UNAVAILABLE'})


def gemini_scanner_configured(settings) -> bool:
    return bool(getattr(settings, 'gemini_image_enabled', False) and getattr(settings, 'gemini_image_api_key', ''))


def _unavailable(code: str) -> dict:
    return {'status': 'unavailable', 'error_code': code, 'attempts': 0, 'elapsed_ms': 0, 'cache_hit': False}


def scan_document(settings, document_path: Path, images: list, expected_type: str, beneficiary: dict,
                  client=None, state_store=None) -> dict:
    """Return {'status': 'completed', 'scan': {...}} or {'status': 'unavailable', 'error_code': ...}.

    Never raises for provider problems; the local review always continues.
    """
    if not gemini_scanner_configured(settings):
        return _unavailable('GEMINI_NOT_CONFIGURED')
    if not images:
        return _unavailable('GEMINI_NO_IMAGES')
    store = state_store or ExternalAiStateStore(Path(settings.external_ai_state_root) / 'gemini_scanner')
    if store.circuit_is_open():
        return _unavailable('GEMINI_CIRCUIT_OPEN')
    try:
        image_data_urls = [prepare_image_data_url(image) for image in images]
    except ExternalAiImageError:
        return _unavailable('GEMINI_IMAGE_INVALID')

    expected = normalize_code(expected_type)
    beneficiary_digest = sha256(str((beneficiary or {}).get('full_name') or '').encode('utf-8')).hexdigest()[:16]
    models = ','.join((settings.gemini_model, *settings.gemini_fallback_models))
    prompt_scope = f'{PROMPT_VERSION}:{expected}:{beneficiary_digest}'
    if expected == 'valid_id' and (beneficiary or {}).get('reference_owner') == 'applicant':
        prompt_scope += ':applicant-owner-v1'
    cache_key = build_cache_key(Path(document_path), models, prompt_scope,
                                f'image:{len(images)}', GEMINI_BASE_URL)
    cached = store.get_cached(cache_key)
    if cached is not None:
        return {**cached, 'cache_hit': True}

    provider = client or GeminiScannerClient(settings)
    request_text = build_request_text(expected, beneficiary, len(images))
    if expected == 'valid_id' and (beneficiary or {}).get('reference_owner') == 'applicant':
        result = provider.scan(request_text, image_data_urls, len(images), instruction=APPLICANT_ID_INSTRUCTION)
    else:
        result = provider.scan(request_text, image_data_urls, len(images))
    output = {**result, 'cache_hit': False, 'page_count': len(images)}
    if output.get('status') == 'completed':
        store.record_success()
        store.put_cached(cache_key, output)
    elif output.get('error_code') in _TRANSIENT:
        store.record_transient_failure()
    return output
