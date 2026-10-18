"""Fail-closed loading for reviewed local verification calibration reports."""
from __future__ import annotations

import json
from pathlib import Path

from .evaluation import PROCESSOR_VERSION, SCHEMA_VERSION


def load_calibration_report(path: Path) -> dict:
    try:
        data = json.loads(Path(path).read_text(encoding='utf-8'))
        if data.get('schema_version') != SCHEMA_VERSION or data.get('processor_version') != PROCESSOR_VERSION:
            return {'profiles': {}}
        return data if isinstance(data.get('profiles'), dict) else {'profiles': {}}
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        return {'profiles': {}}


def profile_is_calibrated(profile, report) -> bool:
    if profile is None:
        return False
    profiles = report.get('profiles', {}) if isinstance(report, dict) else getattr(report, 'profiles', {})
    metrics = profiles.get(profile.code) if isinstance(profiles, dict) else None
    if hasattr(metrics, '__dict__'):
        metrics = metrics.__dict__
    if not isinstance(metrics, dict) or metrics.get('calibrated') is not True:
        return False
    gate = profile.validation_gate
    false_reupload_rate = metrics.get('false_reupload_rate')
    return (int(metrics.get('case_count') or 0) >= gate.min_cases
            and float(metrics.get('type_precision') or 0) >= gate.min_precision
            and float(false_reupload_rate if false_reupload_rate is not None else 1) <= gate.max_false_reupload_rate)
