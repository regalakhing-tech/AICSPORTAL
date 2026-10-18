"""Dedicated Gemini image assessment alongside the existing text provider."""
from __future__ import annotations

from dataclasses import replace
from pathlib import Path

from .service import review_document


def gemini_image_settings(settings):
    if not getattr(settings, 'gemini_image_enabled', False) or not getattr(settings, 'gemini_image_api_key', ''):
        return None
    return replace(
        settings,
        external_ai_enabled=True,
        external_ai_endpoint='https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
        external_ai_model='gemini-2.5-flash',
        external_ai_fallback_models=('gemini-2.5-flash-lite',),
        external_ai_api_key=settings.gemini_image_api_key,
        external_ai_image_enabled=True,
        external_ai_state_root=str(Path(settings.external_ai_state_root) / 'gemini_visual'),
    )


def review_gemini_images(settings, document_path, images, expected_type, ocr_text, fields,
                         local_result, classification, application_context) -> dict:
    visual_settings = gemini_image_settings(settings)
    if visual_settings is None:
        return {'status': 'unavailable', 'payload_mode': 'text', 'error_code': 'GEMINI_IMAGE_NOT_CONFIGURED'}
    output = review_document(visual_settings, document_path, images, expected_type, ocr_text, fields,
                             local_result, classification, application_context, force=True)
    if output.get('payload_mode') != 'image':
        return {'status': 'unavailable', 'payload_mode': 'text', 'error_code': 'GEMINI_IMAGE_UNAVAILABLE'}
    return output
