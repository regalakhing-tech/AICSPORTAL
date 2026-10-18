"""Environment-backed settings; no local paths or credentials are committed."""
from dataclasses import dataclass
import os
from pathlib import Path
from urllib.parse import urlparse

from .private_env import GEMINI_KEY_NAME_PATTERN, load_private_environment

_DEFAULT_PROJECT_ROOT = Path(__file__).resolve().parent.parent
_PRIVATE_ENV_PATH = Path(os.getenv('AICS_EXTERNAL_AI_ENV_FILE', r'C:\xampp\aics_private\aics-ai.env'))
load_private_environment(_PRIVATE_ENV_PATH, os.environ)

_GEMINI_KEY_NAMES = ('AICS_GEMINI_API_KEY', 'AICS_GEMINI_IMAGE_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY')


def resolve_gemini_api_key(environ=os.environ) -> str:
    """Return the first configured Gemini key without logging which one."""
    other_names = sorted(name for name in environ if GEMINI_KEY_NAME_PATTERN.match(name) and name not in _GEMINI_KEY_NAMES)
    for name in (*_GEMINI_KEY_NAMES, *other_names):
        value = str(environ.get(name) or '').strip()
        if value:
            return value
    return ''

@dataclass(frozen=True)
class Settings:
    db_host: str = (os.getenv('AICS_TEST_DB_HOST') if os.getenv('AICS_HTTP_TEST_MODE', '') == '1' else os.getenv('AICS_DB_HOST', 'localhost')) or ''
    db_port: int = int((os.getenv('AICS_TEST_DB_PORT') if os.getenv('AICS_HTTP_TEST_MODE', '') == '1' else os.getenv('AICS_DB_PORT', '3306')) or '0')
    db_name: str = ((os.getenv('AICS_TEST_DB_NAME') if os.getenv('AICS_HTTP_TEST_MODE', '') == '1' else os.getenv('AICS_DB_NAME', 'AICSdb')) or '').strip()
    http_test_mode: bool = os.getenv('AICS_HTTP_TEST_MODE', '') == '1'
    db_user: str = (os.getenv('AICS_TEST_DB_USER') if os.getenv('AICS_HTTP_TEST_MODE', '') == '1' else os.getenv('AICS_DB_USER', 'root')) or ''
    db_password: str = (os.getenv('AICS_TEST_DB_PASSWORD') if os.getenv('AICS_HTTP_TEST_MODE', '') == '1' else os.getenv('AICS_DB_PASS', '')) or ''
    project_root: str = os.getenv('AICS_PROJECT_ROOT', str(_DEFAULT_PROJECT_ROOT))
    upload_root: str = os.getenv('AICS_UPLOAD_ROOT', str(_DEFAULT_PROJECT_ROOT / 'Applicants' / 'uploads'))
    poppler_path: str = os.getenv('AICS_POPPLER_PATH', '')
    pdf_timeout_seconds: int = int(os.getenv('AICS_PDF_TIMEOUT_SECONDS', '60'))
    ocr_timeout_seconds: int = int(os.getenv('AICS_OCR_TIMEOUT_SECONDS', '45'))
    tesseract_path: str = os.getenv('AICS_TESSERACT_PATH', '')
    max_pdf_pages: int = int(os.getenv('AICS_MAX_PDF_PAGES', '3'))
    blur_threshold: float = float(os.getenv('AICS_BLUR_THRESHOLD', '80'))
    stale_minutes: int = int(os.getenv('AICS_AI_STALE_MINUTES', '30'))
    adcd_enabled: bool = os.getenv('AICS_ADCD_ENABLED', '0') == '1'
    adcd_auto_activate: bool = os.getenv('AICS_ADCD_AUTO_ACTIVATE', '1') == '1'
    adcd_enforce_review: bool = os.getenv('AICS_ADCD_ENFORCE_REVIEW', '0') == '1'
    # AICS-prefixed names are the supported deployment contract.  The two
    # legacy names remain only as a backwards-compatible fallback.
    adcd_service_url: str = (os.getenv('AICS_ADCD_SERVICE_URL') or os.getenv('ADCD_SERVICE_URL', 'http://127.0.0.1:5001')).rstrip('/')
    adcd_connect_timeout_seconds: float = float(os.getenv('ADCD_CONNECT_TIMEOUT', '3'))
    adcd_read_timeout_seconds: float = float(os.getenv('ADCD_READ_TIMEOUT', '90'))
    adcd_python_executable: str = os.getenv('AICS_ADCD_PYTHON_EXECUTABLE', '')
    adcd_checkpoint: str = os.getenv('AICS_ADCD_CHECKPOINT', '')
    adcd_docres_checkpoint: str = os.getenv('AICS_ADCD_DOCRES_CHECKPOINT', '')
    adcd_qt_table: str = os.getenv('AICS_ADCD_QT_TABLE', '')
    adcd_net_root: str = os.getenv('AICS_ADCD_NET_ROOT', '')
    adcd_output_root: str = os.getenv('AICS_ADCD_OUTPUT_ROOT', '')
    adcd_device: str = os.getenv('AICS_ADCD_DEVICE', 'auto')
    adcd_timeout_seconds: int = int(os.getenv('AICS_ADCD_TIMEOUT_SECONDS', '90'))
    adcd_save_masks: bool = os.getenv('AICS_ADCD_SAVE_MASKS', '0') == '1'
    ai_review_artifact_root: str = os.getenv('AICS_AI_REVIEW_ARTIFACT_ROOT', r'C:\xampp\aics_private\ai_review_artifacts')
    calibration_report_path: str = os.getenv('AICS_AI_CALIBRATION_REPORT', str(_DEFAULT_PROJECT_ROOT / 'ai_processor' / 'calibration-report.json'))
    external_ai_enabled: bool = os.getenv('AICS_EXTERNAL_AI_ENABLED', '0') == '1'
    external_ai_endpoint: str = os.getenv('AICS_EXTERNAL_AI_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions').strip()
    external_ai_model: str = os.getenv('AICS_EXTERNAL_AI_MODEL', 'openai/gpt-oss-20b').strip()
    external_ai_fallback_models: tuple[str, ...] = tuple(
        model.strip() for model in os.getenv(
            'AICS_EXTERNAL_AI_FALLBACK_MODELS',
            'gemini-2.5-flash,gemini-2.5-flash-lite',
        ).split(',') if model.strip()
    )
    external_ai_api_key: str = os.getenv('AICS_EXTERNAL_AI_API_KEY', '').strip()
    external_ai_timeout_seconds: float = float(os.getenv('AICS_EXTERNAL_AI_TIMEOUT_SECONDS', '12'))
    external_ai_image_enabled: bool = os.getenv('AICS_EXTERNAL_AI_IMAGE_ENABLED', '0') == '1'
    gemini_image_enabled: bool = os.getenv('AICS_GEMINI_IMAGE_ENABLED', '1') == '1'
    gemini_image_api_key: str = resolve_gemini_api_key()
    gemini_model: str = os.getenv('AICS_GEMINI_MODEL', 'gemini-3.5-flash-lite').strip()
    gemini_fallback_models: tuple[str, ...] = tuple(
        model.strip() for model in os.getenv('AICS_GEMINI_FALLBACK_MODELS', 'gemini-3.1-flash-lite').split(',')
        if model.strip()
    )
    gemini_timeout_seconds: float = float(os.getenv('AICS_GEMINI_TIMEOUT_SECONDS', '45'))
    valid_id_owner_mode: str = os.getenv('VALID_ID_OWNER_MODE', 'applicant').strip().lower()
    external_ai_state_root: str = os.getenv('AICS_EXTERNAL_AI_STATE_ROOT', r'C:\xampp\aics_private\external_ai').strip()

SETTINGS = Settings()


def external_ai_configuration_health(settings: Settings = SETTINGS) -> str:
    if not settings.external_ai_enabled:
        return 'disabled'
    if not settings.external_ai_api_key:
        return 'missing_key'
    parsed = urlparse(settings.external_ai_endpoint)
    if parsed.scheme != 'https' or not parsed.netloc or parsed.username or parsed.password:
        return 'invalid_endpoint'
    if not settings.external_ai_model:
        return 'invalid_model'
    if not 2.0 <= settings.external_ai_timeout_seconds <= 12.0:
        return 'invalid_timeout'
    return 'configured'


def redacted_external_ai_config(settings: Settings = SETTINGS) -> dict[str, str | bool | int]:
    """Return only privacy-safe external-provider configuration metadata."""
    return {
        'status': external_ai_configuration_health(settings),
        'enabled': settings.external_ai_enabled,
        'image_enabled': settings.external_ai_image_enabled,
        'timeout_seconds': int(settings.external_ai_timeout_seconds),
    }


def validate_worker_configuration(settings: Settings = SETTINGS) -> None:
    """Reject an invalid deployment configuration without revealing paths."""
    if not Path(settings.project_root).is_dir():
        raise RuntimeError('AI_PROJECT_ROOT_INVALID')
    if not Path(settings.upload_root).is_dir():
        raise RuntimeError('AI_UPLOAD_ROOT_INVALID')
    if settings.adcd_enabled:
        parsed = urlparse(settings.adcd_service_url)
        try:
            port = parsed.port
        except ValueError as exc:
            raise RuntimeError('AI_ADCD_SERVICE_CONFIG_INVALID') from exc
        if parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or parsed.username or parsed.password or not port:
            raise RuntimeError('AI_ADCD_SERVICE_CONFIG_INVALID')
        # A missing optional artifact mirror must not block the complete
        # document-verification worker. Artifact persistence reports its own
        # availability after the ADCD service returns a result.
        if settings.adcd_output_root and not Path(settings.adcd_output_root).is_dir():
            raise RuntimeError('AI_ADCD_OUTPUT_ROOT_INVALID')
