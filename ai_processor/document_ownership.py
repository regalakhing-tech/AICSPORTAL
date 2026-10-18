"""Select the request identity used to verify each document slot."""

from .gemini_scanner.catalog import normalize_code


def document_owner(document_type: str, mode: str = 'applicant') -> str:
    """Only an explicit legacy setting restores beneficiary-owned Valid IDs."""
    return ('applicant' if normalize_code(document_type) == 'valid_id'
            and str(mode).strip().lower() != 'legacy' else 'beneficiary')


def select_reference_context(document_type: str, request_context: dict,
                             mode: str = 'applicant') -> dict:
    beneficiary = {key: value for key, value in (request_context or {}).items() if key != 'applicant'}
    if str(mode).strip().lower() == 'legacy':
        return beneficiary
    if document_owner(document_type, mode) == 'applicant':
        applicant = (request_context or {}).get('applicant') or {}
        return {
            'full_name': str(applicant.get('full_name') or '').strip(),
            'date_of_birth': applicant.get('date_of_birth'),
            'reference_owner': 'applicant',
            '_valid_id_owner_mode': 'applicant',
        }
    beneficiary['_valid_id_owner_mode'] = 'applicant'
    return beneficiary
