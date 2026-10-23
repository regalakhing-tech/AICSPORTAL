"""File and photo fingerprints for duplicate-document checks.

* file_sha256: identical bytes, so the very same file was uploaded twice.
* image_dhash: a 256-bit difference hash of the first page. A re-saved or
  forwarded copy of the same photo stays within 16 bits; two different
  documents, even on the same printed form, were at least 64 bits apart on the
  labelled test set. PHP flags a "same photo" at 24 bits or less
  (includes/duplicate-detection.php).
"""
from hashlib import sha256
from pathlib import Path

import cv2
import numpy as np


def file_sha256(path: Path) -> str:
    digest = sha256()
    with Path(path).open('rb') as handle:
        for block in iter(lambda: handle.read(1 << 20), b''):
            digest.update(block)
    return digest.hexdigest()


def image_dhash(image) -> str | None:
    """64 hex characters, or None for an empty image."""
    if image is None or getattr(image, 'size', 0) == 0:
        return None
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY) if image.ndim == 3 else image
    small = cv2.resize(gray, (17, 16), interpolation=cv2.INTER_AREA).astype(np.int16)
    bits = (small[:, 1:] > small[:, :-1]).flatten()
    value = int(''.join('1' if bit else '0' for bit in bits), 2)
    return f'{value:064x}'


def dhash_distance(left: str, right: str) -> int:
    return bin(int(left, 16) ^ int(right, 16)).count('1')


def document_fingerprint(path: Path, images: list) -> dict:
    """Never raises: a missing fingerprint only skips the duplicate-file check."""
    fingerprint = {}
    try:
        fingerprint['sha256'] = file_sha256(path)
    except OSError:
        pass
    try:
        dhash = image_dhash(images[0]) if images else None
        if dhash:
            fingerprint['dhash'] = dhash
    except (cv2.error, ValueError, TypeError):
        pass
    return fingerprint
