"""Run every test document through the full AICS AI scanner and write a report.

Uses the real pipeline (OCR, local forensics, AICS AI image reading when a key
is configured) exactly as for an uploaded Medical Certificate. Database writes
are intercepted, so no application data changes. Run:  python run_tests.py
"""
from __future__ import annotations

import json
import sys
import time
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import MagicMock, patch

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parents[1]))

from ai_processor import processor  # noqa: E402

ALTERATION_CHECKS = ('unaltered', 'untampered')


def scan(path: Path, expected_type: str, beneficiary: dict, number: int) -> dict:
    job = {'id': 900000 + number, 'document_id': 900000 + number, 'request_id': 900000, 'file_path': str(path),
           'expected_document_type': expected_type, 'beneficiary_complete_name': beneficiary['full_name'],
           'beneficiary_birth_date': beneficiary['date_of_birth'], 'gender': beneficiary['sex'],
           # The ID slot is checked against the applicant; this fictional person applies for themself.
           'applicant_first_name': beneficiary['full_name'], 'applicant_birth_date': beneficiary['date_of_birth'],
           'assistance_types_json': '["medical"]'}
    captured: dict = {}
    with ExitStack() as stack:
        for name, kwargs in (('connect', {'return_value': MagicMock()}), ('claim_document', {'return_value': job}),
                             ('resolve_path', {'return_value': path}), ('update_stage', {}),
                             ('prior_fields', {'return_value': []}), ('persist_review_pages', {'return_value': []}),
                             ('persist_private_artifacts', {'side_effect': lambda result, *_: result}),
                             ('finish', {'side_effect': lambda _db, _id, result: captured.update(result=result)})):
            stack.enter_context(patch.object(processor, name, **kwargs))
        processor.process_one(document_id=job['document_id'])
    return captured['result']


def signals(result: dict) -> set[str]:
    findings = result['findings']
    found = {item.get('type') for item in findings.get('tamper_detection', {}).get('indicators', [])}
    checks = findings.get('thesis_checks', {})
    verdict = findings.get('verdict', {})
    if verdict.get('headline') == 'Wrong document uploaded':
        found.add('wrong_document')
    if checks.get('consistent', {}).get('state') == 'fail' and verdict.get('headline') in {
            'Name does not match the application', 'Details do not agree', 'Possible edited date'}:
        found.add('name_mismatch_or_internal_conflict')
    if findings.get('gemini_scan', {}).get('sample_or_specimen', {}).get('detected'):
        found.add('sample_or_watermark')
    return {item for item in found if item}


def judge(case: dict, result: dict) -> tuple[bool, str]:
    checks = result['findings']['thesis_checks']
    if not case['edited']:
        flagged = [key for key in ALTERATION_CHECKS if checks[key]['state'] in {'review', 'fail'}]
        strong = [item['type'] for item in result['findings'].get('tamper_detection', {}).get('indicators', [])
                  if item.get('confidence_band') == 'high' and item.get('type') != 'date_context_conflict']
        ok = not flagged and not strong
        if not ok and not strong and signals(result) and signals(result) <= set(case.get('allow', [])):
            return True, 'AI noted the generated test file looks computer-made (allowed for generated fixtures only; no tampering flag)'
        return ok, 'no alteration/tampering flags' if ok else f'false alarm: {flagged or strong} {sorted(signals(result))}'
    caught = result['status'] != 'likely_valid' and any(
        checks[key]['state'] in {'review', 'fail'} for key in ('consistent', 'complete', *ALTERATION_CHECKS))
    matched = sorted(signals(result) & set(case.get('expect_any', [])))
    if caught and matched:
        return True, 'caught by: ' + ', '.join(matched)
    if caught:
        return True, 'flagged for review (different signal than expected: ' + ', '.join(sorted(signals(result))) + ')'
    return False, 'MISSED'


def main() -> int:
    manifest = json.loads((HERE / 'manifest.json').read_text(encoding='utf-8'))
    beneficiary = manifest['beneficiary']
    rows = []
    for number, case in enumerate(manifest['documents'], 1):
        path = HERE / 'documents' / case['file']
        started = time.monotonic()
        result = scan(path, case['expected_document_type'], beneficiary, number)
        ok, note = judge(case, result)
        findings = result['findings']
        rows.append({
            'file': case['file'], 'edited': case['edited'], 'description': case.get('edit') or case.get('description'),
            'pass': ok, 'note': note, 'status': result['status'],
            'headline': findings['verdict']['headline'], 'reason': findings['verdict']['reason'],
            'source': findings['verdict']['source'],
            'checks': {key: value['state'] for key, value in findings['thesis_checks'].items()},
            'signals': sorted(signals(result)),
            'seconds': round(time.monotonic() - started, 1),
        })
        print(f"{'PASS' if ok else 'FAIL'}  {case['file']:42} {result['status']:22} {note}", flush=True)
    (HERE / 'results').mkdir(exist_ok=True)
    (HERE / 'results' / 'results.json').write_text(json.dumps(rows, indent=2), encoding='utf-8')
    write_markdown(rows)
    passed = sum(row['pass'] for row in rows)
    print(f'\n{passed}/{len(rows)} passed. Report: tests/edited-documents/results/results.md')
    return 0 if passed == len(rows) else 1


def write_markdown(rows: list[dict]) -> None:
    mark = {'pass': '✓', 'review': '⚠', 'fail': '✗', 'unavailable': '–'}
    lines = ['# AICS AI scanner: edited-document test results', '',
             f'Run: {time.strftime("%Y-%m-%d %H:%M")}. Every file was scanned as an upload to the Medical Certificate slot '
             'for the fictional beneficiary Juan Santos Dela Cruz.', '',
             '| Result | File | Edit | Status | Headline | Complete | Consistent | Readable | Unaltered | Untampered | Signals |',
             '|---|---|---|---|---|---|---|---|---|---|---|']
    for row in rows:
        c = row['checks']
        lines.append(f"| {'PASS' if row['pass'] else '**FAIL**'} | `{row['file']}` | {row['description']} | {row['status']} | "
                     f"{row['headline']} | {mark[c['complete']]} | {mark[c['consistent']]} | {mark[c['readable']]} | "
                     f"{mark[c['unaltered']]} | {mark[c['untampered']]} | {', '.join(row['signals']) or '—'} |")
    lines += ['', '## Notes', '']
    lines += [f"- `{row['file']}`: {row['note']}. {row['reason']}" for row in rows]
    (HERE / 'results' / 'results.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')


if __name__ == '__main__':
    raise SystemExit(main())
