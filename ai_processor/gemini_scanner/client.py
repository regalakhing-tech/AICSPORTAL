"""Minimal Gemini generateContent client for the document scanner.

The API key travels only in the x-goog-api-key header. It is never placed in
the URL, logs, results, or exceptions.
"""
import json
import logging
import re
import socket
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from ..external_ai.client import _gemini_response_schema
from .contract import normalize_scan
from .prompt import SYSTEM_INSTRUCTION, response_schema

logger = logging.getLogger(__name__)
GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/'
_MAX_RESPONSE_BYTES = 2_000_000
_MODEL_PATTERN = re.compile(r'[a-z0-9][a-z0-9._-]{0,99}')


def _unavailable(code: str, attempts: int, started: float, model: str = '') -> dict:
    result = {'status': 'unavailable', 'error_code': code, 'attempts': attempts,
              'elapsed_ms': max(0, round((time.monotonic() - started) * 1000))}
    if model:
        result['model'] = model
    return result


def _inline_part(data_url: str) -> dict:
    header, separator, encoded = str(data_url).partition(',')
    mime_type = header.removeprefix('data:').split(';', 1)[0]
    if not separator or ';base64' not in header or not encoded or mime_type not in {'image/jpeg', 'image/png', 'image/webp'}:
        raise ValueError('invalid image data URL')
    return {'inline_data': {'mime_type': mime_type, 'data': encoded}}


def _without_max_items(schema):
    """Drop maxItems, which Gemini rejects in this schema; normalize_scan bounds list sizes."""
    if isinstance(schema, list):
        return [_without_max_items(item) for item in schema]
    if not isinstance(schema, dict):
        return schema
    output = {}
    for key, value in schema.items():
        if key == 'maxItems':
            continue
        if key == 'properties' and isinstance(value, dict):
            output[key] = {name: _without_max_items(field) for name, field in value.items()}
        else:
            output[key] = _without_max_items(value)
    return output


def _response_text(raw: bytes) -> str:
    envelope = json.loads(raw.decode('utf-8'))
    candidates = envelope.get('candidates') or []
    if not candidates:
        raise PermissionError('blocked')
    parts = (candidates[0].get('content') or {}).get('parts') or []
    text = ''.join(part.get('text', '') for part in parts if isinstance(part, dict) and not part.get('thought'))
    if not text.strip():
        if str(candidates[0].get('finishReason') or '').upper() in {'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST'}:
            raise PermissionError('blocked')
        raise ValueError('empty response')
    return text


class GeminiScannerClient:
    def __init__(self, settings, opener=None):
        self.settings = settings
        self.opener = opener or urlopen

    def _models(self) -> list[str]:
        models = [self.settings.gemini_model, *getattr(self.settings, 'gemini_fallback_models', ())]
        return list(dict.fromkeys(model for model in models if _MODEL_PATTERN.fullmatch(str(model))))

    def _body(self, request_text: str, image_data_urls: list[str], instruction: str | None = None,
              schema: dict | None = None) -> bytes:
        parts = [{'text': request_text}, *(_inline_part(url) for url in image_data_urls)]
        body = {
            'systemInstruction': {'parts': [{'text': instruction or SYSTEM_INSTRUCTION}]},
            'contents': [{'role': 'user', 'parts': parts}],
            'generationConfig': {
                'temperature': 0,
                'responseMimeType': 'application/json',
                'responseSchema': _without_max_items(_gemini_response_schema(schema or response_schema())),
            },
        }
        return json.dumps(body, ensure_ascii=False, separators=(',', ':')).encode('utf-8')

    def scan(self, request_text: str, image_data_urls: list[str], page_count: int, *, instruction: str | None = None,
             schema: dict | None = None, normalizer=None) -> dict:
        """Default: the document scanner. instruction/schema/normalizer let other
        AICS AI checks (e.g. the sign-up ID check) reuse the same transport."""
        started = time.monotonic()
        try:
            body = self._body(request_text, image_data_urls, instruction, schema)
        except ValueError:
            return _unavailable('GEMINI_IMAGE_INVALID', 0, started)
        timeout = max(5.0, float(self.settings.gemini_timeout_seconds))
        attempts, code, model = 0, 'GEMINI_UNAVAILABLE', ''
        models = self._models()
        for index, model in enumerate(models):
            # A brief connection failure after a slow primary-model timeout
            # should not discard an otherwise recoverable document check.
            max_attempts = 2 if index == len(models) - 1 else 1
            for retry in range(max_attempts):
                attempts += 1
                request = Request(GEMINI_BASE_URL + model + ':generateContent', data=body, method='POST', headers={
                    'Content-Type': 'application/json', 'Accept': 'application/json',
                    'x-goog-api-key': self.settings.gemini_image_api_key,
                })
                retry_next_model = True
                retry_same_model = False
                try:
                    with self.opener(request, timeout=timeout) as response:
                        raw = response.read(_MAX_RESPONSE_BYTES + 1)
                    if len(raw) > _MAX_RESPONSE_BYTES:
                        raise ValueError('response too large')
                    scan = (normalizer or normalize_scan)(json.loads(_response_text(raw)), page_count=page_count)
                    elapsed_ms = max(0, round((time.monotonic() - started) * 1000))
                    logger.info('Gemini scan completed model=%s attempts=%s elapsed_ms=%s pages=%s',
                                model, attempts, elapsed_ms, page_count)
                    return {'status': 'completed', 'provider': 'google_gemini', 'model': model,
                            'attempts': attempts, 'elapsed_ms': elapsed_ms, 'scan': scan}
                except HTTPError as exc:
                    if exc.code in {401, 403}:
                        code, retry_next_model = 'GEMINI_AUTH_FAILED', False
                    elif exc.code == 400:
                        code, retry_next_model = 'GEMINI_BAD_REQUEST', False
                    elif exc.code == 404:
                        code = 'GEMINI_MODEL_NOT_FOUND'
                    elif exc.code == 429:
                        code = 'GEMINI_RATE_LIMITED'
                    else:
                        code, retry_same_model = 'GEMINI_UNAVAILABLE', True
                except (TimeoutError, socket.timeout):
                    code = 'GEMINI_TIMEOUT'
                except PermissionError:
                    code = 'GEMINI_BLOCKED'
                except (URLError, ConnectionError):
                    code, retry_same_model = 'GEMINI_UNAVAILABLE', True
                except (ValueError, KeyError, IndexError, TypeError, UnicodeError, AttributeError):
                    code = 'GEMINI_INVALID_RESPONSE'
                logger.warning('Gemini scan attempt failed model=%s code=%s', model, code)
                if not retry_same_model or retry == max_attempts - 1:
                    break
            if not retry_next_model:
                break
        return _unavailable(code, attempts, started, model)
