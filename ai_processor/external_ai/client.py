"""Failure-tolerant OpenAI-compatible chat-completions client."""
import json
import logging
import os
import re
import shutil
import socket
import subprocess
import time
from email.message import Message
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

from ..config import external_ai_configuration_health
from .contracts import ExternalAiContractError, normalize_assessment


logger = logging.getLogger(__name__)
_MAX_RESPONSE_BYTES = 1_000_000
_REASON_CODES = (
    'APPLICANT_NAME_MISMATCH',
    'APPLICANT_NAME_UNCERTAIN',
    'DATE_UNCERTAIN',
    'DOCUMENT_TYPE_MISMATCH',
    'DOCUMENT_TYPE_UNCERTAIN',
    'FIELD_CONFLICT',
    'FIELD_UNCERTAIN',
    'INSUFFICIENT_EVIDENCE',
    'LOW_OCR_QUALITY',
    'NO_CONFLICT_FOUND',
    'ALTERATION_REVIEW_SIGNAL',
    'NO_ALTERATION_SIGNAL',
)
_SYSTEM_INSTRUCTION = """You are an advisory document-review component. Return JSON only.
Use schema_version external-ai-assessment-v2. Allowed state values are support, review,
conflict, unavailable. Allowed confidence_band values are low, moderate, high. Never
approve, reject, confirm a duplicate, accuse fraud, or make an eligibility decision.
Return keys: schema_version, state, confidence_band, document_type, reasons, fields.
Reasons must contain only the allowed uppercase reason codes. Fields must be a JSON
object keyed by the supplied field names; each value contains only value and state.
Cross-reference identity fields only against the supplied beneficiary object. Never use
or infer applicant identity. Read every supplied page. Extract identity values independently
from the document image; local OCR fields are hints and may contain sentence fragments.
Do not mark an identity conflict solely from local OCR or a prior local assessment. Mark
conflict only when a different identity value is clearly readable in the document, and
return the clearly readable conflicting value. Otherwise use review or unavailable. For a
medical document, search the entire document for the supplied beneficiary name before choosing
patient_name. If that beneficiary is clearly named as the patient, applicant, certified person,
caregiver, or bedside watcher, return the matching beneficiary identity as support. Do not mark
a conflict merely because another related person is also named. Never select the physician,
signatory, or authorizer as patient_name, and never use prose following the word patient. Also return document_quality,
signature_review, and visual_integrity objects with state and a short reason. A visual
integrity or signature flag is advisory and is never proof of forgery or tampering.
Also return tamper_assessment with state, confidence_band, summary, and at most 12
localized indicators. Each indicator must contain type, page, normalized box_2d as
[ymin,xmin,ymax,xmax] from 0 to 1000, confidence_band, and a short reason. Compare
security-sensitive filled values such as dates, names, amounts, reference numbers,
signatures, and stamps with nearby peer text of the same role and rendering mode.
A font difference alone is never enough for a review signal. Look for corroborating
size, weight, spacing, baseline, edge sharpness, ink color, pasted boundaries,
background texture, compression, or chronology differences. Never conclude forgery,
fraud, authenticity, or applicant intent. Use unavailable when images are absent.
Use "unknown" when the document type cannot be identified."""
_APPLICANT_ID_INSTRUCTION = _SYSTEM_INSTRUCTION.replace(
    'Cross-reference identity fields only against the supplied beneficiary object. Never use\nor infer applicant identity.',
    'Cross-reference the Valid ID cardholder only against the supplied applicant object. '
    'Never use beneficiary identity to pass this ID.',
)


def _assessment_json_schema(evidence: dict) -> dict:
    evidence_fields = evidence.get('fields') if isinstance(evidence, dict) else {}
    field_names = [
        name for name in (evidence_fields or {})
        if isinstance(name, str) and re.fullmatch(r'[a-z][a-z0-9_]{0,63}', name)
    ]
    field_item = {
        'type': 'object',
        'properties': {
            'value': {'type': ['string', 'number', 'boolean', 'null']},
            'state': {'type': 'string', 'enum': ['support', 'review', 'conflict', 'unavailable']},
        },
        'required': ['value', 'state'],
        'additionalProperties': False,
    }
    return {
        'type': 'object',
        'properties': {
            'schema_version': {'type': 'string', 'enum': ['external-ai-assessment-v2']},
            'state': {'type': 'string', 'enum': ['support', 'review', 'conflict', 'unavailable']},
            'confidence_band': {'type': 'string', 'enum': ['low', 'moderate', 'high']},
            'document_type': {'type': 'string'},
            'reasons': {
                'type': 'array',
                'items': {'type': 'string', 'enum': list(_REASON_CODES)},
                'maxItems': 8,
            },
            'fields': {
                'type': 'object',
                'properties': {name: field_item for name in field_names},
                'required': field_names,
                'additionalProperties': False,
            },
            'document_quality': {
                'type': 'object',
                'properties': {
                    'state': {'type': 'string', 'enum': ['good', 'usable', 'recapture_required']},
                    'reason': {'type': 'string'},
                },
                'required': ['state', 'reason'],
                'additionalProperties': False,
            },
            'signature_review': {
                'type': 'object',
                'properties': {
                    'state': {'type': 'string', 'enum': ['visible', 'not_visible', 'review_needed']},
                    'reason': {'type': 'string'},
                },
                'required': ['state', 'reason'],
                'additionalProperties': False,
            },
            'visual_integrity': {
                'type': 'object',
                'properties': {
                    'state': {'type': 'string', 'enum': ['no_obvious_issue', 'review_needed', 'unavailable']},
                    'reason': {'type': 'string'},
                },
                'required': ['state', 'reason'],
                'additionalProperties': False,
            },
            'tamper_assessment': {
                'type': 'object',
                'properties': {
                    'state': {'type': 'string', 'enum': ['no_signal', 'review_signal', 'unavailable']},
                    'confidence_band': {'type': 'string', 'enum': ['low', 'moderate', 'high']},
                    'summary': {'type': 'string'},
                    'indicators': {
                        'type': 'array', 'maxItems': 12,
                        'items': {
                            'type': 'object',
                            'properties': {
                                'type': {'type': 'string', 'enum': [
                                    'typography_mismatch', 'baseline_or_spacing_mismatch',
                                    'ink_or_color_mismatch', 'pasted_region_boundary',
                                    'background_texture_discontinuity',
                                    'compression_or_resampling_difference', 'date_context_conflict',
                                    'value_context_conflict', 'signature_or_stamp_anomaly',
                                    'other_visual_anomaly',
                                ]},
                                'page': {'type': 'integer'},
                                'box_2d': {'type': 'array', 'items': {'type': 'integer'}, 'minItems': 4, 'maxItems': 4},
                                'confidence_band': {'type': 'string', 'enum': ['low', 'moderate', 'high']},
                                'reason': {'type': 'string'},
                            },
                            'required': ['type', 'page', 'box_2d', 'confidence_band', 'reason'],
                            'additionalProperties': False,
                        },
                    },
                },
                'required': ['state', 'confidence_band', 'summary', 'indicators'],
                'additionalProperties': False,
            },
        },
        'required': ['schema_version', 'state', 'confidence_band', 'document_type', 'reasons', 'fields',
                     'document_quality', 'signature_review', 'visual_integrity', 'tamper_assessment'],
        'additionalProperties': False,
    }


def _gemini_response_schema(schema: dict) -> dict:
    """Translate the shared JSON schema into Gemini's supported Schema subset."""
    type_names = {
        'object': 'OBJECT', 'array': 'ARRAY', 'string': 'STRING',
        'number': 'NUMBER', 'integer': 'INTEGER', 'boolean': 'BOOLEAN',
    }

    def convert(value):
        if isinstance(value, list):
            return [convert(item) for item in value]
        if not isinstance(value, dict):
            return value
        output = {}
        for key, item in value.items():
            if key == 'additionalProperties':
                continue
            if key == 'properties' and isinstance(item, dict):
                # Keys here are field names (a field may be called "type"),
                # so only their values are schemas.
                output['properties'] = {name: convert(field) for name, field in item.items()}
                continue
            if key == 'type':
                if isinstance(item, list):
                    allowed = [candidate for candidate in item if candidate != 'null']
                    selected = 'string' if 'string' in allowed else (allowed[0] if allowed else 'string')
                    output['type'] = type_names.get(selected, str(selected).upper())
                    if 'null' in item:
                        output['nullable'] = True
                else:
                    output['type'] = type_names.get(str(item), str(item).upper())
                continue
            output[key] = convert(item)
        return output

    return convert(schema)


class _CurlResponse:
    def __init__(self, payload: bytes, status: int):
        self.payload = payload
        self.status = status

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self, limit=-1):
        return self.payload if limit is None or limit < 0 else self.payload[:limit]


def _curl_config_value(value: str) -> str:
    return value.replace('\\', '\\\\').replace('"', '\\"').replace('\r', '\\r').replace('\n', '\\n')


class CurlOpener:
    """Native-curl transport with credentials passed through stdin, never argv."""
    def __init__(self, runner=subprocess.run, executable=None):
        self.runner = runner
        self.executable = executable or shutil.which('curl.exe') or shutil.which('curl')

    def __call__(self, request: Request, timeout: float):
        if not self.executable:
            raise URLError('native curl transport unavailable')
        lines = [
            f'url = "{_curl_config_value(request.full_url)}"',
            f'request = "{_curl_config_value(request.get_method())}"',
        ]
        for name, value in request.header_items():
            lines.append(f'header = "{_curl_config_value(name + ": " + value)}"')
        if request.data is not None:
            body = request.data.decode('utf-8') if isinstance(request.data, bytes) else str(request.data)
            lines.append(f'data = "{_curl_config_value(body)}"')
        config = '\n'.join(lines) + '\n'
        marker = '\n__AICS_HTTP_STATUS__:%{http_code}\n__AICS_CONTENT_TYPE__:%{content_type}'
        args = [self.executable, '--silent', '--show-error', '--config', '-', '--max-time', str(max(1, int(timeout))), '--write-out', marker]
        creationflags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
        try:
            completed = self.runner(
                args, input=config, capture_output=True, text=True,
                timeout=max(1, int(timeout)) + 1, creationflags=creationflags,
            )
        except subprocess.TimeoutExpired as exc:
            raise TimeoutError('native curl transport timed out') from exc
        if completed.returncode != 0:
            raise URLError('native curl transport failed')
        output = completed.stdout
        if '\n__AICS_HTTP_STATUS__:' not in output:
            raise URLError('native curl transport returned no status')
        raw_body, metadata = output.rsplit('\n__AICS_HTTP_STATUS__:', 1)
        status_text, _, content_type = metadata.partition('\n__AICS_CONTENT_TYPE__:')
        try:
            status = int(status_text.strip())
        except ValueError as exc:
            raise URLError('native curl transport returned invalid status') from exc
        payload = raw_body.encode('utf-8')
        if status >= 400:
            headers = Message()
            headers['Content-Type'] = content_type.strip() or 'application/octet-stream'
            raise HTTPError(request.full_url, status, 'Provider request failed', headers, __import__('io').BytesIO(payload))
        return _CurlResponse(payload, status)


def _unavailable(code: str, attempts: int, elapsed_ms: int, payload_mode: str) -> dict:
    return {
        'status': 'unavailable',
        'error_code': code,
        'attempts': attempts,
        'elapsed_ms': elapsed_ms,
        'payload_mode': payload_mode,
    }


def _forbidden_is_access_blocked(error: HTTPError) -> bool:
    """Distinguish an edge/browser challenge from an API credential rejection."""
    headers = error.headers
    content_type = str(headers.get('Content-Type') or '').lower() if headers else ''
    server = str(headers.get('Server') or '').lower() if headers else ''
    try:
        body = error.read(16_384).decode('utf-8', 'ignore').lower()
    except (OSError, AttributeError, UnicodeError):
        body = ''
    challenge = any(marker in body for marker in ('just a moment', 'cloudflare', 'cf-chl-', 'attention required'))
    return 'text/html' in content_type or 'cloudflare' in server or challenge


class ExternalAiClient:
    def __init__(self, settings, opener=None, sleeper=time.sleep):
        self.settings = settings
        self.opener = opener or (CurlOpener() if urlparse(settings.external_ai_endpoint).hostname == 'api.groq.com' else urlopen)
        self.sleeper = sleeper

    def _is_gemini(self) -> bool:
        return urlparse(self.settings.external_ai_endpoint).hostname == 'generativelanguage.googleapis.com'

    def _gemini_models(self) -> list[str]:
        models = [self.settings.external_ai_model, *getattr(self.settings, 'external_ai_fallback_models', ())]
        return list(dict.fromkeys(model for model in models if re.fullmatch(r'[a-z0-9][a-z0-9._-]{0,99}', model)))

    def _gemini_endpoint(self, model: str) -> str:
        return re.sub(
            r'(/models/)[^/:]+(:generateContent(?:\?.*)?$)',
            rf'\g<1>{model}\g<2>',
            self.settings.external_ai_endpoint,
        )

    @staticmethod
    def _gemini_inline_part(data_url: str) -> dict:
        header, separator, encoded = str(data_url).partition(',')
        if not separator or ';base64' not in header or not encoded:
            raise ValueError('invalid image data URL')
        mime_type = header.removeprefix('data:').split(';', 1)[0]
        if mime_type not in {'image/jpeg', 'image/png', 'image/webp'}:
            raise ValueError('unsupported image data URL')
        return {'inline_data': {'mime_type': mime_type, 'data': encoded}}

    def _request_body(self, evidence: dict, image_data_urls: list[str]) -> bytes:
        evidence_json = json.dumps(evidence, ensure_ascii=False, separators=(',', ':'), allow_nan=False)
        instruction = (_APPLICANT_ID_INSTRUCTION if evidence.get('reference_person') == 'applicant'
                       and evidence.get('expected_document_type') == 'valid_id' else _SYSTEM_INSTRUCTION)
        if self._is_gemini():
            parts = [{'text': evidence_json}]
            parts.extend(self._gemini_inline_part(data_url) for data_url in image_data_urls)
            body = {
                'systemInstruction': {'parts': [{'text': instruction}]},
                'contents': [{'role': 'user', 'parts': parts}],
                'generationConfig': {
                    'temperature': 0,
                    'responseMimeType': 'application/json',
                    'responseSchema': _gemini_response_schema(_assessment_json_schema(evidence)),
                },
            }
            return json.dumps(body, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode('utf-8')
        if not image_data_urls:
            user_content = evidence_json
        else:
            user_content = [{'type': 'text', 'text': evidence_json}]
            user_content.extend({'type': 'image_url', 'image_url': {'url': data_url}} for data_url in image_data_urls)
        body = {
            'model': self.settings.external_ai_model,
            'temperature': 0,
            'messages': [
                {'role': 'system', 'content': instruction},
                {'role': 'user', 'content': user_content},
            ],
        }
        if urlparse(self.settings.external_ai_endpoint).hostname == 'api.groq.com':
            body['response_format'] = {
                'type': 'json_schema',
                'json_schema': {
                    'name': 'aics_document_assessment',
                    'strict': True,
                    'schema': _assessment_json_schema(evidence),
                },
            }
        return json.dumps(body, ensure_ascii=False, separators=(',', ':'), allow_nan=False).encode('utf-8')

    def _extract_assessment(self, raw: bytes, payload_mode: str = 'text', page_count: int = 0) -> dict:
        envelope = json.loads(raw.decode('utf-8'))
        if self._is_gemini():
            content = envelope['candidates'][0]['content']['parts'][0]['text']
        else:
            content = envelope['choices'][0]['message']['content']
        if not isinstance(content, str):
            raise ValueError('content is not text')
        content = content.strip()
        if content.startswith('```') and content.endswith('```'):
            lines = content.splitlines()
            if len(lines) >= 3:
                content = '\n'.join(lines[1:-1]).strip()
                if content.lower().startswith('json\n'):
                    content = content[5:].lstrip()
        return normalize_assessment(json.loads(content), payload_mode=payload_mode, page_count=page_count)

    def assess(self, evidence: dict, image_data_url: str | None = None,
               image_data_urls: list[str] | None = None) -> dict:
        images = list(image_data_urls or ([] if image_data_url is None else [image_data_url]))
        payload_mode = 'image' if images else 'text'
        started = time.monotonic()
        if external_ai_configuration_health(self.settings) != 'configured':
            return _unavailable('EXTERNAL_AI_NOT_CONFIGURED', 0, 0, payload_mode)
        body = self._request_body(evidence, images)
        attempts = 0
        last_code = 'EXTERNAL_AI_UNAVAILABLE'
        is_gemini = self._is_gemini()
        attempt_models = self._gemini_models() if is_gemini else [self.settings.external_ai_model] * 2
        for attempt_index, active_model in enumerate(attempt_models):
            elapsed = time.monotonic() - started
            # Each Gemini model receives its own bounded attempt. Otherwise a
            # slow primary would consume the entire budget before failover.
            remaining = self.settings.external_ai_timeout_seconds if is_gemini else self.settings.external_ai_timeout_seconds - elapsed
            if remaining <= 0:
                last_code = 'EXTERNAL_AI_TIMEOUT'
                break
            attempts += 1
            headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            }
            if is_gemini:
                headers['x-goog-api-key'] = self.settings.external_ai_api_key
            else:
                headers['Authorization'] = 'Bearer ' + self.settings.external_ai_api_key
            request = Request(
                self._gemini_endpoint(active_model) if is_gemini else self.settings.external_ai_endpoint,
                data=body,
                headers=headers,
                method='POST',
            )
            retryable = False
            try:
                with self.opener(request, timeout=max(0.1, remaining)) as response:
                    raw = response.read(_MAX_RESPONSE_BYTES + 1)
                    if len(raw) > _MAX_RESPONSE_BYTES:
                        raise ValueError('response too large')
                assessment = self._extract_assessment(raw, payload_mode=payload_mode, page_count=len(images))
                elapsed_ms = max(0, round((time.monotonic() - started) * 1000))
                result = {
                    'status': 'completed',
                    'assessment': assessment,
                    'provider': 'google_gemini' if is_gemini else 'external_chat_completions',
                    'model': active_model,
                    'attempts': attempts,
                    'elapsed_ms': elapsed_ms,
                    'payload_mode': payload_mode,
                }
                logger.info('External AI request status=completed attempts=%s elapsed_ms=%s payload_mode=%s', attempts, elapsed_ms, payload_mode)
                return result
            except HTTPError as exc:
                if exc.code == 403 and _forbidden_is_access_blocked(exc):
                    last_code = 'EXTERNAL_AI_ACCESS_BLOCKED'
                elif exc.code in {401, 403}:
                    last_code = 'EXTERNAL_AI_AUTH_FAILED'
                elif exc.code in {400, 404, 422}:
                    last_code = 'EXTERNAL_AI_MODEL_INVALID'
                    retryable = is_gemini and exc.code == 404
                elif exc.code == 429:
                    last_code = 'EXTERNAL_AI_RATE_LIMITED'
                    retryable = True
                elif 500 <= exc.code <= 599:
                    last_code = 'EXTERNAL_AI_UNAVAILABLE'
                    retryable = True
                else:
                    last_code = 'EXTERNAL_AI_UNAVAILABLE'
            except (TimeoutError, socket.timeout):
                last_code = 'EXTERNAL_AI_TIMEOUT'
                retryable = True
            except (URLError, ConnectionError, ConnectionResetError):
                last_code = 'EXTERNAL_AI_UNAVAILABLE'
                retryable = True
            except (ExternalAiContractError, ValueError, KeyError, IndexError, TypeError, UnicodeError, json.JSONDecodeError):
                last_code = 'EXTERNAL_AI_INVALID_RESPONSE'
            if not retryable or attempt_index == len(attempt_models) - 1:
                break
            if not is_gemini:
                self.sleeper(min(0.1, max(0.0, self.settings.external_ai_timeout_seconds - (time.monotonic() - started))))
        elapsed_ms = max(0, round((time.monotonic() - started) * 1000))
        logger.warning('External AI request status=unavailable code=%s attempts=%s elapsed_ms=%s payload_mode=%s', last_code, attempts, elapsed_ms, payload_mode)
        return _unavailable(last_code, attempts, elapsed_ms, payload_mode)
