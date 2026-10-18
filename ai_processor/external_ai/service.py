"""Conditional external-AI orchestration with cache and circuit fallback."""
from pathlib import Path
from hashlib import sha256
from urllib.parse import urlparse

from ..config import external_ai_configuration_health
from .client import ExternalAiClient
from .contracts import build_evidence, decide_invocation
from .images import ExternalAiImageError, prepare_image_data_url
from .state import ExternalAiStateStore, build_cache_key


PROMPT_VERSION = 'external-ai-assessment-v4'
_TRANSIENT_CODES = frozenset({'EXTERNAL_AI_TIMEOUT', 'EXTERNAL_AI_RATE_LIMITED', 'EXTERNAL_AI_UNAVAILABLE'})


def review_document(settings, document_path: Path, images: list, expected_type: str, ocr_text: str,
                    fields: dict, local_result: dict, classification: dict, application_context: dict,
                    force: bool = False, client=None, state_store=None) -> dict:
    health = external_ai_configuration_health(settings)
    if health == 'disabled':
        return {'status': 'not_needed', 'reason': 'provider_disabled', 'payload_mode': 'text', 'elapsed_ms': 0, 'attempts': 0}
    if health != 'configured':
        return {'status': 'unavailable', 'reason': 'provider_not_configured', 'error_code': 'EXTERNAL_AI_NOT_CONFIGURED', 'payload_mode': 'text', 'elapsed_ms': 0, 'attempts': 0}
    decision = decide_invocation(local_result, classification, fields, force=force)
    if not decision.invoke:
        return {'status': 'not_needed', 'reason': decision.reason, 'payload_mode': 'text', 'elapsed_ms': 0, 'attempts': 0}
    store = state_store or ExternalAiStateStore(Path(settings.external_ai_state_root))
    if store.circuit_is_open():
        return {'status': 'unavailable', 'reason': decision.reason, 'error_code': 'EXTERNAL_AI_CIRCUIT_OPEN', 'payload_mode': 'text', 'elapsed_ms': 0, 'attempts': 0}
    payload_mode = 'text'
    image_data_urls = []
    gemini_endpoint = urlparse(settings.external_ai_endpoint).hostname == 'generativelanguage.googleapis.com'
    image_capable = gemini_endpoint or store.image_capability_verified(settings.external_ai_endpoint, settings.external_ai_model)
    if settings.external_ai_image_enabled and images and image_capable:
        try:
            image_data_urls = [prepare_image_data_url(image) for image in images]
            payload_mode = 'image'
        except ExternalAiImageError:
            image_data_urls = []
            payload_mode = 'text'
    model_scope = ','.join((settings.external_ai_model, *getattr(settings, 'external_ai_fallback_models', ())))
    prompt_scope = PROMPT_VERSION
    if expected_type == 'valid_id' and (application_context or {}).get('reference_owner') == 'applicant':
        digest = sha256(str(application_context.get('full_name') or '').encode('utf-8')).hexdigest()[:16]
        prompt_scope += f':applicant-owner-v1:{digest}'
    cache_key = build_cache_key(document_path, model_scope, prompt_scope, payload_mode, settings.external_ai_endpoint)
    cached = store.get_cached(cache_key)
    if cached is not None:
        output = dict(cached)
        output['cache_hit'] = True
        output['reason'] = decision.reason
        return output
    evidence = build_evidence(expected_type, ocr_text, fields, local_result, application_context)
    provider = client or ExternalAiClient(settings)
    result = provider.assess(evidence, image_data_urls=image_data_urls)
    output = dict(result)
    output['payload_mode'] = payload_mode
    output['cache_hit'] = False
    output['reason'] = decision.reason
    if output.get('status') == 'completed':
        store.record_success()
        store.put_cached(cache_key, output)
    elif output.get('error_code') in _TRANSIENT_CODES:
        store.record_transient_failure()
    return output
