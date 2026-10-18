import re
from difflib import SequenceMatcher
from .field_normalization import normalize_date, normalize_name


def _name_parts(value: str) -> tuple[str | None, list[str]]:
    """Return a given name and normalized tokens while retaining comma order."""
    cleaned = re.sub(r'[^A-Za-z\s,]', ' ', value.upper())
    if ',' in cleaned:
        surname, remainder = cleaned.split(',', 1)
        right_tokens = [part for part in remainder.split() if part]
        tokens = right_tokens + [part for part in surname.split() if part]
        return (right_tokens[0] if right_tokens else None), tokens
    tokens = [part for part in cleaned.split() if part]
    return (tokens[0] if tokens else None), tokens


def name_match_details(extracted: str | None, expected: str | None) -> dict:
    """Compare names while tolerating normal order and middle-name variation."""
    if not extracted:
        return {'result': 'not_present', 'confidence': 0, 'normalized_extracted': '', 'normalized_expected': normalize_name(expected or '')}
    if not expected:
        return {'result': 'uncertain', 'confidence': 0, 'normalized_extracted': normalize_name(extracted), 'normalized_expected': ''}
    a, b = normalize_name(extracted), normalize_name(expected)
    if a == b or set(a.split()) == set(b.split()):
        return {'result': 'match', 'confidence': 100, 'normalized_extracted': a, 'normalized_expected': b}
    given_a, tokens_a = _name_parts(extracted)
    given_b, tokens_b = _name_parts(expected)
    given_similarity = SequenceMatcher(None, given_a or '', given_b or '').ratio()
    overall_similarity = SequenceMatcher(None, a, b).ratio()
    shared = set(tokens_a) & set(tokens_b)
    first_last_match = bool(tokens_a and tokens_b and tokens_a[0] == tokens_b[0] and tokens_a[-1] == tokens_b[-1])

    # A shared surname must never outweigh a clear given-name conflict. The
    # lower threshold permits small OCR substitutions such as JUAN / JUAM.
    if given_a and given_b and given_a != given_b and given_similarity < .75:
        return {'result': 'mismatch', 'confidence': round(overall_similarity * 100, 2), 'normalized_extracted': a, 'normalized_expected': b}

    extras = (set(tokens_a) ^ set(tokens_b))
    if given_a == given_b and len(shared) >= 2 and all(len(token) == 1 for token in extras):
        return {'result': 'match', 'confidence': round(max(given_similarity, overall_similarity) * 100, 2), 'normalized_extracted': a, 'normalized_expected': b}
    # Juan D. Cruz / Juan Dela Cruz: first and surname agree; middle tokens
    # may be initials or omitted without identifying a different person.
    middle_a, middle_b = tokens_a[1:-1], tokens_b[1:-1]
    middle_tokens = middle_a + middle_b if first_last_match else []
    middle_compatible = all(
        len(token) == 1 or token in shared or any(other.startswith(token[0]) for other in middle_tokens if other != token)
        for token in middle_tokens
    )
    if first_last_match and len(shared) >= 2 and middle_compatible:
        return {'result': 'match', 'confidence': round(max(.82, overall_similarity) * 100, 2), 'normalized_extracted': a, 'normalized_expected': b}
    if given_similarity >= .75 and (len(shared) >= 2 or SequenceMatcher(None, a, b).ratio() >= .76):
        return {'result': 'likely_match', 'confidence': round(max(given_similarity, overall_similarity) * 100, 2), 'normalized_extracted': a, 'normalized_expected': b}
    return {'result': 'mismatch', 'confidence': round(overall_similarity * 100, 2), 'normalized_extracted': a, 'normalized_expected': b}


def name_match(extracted: str | None, expected: str | None) -> str:
    return name_match_details(extracted, expected)['result']
