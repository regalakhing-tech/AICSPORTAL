"""Privacy-safe metrics for de-identified AI verification manifests."""
from __future__ import annotations

import argparse
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
import hashlib
import json
import math
from pathlib import Path, PureWindowsPath


SCHEMA_VERSION = 1
PROCESSOR_VERSION = 'component-assessments-v2'


@dataclass(frozen=True)
class ProfileMetrics:
    case_count: int
    type_precision: float | None
    type_recall: float | None
    field_accuracy: float | None
    identity_false_match_rate: float | None
    identity_missed_match_rate: float | None
    false_reupload_rate: float | None
    doctamper_precision: float | None
    doctamper_recall: float | None
    calibrated: bool = False


@dataclass(frozen=True)
class EvaluationReport:
    schema_version: int
    processor_version: str
    generated_at: str
    manifest_sha256: str
    profiles: dict[str, ProfileMetrics]

    def to_dict(self):
        return asdict(self)


def _ratio(numerator, denominator):
    return round(numerator / denominator, 6) if denominator else None


def _percentile(values, percentile):
    ordered = sorted(float(value) for value in values)
    if not ordered:
        return None
    position = (len(ordered) - 1) * percentile
    lower = math.floor(position)
    upper = math.ceil(position)
    if lower == upper:
        return ordered[lower]
    return round(ordered[lower] + (ordered[upper] - ordered[lower]) * (position - lower), 3)


def evaluate_operational_records(records, feedback):
    """Summarize workflow reliability without presenting it as model accuracy."""
    records = list(records or [])
    feedback = list(feedback or [])
    invoked = [record for record in records if bool(record.get('external_invoked'))]
    latencies = [
        float(record.get('external_ms', 0))
        for record in invoked
        if isinstance(record.get('external_ms'), (int, float)) and float(record.get('external_ms', 0)) >= 0
    ]
    applicable_feedback = [
        item for item in feedback
        if item.get('agreement_state') in {'agreed', 'overridden'}
    ]
    return {
        'record_count': len(records),
        'external_invocation_rate': _ratio(len(invoked), len(records)),
        'external_cache_hit_rate': _ratio(sum(bool(record.get('cache_hit')) for record in records), len(records)),
        'external_unavailable_rate': _ratio(
            sum(record.get('external_status') == 'unavailable' for record in records), len(records)
        ),
        'external_median_ms': _percentile(latencies, 0.5),
        'external_p95_ms': _percentile(latencies, 0.95),
        'staff_feedback_count': len(applicable_feedback),
        'staff_agreement_rate': _ratio(
            sum(item.get('agreement_state') == 'agreed' for item in applicable_feedback), len(applicable_feedback)
        ),
        'staff_override_rate': _ratio(
            sum(item.get('agreement_state') == 'overridden' for item in applicable_feedback), len(applicable_feedback)
        ),
        'validated_model_accuracy': False,
    }


def _validate_manifest(raw: str, data: dict) -> None:
    forbidden = {'real_applicant', 'contact_number', 'reference_no'}
    if any(key in raw.lower() for key in forbidden):
        raise ValueError('Manifest contains a prohibited applicant field.')
    for case in data.get('cases', []):
        path = str(case.get('path') or '')
        if Path(path).is_absolute() or PureWindowsPath(path).is_absolute():
            raise ValueError('Fixture paths must be relative.')


def evaluate_manifest(path: Path) -> EvaluationReport:
    manifest_path = Path(path)
    raw = manifest_path.read_text(encoding='utf-8')
    data = json.loads(raw)
    if not isinstance(data, dict) or not isinstance(data.get('cases'), list):
        raise ValueError('Invalid evaluation manifest.')
    _validate_manifest(raw, data)
    grouped = {}
    for case in data['cases']:
        profile = str(case.get('profile') or '')
        grouped.setdefault(profile, []).append(case)
    profiles = {}
    for profile, cases in grouped.items():
        expected_positive = [case for case in cases if case.get('expected_type') == 'match']
        predicted_positive = [case for case in cases if case.get('predicted_type') == 'match']
        true_positive = sum(case.get('expected_type') == 'match' and case.get('predicted_type') == 'match' for case in cases)
        negative_reuploads = [case for case in cases if not bool(case.get('expected_reupload'))]
        false_reuploads = sum(not bool(case.get('expected_reupload')) and bool(case.get('predicted_reupload')) for case in cases)
        expected_tamper = [case for case in cases if case.get('expected_doctamper') == 'review']
        predicted_tamper = [case for case in cases if case.get('predicted_doctamper') == 'review']
        tamper_true_positive = sum(case.get('expected_doctamper') == 'review' and case.get('predicted_doctamper') == 'review' for case in cases)
        field_cases = [case for case in cases if isinstance(case.get('field_results'), list)]
        field_total = sum(len(case['field_results']) for case in field_cases)
        field_correct = sum(sum(bool(value) for value in case['field_results']) for case in field_cases)
        profiles[profile] = ProfileMetrics(
            len(cases), _ratio(true_positive, len(predicted_positive)),
            _ratio(true_positive, len(expected_positive)), _ratio(field_correct, field_total),
            None, None, _ratio(false_reuploads, len(negative_reuploads)),
            _ratio(tamper_true_positive, len(predicted_tamper)),
            _ratio(tamper_true_positive, len(expected_tamper)), False,
        )
    return EvaluationReport(SCHEMA_VERSION, PROCESSOR_VERSION,
                            datetime.now(timezone.utc).isoformat(),
                            hashlib.sha256(raw.encode('utf-8')).hexdigest(), profiles)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--manifest', required=True, type=Path)
    parser.add_argument('--format', choices=('json',), default='json')
    args = parser.parse_args()
    print(json.dumps(evaluate_manifest(args.manifest).to_dict(), indent=2))


if __name__ == '__main__':
    main()
