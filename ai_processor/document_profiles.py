"""Canonical, privacy-safe profiles for locally verified AICS documents."""
from dataclasses import dataclass


@dataclass(frozen=True)
class ValidationGate:
    min_cases: int
    min_precision: float
    max_false_reupload_rate: float


@dataclass(frozen=True)
class DocumentProfile:
    code: str
    label: str
    positive_anchor_groups: tuple[tuple[str, ...], ...]
    negative_anchors: tuple[str, ...]
    extractable_fields: tuple[str, ...]
    required_fields: tuple[str, ...]
    identity_field: str | None
    validation_gate: ValidationGate


DEFAULT_GATE = ValidationGate(20, .90, .03)
MEDICAL_GATE = ValidationGate(30, .95, .02)


def _profile(code, label, anchors, fields=(), required=(), identity=None,
             negatives=(), gate=DEFAULT_GATE):
    return DocumentProfile(code, label, tuple(tuple(group) for group in anchors),
                           tuple(negatives), tuple(fields), tuple(required),
                           identity, gate)


_PROFILES = {
    'valid_id': _profile(
        'valid_id', 'Valid Identification Card',
        (('philippine identification', 'driver license', 'passport', 'professional identification card'),
         ('full name', 'surname', 'given name', 'date of birth')),
        ('name', 'birth_date', 'sex', 'address', 'valid_until', 'issuing_organization'),
        ('name',), 'name', ('birth certificate', 'medical certificate')),
    'med_cert': _profile(
        'med_cert', 'Medical Abstract or Medical Certificate',
        (('medical abstract', 'medical certificate', 'clinical summary'),
         ('hospital', 'medical center', 'clinic'),
         ('patient', 'diagnosis', 'physician')),
        ('patient_name', 'birth_date', 'age', 'sex', 'document_date',
         'hospital_or_clinic', 'physician_name', 'diagnosis_present', 'urgency_evidence'),
        ('patient_name',), 'patient_name',
        ('death certificate', 'billing statement'), MEDICAL_GATE),
    'billing_statement': _profile(
        'billing_statement', 'Billing Statement or Prescription',
        (('statement of account', 'billing statement', 'prescription', 'rx'),
         ('patient', 'account number', 'medicine'),
         ('amount due', 'balance', 'dosage', 'physician')),
        ('patient_name', 'birth_date', 'age', 'facility', 'document_date',
         'document_subtype', 'urgency_evidence'),
        ('patient_name',), 'patient_name', ('medical certificate',), MEDICAL_GATE),
    'death_cert': _profile(
        'death_cert', 'Death Certificate',
        (('certificate of death', 'death certificate'),
         ('civil registrar', 'philippine statistics authority', 'municipal form no. 103'),
         ('name of deceased', 'date of death', 'cause of death')),
        ('deceased_name', 'birth_date', 'age', 'sex', 'date_of_death', 'place_of_death'),
        ('deceased_name', 'date_of_death'), 'deceased_name', ('birth certificate',)),
    'funeral_contract': _profile(
        'funeral_contract', 'Funeral Contract',
        (('funeral service contract', 'funeral contract', 'memorial service contract'),
         ('funeral home', 'memorial chapel'), ('deceased', 'service package', 'contract')),
        ('deceased_name', 'funeral_home', 'document_date'), ('deceased_name',),
        'deceased_name', ('official receipt', 'death certificate')),
    'report_police_med': _profile(
        'report_police_med', 'Police Report or Medical Report',
        (('police report', 'incident report', 'medical report', 'medico legal'),
         ('philippine national police', 'hospital', 'physician'),
         ('subject', 'patient', 'incident', 'findings')),
        ('subject_name', 'birth_date', 'age', 'sex', 'document_date',
         'issuing_organization', 'report_subtype'), ('subject_name',), 'subject_name'),
    'school_assessment': _profile(
        'school_assessment', 'School Assessment Form',
        (('assessment form', 'statement of account', 'assessment of fees'),
         ('school', 'college', 'university'), ('student name', 'school year', 'total assessment')),
        ('client_name', 'document_date', 'issuing_organization'), ('client_name',),
        'client_name', ('registration form', 'report card')),
    'registration_form': _profile(
        'registration_form', 'Registration Form',
        (('registration form', 'enrollment form', 'certificate of registration'),
         ('school', 'college', 'university'), ('learner', 'student name', 'school year')),
        ('client_name', 'document_date', 'issuing_organization'), ('client_name',),
        'client_name', ('assessment form', 'report card')),
    'mdrrmo_cert': _profile(
        'mdrrmo_cert', 'MDRRMO Certification of Damage or Displacement',
        (('mdrrmo', 'ldrrmo', 'disaster risk reduction'),
         ('certification', 'certificate'), ('damage', 'displaced', 'displacement')),
        ('client_name', 'document_date', 'issuing_organization'), ('client_name',),
        'client_name', ('barangay clearance',)),
    'eclip_cert': _profile(
        'eclip_cert', 'ECLIP Committee or JTF-ELCAC Certification',
        (('enhanced comprehensive local integration program', 'e-clip', 'eclip'),
         ('former rebel', 'eligibility', 'certification'), ('joint task force', 'jtf elcac')),
        ('client_name',), ('client_name',), 'client_name'),
    'crisis_situation_cert': _profile(
        'crisis_situation_cert', 'Certificate of Person in Crisis Situation',
        (('certificate of person in crisis situation', 'person in crisis situation'),
         ('mswdo', 'pswdo', 'social welfare'), ('certification', 'client', 'beneficiary')),
        ('client_name',), ('client_name',), 'client_name'),
    'damaged_house_photo': _profile(
        'damaged_house_photo', 'Photographic Evidence of Damaged House', (), (), (),
        None, (), DEFAULT_GATE),
}


def get_profile(document_type: str) -> DocumentProfile | None:
    return _PROFILES.get((document_type or '').strip().lower())


def supported_document_types() -> tuple[str, ...]:
    return tuple(_PROFILES)
