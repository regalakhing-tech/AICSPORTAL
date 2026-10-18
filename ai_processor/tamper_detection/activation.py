"""Opt-in-ready localhost ADCD service activation; never starts a process."""
from __future__ import annotations

import json
from urllib.parse import urlparse
from urllib.request import Request, urlopen


def adcd_service_ready(settings) -> bool:
    """Use only the identified local service and a model it has actually loaded."""
    if not getattr(settings, 'adcd_auto_activate', True):
        return False
    base = str(getattr(settings, 'adcd_service_url', '')).rstrip('/')
    parsed = urlparse(base)
    if parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or parsed.username or parsed.password:
        return False
    timeout = min(1.5, max(.2, float(getattr(settings, 'adcd_connect_timeout_seconds', .5))))
    try:
        with urlopen(Request(base + '/health', method='GET'), timeout=timeout) as response:
            health = json.loads(response.read(4096).decode('utf-8'))
        if not isinstance(health, dict) or health.get('service') != 'aics-adcd-net':
            return False
        if health.get('status') == 'ready' and health.get('model_loaded') is True:
            return True
        if health.get('status') not in {'not_loaded', 'idle'}:
            return False
        # The service itself verifies its configured runtime and checkpoint files.
        with urlopen(Request(base + '/load', data=b'', method='POST'), timeout=45) as response:
            loaded = json.loads(response.read(4096).decode('utf-8'))
        return isinstance(loaded, dict) and loaded.get('success') is True and loaded.get('model_loaded') is True
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        return False
