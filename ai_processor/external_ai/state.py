"""Private file-backed external-provider cache and circuit state."""
from hashlib import sha256
import json
import os
from pathlib import Path
import re
import tempfile
import time


_CACHE_KEY_PATTERN = re.compile(r'^[a-f0-9]{64}$')


def build_cache_key(document_path: Path, model: str, prompt_version: str, payload_mode: str, endpoint: str = '') -> str:
    digest = sha256()
    with document_path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    for value in (endpoint, model, prompt_version, payload_mode):
        digest.update(b'\x00')
        digest.update(str(value).encode('utf-8'))
    return digest.hexdigest()


class ExternalAiStateStore:
    def __init__(self, root: Path):
        self.root = Path(root)
        self.cache_root = self.root / 'cache'
        self.provider_state_path = self.root / 'provider-state.json'
        self.capability_path = self.root / 'image-capability.json'

    @staticmethod
    def _read_json(path: Path) -> dict | None:
        try:
            payload = json.loads(path.read_text(encoding='utf-8'))
            return payload if isinstance(payload, dict) else None
        except (OSError, UnicodeError, json.JSONDecodeError):
            return None

    @staticmethod
    def _write_json(path: Path, payload: dict) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        temporary_name = None
        try:
            with tempfile.NamedTemporaryFile('w', encoding='utf-8', dir=path.parent, prefix=path.name + '.', suffix='.tmp', delete=False) as handle:
                temporary_name = handle.name
                json.dump(payload, handle, separators=(',', ':'), sort_keys=True, allow_nan=False)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary_name, path)
        finally:
            if temporary_name and os.path.exists(temporary_name):
                try:
                    os.unlink(temporary_name)
                except OSError:
                    pass

    def _cache_path(self, cache_key: str) -> Path | None:
        return self.cache_root / (cache_key + '.json') if _CACHE_KEY_PATTERN.fullmatch(cache_key) else None

    def get_cached(self, cache_key: str, now: float | None = None) -> dict | None:
        path = self._cache_path(cache_key)
        if path is None:
            return None
        payload = self._read_json(path)
        current = time.time() if now is None else now
        if not payload or float(payload.get('expires_at') or 0) <= current or not isinstance(payload.get('value'), dict):
            return None
        return payload['value']

    def put_cached(self, cache_key: str, value: dict, now: float | None = None, ttl_seconds: int = 604800) -> None:
        path = self._cache_path(cache_key)
        if path is None or not isinstance(value, dict):
            raise ValueError('EXTERNAL_AI_CACHE_INVALID')
        current = time.time() if now is None else now
        self._write_json(path, {'expires_at': current + max(1, ttl_seconds), 'value': value})

    def _provider_state(self) -> dict:
        return self._read_json(self.provider_state_path) or {'failure_count': 0, 'circuit_open_until': 0}

    def circuit_is_open(self, now: float | None = None) -> bool:
        current = time.time() if now is None else now
        return float(self._provider_state().get('circuit_open_until') or 0) > current

    def record_success(self, now: float | None = None) -> None:
        current = time.time() if now is None else now
        self._write_json(self.provider_state_path, {'failure_count': 0, 'circuit_open_until': 0, 'updated_at': current})

    def record_transient_failure(self, now: float | None = None) -> None:
        current = time.time() if now is None else now
        state = self._provider_state()
        count = max(0, int(state.get('failure_count') or 0)) + 1
        open_until = current + 300 if count >= 3 else 0
        self._write_json(self.provider_state_path, {'failure_count': count, 'circuit_open_until': open_until, 'updated_at': current})

    @staticmethod
    def _endpoint_hash(endpoint: str) -> str:
        return sha256(str(endpoint).encode('utf-8')).hexdigest()

    def mark_image_capability(self, endpoint: str, model: str, now: float | None = None) -> None:
        self._write_json(self.capability_path, {
            'schema_version': 'external-ai-image-capability-v1',
            'endpoint_hash': self._endpoint_hash(endpoint),
            'model': str(model),
            'verified_at': time.time() if now is None else now,
        })

    def image_capability_verified(self, endpoint: str, model: str) -> bool:
        payload = self._read_json(self.capability_path)
        return bool(
            payload
            and payload.get('schema_version') == 'external-ai-image-capability-v1'
            and payload.get('endpoint_hash') == self._endpoint_hash(endpoint)
            and payload.get('model') == str(model)
        )
