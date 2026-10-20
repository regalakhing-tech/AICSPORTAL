"""AICS documentary requirements known to the Gemini scanner.

One catalog feeds both the prompt (what each document looks like) and the
verdict (which document role supplies the extracted identity). Codes and labels mirror
includes/document-requirements.php.
"""

DOCUMENT_CATALOG: dict[str, dict] = {
    'valid_id': {
        'label': 'Valid Identification Card',
        'looks_like': 'Government-issued photo ID card such as PhilSys National ID, driver\'s license, UMID, '
                      'passport, postal ID, voter\'s ID, senior citizen ID, PWD ID, or barangay ID.',
        'role': 'cardholder',
    },
    'med_cert': {
        'label': 'Medical Abstract or Medical Certificate',
        'looks_like': 'Hospital or clinic certificate or clinical abstract describing a patient\'s diagnosis, '
                      'confinement, or treatment, usually signed by a physician with a license number.',
        'role': 'patient',
    },
    'billing_statement': {
        'label': 'Billing Statement or Prescription',
        'looks_like': 'Hospital statement of account listing charges and balance due, or a physician\'s '
                      'prescription listing medicines and dosage for a patient.',
        'role': 'patient',
    },
    'death_cert': {
        'label': 'Death Certificate',
        'looks_like': 'Civil registry Certificate of Death (PSA or Local Civil Registrar form) with the '
                      'deceased\'s name, date and cause of death.',
        'role': 'deceased',
    },
    'funeral_contract': {
        'label': 'Funeral Contract',
        'looks_like': 'Contract or service agreement with a funeral parlor or memorial chapel for the '
                      'deceased, listing the service package and amount.',
        'role': 'deceased',
    },
    'report_police_med': {
        'label': 'Police Report or Medical Report',
        'looks_like': 'Police blotter or incident report, or a medico-legal or medical report describing '
                      'an incident and the person involved.',
        'role': 'subject',
    },
    'school_assessment': {
        'label': 'School Assessment Form',
        'looks_like': 'School statement of assessment or tuition/fee breakdown for a student for a term.',
        'role': 'learner',
    },
    'school_id': {
        'label': 'School ID',
        'looks_like': 'The learner\'s school ID card: a student ID issued by the school, showing the school name and '
                      'the learner\'s name, usually with a photo, student number or LRN, and school year.',
        'role': 'learner',
    },
    'registration_form': {
        'label': 'Enrollment Form',
        'looks_like': 'The learner\'s school enrollment form or registration form, or a certificate of enrollment, '
                      'for the current school year. Not a school ID card.',
        'role': 'learner',
    },
    'mdrrmo_cert': {
        'label': 'MDRRMO Certification of Damage or Displacement',
        'looks_like': 'Certification from a Municipal Disaster Risk Reduction and Management Office that a '
                      'person or household was affected, damaged, or displaced by a disaster.',
        'role': 'affected_person',
    },
    'damaged_house_photo': {
        'label': 'Photographic Evidence of Damaged House',
        'looks_like': 'Photograph of a house or property showing disaster damage. Not a paper document.',
        'role': None,
    },
    'crisis_situation_cert': {
        'label': 'Certificate of Person in Crisis Situation',
        'looks_like': 'Certificate from the MSWDO or barangay stating that the person is in a crisis situation.',
        'role': 'client',
    },
}

IDENTITY_ROLES = ('cardholder', 'patient', 'deceased', 'subject', 'learner', 'affected_person',
                  'client', 'informant', 'physician', 'signatory', 'other', 'none')

_ALIASES = {
    'valid_identification_card': 'valid_id', 'medical_certificate': 'med_cert', 'medical_abstract': 'med_cert',
    'medical_abstract_certificate': 'med_cert', 'death_certificate': 'death_cert',
    'billing_statement_or_prescription': 'billing_statement', 'damage_photos': 'damaged_house_photo',
    'picture_of_damaged_property': 'damaged_house_photo', 'inflation_crisis_cert': 'crisis_situation_cert',
    'certificate_person_in_crisis': 'crisis_situation_cert',
    'certificate_of_person_in_crisis_situation': 'crisis_situation_cert', 'coe_mswdo': 'crisis_situation_cert',
}


def normalize_code(code: str) -> str:
    value = str(code or '').strip().lower()
    return _ALIASES.get(value, value)


def document_label(code: str) -> str:
    value = normalize_code(code)
    entry = DOCUMENT_CATALOG.get(value)
    if entry:
        return entry['label']
    return value.replace('_', ' ').title() if value else 'Supporting Document'


def identity_role(code: str) -> str | None:
    entry = DOCUMENT_CATALOG.get(normalize_code(code))
    return entry['role'] if entry else None
