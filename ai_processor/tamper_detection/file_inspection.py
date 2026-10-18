"""Bounded, advisory inspection of uploaded file metadata and PDF signatures."""
from __future__ import annotations

from datetime import datetime
from itertools import islice
from pathlib import Path
import os
import re
import shutil
import subprocess
import tempfile

from PIL import Image


_PDF_DATE = re.compile(rb'/(CreationDate|ModDate)\s*\((D:\d{14}[^)]{0,7})\)')
_PDF_INFO = re.compile(rb'/(Creator|Producer)\s*\(([^)]{0,160})\)')
_BYTE_RANGE = re.compile(rb'/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]')


def _timestamp(value: str) -> datetime | None:
    compact = re.sub(r'[^0-9]', '', value)
    if len(compact) < 14:
        return None
    try:
        return datetime.strptime(compact[:14], '%Y%m%d%H%M%S')
    except ValueError:
        return None


def _verify_signature(raw: bytes, match: re.Match[bytes]) -> dict[str, str]:
    """Verify one signed PDF byte range; signer authority is never inferred."""
    offsets = [int(value) for value in match.groups()]
    if offsets[0] != 0 or offsets[1] <= 0 or offsets[2] <= offsets[1] or offsets[3] <= 0 or offsets[2] + offsets[3] > len(raw):
        return {'state': 'invalid_structure'}
    excluded = raw[max(0, offsets[1] - 1):min(len(raw), offsets[2] + 1)]
    contents = re.search(rb'<([0-9A-Fa-f\s]+)>', excluded)
    if contents is None or len(contents.group(1)) > 1_000_000:
        return {'state': 'invalid_structure'}
    try:
        encoded = bytes.fromhex(contents.group(1).decode('ascii'))
        if len(encoded) < 4 or encoded[0] != 0x30:
            return {'state': 'invalid_structure'}
        length_byte = encoded[1]
        if length_byte < 128:
            der_end = 2 + length_byte
        else:
            width = length_byte & 0x7f
            if width == 0 or width > 4:
                return {'state': 'invalid_structure'}
            der_end = 2 + width + int.from_bytes(encoded[2:2 + width], 'big')
        if der_end > len(encoded):
            return {'state': 'invalid_structure'}
        signature = encoded[:der_end]
    except (ValueError, UnicodeError):
        return {'state': 'invalid_structure'}
    configured = os.getenv('AICS_OPENSSL_PATH', '').strip()
    bundled = Path(r'C:\xampp\apache\bin\openssl.exe')
    openssl = configured if configured and Path(configured).is_file() else shutil.which('openssl') or (str(bundled) if bundled.is_file() else '')
    if not openssl:
        return {'state': 'unavailable', 'note': 'A signature is present, but cryptographic verification is unavailable.'}
    try:
        with tempfile.TemporaryDirectory(prefix='aics_pdf_sig_') as directory:
            signed = Path(directory) / 'signed.bin'
            cms = Path(directory) / 'signature.der'
            signed.write_bytes(raw[:offsets[1]] + raw[offsets[2]:offsets[2] + offsets[3]])
            cms.write_bytes(signature)
            command = [openssl, 'cms', '-verify', '-binary', '-inform', 'DER', '-noverify',
                       '-in', str(cms), '-content', str(signed), '-out', os.devnull]
            completed = subprocess.run(command, capture_output=True, timeout=10,
                                       creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
    except (OSError, subprocess.TimeoutExpired):
        return {'state': 'unavailable', 'note': 'Cryptographic verification could not be completed.'}
    if completed.returncode != 0:
        return {'state': 'invalid_signature', 'note': 'The signed PDF bytes did not verify cryptographically.'}
    if offsets[2] + offsets[3] < len(raw.rstrip()):
        return {'state': 'changed_after_signing', 'note': 'Additional bytes follow the verified signature range.'}
    return {'state': 'intact', 'note': 'Signed bytes verified; signer authority was not checked.'}


def _signature_state(raw: bytes) -> dict[str, str]:
    """Inspect each visible signature; a valid later revision is not an alteration."""
    matches = list(islice(_BYTE_RANGE.finditer(raw), 17))
    if len(matches) > 16:
        return {'state': 'unavailable', 'note': 'Too many PDF signature ranges for a bounded review.'}
    if not matches:
        return {'state': 'unsigned' if b'/Type /Sig' not in raw and b'/Type/Sig' not in raw else 'invalid_structure'}
    states = [_verify_signature(raw, match) for match in matches]
    for state in ('invalid_signature', 'invalid_structure', 'unavailable'):
        if any(item['state'] == state for item in states):
            return next(item for item in states if item['state'] == state)
    # Earlier signatures normally end before countersigning revisions. The
    # latest cryptographically valid range decides whether bytes remain after
    # signing. This does not authenticate the signer or certify the revision.
    return states[-1]


_DESIGN_APPS = re.compile(
    r'(?i)\b(canva|photoshop|lightroom|gimp|picsart|photopea|pixlr|snapseed|illustrator|affinity|paint\.net|'
    r'fotor|polarr|kapwing|adobe express|inshot|meitu|picmonkey|befunky|photoroom|photo ?editor)\b'
)
_XMP_CREATOR = re.compile(r'CreatorTool(?:="([^"]{1,300})"|>([^<]{1,300})<)')


def _exif_text(value, limit: int) -> str | None:
    """Printable text up to the first control character (broken EXIF often
    continues with raw TIFF bytes such as 'MM*')."""
    text = str(value or '')
    text = re.split(r'[\x00-\x1f\x7f]', text, maxsplit=1)[0].strip()[:limit]
    return text or None


def _xmp_creator_tool(image) -> str | None:
    """App name from XMP CreatorTool, without account or document identifiers
    (Canva writes 'Canva doc=... user=... brand=...')."""
    raw = image.info.get('XML:com.adobe.xmp') or image.info.get('xmp') or ''
    text = raw.decode('utf-8', 'ignore') if isinstance(raw, bytes) else str(raw)
    match = _XMP_CREATOR.search(text[:200_000])
    if not match:
        return None
    tool = (match.group(1) or match.group(2) or '').replace('&#39;', "'")
    words = []
    for word in tool.split():
        if '=' in word:
            break
        words.append(word)
    return _exif_text(' '.join(words), 60)


def _image_facts(image) -> dict:
    """Describe an image's origin from allow-listed EXIF tags.

    Only camera, software, and timestamp tags are read. GPS location is never
    read. Missing camera data is common (screenshots, messaging apps, scanners)
    and is reported as a fact, never as an alteration signal.
    """
    exif = image.getexif()
    exif_ifd = exif.get_ifd(0x8769)
    date_taken = _exif_text(exif_ifd.get(36867), 32)   # DateTimeOriginal lives in the Exif sub-IFD
    modified = _exif_text(exif.get(306), 32)            # DateTime
    make, model = _exif_text(exif.get(0x010F), 40), _exif_text(exif.get(0x0110), 60)
    if make and model and model.lower().startswith(make.lower()):
        make = None
    camera = ' '.join(part for part in (make, model) if part) or None
    software = _exif_text(exif.get(305), 80) or _xmp_creator_tool(image)
    return {
        'creation_date': date_taken or modified,
        'modification_date': modified,
        'date_taken': date_taken,
        'camera': camera,
        'camera_metadata': 'present' if camera or date_taken else 'missing',
        'editing_software': software,
        'design_app': bool(software and _DESIGN_APPS.search(software)),
        'saved_with_adobe': 'adobe' in image.info,
        'pixel_size': f'{image.width} × {image.height}',
        'image_format': image.format,
    }


def inspect_file(path: Path) -> dict:
    """Inspect original bytes without treating metadata as proof of alteration."""
    result = {'status': 'completed', 'family': 'metadata', 'decision': 'clear', 'indicators': [], 'facts': {}}
    try:
        if path.suffix.lower() == '.pdf':
            if path.stat().st_size > 32_000_000:
                return {'status': 'unavailable', 'family': 'metadata', 'decision': 'clear', 'indicators': [], 'facts': {'pdf_signature': {'state': 'unavailable'}}}
            raw = path.read_bytes()
            if not raw.startswith(b'%PDF-'):
                result['indicators'].append({'type': 'file_format_mismatch', 'confidence_band': 'moderate', 'reason': 'The uploaded file does not have a PDF header.'})
            dates = {key.decode(): value.decode('ascii', 'ignore')[:24] for key, value in _PDF_DATE.findall(raw)}
            labels = {key.decode(): value.decode('latin-1', 'ignore')[:80] for key, value in _PDF_INFO.findall(raw)}
            signature = _signature_state(raw)
            result['facts'].update({'creation_date': dates.get('CreationDate'), 'modification_date': dates.get('ModDate'),
                                    'creator': labels.get('Creator'), 'editing_software': labels.get('Producer'),
                                    'pdf_signature': signature})
            if signature['state'] in {'invalid_signature', 'changed_after_signing'}:
                result['decision'] = 'review'
                result['indicators'].append({
                    'type': 'invalid_pdf_signature' if signature['state'] == 'invalid_signature' else 'pdf_changed_after_signing',
                    'confidence_band': 'high',
                    'reason': ('The signed bytes failed cryptographic verification.' if signature['state'] == 'invalid_signature'
                               else 'The PDF contains a revision after its verified signature; staff must inspect the revision.'),
                })
            created, modified = _timestamp(dates.get('CreationDate', '')), _timestamp(dates.get('ModDate', ''))
        else:
            with Image.open(path) as image:
                result['facts'].update(_image_facts(image))
                created = _timestamp(result['facts']['creation_date'] or '')
                modified = _timestamp(result['facts']['modification_date'] or '')
        if created and modified and modified < created:
            result['indicators'].append({'type': 'metadata_date_conflict', 'confidence_band': 'low',
                                         'reason': 'File creation and modification timestamps are out of order.'})
        return result
    except (OSError, ValueError, TypeError):
        return {'status': 'unavailable', 'family': 'metadata', 'decision': 'clear', 'indicators': [], 'facts': {}}
