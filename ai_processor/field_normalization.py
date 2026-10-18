"""Shared conservative normalization for extracted document fields."""
import re
import unicodedata
from datetime import datetime
from decimal import Decimal, InvalidOperation


def normalize_name(value: str | None) -> str:
    value = unicodedata.normalize('NFKC', value or '').upper()
    return ' '.join(part for part in re.split(r'[^A-Z]+', value) if part)


def normalize_date(value, allow_ambiguous_numeric: bool = False) -> str | None:
    if not value:
        return None
    if hasattr(value, 'isoformat'):
        return value.isoformat()
    raw = re.sub(r',\s*', ', ', str(value).strip().replace('.', ''))
    formats = ('%Y-%m-%d', '%Y/%m/%d', '%B %d, %Y', '%b %d, %Y',
               '%B %d %Y', '%b %d %Y', '%d %B %Y', '%d %b %Y')
    for fmt in formats:
        try:
            return datetime.strptime(raw, fmt).date().isoformat()
        except ValueError:
            pass
    numeric = re.fullmatch(r'(\d{1,2})[/-](\d{1,2})[/-](\d{4})', raw)
    if numeric:
        first, second, year = map(int, numeric.groups())
        # Only choose an ordering where the other ordering is impossible.
        if first > 12 >= second:
            day, month = first, second
        elif second > 12 >= first:
            month, day = first, second
        elif allow_ambiguous_numeric:
            month, day = first, second
        else:
            return None
        try:
            return datetime(year, month, day).date().isoformat()
        except ValueError:
            return None
    return None


def normalize_sex(value: str | None) -> str | None:
    token = re.sub(r'[^a-z]', '', (value or '').lower())
    if token in ('m', 'male', 'man'):
        return 'male'
    if token in ('f', 'female', 'woman'):
        return 'female'
    return None


def normalize_address(value: str | None) -> str:
    text = unicodedata.normalize('NFKC', value or '').lower()
    replacements = {'san jose de buenavista': 'san jose', 'brgy': 'barangay', 'prov': 'province'}
    for source, target in replacements.items():
        text = text.replace(source, target)
    return ' '.join(re.findall(r'[a-z0-9]+', text))


def normalize_amount(value: str | None) -> str | None:
    if not value:
        return None
    cleaned = re.sub(r'[^0-9.-]', '', str(value).replace(',', ''))
    try:
        return format(Decimal(cleaned).quantize(Decimal('0.01')), 'f')
    except (InvalidOperation, ValueError):
        return None
