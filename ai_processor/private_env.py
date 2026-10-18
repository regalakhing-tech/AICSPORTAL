"""Load allowlisted external-AI settings from a server-only file."""
import re
from pathlib import Path
from typing import MutableMapping


ALLOWED_EXTERNAL_AI_NAMES = frozenset({
    'AICS_EXTERNAL_AI_ENABLED',
    'AICS_EXTERNAL_AI_ENDPOINT',
    'AICS_EXTERNAL_AI_MODEL',
    'AICS_EXTERNAL_AI_FALLBACK_MODELS',
    'AICS_EXTERNAL_AI_API_KEY',
    'AICS_EXTERNAL_AI_TIMEOUT_SECONDS',
    'AICS_EXTERNAL_AI_IMAGE_ENABLED',
    'AICS_EXTERNAL_AI_STATE_ROOT',
    # Gemini vision document scanner. Several common key names are accepted so
    # an existing private file keeps working without renaming its entries.
    'AICS_GEMINI_API_KEY',
    'AICS_GEMINI_IMAGE_API_KEY',
    'GEMINI_API_KEY',
    'GOOGLE_API_KEY',
    'AICS_GEMINI_IMAGE_ENABLED',
    'AICS_GEMINI_MODEL',
    'AICS_GEMINI_FALLBACK_MODELS',
    'AICS_GEMINI_TIMEOUT_SECONDS',
    'VALID_ID_OWNER_MODE',
})
# Other spellings of a Gemini key name, e.g. GEMINI_KEY or AICS_GOOGLE_AI_KEY.
GEMINI_KEY_NAME_PATTERN = re.compile(r'^(?:AICS_)?(?:GEMINI|GOOGLE)(?:_AI|_IMAGE|_VISION)?(?:_API)?_KEY$')


def load_private_environment(path: Path, environ: MutableMapping[str, str]) -> dict[str, str]:
    """Load allowlisted NAME=VALUE lines without overriding process values."""
    loaded: dict[str, str] = {}
    if not path.is_file():
        return loaded
    try:
        lines = path.read_text(encoding='utf-8-sig').splitlines()
    except (OSError, UnicodeError):
        return loaded
    for line in lines:
        stripped = line.strip()
        if not stripped or stripped.startswith('#') or '=' not in stripped:
            continue
        name, value = (part.strip() for part in stripped.split('=', 1))
        if (name not in ALLOWED_EXTERNAL_AI_NAMES and not GEMINI_KEY_NAME_PATTERN.match(name)) or '\x00' in value:
            continue
        loaded[name] = value
        environ.setdefault(name, value)
    return loaded
