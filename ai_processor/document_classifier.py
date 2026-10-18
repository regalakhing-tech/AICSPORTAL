"""Conservative, offline OCR evidence classifier for canonical AICS document families.

Valid-ID classification deliberately detects document *format evidence*, not legal
authenticity. It never verifies an identifier with an outside service.
"""
import re
import unicodedata

from .document_profiles import get_profile

DOCUMENT_CLASS_RULES = {
    'eclip_cert': (get_profile('eclip_cert').label, list(get_profile('eclip_cert').positive_anchor_groups[0]), list(get_profile('eclip_cert').positive_anchor_groups[1] + get_profile('eclip_cert').positive_anchor_groups[2])),
    'crisis_situation_cert': (get_profile('crisis_situation_cert').label, list(get_profile('crisis_situation_cert').positive_anchor_groups[0]), list(get_profile('crisis_situation_cert').positive_anchor_groups[1] + get_profile('crisis_situation_cert').positive_anchor_groups[2])),
}
ALIASES = {'medical_certificate':'med_cert','death_certificate':'death_cert','special_case_report':'report_police_med','coe_mswdo':'crisis_situation_cert','inflation_crisis_cert':'crisis_situation_cert','damage_photos':'damaged_house_photo'}

ID_SUBTYPES = {
    'national_id': ('Philippine National ID / PhilID', ('philippine statistics authority', 'psa', 'philsys', 'republika ng pilipinas'), ('philippine identification', 'philippine identification card', 'pambansang pagkakakilanlan', 'national id', 'philip id', 'philipid', 'ephilipid', 'digital national id')),
    'drivers_license': ("Philippine Driver's License", ('department of transportation', 'd o tr', 'dotr', 'land transportation office', 'lto'), ('driver license', 'driver s license', 'drivers license', 'drivers  license')),
    'philippine_passport': ('Philippine Passport', ('republic of the philippines', 'republika ng pilipinas', 'republika ng philippines'), ('passport',)),
    'prc_pic': ('PRC Professional ID / e-PIC', ('professional regulation commission', 'prc'), ('professional identification card', 'professional id card', 'e-pic', 'epic')),
}

def normalize_text(text: str) -> str:
    return re.sub(r'\s+', ' ', re.sub(r'[^\w\s<.-]', ' ', unicodedata.normalize('NFKC', text or '').lower())).strip()

def _contains_any(text, phrases): return any(phrase in text for phrase in phrases)

def _identity_labels(text):
    groups = (
        ('Name field detected', ('full name', 'surname', 'given name', 'given names', 'first name', 'last name', 'apelyido', 'mga pangalan', 'gitnang apelyido', 'cardholder', 'holder', 'name')),
        ('Date of birth field detected', ('date of birth', 'birth date', 'birthdate', 'dob', 'born', 'petsa ng kapanganakan')),
        ('Address field detected', ('address', 'residential address', 'present address', 'tirahan')),
        ('Sex or nationality field detected', ('sex', 'gender', 'nationality', 'citizenship')),
    )
    return [label for label, phrases in groups if _contains_any(text, phrases)]

def _subtype_fields(text, subtype):
    groups = {
        'national_id': (('Card-number format marker detected', ('philsys card number', 'pcn', 'national id card number')), ('QR marker detected', ('qr code',)), ('PhilID bilingual demographic-label group detected', ('apelyido', 'mga pangalan', 'gitnang apelyido', 'petsa ng kapanganakan', 'tirahan'))),
        'drivers_license': (('License-number field detected', ('license no', 'license number')), ('Expiration field detected', ('expiration date', 'expiry date')), ('Driver qualification field detected', ('dl codes', 'conditions', 'agency code', 'blood type'))),
        'philippine_passport': (('Passport-number field detected', ('passport no', 'passport number')), ('Issue or expiry field detected', ('date of issue', 'date of expiry', 'place of issue')), ('MRZ country marker detected', ('p<phl', 'phl'))),
        'prc_pic': (('Profession field detected', ('profession',)), ('Registration-number field detected', ('registration number', 'prc number')), ('Validity field detected', ('valid until', 'valid thru', 'expiry date', 'expiration date'))),
    }[subtype]
    return [label for label, phrases in groups if _contains_any(text, phrases)]

def _id_result(text):
    """Return privacy-safe evidence only; never OCR values or ID numbers."""
    identity = _identity_labels(text)
    for subtype, (display, issuers, markers) in ID_SUBTYPES.items():
        issuer, marker, fields = _contains_any(text, issuers), _contains_any(text, markers), _subtype_fields(text, subtype)
        if issuer and marker and identity and fields:
            issuer_label = {'national_id':'PSA / PhilSys issuer marker detected','drivers_license':'DOTr / LTO issuer marker detected','philippine_passport':'Philippine passport issuer marker detected','prc_pic':'PRC issuer marker detected'}[subtype]
            marker_label = {'national_id':'National ID document marker detected','drivers_license':"Driver's License document marker detected",'philippine_passport':'Passport document marker detected','prc_pic':'Professional ID document marker detected'}[subtype]
            return {'recognized':True,'subtype':subtype,'display_type':display,'score':12 + len(identity) + len(fields),'evidence':{'issuer':[issuer_label],'document_marker':[marker_label],'identity_fields':identity,'subtype_fields':fields}}
    generic_marker = _contains_any(text, ('identification card', 'identification', 'id card', 'government id'))
    generic_traits = _contains_any(text, ('id number', 'identification number', 'valid until', 'valid thru', 'signature', 'expiration date', 'expiry date'))
    if generic_marker and len(identity) >= 2 and generic_traits:
        return {'recognized':False,'subtype':'other_id','display_type':'Other Identification Document','score':7 + len(identity),'evidence':{'issuer':[],'document_marker':['Generic identification-document marker detected'],'identity_fields':identity,'subtype_fields':['Generic identity-card field pattern detected']}}
    return {'recognized':False,'subtype':'unknown','display_type':None,'score':0,'evidence':{'issuer':[],'document_marker':[],'identity_fields':identity,'subtype_fields':[]}}


def _medical_result(text):
    """Detect only structural medical-document evidence, never clinical values."""
    facility = _contains_any(text, ('hospital', 'medical center', 'medical centre', 'clinic', 'infirmary', 'medical records', 'health information management', 'himd', 'provincial hospital', 'district hospital', 'regional medical center', 'general hospital'))
    government_issuer = _contains_any(text, ('republic of the philippines', 'department of health', 'doh', 'provincial government', 'city government', 'municipal government'))
    patient = _contains_any(text, ('patient name', 'name of patient', 'patient', 'hospital number', 'case number', 'medical record number'))
    diagnosis = _contains_any(text, ('diagnosis', 'medical condition', 'findings'))
    treatment = _contains_any(text, ('treatment', 'management', 'medication', 'prognosis', 'chief complaint', 'history of present illness', 'signs and symptoms', 'physical examination', 'laboratory findings', 'laboratory results', 'course in the ward', 'hospital course', 'condition on discharge', 'procedure', 'operation', 'operative procedure'))
    physician = _contains_any(text, ('attending physician', 'physician', 'medical officer', 'doctor', 'dr ', 'dr. '))
    certification = _contains_any(text, ('this is to certify', 'this is certify', 'certify that', 'was examined', 'was treated', 'was confined', 'has been diagnosed', 'is advised', 'medically fit', 'medically unfit'))
    license_field = _contains_any(text, ('prc license no', 'prc license number', 'ptr no', 'ptr number', 's2 no', 'license no', 'license number'))
    signature_marker = _contains_any(text, ('signed by', 'signature', 'digitally signed', 'attending physician'))
    seal_marker = _contains_any(text, ('dry seal', 'official seal', 'hospital seal'))
    medical_date = _contains_any(text, ('date issued', 'date of consultation', 'date of admission', 'date of discharge', 'date examined'))
    evidence = {
        'facility': (['Health facility information detected'] if facility else []) + (['Government health issuer marker detected'] if government_issuer else []),
        'document_marker': [],
        'patient_identity': ['Patient information detected'] if patient else [],
        'clinical_sections': (['Diagnosis information detected'] if diagnosis else []) + (['Treatment / hospital-course information detected'] if treatment else []),
        'physician': (['Attending physician information detected'] if physician else []) + (['Physician license field detected'] if license_field else []) + (['Physician signature marker detected'] if signature_marker else []) + (['Hospital seal or official-certification indicator detected'] if seal_marker else []),
        'date_evidence': ['Medical date field detected'] if medical_date else [],
    }
    abstract_direct = _contains_any(text, ('medical abstract', 'clinical abstract'))
    abstract_summary = _contains_any(text, ('medical summary', 'clinical summary'))
    certificate = _contains_any(text, ('medical certificate', 'medical certification', 'certificate of medical condition'))
    excluded_certificate = _contains_any(text, ('certificate of confinement', 'medico legal certificate', 'medico-legal certificate', 'dental certificate'))
    if abstract_direct and facility and patient and diagnosis and treatment and physician:
        evidence['document_marker'] = ['Medical Abstract heading detected']
        return {'recognized': True, 'subtype': 'medical_abstract', 'display_type': 'Medical Abstract', 'score': 17 + int(license_field) + int(signature_marker) + int(medical_date) + int(government_issuer), 'evidence': evidence}
    if abstract_summary and facility and patient and diagnosis and treatment and physician:
        evidence['document_marker'] = ['Clinical or Medical Summary heading detected']
        return {'recognized': True, 'subtype': 'medical_abstract', 'display_type': 'Medical Abstract', 'score': 16 + int(license_field) + int(signature_marker) + int(medical_date) + int(government_issuer), 'evidence': evidence}
    condition_or_certification = diagnosis or certification
    if certificate and not excluded_certificate and facility and patient and condition_or_certification and physician:
        evidence['document_marker'] = ['Medical Certificate heading detected']
        if certification:
            evidence['clinical_sections'].append('Medical-condition certification language detected')
        return {'recognized': True, 'subtype': 'medical_certificate', 'display_type': 'Medical Certificate', 'score': 15 + int(license_field) + int(signature_marker) + int(medical_date) + int(government_issuer), 'evidence': evidence}
    ambiguous = _contains_any(text, ('discharge summary', 'clinical record', 'hospital record', 'laboratory result', 'consultation record')) or (facility and patient and (diagnosis or treatment or physician)) or excluded_certificate
    if ambiguous:
        evidence['document_marker'] = ['Other medical-record heading detected'] if _contains_any(text, ('discharge summary', 'clinical record', 'hospital record', 'laboratory result', 'consultation record')) else []
        return {'recognized': False, 'subtype': 'medical_record_other', 'display_type': 'Medical Record', 'score': 7, 'evidence': evidence}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': evidence}


def _billing_result(text):
    """Detect billing/prescription structure without retaining financial or medical values."""
    facility = _contains_any(text, ('hospital', 'medical center', 'medical centre', 'clinic', 'infirmary', 'district hospital', 'provincial hospital', 'regional medical center', 'general hospital'))
    billing_marker = _contains_any(text, ('statement of account', 'hospital bill', 'billing statement', 'final hospital bill', 'temporary hospital bill', 'patient account', 'account statement', 'soa'))
    patient_account = _contains_any(text, ('patient name', 'name of patient', 'patient id', 'hospital number', 'account number', 'case number', 'admission number'))
    financial = _contains_any(text, ('total charges', 'hospital charges', 'amount due', 'balance', 'outstanding balance', 'net amount due', 'total amount', 'remaining balance'))
    billing_office = _contains_any(text, ('billing office', 'billing department', 'credit and collection', 'cashier', 'billing clerk', 'prepared by', 'verified by', 'billing officer', 'credit and collection officer', 'authorized signature'))
    prescription_marker = _contains_any(text, ('prescription', 'rx'))
    prescription_patient = _contains_any(text, ('patient name', 'name of patient', 'patient', 'name'))
    medicine = _contains_any(text, ('generic drug', 'generic name', 'medicine', 'medication', 'tablet', 'capsule', 'syrup', 'suspension', 'ointment', 'drops'))
    dosage = bool(re.search(r'\b\d{1,4}\s*(?:mg|mcg|ml)\b', text)) or _contains_any(text, ('dosage', 'dose', 'frequency', 'once daily', 'twice daily', 'every 8 hours', 'every 12 hours', 'for 7 days', 'take', 'sig', 'dispense', 'quantity', 'qty'))
    physician = _contains_any(text, ('physician', 'attending physician', 'doctor', 'medical officer', 'dr ', 'dr. '))
    physician_license = _contains_any(text, ('prc license no', 'prc license number', 'ptr no', 'ptr number', 's2 no', 'license no', 'license number'))
    date_evidence = _contains_any(text, ('date issued', 'date of prescription', 'issued on', 'statement date', 'billing date', 'date generated', 'date of admission', 'date of discharge'))
    billing_evidence = {
        'facility': ['Hospital / medical facility information detected'] if facility else [],
        'document_marker': ['Statement of Account / billing marker detected'] if billing_marker else [],
        'patient_account': ['Patient or account field detected'] if patient_account else [],
        'financial_fields': ['Charges / balance field detected'] if financial else [],
        'billing_office': ['Billing-office certification information detected'] if billing_office else [],
        'date_evidence': ['Billing date field detected'] if date_evidence else [],
    }
    prescription_evidence = {
        'prescription_structure': ['Prescription structure detected'] if prescription_marker else [],
        'patient_identity': ['Patient information detected'] if prescription_patient else [],
        'medicine_fields': ['Medicine information detected'] if medicine else [],
        'dosage_or_directions': ['Dosage / directions information detected'] if dosage else [],
        'physician': (['Physician information detected'] if physician else []) + (['Physician license field detected'] if physician_license else []),
        'facility': ['Hospital / clinic information detected'] if facility else [],
        'date_evidence': ['Prescription date field detected'] if date_evidence else [],
    }
    # Billing takes precedence only when its complete billing structure is present.
    if facility and billing_marker and patient_account and financial:
        return {'recognized': True, 'subtype': 'billing_statement', 'display_type': 'Hospital Billing Statement', 'score': 16 + int(billing_office) + int(date_evidence), 'evidence': billing_evidence}
    if prescription_marker and prescription_patient and medicine and dosage and physician:
        return {'recognized': True, 'subtype': 'prescription', 'display_type': 'Prescription', 'score': 15 + int(physician_license) + int(facility) + int(date_evidence), 'evidence': prescription_evidence}
    ambiguous = _contains_any(text, ('official receipt', 'acknowledgment receipt', 'sales invoice', 'pharmacy receipt', 'quotation', 'certificate of balance', 'promissory note', 'treatment protocol', 'doctor s order', 'doctor order', 'laboratory request')) or (facility and (financial or prescription_marker))
    if ambiguous:
        return {'recognized': False, 'subtype': 'billing_or_treatment_other', 'display_type': 'Medical Financial / Treatment Document', 'score': 7, 'evidence': billing_evidence if (financial or billing_marker) else prescription_evidence}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': billing_evidence if billing_marker or financial else prescription_evidence}


def _death_result(text):
    """Classify civil-registry Death Certificate structure with privacy-safe flags."""
    marker = _contains_any(text, ('certificate of death', 'death certificate'))
    form_103 = _contains_any(text, ('municipal form no. 103', 'municipal form 103'))
    psa = _contains_any(text, ('philippine statistics authority', 'psa'))
    civil_registry = _contains_any(text, ('office of the civil registrar general', 'office of the city civil registrar', 'office of the municipal civil registrar', 'city civil registrar', 'municipal civil registrar', 'local civil registry office', 'lcro', 'civil registrar'))
    registry_field = _contains_any(text, ('registry no', 'registry number', 'date of registration', 'received at the office of the civil registrar'))
    deceased_name = _contains_any(text, ('name of deceased', 'name of the deceased', 'deceased name', 'deceased full name'))
    demographic = _contains_any(text, ('date of birth', 'birth date', 'age at time of death', 'age', 'sex', 'gender', 'civil status', 'citizenship', 'nationality'))
    death_date = _contains_any(text, ('date of death', 'time of death'))
    death_place = _contains_any(text, ('place of death', 'place of occurrence'))
    cause_section = _contains_any(text, ('cause of death', 'immediate cause', 'antecedent cause', 'underlying cause', 'other significant conditions'))
    medical_certification = _contains_any(text, ('certification of death', 'medical certification', 'attendant at death', 'physician', 'medical officer', 'health officer'))
    informant = _contains_any(text, ('informant', 'relationship to deceased', 'signature of informant', 'prepared by'))
    evidence = {
        'civil_registry': (['Municipal Form No. 103 marker detected'] if form_103 else []) + (['PSA-related civil-registry marker detected'] if psa else []) + (['Civil-registry information detected'] if civil_registry else []),
        'document_marker': ['Certificate of Death heading detected'] if marker else [],
        'deceased_identity': (['Deceased-person name field detected'] if deceased_name else []) + (['Deceased demographic field detected'] if demographic else []),
        'death_event': (['Date of death information detected'] if death_date else []) + (['Place of death information detected'] if death_place else []),
        'medical_certification': (['Cause-of-death section detected'] if cause_section else []) + (['Medical certification section detected'] if medical_certification else []),
        'registration_informant': (['Registration field detected'] if registry_field else []) + (['Informant section detected'] if informant else []),
    }
    excluded = _contains_any(text, ('certificate of fetal death', 'birth certificate', 'marriage certificate', 'certificate of no marriage', 'burial permit'))
    if marker and not excluded and deceased_name and demographic and (death_date or death_place) and (civil_registry or registry_field or cause_section or medical_certification):
        subtype = 'municipal_form_103' if form_103 else ('psa_death_certificate' if psa else ('civil_registry_death_certificate' if civil_registry else 'death_certificate'))
        return {'recognized': True, 'subtype': subtype, 'display_type': 'Death Certificate', 'score': 16 + int(form_103) + int(psa) + int(registry_field) + int(informant), 'evidence': evidence}
    related = _contains_any(text, ('burial permit', 'cremation permit', 'funeral service agreement', 'certificate of burial', 'cemetery', 'hospital death notice', 'mortuary notice', 'register of deaths', 'facts of death appear'))
    if related or (marker and not excluded and (deceased_name or death_date or death_place)):
        return {'recognized': False, 'subtype': 'unknown_death_record', 'display_type': 'Death-Related Record', 'score': 7, 'evidence': evidence}
    if _contains_any(text, ('birth certificate', 'certificate of live birth')):
        return {'recognized': False, 'subtype': 'birth_certificate', 'display_type': 'Birth Certificate', 'score': 14, 'evidence': evidence, 'distinct_wrong_type': 'birth_certificate'}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': evidence}


def _funeral_result(text):
    """Detect funeral-service contract structure without retaining payment values."""
    establishment = _contains_any(text, ('funeral homes', 'funeral home', 'funeral services', 'funeral service', 'funeral parlor', 'funeral parlour', 'memorial chapel', 'memorial services', 'mortuary', 'mortuary services', 'punerarya'))
    direct_contract = _contains_any(text, ('funeral contract', 'funeral service contract', 'funeral services contract', 'contract for funeral services', 'funeral agreement', 'funeral service agreement', 'kontrata', 'serbisyo sa libing'))
    deceased = _contains_any(text, ('name of deceased', 'name of decedent', 'name of the deceased', 'deceased name', 'deceased full name'))
    services = _contains_any(text, ('casket', 'coffin', 'kabaong', 'embalming', 'viewing', 'wake', 'burol', 'chapel service', 'memorial service', 'hearse', 'funeral coach', 'funeral car', 'service vehicle', 'transfer of remains', 'transport of cadaver', 'dressing', 'cosmetics', 'flowers', 'funeral arrangement', 'interment assistance', 'documentation', 'permit assistance', 'funeral package', 'service package'))
    financial = _contains_any(text, ('contract price', 'package price', 'total contract price', 'total amount', 'amount paid', 'down payment', 'deposit', 'balance due', 'remaining balance', 'payment terms', 'mode of payment', 'installment', 'bayad', 'balanse'))
    authorization = _contains_any(text, ('authorized representative', 'funeral director', 'funeral service provider', 'prepared by', 'approved by', 'client signature', 'contracting party signature', 'authorized signature', 'representative signature', 'date signed', 'contract date'))
    client_party = _contains_any(text, ('contracting party', 'responsible person', 'family representative', 'next of kin', 'purchaser', 'client'))
    generic_contract = _contains_any(text, ('service agreement', 'agreement', 'contract'))
    contract_marker = direct_contract or (establishment and generic_contract and services)
    evidence = {
        'funeral_establishment': ['Funeral establishment information detected'] if establishment else [],
        'contract_marker': ['Funeral contract / agreement marker detected'] if contract_marker else [],
        'deceased_identity': ['Deceased-person information detected'] if deceased else [],
        'service_package': ['Funeral service / package information detected'] if services else [],
        'financial_terms': ['Contract amount / payment structure detected'] if financial else [],
        'authorization': ['Contract authorization / signature fields detected'] if authorization else [],
        'contracting_party': ['Contracting-party field detected'] if client_party else [],
    }
    if establishment and contract_marker and deceased and services:
        return {'recognized': True, 'subtype': 'funeral_service_contract', 'display_type': 'Funeral Contract', 'score': 16 + int(financial) + int(authorization) + int(client_party), 'evidence': evidence}
    related = _contains_any(text, ('statement of account', 'certificate of balance', 'promissory note', 'official receipt', 'acknowledgment receipt', 'burial permit', 'transfer permit', 'cremation permit', 'cemetery contract', 'memorial plan', 'pre-need plan', 'pre need plan', 'life plan', 'funeral quotation', 'price proposal')) or (establishment and (financial or deceased or services)) or _contains_any(text, ('funeral', 'memorial'))
    if related:
        return {'recognized': False, 'subtype': 'funeral_related_other', 'display_type': 'Funeral-Related Document', 'score': 7, 'evidence': evidence}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': evidence}


def _special_report_result(text):
    """Separate PNP incident-report and clinical-report structure without sensitive values."""
    police_issuer = _contains_any(text, ('philippine national police', 'pnp', 'police station', 'city police station', 'municipal police station', 'police community precinct', 'pcp', 'provincial police office', 'ppo', 'city police office', 'cpo', 'municipal police station', 'mps', 'police regional office', 'pro', 'women and children protection desk', 'wcpd', 'investigation section', 'investigation unit'))
    police_marker = _contains_any(text, ('police report', 'incident report', 'incident record form', 'spot report'))
    blotter = _contains_any(text, ('police blotter', 'blotter report', 'blotter extract'))
    incident_fields = _contains_any(text, ('blotter entry number', 'blotter no', 'entry no', 'incident number', 'case number', 'reference number', 'type of incident', 'date time reported', 'date time of incident', 'place of incident'))
    person_roles = _contains_any(text, ('reporting person', 'complainant', 'victim', 'injured person'))
    narrative = _contains_any(text, ('facts of the incident', 'narrative', 'details of incident', 'brief facts', 'circumstances', 'initial investigation', 'incident details'))
    officer = _contains_any(text, ('recorded by', 'prepared by', 'investigating officer', 'duty investigator', 'police officer', 'investigator', 'p capt', 'plt', 'pssg', 'pcpl', 'patrolman', 'certified true copy', 'officer signature'))
    police_evidence = {
        'police_issuer': ['PNP / police-unit information detected'] if police_issuer else [],
        'document_marker': ['Police / Incident Report marker detected'] if police_marker else [],
        'incident_fields': ['Incident reference, date, or place field detected'] if incident_fields else [],
        'person_roles': ['Victim or reporting-person field detected'] if person_roles else [],
        'incident_narrative': ['Incident-details section detected'] if narrative else [],
        'officer_certification': ['Police officer / certification information detected'] if officer else [],
    }
    facility = _contains_any(text, ('hospital', 'medical center', 'medical centre', 'clinic', 'health center', 'emergency room', 'emergency department', 'outpatient department', 'opd', 'provincial hospital', 'district hospital', 'regional medical center', 'general hospital', 'medical records'))
    medical_marker = _contains_any(text, ('medical report', 'clinical report', 'medical examination report', 'medico legal report', 'medico-legal report', 'medico legal examination', 'medico-legal examination'))
    medico_legal = _contains_any(text, ('medico legal', 'medico-legal'))
    patient = _contains_any(text, ('patient name', 'name of patient', 'name of examinee', 'examined person', 'injured person'))
    examination = _contains_any(text, ('physical examination', 'physical findings', 'clinical findings', 'examination findings', 'history of incident', 'chief complaint', 'injury findings', 'signs and symptoms', 'laboratory findings', 'imaging findings', 'diagnostic findings'))
    assessment = _contains_any(text, ('assessment', 'impression', 'diagnosis', 'treatment', 'management'))
    physician = _contains_any(text, ('attending physician', 'examining physician', 'medical officer', 'doctor', 'forensic physician', 'medico legal officer', 'dr ', 'dr. '))
    license_field = _contains_any(text, ('prc license no', 'prc license number', 'ptr no', 'ptr number', 'license no', 'license number'))
    medical_evidence = {
        'facility': ['Health facility information detected'] if facility else [],
        'document_marker': ['Medical Report / examination marker detected'] if medical_marker else [],
        'patient_identity': ['Patient or examinee information detected'] if patient else [],
        'examination_findings': ['Examination / findings section detected'] if examination else [],
        'assessment': ['Clinical assessment section detected'] if assessment else [],
        'physician': (['Physician information detected'] if physician else []) + (['Physician license field detected'] if license_field else []),
        'incident_relation': ['Incident-related clinical context detected'] if _contains_any(text, ('accident', 'assault', 'physical injury', 'abuse', 'violence', 'incident', 'trauma')) else [],
    }
    if police_issuer and police_marker and incident_fields and person_roles and (narrative or officer):
        return {'recognized': True, 'subtype': 'police_report', 'display_type': 'Police Report', 'score': 16 + int(narrative) + int(officer), 'evidence': police_evidence}
    # The legacy canonical report slot already included medico-legal reports;
    # retain that accepted medical-report semantics without external validation.
    if facility and medical_marker and patient and examination and physician:
        return {'recognized': True, 'subtype': 'medical_report', 'display_type': 'Medical Report', 'score': 16 + int(assessment) + int(medico_legal) + int(license_field), 'evidence': medical_evidence}
    related = blotter or _contains_any(text, ('police clearance', 'nbi clearance', 'barangay blotter', 'barangay certificate', 'affidavit', 'subpoena', 'court order', 'protection order', 'traffic citation', 'arrest report', 'investigation referral', 'fire report', 'laboratory result', 'x-ray result', 'xray result', 'hospital er note')) or (police_issuer and _contains_any(text, ('police', 'incident'))) or (facility and (medical_marker or examination or assessment))
    if related:
        return {'recognized': False, 'subtype': 'special_case_related_other', 'display_type': 'Related Police / Medical Document', 'score': 7, 'evidence': police_evidence if (police_issuer or blotter) else medical_evidence}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': police_evidence if police_issuer else medical_evidence}


def _school_assessment_result(text):
    """Detect enrollment/fee assessment structure, never learner or fee values."""
    school = _contains_any(text, ('academy', 'institute', 'college', 'integrated school', 'national high school', 'high school', 'senior high school', 'junior high school', 'learning center', 'school'))
    deped = _contains_any(text, ('department of education', 'deped', 'schools division office', 'school id'))
    direct_marker = _contains_any(text, ('school assessment form', 'enrollment assessment form', 'enrolment assessment form', 'enrollment assessment', 'enrolment assessment', 'student assessment of fees', 'assessment of fees', 'school fees assessment'))
    ambiguous_marker = _contains_any(text, ('assessment form', 'assessment'))
    learner = _contains_any(text, ('learner name', 'name of learner', 'student name', 'name of student', 'learner reference number', 'lrn', 'student number', 'student id number'))
    school_year_grade = _contains_any(text, ('school year', 'grade level', 'grade 7', 'grade 8', 'grade 9', 'grade 10', 'grade 11', 'grade 12'))
    financial = _contains_any(text, ('tuition fee', 'tuition', 'miscellaneous fees', 'miscellaneous fee', 'school fees', 'other fees', 'laboratory fee', 'computer fee', 'activity fee', 'total assessment', 'total fees', 'amount assessed', 'net assessment', 'amount due', 'payment schedule', 'installment', 'voucher', 'discount', 'scholarship', 'subsidy'))
    office = _contains_any(text, ('school registrar', 'registrar', 'accounting office', 'finance office', 'cashier', 'assessment office', 'prepared by', 'assessed by', 'verified by', 'registrar signature', 'authorized signature', 'date assessed'))
    shs = _contains_any(text, ('senior high school', 'shs', 'semester', 'track', 'strand', 'humss', 'stem', 'abm', 'gas', 'ict', 'tvl'))
    evidence = {'school_issuer': (['School information detected'] if school else []) + (['DepEd / public-school marker detected'] if deped else []), 'document_marker': ['Enrollment / School Assessment marker detected'] if (direct_marker or ambiguous_marker) else [], 'learner_identity': ['Learner information detected'] if learner else [], 'school_year_or_grade': ['School year / grade-level information detected'] if school_year_grade else [], 'financial_assessment': ['Fee / assessment structure detected'] if financial else [], 'school_office': ['School-office / assessment authorization information detected'] if office else [], 'shs_support': ['Senior High School track / strand information detected'] if shs else []}
    if school and (direct_marker or ambiguous_marker) and learner and school_year_grade and financial:
        subtype = 'senior_high_school_assessment' if shs else ('enrollment_assessment' if _contains_any(text, ('enrollment', 'enrolment')) else 'school_assessment')
        return {'recognized': True, 'subtype': subtype, 'display_type': 'School Assessment Form', 'score': 16 + int(office) + int(shs) + int(deped), 'evidence': evidence}
    registration = _contains_any(text, ('registration form', 'enrollment form', 'enrolment form', 'basic education enrollment form', 'certificate of registration'))
    report_card = _contains_any(text, ('report card', 'school form 9', 'sf9', 'form 138', 'quarterly grades', 'final rating', 'general average'))
    academic = _contains_any(text, ('learning competencies', 'assessment scores', 'test results', 'proficiency level', 'rubric', 'student performance'))
    if registration or report_card or academic:
        label = 'Registration / Enrollment Form' if registration else ('Report Card' if report_card else 'Academic Assessment Record')
        return {'recognized': False, 'subtype': 'school_related_wrong_type', 'display_type': label, 'score': 14, 'evidence': evidence, 'distinct_wrong_type': 'registration_form' if registration else 'academic_record'}
    related = _contains_any(text, ('certificate of enrollment', 'statement of account', 'assessment slip', 'student ledger', 'official receipt', 'enrollment confirmation', 'admission form', 'student information sheet')) or (school and (ambiguous_marker or financial or learner))
    if related:
        return {'recognized': False, 'subtype': 'school_related_other', 'display_type': 'School-Related Document', 'score': 7, 'evidence': evidence}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': evidence}


def _registration_form_result(text):
    """Detect Philippine basic-education registration structure, never learner data."""
    school = _contains_any(text, ('academy', 'institute', 'college', 'integrated school', 'national high school', 'high school', 'senior high school', 'junior high school', 'learning center', 'private school', 'school'))
    deped = _contains_any(text, ('republic of the philippines', 'department of education', 'deped', 'schools division office', 'division office', 'school id'))
    early = _contains_any(text, ('early registration form', 'basic education early registration'))
    marker = _contains_any(text, ('registration form', 'student registration form', 'enrollment form', 'enrolment form', 'basic education enrollment form', 'enhanced basic education enrollment form', 'enrollment registration form', 'school registration form', 'student enrollment form', 'registration enrollment'))
    learner = _contains_any(text, ('learner name', 'name of learner', 'student name', 'name of student', 'last name', 'first name', 'middle name'))
    school_year = _contains_any(text, ('school year', 's y ', 'sy '))
    grade = _contains_any(text, ('grade level to enroll', 'grade level', 'grade 7', 'grade 8', 'grade 9', 'grade 10', 'grade 11', 'grade 12'))
    registration_fields = _contains_any(text, ('with lrn', 'no lrn', 'returning', 'balik aral', 'new student', 'new learner', 'transferee', 'transfer in', 'previous school', 'last school attended', 'school to enroll', 'enrollment status', 'enrollment date', 'date registered', 'registration date', 'student type'))
    parent_guardian = _contains_any(text, ('parent guardian', "father s name", "mother s maiden name", "guardian s name", 'relationship to learner'))
    office = _contains_any(text, ('registrar', 'school registrar', 'enrollment officer', 'admissions office', 'registrar s office', 'received by', 'processed by', 'approved by', 'verified by'))
    shs = _contains_any(text, ('senior high school', 'shs', 'semester', 'track', 'strand', 'humss', 'stem', 'abm', 'gas', 'ict', 'tvl'))
    subject_section = _contains_any(text, ('section', 'subject code', 'subject description', 'subjects enrolled', 'schedule', 'class adviser', 'adviser'))
    certificate = _contains_any(text, ('certificate of enrollment', 'this is to certify', 'this is certify'))
    report_card = _contains_any(text, ('report card', 'school form 9', 'sf9', 'form 138', 'quarterly grade', 'final rating', 'general average', 'attendance record'))
    student_sheet = _contains_any(text, ('student information sheet', 'student profile'))
    admission = _contains_any(text, ('application for admission', 'admission application', 'admission form', 'entrance application', 'entrance examination'))
    financial = _contains_any(text, ('tuition fee', 'total assessment', 'amount due', 'balance', 'voucher', 'discount'))
    evidence = {
        'school_issuer': (['School information detected'] if school else []) + (['DepEd / public-school marker detected'] if deped else []),
        'registration_marker': ['Registration / enrollment form marker detected'] if marker else [],
        'learner_identity': ['Learner information detected'] if learner else [],
        'school_year_or_grade': (['School year information detected'] if school_year else []) + (['Grade-level information detected'] if grade else []),
        'registration_fields': ['Enrollment / registration fields detected'] if registration_fields else [],
        'parent_guardian_section': ['Parent / guardian section detected'] if parent_guardian else [],
        'school_office': ['School registration/authorization information detected'] if office else [],
        'shs_track_strand': ['Senior High School track / strand information detected'] if shs else [],
        'subject_section_structure': ['Subject / section structure detected'] if subject_section else [],
    }
    if early:
        return {'recognized': False, 'subtype': 'early_registration_form', 'display_type': 'Basic Education Early Registration Form', 'score': 8, 'evidence': evidence, 'related': True}
    # A completed form needs school, form marker, learner identity, school-year/grade
    # context, and a registration-specific field.  Optional fields only strengthen it.
    if school and marker and learner and (school_year or grade) and registration_fields and not report_card and not certificate and not admission:
        if _contains_any(text, ('enhanced basic education enrollment form',)):
            subtype, display = 'deped_basic_education_enrollment_form', 'Enhanced Basic Education Enrollment Form'
        elif deped and _contains_any(text, ('basic education enrollment form', 'enrollment form', 'enrolment form')):
            subtype, display = 'deped_basic_education_enrollment_form', 'Basic Education Enrollment Form'
        elif _contains_any(text, ('registration form', 'student registration form', 'school registration form')):
            subtype, display = 'private_school_registration_form', 'School Registration Form'
        else:
            subtype, display = 'enrollment_form', 'Enrollment Form'
        return {'recognized': True, 'subtype': subtype, 'display_type': display, 'score': 16 + int(deped) + int(parent_guardian) + int(office) + int(shs) + int(subject_section), 'evidence': evidence}
    if report_card:
        return {'recognized': False, 'subtype': 'report_card', 'display_type': 'Report Card', 'score': 14, 'evidence': evidence, 'distinct_wrong_type': 'academic_record'}
    if financial and school and learner and (school_year or grade):
        return {'recognized': False, 'subtype': 'school_assessment_form', 'display_type': 'School Assessment Form', 'score': 14, 'evidence': evidence, 'distinct_wrong_type': 'school_assessment'}
    if certificate or student_sheet or admission or marker or (school and _contains_any(text, ('enrollment confirmation', 'enrollment slip', 'enrollment'))):
        display = 'Certificate of Enrollment' if certificate else ('Student Information Sheet' if student_sheet else ('Admission Application' if admission else 'School-Related Registration Document'))
        return {'recognized': False, 'subtype': 'registration_related_other', 'display_type': display, 'score': 7, 'evidence': evidence, 'related': True}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': evidence}


def _mdrrmo_result(text):
    """Detect local DRRM certification structure; retain no addresses, values, or reference numbers."""
    drrmo = _contains_any(text, ('municipal disaster risk reduction and management office', 'local disaster risk reduction and management office', 'city disaster risk reduction and management office', 'provincial disaster risk reduction and management office', 'disaster risk reduction and management office', 'mdrrmo', 'ldrrmo', 'cdrrmo', 'pdrrmo', 'drrmo'))
    certification = _contains_any(text, ('certification', 'certificate of damage', 'damage certification', 'certification of damage', 'certificate of displacement', 'displacement certification', 'this is to certify', 'this certifies that', 'sertipikasyon'))
    affected = _contains_any(text, ('name of affected person', 'name of client', 'name of beneficiary', 'name of house owner', 'name of household head', 'affected family', 'affected household', 'resident of', 'displaced person', 'displaced family', 'evacuee', 'family of'))
    property_field = _contains_any(text, ('residential house', 'damaged house', 'house was damaged', 'property was damaged', 'dwelling', 'residence', 'property', 'structure', 'affected area', 'evacuation center'))
    disaster = _contains_any(text, ('typhoon', 'bagyo', 'flood', 'flooding', 'baha', 'earthquake', 'lindol', 'landslide', 'storm surge', 'tornado', 'buhawi', 'fire', 'sunog', 'heavy rainfall', 'strong winds', 'habagat', 'calamity', 'natural disaster', 'disaster'))
    damage = _contains_any(text, ('damaged', 'partially damaged', 'totally damaged', 'partially destroyed', 'totally destroyed', 'uninhabitable', 'not fit for occupancy', 'roof damaged', 'structure damaged', 'flood damaged', 'washed out', 'nasira', 'bahagyang nasira', 'lubos na nasira', 'damage assessment', 'assessment of damage'))
    displacement = _contains_any(text, ('displaced', 'temporarily displaced', 'evacuated', 'evacuee', 'forced to evacuate', 'relocated', 'temporarily staying', 'evacuation center', 'unable to return home', 'lost residence', 'lumikas', 'pinalikas'))
    assessment = _contains_any(text, ('field validation', 'assessment conducted', 'upon inspection', 'inspection', 'field evaluation', 'validation', 'verification of reported damage', 'site visit', 'ocular inspection', 'based on records', 'our office verified'))
    officer = _contains_any(text, ('mdrrmo officer', 'ldrrmo officer', 'drrm officer', 'prepared by', 'certified by', 'approved by', 'issued by', 'officer in charge', 'oic', 'official seal', 'dry seal'))
    barangay = _contains_any(text, ('punong barangay', 'barangay captain', 'barangay certification', 'barangay certificate'))
    related = _contains_any(text, ('state of calamity', 'sangguniang bayan', 'sangguniang panlalawigan', 'incident report', 'situation report', 'sitrep', 'dafac', 'family access card in emergencies', 'faced', 'bureau of fire protection', 'bfp', 'fire investigation report', 'certificate of residency'))
    evidence = {
        'drrmo_issuer': ['DRRM office / LGU issuer detected'] if drrmo else [],
        'certification_marker': ['Certification marker detected'] if certification else [],
        'affected_person_or_property': (['Affected-person information detected'] if affected else []) + (['Affected property / residence information detected'] if property_field else []),
        'disaster_context': ['Disaster information detected'] if disaster else [],
        'damage_status': (['Damage assessment information detected'] if damage else []) + (['Damage classification field detected'] if _contains_any(text, ('partially damaged', 'totally damaged', 'bahagyang nasira', 'lubos na nasira')) else []),
        'displacement_status': ['Displacement / evacuation information detected'] if displacement else [],
        'assessment_validation': ['DRRM assessment / validation wording detected'] if assessment else [],
        'officer_authorization': ['DRRM certification / authorization information detected'] if officer else [],
    }
    if drrmo and certification and (affected or property_field) and disaster and ((damage and (assessment or officer)) or (displacement and (assessment or officer))):
        if displacement and not damage:
            subtype, display = 'displacement_certification', 'DRRMO Displacement Certification'
        elif _contains_any(text, ('house', 'residential house', 'dwelling')):
            subtype, display = 'house_damage_certification', 'MDRRMO Damage Certification'
        else:
            subtype, display = 'damage_certification', 'MDRRMO Damage Certification'
        return {'recognized': True, 'subtype': subtype, 'display_type': display, 'score': 16 + int(assessment) + int(officer) + int(displacement) + int(damage), 'evidence': evidence}
    if barangay or related or (drrmo and (certification or disaster or damage or displacement)) or (certification and disaster):
        display = 'Barangay Disaster Certification' if barangay else ('Disaster-Related Document' if not related else 'Disaster-Related Record')
        return {'recognized': False, 'subtype': 'disaster_related_other', 'display_type': display, 'score': 7, 'evidence': evidence, 'related': True}
    return {'recognized': False, 'subtype': 'unknown', 'display_type': None, 'score': 0, 'evidence': evidence}

def classify_document(text: str, expected_type: str) -> dict:
    expected = ALIASES.get((expected_type or '').lower(), (expected_type or '').lower())
    normalized = normalize_text(text); scores = {}; evidence = {}
    for code, (_, strong, support) in DOCUMENT_CLASS_RULES.items():
        found = [p for p in strong + support if p in normalized]
        evidence[code] = found[:5]
        scores[code] = sum(6 for p in strong if p in normalized) + sum(1 for p in support if p in normalized)
    id_result = _id_result(normalized)
    scores['valid_id'] = id_result['score']
    evidence['valid_id'] = [item for group in id_result['evidence'].values() for item in group]
    medical_result = _medical_result(normalized)
    scores['med_cert'] = medical_result['score']
    evidence['med_cert'] = [item for group in medical_result['evidence'].values() for item in group]
    billing_result = _billing_result(normalized)
    scores['billing_statement'] = billing_result['score']
    evidence['billing_statement'] = [item for group in billing_result['evidence'].values() for item in group]
    death_result = _death_result(normalized)
    scores['death_cert'] = death_result['score']
    evidence['death_cert'] = [item for group in death_result['evidence'].values() for item in group]
    funeral_result = _funeral_result(normalized)
    scores['funeral_contract'] = funeral_result['score']
    evidence['funeral_contract'] = [item for group in funeral_result['evidence'].values() for item in group]
    report_result = _special_report_result(normalized)
    scores['report_police_med'] = report_result['score']
    evidence['report_police_med'] = [item for group in report_result['evidence'].values() for item in group]
    school_result = _school_assessment_result(normalized)
    # Registration/report-card discrimination is scoped to the School
    # Assessment slot; it must not alter Registration Form classification.
    scores['school_assessment'] = school_result['score'] if (expected == 'school_assessment' or not school_result.get('distinct_wrong_type')) else 0
    evidence['school_assessment'] = [item for group in school_result['evidence'].values() for item in group]
    registration_result = _registration_form_result(normalized)
    scores['registration_form'] = registration_result['score']
    evidence['registration_form'] = [item for group in registration_result['evidence'].values() for item in group]
    mdrrmo_result = _mdrrmo_result(normalized)
    scores['mdrrmo_cert'] = mdrrmo_result['score']
    evidence['mdrrmo_cert'] = [item for group in mdrrmo_result['evidence'].values() for item in group]
    ranked = sorted(scores, key=lambda code: scores[code], reverse=True)
    detected = ranked[0] if scores[ranked[0]] else None; top = scores[ranked[0]] if detected else 0; second = scores[ranked[1]] if len(ranked) > 1 else 0; expected_score = scores.get(expected, 0)
    high = top >= 8 and top - second >= 3
    structured = {'valid_id': id_result, 'med_cert': medical_result, 'billing_statement': billing_result,
                  'death_cert': death_result, 'funeral_contract': funeral_result, 'report_police_med': report_result,
                  'school_assessment': school_result, 'registration_form': registration_result,
                  'mdrrmo_cert': mdrrmo_result}
    expected_recognized = bool(structured.get(expected, {}).get('recognized'))
    partial_other = []
    if not expected_recognized:
        for code, details in structured.items():
            groups = details.get('evidence', {})
            if (code != expected and details.get('score', 0) >= 7 and isinstance(groups, dict)
                    and any(not str(marker).lower().startswith(('other ', 'generic ', 'related '))
                            for marker in groups.get('document_marker', []))
                    and sum(bool(items) for items in groups.values()) >= 3):
                partial_other.append(code)
    if len(partial_other) == 1:
        labels = {'valid_id': 'Valid ID', 'med_cert': 'Medical Abstract or Medical Certificate',
                  'billing_statement': 'Billing Statement or Prescription', 'death_cert': 'Death Certificate',
                  'funeral_contract': 'Funeral Contract', 'report_police_med': 'Police or Medical Report',
                  'school_assessment': 'School Assessment Form', 'registration_form': 'Registration Form',
                  'mdrrmo_cert': 'MDRRMO Certification'}
        detected, high, decision, slot_result = partial_other[0], True, 'wrong_slot', 'wrong_document'
        reason = f'The uploaded document appears to be a {labels[detected]}, but this upload slot requires a {labels.get(expected, expected)}.'
    elif expected == 'mdrrmo_cert' and mdrrmo_result['recognized']:
        detected, high, decision, reason, slot_result = 'mdrrmo_cert', True, 'match', 'Multiple Philippine DRRM-office certification evidence groups match the required MDRRMO Certification of Damage or Displacement slot.', 'matched'
    elif expected == 'mdrrmo_cert' and mdrrmo_result.get('related') and not (detected and detected != 'mdrrmo_cert' and high):
        detected, decision, reason, slot_result = 'mdrrmo_cert', 'uncertain', 'The submitted file appears to be a disaster-related document, but the system could not reliably confirm that it is the required MDRRMO/LDRRMO Certification of Damage or Displacement.', 'manual_review'
    elif expected == 'registration_form' and registration_result['recognized']:
        detected, high, decision, reason, slot_result = 'registration_form', True, 'match', 'Multiple Philippine school-registration evidence groups match the required Registration Form slot.', 'matched'
    elif expected == 'registration_form' and registration_result.get('distinct_wrong_type'):
        detected, high, decision, reason, slot_result = registration_result['distinct_wrong_type'], True, 'wrong_slot', f'The uploaded document appears to be a {registration_result["display_type"]}, which is separate from the required Registration Form.', 'wrong_document'
    elif expected == 'registration_form' and registration_result.get('related') and not (detected and detected != 'registration_form' and high):
        detected, decision, reason, slot_result = 'registration_form', 'uncertain', 'The submitted file appears related to school registration or enrollment, but the system could not reliably confirm that it is the required Registration Form.', 'manual_review'
    elif expected == 'school_assessment' and school_result['recognized']:
        detected, high, decision, reason, slot_result = 'school_assessment', True, 'match', 'Multiple school enrollment-assessment evidence groups match the required School Assessment Form slot.', 'matched'
    elif expected == 'school_assessment' and school_result.get('distinct_wrong_type'):
        detected, high, decision, reason, slot_result = school_result['distinct_wrong_type'], True, 'wrong_slot', f'The uploaded document appears to be a {school_result["display_type"]}, which is separate from the required School Assessment Form.', 'wrong_document'
    elif expected == 'school_assessment' and school_result['subtype'] == 'school_related_other' and not (detected and detected != 'school_assessment' and high):
        detected, decision, reason, slot_result = 'school_assessment', 'uncertain', 'The submitted file appears to be a school-related document, but the system could not reliably confirm that it is a School Assessment Form.', 'manual_review'
    elif expected == 'report_police_med' and report_result['recognized']:
        detected, high, decision, reason, slot_result = 'report_police_med', True, 'match', 'Multiple Police Report or Medical Report evidence groups match the required Special Cases document slot.', 'matched'
    elif expected == 'report_police_med' and report_result['subtype'] == 'special_case_related_other' and not (detected and detected != 'report_police_med' and high):
        detected, decision, reason, slot_result = 'report_police_med', 'uncertain', 'The submitted file appears related to the reported special case, but the system could not reliably confirm that it is a Police Report or Medical Report.', 'manual_review'
    elif expected == 'funeral_contract' and funeral_result['recognized']:
        detected, high, decision, reason, slot_result = 'funeral_contract', True, 'match', 'Multiple funeral-service contract evidence groups match the required Funeral Contract slot.', 'matched'
    elif expected == 'funeral_contract' and funeral_result['subtype'] == 'funeral_related_other' and not (detected and detected != 'funeral_contract' and high):
        detected, decision, reason, slot_result = 'funeral_contract', 'uncertain', 'The submitted file appears to be related to funeral services, but the system could not reliably confirm that it is a Funeral Contract.', 'manual_review'
    elif expected == 'death_cert' and death_result['recognized']:
        detected, high, decision, reason, slot_result = 'death_cert', True, 'match', 'Multiple Philippine civil-registry and death-event evidence groups match the required Death Certificate slot.', 'matched'
    elif expected == 'death_cert' and death_result.get('distinct_wrong_type'):
        detected, high, decision, reason, slot_result = death_result['distinct_wrong_type'], True, 'wrong_slot', 'The uploaded document appears to be a Birth Certificate, but this upload slot requires a Death Certificate.', 'wrong_document'
    elif expected == 'death_cert' and death_result['subtype'] == 'unknown_death_record' and not (detected and detected != 'death_cert' and high):
        detected, decision, reason, slot_result = 'death_cert', 'uncertain', 'The submitted file appears to be related to the death record, but the system could not reliably confirm that it is a Death Certificate.', 'manual_review'
    elif expected == 'billing_statement' and billing_result['recognized']:
        detected, high, decision, reason, slot_result = 'billing_statement', True, 'match', 'Multiple billing or prescription evidence groups match the required Billing Statement or Prescription slot.', 'matched'
    elif expected == 'billing_statement' and billing_result['subtype'] == 'billing_or_treatment_other' and not (detected and detected != 'billing_statement' and high):
        detected, decision, reason, slot_result = 'billing_statement', 'uncertain', 'The submitted file appears to be a medical financial or treatment document, but the system could not reliably confirm that it is a Billing Statement or Prescription.', 'manual_review'
    elif expected == 'med_cert' and medical_result['recognized']:
        detected, high, decision, reason, slot_result = 'med_cert', True, 'match', 'Multiple medical-document evidence groups match the required Medical Abstract or Medical Certificate slot.', 'matched'
    elif expected == 'med_cert' and medical_result['subtype'] == 'medical_record_other' and not (detected and detected != 'med_cert' and high):
        detected, decision, reason, slot_result = 'med_cert', 'uncertain', 'The submitted file appears to be a medical record, but the system could not reliably confirm that it is a Medical Abstract or Medical Certificate.', 'manual_review'
    elif expected == 'valid_id' and id_result['recognized']:
        detected, high, decision, reason, slot_result = 'valid_id', True, 'match', 'Multiple Philippine ID format evidence groups match the required identification-card slot.', 'matched'
    elif expected == 'valid_id' and id_result['subtype'] == 'other_id' and not (detected and detected != 'valid_id' and high):
        detected, decision, reason, slot_result = 'valid_id', 'uncertain', 'A plausible identification document was found, but its Philippine ID subtype requires staff confirmation.', 'manual_review'
    elif detected == expected and high:
        decision, reason, slot_result = 'match', 'Detected document evidence matches the expected upload slot.', 'matched'
    elif detected and detected != expected and high and top - expected_score >= 5:
        labels = {'valid_id': 'Valid Identification Card', 'med_cert': 'Medical Abstract / Medical Certificate', 'billing_statement': 'Billing Statement / Prescription', 'death_cert': 'Death Certificate', 'funeral_contract': 'Funeral Contract', 'report_police_med': 'Police Report / Medical Report', 'school_assessment': 'School Assessment Form', 'registration_form': 'Registration Form', 'mdrrmo_cert': 'MDRRMO Certification of Damage or Displacement', 'academic_record': 'Academic Assessment Record', 'birth_certificate': 'Birth Certificate'}
        label = labels[detected] if detected in labels else DOCUMENT_CLASS_RULES[detected][0]
        expected_label = {'valid_id': 'Valid ID', 'med_cert': 'Medical Abstract or Medical Certificate', 'billing_statement': 'Billing Statement or Prescription', 'death_cert': 'Death Certificate', 'funeral_contract': 'Funeral Contract', 'report_police_med': 'Police Report or Medical Report', 'school_assessment': 'School Assessment Form', 'registration_form': 'Registration Form', 'mdrrmo_cert': 'MDRRMO Certification of Damage or Displacement'}.get(expected, DOCUMENT_CLASS_RULES.get(expected, (expected,))[0])
        decision, reason, slot_result = 'wrong_slot', f'The uploaded document appears to be a {label}, but this upload slot requires a {expected_label}.', 'wrong_document'
    else:
        decision, reason, slot_result = 'uncertain', 'The document contains insufficient reliable evidence to confidently determine its document type.', 'manual_review'
    result = {'expected_type':expected,'detected_type':detected,'confidence_band':'high' if high else ('moderate' if top >= 4 else 'low'),'scores':scores,'evidence':evidence.get(detected,[]),'competing_type':ranked[1] if second else None,'decision':decision,'reason':reason}
    if expected == 'valid_id' or detected == 'valid_id':
        result.update({'document_subtype':id_result['subtype'],'display_type':id_result['display_type'],'id_evidence':id_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Format evidence only; this system does not verify legal authenticity or ID numbers.'})
    if expected == 'med_cert' or detected == 'med_cert':
        result.update({'document_subtype':medical_result['subtype'],'display_type':medical_result['display_type'],'medical_evidence':medical_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. Hospital and physician authenticity are not independently verified.'})
    if expected == 'billing_statement' or detected == 'billing_statement':
        result.update({'document_subtype':billing_result['subtype'],'display_type':billing_result['display_type'],'billing_evidence':billing_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. Hospital billing records, prescriptions, physician credentials, signatures, and legal authenticity are not independently verified.'})
    if expected == 'death_cert' or detected == 'death_cert':
        result.update({'document_subtype':death_result['subtype'],'display_type':death_result['display_type'],'death_evidence':death_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. PSA registration, civil-registry authenticity, signatures, seals, and legal validity are not independently verified.'})
    if expected == 'funeral_contract' or detected == 'funeral_contract':
        result.update({'document_subtype':funeral_result['subtype'],'display_type':funeral_result['display_type'],'funeral_evidence':funeral_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. The funeral establishment, signatures, contract enforceability, and legal authenticity are not independently verified.'})
    if expected == 'report_police_med' or detected == 'report_police_med':
        result.update({'document_subtype':report_result['subtype'],'display_type':report_result['display_type'],'special_report_evidence':report_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. PNP records, health facilities, physicians, signatures, case facts, and legal authenticity are not independently verified.'})
    if expected == 'school_assessment' or detected == 'school_assessment':
        result.update({'document_subtype':school_result['subtype'],'display_type':school_result['display_type'],'school_evidence':school_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. School enrollment, fee assessment, registrar authorization, signatures, and legal authenticity are not independently verified.'})
    if expected == 'registration_form' or detected == 'registration_form':
        result.update({'document_subtype':registration_result['subtype'],'display_type':registration_result['display_type'],'registration_evidence':registration_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. School enrollment, learner registration, registrar authorization, signatures, and legal authenticity are not independently verified.'})
    if expected == 'mdrrmo_cert' or detected == 'mdrrmo_cert':
        result.update({'document_subtype':mdrrmo_result['subtype'],'display_type':mdrrmo_result['display_type'],'mdrrmo_evidence':mdrrmo_result['evidence'],'slot_result':slot_result,'authenticity_notice':'Document content and format are AI-assisted checks. MDRRMO/LDRRMO issuance, field inspection, damage assessment, displacement status, signatures, seals, and legal authenticity are not independently verified.'})
    return result

def classify_ocr_candidates(selected_text: str, expected_type: str, metadata: dict | None,
                            *, primary: dict | None = None) -> dict:
    """Recover strong type evidence from retained OCR readings without guessing."""
    primary = primary if isinstance(primary, dict) else classify_document(selected_text, expected_type)
    results = [primary]
    seen = {normalize_text(selected_text)}
    candidates = metadata.get('field_candidates', []) if isinstance(metadata, dict) else []
    for candidate in candidates[:8] if isinstance(candidates, list) else []:
        if not isinstance(candidate, dict):
            continue
        candidate_text = str(candidate.get('text') or '')
        key = normalize_text(candidate_text)
        if not key or key in seen:
            continue
        seen.add(key)
        results.append(classify_document(candidate_text, expected_type))
    matches = [result for result in results if result.get('decision') == 'match']
    wrong = [result for result in results if result.get('decision') == 'wrong_slot']
    families = {str(result.get('detected_type') or '') for result in wrong}
    if matches and wrong or len(families) > 1:
        output = dict(primary)
        output.update({'decision': 'uncertain', 'confidence_band': 'low',
                       'reason': 'OCR readings disagree about the document type; staff must inspect the original.'})
        return output
    if primary.get('decision') in {'match', 'wrong_slot'}:
        return primary
    if matches:
        return matches[0]
    if len(families) == 1:
        return wrong[0]
    return primary


def classify(text: str):
    result = classify_document(text, ''); detected = result['detected_type']; score = result['scores'].get(detected, 0) if detected else 0; total = sum(result['scores'].values())
    return detected, round((score / total * 100) if total else 0, 2), result['scores']
