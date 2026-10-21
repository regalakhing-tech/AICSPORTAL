"""Score the AICS AI screening against a folder of labelled documents.

Every file goes through ai_processor.processor.analyze_document(), the same
checks the worker runs on an upload. Nothing is saved to the database or the
review artifacts; Gemini results are cached as usual, so a second run of an
unchanged file does not call Gemini again.

The label comes from the folder and file name:
  * document type: DeathCertificate / Certificate of Death, FuneralContract,
    ClinicalAbstract / MedicalCertificate, MedicalBill / Reseta, ValidID,
    SchoolID, and the Disaster folder (photos of damage);
  * "Tampered" anywhere in the path marks an edited copy, otherwise original.
The back of an ID is skipped: the portal never runs the AI on it.

    python -m tools.ai_screening_eval "C:/path/to/Aics Documents"
    python -m tools.ai_screening_eval FOLDER --only Nicasio --json report.json
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import time
from pathlib import Path

# Labels the folder uses -> (document type on the file, portal slot it is uploaded to).
_TYPES = (
    (re.compile(r'death\s*cert|certi\w*\s+of\s+dea', re.I), 'death_cert', 'death_cert'),
    (re.compile(r'funeral', re.I), 'funeral_contract', 'funeral_contract'),
    (re.compile(r'clinical\s*abstract|medical\s*cert', re.I), 'med_cert', 'med_cert'),
    (re.compile(r'medical\s*bill|reseta', re.I), 'billing_statement', 'billing_statement'),
    (re.compile(r'school\s*id', re.I), 'school_id', 'valid_id'),  # a school ID put in the Valid ID slot
    (re.compile(r'valid', re.I), 'valid_id', 'valid_id'),
)
_IMAGE_SUFFIXES = {'.jpg', '.jpeg', '.png', '.pdf'}
_RANK = {'likely_valid': 0, 'needs_staff_review': 1, 'needs_reupload': 2, 'flagged_inconsistency': 3}


def label_for(path: Path) -> dict | None:
    """The expected outcome for one file, or None when the portal would not check it."""
    if re.search(r'\(back\)', path.name, re.I):
        return None
    if 'disaster' in (part.lower() for part in path.parts):
        actual, slot = 'damaged_house_photo', 'damaged_house_photo'
    else:
        match = next(((actual, slot) for pattern, actual, slot in _TYPES if pattern.search(path.name)), None)
        if match is None:
            return None
        actual, slot = match
    return {'actual_type': actual, 'slot': slot,
            'tampered': any('tampered' in part.lower() for part in path.parts)}


def judge(label: dict, result: dict) -> dict:
    """Compare one review with its label. 'type_ok' checks what the AI read the document as;
    'outcome' is ok / missed / false_alarm / wrong_slot_missed."""
    findings = result.get('findings') or {}
    checks = findings.get('thesis_checks') or {}
    status = str(result.get('status') or '')
    detected = result.get('detected_document_type')
    edit_states = {checks.get(key, {}).get('state') for key in ('unaltered', 'untampered')}
    edit_flag = 'fail' if 'fail' in edit_states else ('review' if 'review' in edit_states else 'pass')
    expect_type = label['actual_type']
    if label['slot'] == 'damaged_house_photo':
        type_ok = detected in (None, 'damaged_house_photo')
    else:
        type_ok = detected == expect_type
    if label['actual_type'] != label['slot']:
        # A different document in the slot must not pass.
        outcome = 'ok' if status != 'likely_valid' else 'wrong_slot_missed'
    elif label['tampered']:
        # Caught when staff are stopped from seeing "Document looks good", by any check.
        outcome = 'ok' if status != 'likely_valid' else 'missed'
    else:
        outcome = 'false_alarm' if edit_flag == 'fail' or status == 'flagged_inconsistency' else 'ok'
    raised = {key: value.get('state') for key, value in checks.items()
              if isinstance(value, dict) and value.get('state') in {'review', 'fail'}}
    return {'status': status, 'detected': detected, 'type_ok': type_ok, 'edit_flag': edit_flag, 'outcome': outcome,
            'raised': raised,
            'raised_reasons': {key: checks[key].get('reason', '') for key in raised},
            'headline': (findings.get('verdict') or {}).get('headline', ''),
            'reason': (findings.get('verdict') or {}).get('reason', ''),
            'source': (findings.get('verdict') or {}).get('source', '')}


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('folder', type=Path)
    parser.add_argument('--only', default='', help='only files whose path contains this text')
    parser.add_argument('--json', type=Path, help='write every result to this file')
    args = parser.parse_args(argv)

    from ai_processor.config import SETTINGS
    from ai_processor.document_ownership import select_reference_context
    from ai_processor.processor import analyze_document

    files = sorted(path for path in args.folder.rglob('*') if path.suffix.lower() in _IMAGE_SUFFIXES
                   and args.only.lower() in str(path).lower())
    rows = []
    for path in files:
        label = label_for(path)
        if label is None:
            continue
        started = time.monotonic()
        reference = select_reference_context(label['slot'], {}, SETTINGS.valid_id_owner_mode)
        try:
            result = analyze_document(path, label['slot'], reference, [], log_ref=f'eval={path.name}')
            verdict = judge(label, result)
        except Exception as exc:  # one broken file must not stop the run
            verdict = {'status': 'error', 'detected': None, 'type_ok': False, 'edit_flag': '', 'outcome': 'error',
                       'raised': {}, 'raised_reasons': {},
                       'headline': type(exc).__name__, 'reason': str(exc)[:200], 'source': ''}
        row = {'file': str(path.relative_to(args.folder)), **label, **verdict,
               'seconds': round(time.monotonic() - started, 1)}
        rows.append(row)
        raised = ','.join(f'{key}:{state}' for key, state in row['raised'].items()) or '-'
        print(f"{row['outcome']:<18} type {'ok ' if row['type_ok'] else 'BAD'} {row['status']:<22} {raised:<40} "
              f"{row['file']}  [{row['detected']}] {row['headline']}", flush=True)

    total = len(rows)
    if not total:
        print('No labelled documents found.')
        return 1
    ok = sum(row['outcome'] == 'ok' for row in rows)
    type_ok = sum(row['type_ok'] for row in rows)
    tampered = [row for row in rows if row['tampered']]
    originals = [row for row in rows if not row['tampered'] and row['actual_type'] == row['slot']]
    print('\nSummary')
    print(f'  correct outcome      {ok}/{total}')
    print(f'  document type read   {type_ok}/{total}')
    print(f"  tampered caught      {sum(row['outcome'] == 'ok' for row in tampered)}/{len(tampered)}")
    print(f"  originals not flagged {sum(row['outcome'] == 'ok' for row in originals)}/{len(originals)}")
    print(f"  originals passed clean {sum(row['status'] == 'likely_valid' for row in originals)}/{len(originals)}")
    if args.json:
        args.json.write_text(json.dumps(rows, indent=2, ensure_ascii=False), encoding='utf-8')
    return 0 if ok == total else 2


if __name__ == '__main__':
    sys.exit(main())
