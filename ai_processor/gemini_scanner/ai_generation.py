"""Focused AICS AI check: is the upload a real photo or scan of paper, or an
image made (or remade) by an AI image generator?

The main document scan answers many questions at once; this check asks only
one, on a stronger model, so a fully AI-made document is not missed. It runs
for every image upload and is cached like the scan.
"""
import json
from dataclasses import replace
from pathlib import Path

from ..external_ai.images import ExternalAiImageError, prepare_image_data_url
from ..external_ai.state import ExternalAiStateStore, build_cache_key
from .client import GEMINI_BASE_URL, GeminiScannerClient
from .contract import _box, _neutral, _text

AI_CHECK_VERSION = 'ai-generation-check-v1'
VERDICTS = ('ai_generated', 'uncertain', 'real_capture')
SIGN_TYPES = (
    'garbled_text', 'inconsistent_text', 'malformed_mark', 'synthetic_handwriting',
    'impossible_geometry', 'synthetic_texture', 'layout_artifact', 'background_artifact',
)
SIGN_LABELS = {
    'garbled_text': 'Garbled or invented printed words',
    'inconsistent_text': 'Same detail written differently',
    'malformed_mark': 'Malformed seal, logo, or stamp',
    'synthetic_handwriting': 'Machine-drawn handwriting or signature',
    'impossible_geometry': 'Text or lines ignore the paper shape',
    'synthetic_texture': 'Painted look without camera noise',
    'layout_artifact': 'Table lines wobble or merge',
    'background_artifact': 'Malformed objects around the document',
}

INSTRUCTION = """You are an image forensics assistant for the Provincial Social Welfare and Development
Office (PSWDO) of Antique, Philippines. Applicants upload phone photos or scans of paper documents:
medical certificates and abstracts, hospital bills, prescriptions, death certificates, funeral
contracts, IDs, and photos of damaged houses. Some uploads are not photos of real paper at all: the
whole image was generated, or regenerated from a real document, by an AI image model (for example
Gemini or Nano Banana, Imagen, ChatGPT image generation, DALL-E, Midjourney, Stable Diffusion, Flux).
Your only job is to decide which. Look at every region of the image closely before deciding.

Signs that the image was made by an AI model. Report each one you clearly see:
- garbled_text: printed words that are misspelled, invented, replaced by a similar-looking word, or
  that dissolve into letter-like shapes, especially in small print, headings, footers, table labels,
  stamps, and logos. A real printed form spells its own labels correctly.
- inconsistent_text: the same name, number, or word is written differently in two places, or a
  printed field label does not fit the value beside it.
- malformed_mark: a seal, logo, emblem, or rubber stamp whose ring text is unreadable or nonsense,
  whose symbols are warped, or whose letters are unevenly spaced.
- synthetic_handwriting: handwriting or a signature that looks machine-drawn: letters that morph in
  the middle of a word, strokes of perfectly even thickness with no pen pressure, scribbles posing as
  words.
- impossible_geometry: text, lines, or tables that do not follow the paper's folds, curl, or
  perspective; shadows or light that disagree; paper edges that melt into the background.
- synthetic_texture: a smooth, painted look with no camera sensor noise, unnaturally even paper, or
  blotchy artificial grain; everything equally sharp.
- layout_artifact: table rules that wobble, merge, or stop in the middle of a cell; columns that
  drift; boxes that do not close.
- background_artifact: objects around the document (desk, fingers, other papers) that are malformed
  or blend into each other.

Signs of a real capture. List the ones you see in real_capture_evidence: camera sensor noise or JPEG
blockiness, focus or motion blur that changes across the page, one consistent perspective and light,
real paper fibres, creases and stains, fine print that reads correctly everywhere, ink bleed and pen
pressure in handwriting.

Decide the verdict:
- ai_generated: two or more different kinds of AI signs are clear.
- uncertain: only one kind of AI sign is clear, or the image is too small or blurry to tell.
- real_capture: otherwise.
A real phone photo can be blurry, dark, tilted, wrinkled, low resolution, compressed by a messaging
app, cut off at the edge, have a stamp over text, or have messy handwriting. None of these alone is a
sign of AI. Text typed onto a real photo with an editing app is not an AI-made image; judge only
whether the photo itself was generated.

confidence is 0-100 for your verdict. For every AI sign give the page (starting at 1), box_2d as
[ymin, xmin, ymax, xmax] normalized to 0-1000, and one short neutral sentence that quotes the word or
describes the mark. At most 8 signs. summary is one neutral sentence for PSWDO staff. Never use the
words forged, forgery, fake, fraud, fraudulent, or authentic."""


def response_schema() -> dict:
    return {
        'type': 'object',
        'properties': {
            'verdict': {'type': 'string', 'enum': list(VERDICTS)},
            'confidence': {'type': 'integer', 'minimum': 0, 'maximum': 100},
            'signs': {
                'type': 'array', 'maxItems': 8,
                'items': {
                    'type': 'object',
                    'properties': {
                        'type': {'type': 'string', 'enum': list(SIGN_TYPES)},
                        'page': {'type': 'integer'},
                        'box_2d': {'type': 'array', 'items': {'type': 'integer'}, 'minItems': 4, 'maxItems': 4},
                        'description': {'type': 'string'},
                    },
                    'required': ['type', 'page', 'box_2d', 'description'],
                    'propertyOrdering': ['type', 'page', 'box_2d', 'description'],
                },
            },
            'real_capture_evidence': {'type': 'array', 'maxItems': 6, 'items': {'type': 'string'}},
            'summary': {'type': 'string'},
        },
        'required': ['verdict', 'confidence', 'signs', 'real_capture_evidence', 'summary'],
        'propertyOrdering': ['verdict', 'confidence', 'signs', 'real_capture_evidence', 'summary'],
    }


def normalize_check(raw, page_count: int) -> dict:
    """Bounded check result; raise ValueError for a non-object response."""
    if not isinstance(raw, dict):
        raise ValueError('check response is not an object')
    verdict = str(raw.get('verdict') or '').strip().lower()
    try:
        confidence = int(round(float(raw.get('confidence') or 0)))
    except (TypeError, ValueError):
        confidence = 0
    signs = []
    for item in raw.get('signs')[:8] if isinstance(raw.get('signs'), list) else []:
        if not isinstance(item, dict) or item.get('type') not in SIGN_TYPES:
            continue
        try:
            page = int(item.get('page') or 1)
        except (TypeError, ValueError):
            page = 1
        description = _neutral(_text(item.get('description'), 200), SIGN_LABELS[item['type']] + '.')
        signs.append({'type': item['type'], 'page': min(max(1, page), max(1, int(page_count))),
                      'box_2d': _box(item.get('box_2d')), 'description': description or SIGN_LABELS[item['type']] + '.'})
    evidence = [_text(item, 120) for item in (raw.get('real_capture_evidence') or [])[:6]
                if isinstance(raw.get('real_capture_evidence'), list)]
    return {
        'verdict': verdict if verdict in VERDICTS else 'uncertain',
        'confidence': min(100, max(0, confidence)),
        'signs': signs,
        'sign_kinds': sorted({sign['type'] for sign in signs}),
        'real_capture_evidence': [item for item in evidence if item],
        'summary': _neutral(_text(raw.get('summary'), 300), ''),
    }


def _settings_for_check(settings):
    """The check runs on its own (stronger) model list; the key and timeout are shared."""
    model = str(getattr(settings, 'gemini_ai_check_model', '') or getattr(settings, 'gemini_model', ''))
    fallbacks = tuple(getattr(settings, 'gemini_ai_check_fallback_models', ()) or ())
    try:
        return replace(settings, gemini_model=model, gemini_fallback_models=fallbacks,
                       gemini_timeout_seconds=max(float(settings.gemini_timeout_seconds), 60.0))
    except TypeError:  # test doubles that are not dataclasses
        settings.gemini_model, settings.gemini_fallback_models = model, fallbacks
        return settings


def check_ai_generation(settings, document_path: Path, images: list, client=None, state_store=None) -> dict:
    """{'status': 'completed', 'check': {...}} or {'status': 'unavailable', 'error_code': ...}. Never raises."""
    if not (getattr(settings, 'gemini_image_enabled', False) and getattr(settings, 'gemini_image_api_key', '')):
        return {'status': 'unavailable', 'error_code': 'GEMINI_NOT_CONFIGURED'}
    if not getattr(settings, 'gemini_ai_check_enabled', True):
        return {'status': 'unavailable', 'error_code': 'AI_CHECK_DISABLED'}
    pages = list(images or [])[:2]  # the first pages carry the document; bounded cost
    if not pages:
        return {'status': 'unavailable', 'error_code': 'GEMINI_NO_IMAGES'}
    try:
        urls = [prepare_image_data_url(image, max_edge=2048, jpeg_quality=90) for image in pages]
    except ExternalAiImageError:
        return {'status': 'unavailable', 'error_code': 'GEMINI_IMAGE_INVALID'}
    check_settings = _settings_for_check(settings)
    store = state_store or ExternalAiStateStore(Path(settings.external_ai_state_root) / 'gemini_ai_check')
    models = ','.join((check_settings.gemini_model, *check_settings.gemini_fallback_models))
    try:
        cache_key = build_cache_key(Path(document_path), models, AI_CHECK_VERSION, f'image:{len(urls)}', GEMINI_BASE_URL)
    except OSError:
        return {'status': 'unavailable', 'error_code': 'AI_CHECK_FILE_UNAVAILABLE'}
    cached = store.get_cached(cache_key)
    if cached is not None:
        return {**cached, 'cache_hit': True}
    request = json.dumps({'task': 'Decide whether this upload is a real photo or scan of paper, or AI-made.',
                          'page_count': len(urls)})
    provider = client or GeminiScannerClient(check_settings)
    result = provider.scan(request, urls, len(urls), instruction=INSTRUCTION, schema=response_schema(),
                           normalizer=normalize_check)
    if result.get('status') != 'completed':
        return result
    output = {'status': 'completed', 'model': result.get('model', ''), 'elapsed_ms': result.get('elapsed_ms', 0),
              'check': result['scan'], 'cache_hit': False}
    store.put_cached(cache_key, output)
    return output
