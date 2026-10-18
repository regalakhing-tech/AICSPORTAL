"""Validation-only fictional document matrix for the AICS OCR pipeline.

Files are generated under the OS temporary directory and removed afterwards.
No applicant upload, database row, worker queue, or production setting is
modified.  This intentionally tests loader -> OCR -> classification -> safe
field extraction, not ADCD inference or persistence.
"""
from __future__ import annotations

import json
import argparse
import os
import tempfile
import time
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

from ai_processor.document_classifier import classify_document
from ai_processor.document_loader import load_images
from ai_processor.field_extractor import extract_document_fields
from ai_processor.image_quality import analyze_document_quality
from ai_processor.ocr_engine import extract_document_text, resolve_roi_profile, retry_philid_field_rois


FONT = Path(r"C:\Windows\Fonts\arial.ttf")
TESSERACT = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
POPLER = r"C:\ProgramData\miniforge3\Library\bin"


SAMPLES = [
    ('valid_id_clean.png', 'valid_id', 'JUAN DELA CRUZ', 'name', 'clean', '''PHILIPPINE IDENTIFICATION CARD
REPUBLIC OF THE PHILIPPINES
PHILIPPINE STATISTICS AUTHORITY PHILSYS
Full Name: JUAN DELA CRUZ
Date of Birth: January 2, 1990
Address: SAMPLE BARANGAY, ANTIQUE
Sex: Male
Valid Until: December 31, 2030'''),
    ('medical_certificate_phone.jpg', 'med_cert', 'ANA REYES', 'patient_name', 'phone', '''MEDICAL CERTIFICATE
SAMPLE PROVINCIAL HOSPITAL
Patient Name: ANA REYES
Date of Birth: February 3, 1995
Diagnosis: SYNTHETIC CONDITION
Attending Physician: DR MARIA SANTOS
Date Issued: September 1, 2026'''),
    ('medical_abstract_skew.jpeg', 'med_cert', 'PEDRO CRUZ', 'patient_name', 'skew', '''MEDICAL ABSTRACT
SAMPLE GENERAL HOSPITAL MEDICAL RECORDS
Patient Name: PEDRO CRUZ
Date of Birth: March 4, 1988
Diagnosis: SYNTHETIC CONDITION
Treatment: SYNTHETIC TREATMENT
Attending Physician: DR MARIA SANTOS
Date Issued: September 1, 2026'''),
    ('billing_statement.pdf', 'billing_statement', 'LIZA RAMOS', 'patient_name', 'pdf', '''BILLING STATEMENT
SAMPLE DISTRICT HOSPITAL
Patient Name: LIZA RAMOS
Account Number: TEST-ACCOUNT-77
Total Charges: PHP 5,000.00
Amount Due: PHP 5,000.00
Billing Office: SAMPLE OFFICE'''),
    ('prescription_low.png', 'billing_statement', 'CARLO REYES', 'patient_name', 'low', '''PRESCRIPTION
Patient Name: CARLO REYES
Generic Drug: SYNTHETIC TABLET 500 MG
Take twice daily for 7 days
Attending Physician: DR MARIA SANTOS'''),
    ('death_certificate_shadow.jpg', 'death_cert', 'ROSA DELA CRUZ', 'deceased_name', 'shadow', '''CERTIFICATE OF DEATH
OFFICE OF THE MUNICIPAL CIVIL REGISTRAR
Name of Deceased: ROSA DELA CRUZ
Sex: Female
Date of Death: August 1, 2026
Place of Death: SAMPLE MUNICIPALITY
Registry Number: TEST-REGISTRY-12'''),
    ('funeral_contract_part.jpeg', 'funeral_contract', 'MARIO SANTOS', 'deceased_name', 'part', '''FUNERAL CONTRACT
SAMPLE MEMORIAL SERVICES
Name of Deceased: MARIO SANTOS
Funeral Home: SAMPLE MEMORIAL SERVICES
Casket and Hearse Service Agreement
Contract Amount: PHP 10,000.00'''),
    ('police_report_clean.png', 'report_police_med', 'RINA CRUZ', 'subject_name', 'clean', '''PHILIPPINE NATIONAL POLICE
POLICE REPORT
Victim Name: RINA CRUZ
Reporting Person: MARIA SANTOS
Incident Date: September 2, 2026
Police Station: SAMPLE STATION
Report Number: TEST-REPORT-99'''),
    ('medical_report_phone.jpg', 'report_police_med', 'JOSE RAMOS', 'subject_name', 'phone', '''MEDICAL REPORT
SAMPLE MEDICAL CENTER
Name of Examinee: JOSE RAMOS
Examining Physician: DR MARIA SANTOS
Requested By: PEDRO CRUZ
Date Issued: September 2, 2026'''),
    ('school_assessment_skew.jpeg', 'school_assessment', 'MIA REYES', 'client_name', 'skew', '''SAMPLE NATIONAL HIGH SCHOOL
SCHOOL ASSESSMENT FORM
Student Name: MIA REYES
School Year: 2026 2027
Grade Level: 10
Tuition Fee: PHP 1,000.00
Registrar: MARIA SANTOS'''),
    ('registration_shadow.png', 'registration_form', 'NICO CRUZ', 'client_name', 'shadow', '''SAMPLE NATIONAL HIGH SCHOOL
BASIC EDUCATION ENROLLMENT FORM
Learner Name: NICO CRUZ
School Year: 2026 2027
Grade Level to Enroll: 7
LRN: TEST-LRN-123
Parent Guardian: MARIA SANTOS
Registrar: PEDRO REYES'''),
    ('drrm_damage_low.jpg', 'mdrrmo_cert', 'ELLA RAMOS', 'client_name', 'low', '''MUNICIPAL DISASTER RISK REDUCTION AND MANAGEMENT OFFICE
CERTIFICATION OF DAMAGE
Name of Affected Person: ELLA RAMOS
Residential House affected by Typhoon Sample. Partially Damaged.
Based on Field Validation. Certified By MDRRMO Officer.
Certificate Number: TEST-DRRM-22'''),
    ('drrm_displacement_part.jpeg', 'mdrrmo_cert', 'TONY REYES', 'client_name', 'part', '''CITY LOCAL DISASTER RISK REDUCTION AND MANAGEMENT OFFICE
CERTIFICATION OF DISPLACEMENT
Displaced Person: TONY REYES
Flooding event. Temporarily Displaced. Evacuation Center.
Upon Inspection. Certified By LDRRMO Officer.'''),
    ('eclip_clean.png', 'eclip_cert', 'JUN DELA CRUZ', 'client_name', 'clean', '''ENHANCED COMPREHENSIVE LOCAL INTEGRATION PROGRAM
FORMER REBEL ELIGIBILITY CERTIFICATION
Beneficiary: JUN DELA CRUZ
Committee Chair: MARIA SANTOS
Certifying Officer: PEDRO REYES
Reference No: TEST-ECLIP-123'''),
    ('crisis_phone.jpg', 'crisis_situation_cert', 'LARA CRUZ', 'client_name', 'phone', '''CERTIFICATE OF PERSON IN CRISIS SITUATION
MSWDO SOCIAL WELFARE CERTIFICATION
Beneficiary: LARA CRUZ
Social Worker: MARIA SANTOS
Approved By: PEDRO REYES
Household Income: PHP 5,000.00'''),
    ('medical_certificate_forced_roi.png', 'med_cert', 'ANA REYES', 'patient_name', 'roi_patient', '''MEDICAL CERTIFICATE
SAMPLE PROVINCIAL HOSPITAL
Patient Name
ANA REYES
Date of Birth: February 3, 1995
Diagnosis: SYNTHETIC CONDITION
Attending Physician: DR MARIA SANTOS
Date Issued: September 1, 2026'''),
    ('valid_id_forced_roi.png', 'valid_id', 'JUAN MIGUEL DELA CRUZ', 'name', 'roi_valid_id', '''PHILIPPINE IDENTIFICATION CARD
REPUBLIC OF THE PHILIPPINES
PHILIPPINE STATISTICS AUTHORITY PHILSYS
Mga Pangalan
JUAN MIGUEL DELA CRUZ
Petsa ng Kapanganakan: January 2, 1990
Tirahan: SAMPLE BARANGAY, ANTIQUE
Sex: Male
Valid Until: December 31, 2030'''),
]

PRIVATE_MARKERS = {
    'valid_id_clean.png': ('PCN',),
    'medical_certificate_phone.jpg': ('SYNTHETIC CONDITION',),
    'medical_abstract_skew.jpeg': ('SYNTHETIC CONDITION', 'SYNTHETIC TREATMENT'),
    'billing_statement.pdf': ('TEST-ACCOUNT-77', 'PHP 5,000.00'),
    'prescription_low.png': ('SYNTHETIC TABLET',),
    'death_certificate_shadow.jpg': ('TEST-REGISTRY-12',),
    'funeral_contract_part.jpeg': ('PHP 10,000.00',),
    'police_report_clean.png': ('TEST-REPORT-99',),
    'registration_shadow.png': ('TEST-LRN-123',),
    'drrm_damage_low.jpg': ('TEST-DRRM-22',),
    'eclip_clean.png': ('TEST-ECLIP-123',),
    'crisis_phone.jpg': ('PHP 5,000.00',),
}


def _font(size: int):
    return ImageFont.truetype(str(FONT), size) if FONT.is_file() else ImageFont.load_default()


def _render(text: str, condition: str = '') -> Image.Image:
    image = Image.new('RGB', (1200, 1600), '#f7f6f1')
    draw = ImageDraw.Draw(image)
    draw.rectangle((60, 55, 1140, 1540), outline='#202020', width=3)
    draw.text((100, 110), 'SYNTHETIC VALIDATION DOCUMENT — NOT VALID', fill='#333333', font=_font(22))
    y = 190
    for index, line in enumerate(text.splitlines()):
        # The label stays normal-size; this fictional next-line value is small
        # enough to make whole-page OCR weak while the label-relative 4x ROI
        # has a realistic chance to recover it.
        weakened_value = (condition == 'roi_patient' and line == 'ANA REYES') or (condition == 'roi_valid_id' and line == 'JUAN MIGUEL DELA CRUZ')
        size = 9 if weakened_value else (30 if index == 0 else 23)
        draw.text((105, y), line, fill='#111111', font=_font(size))
        y += 64
    draw.line((105, 1400, 550, 1400), fill='#222222', width=2)
    draw.text((105, 1415), 'Synthetic signature line', fill='#333333', font=_font(18))
    return image


def _vary(image: Image.Image, condition: str) -> Image.Image:
    if condition == 'phone':
        canvas = Image.new('RGB', (1350, 1750), '#7b756a'); canvas.paste(image.resize((1040, 1390)), (150, 170)); return canvas
    if condition == 'skew':
        return image.rotate(2.2, expand=True, fillcolor='#d8d2c7')
    if condition == 'shadow':
        array = np.asarray(image).copy(); gradient = np.linspace(.55, 1.0, array.shape[1])[None, :, None]; return Image.fromarray(np.clip(array * gradient, 0, 255).astype(np.uint8))
    if condition == 'part':
        canvas = Image.new('RGB', (1550, 1700), '#b9c1ba'); canvas.paste(image.resize((810, 1080)), (370, 290)); return canvas
    if condition == 'low':
        return image.resize((600, 800)).resize((1200, 1600))
    return image


def _save(image: Image.Image, path: Path, condition: str) -> None:
    if path.suffix == '.pdf': image.save(path, 'PDF', resolution=150)
    elif path.suffix in ('.jpg', '.jpeg'): image.save(path, quality=48 if condition == 'phone' else 68)
    else: image.save(path)


def run(selected: set[str] | None = None, output: Path | None = None) -> list[dict]:
    rows = []
    with tempfile.TemporaryDirectory(prefix='aics-fictional-ocr-validation-') as directory:
        root = Path(directory)
        for filename, expected, expected_name, identity_key, condition, content in SAMPLES:
            if selected and filename not in selected:
                continue
            path = root / filename
            _save(_vary(_render(content, condition), condition), path, condition)
            started = time.perf_counter()
            loaded = load_images(path, 1, POPLER, 45)
            load_seconds = time.perf_counter() - started
            started = time.perf_counter()
            text, ocr_confidence, metadata = extract_document_text(loaded, TESSERACT, 45)
            ocr_seconds = time.perf_counter() - started
            classification = classify_document(text, expected)
            fields = extract_document_fields(expected, text, metadata)['fields']
            profile = resolve_roi_profile(expected, classification)
            initial_identity = dict(fields.get(identity_key, {}))
            weak = {identity_key} if profile and fields.get(identity_key, {}).get('status') != 'extracted' else set()
            roi_geometry = None
            if weak:
                labels = ('patient name', 'name of patient', 'patient') if identity_key == 'patient_name' else ('mga pangalan', 'given names') if identity_key == 'name' else ()
                for line in metadata.get('pages', [{}])[0].get('lines', []):
                    if labels and any(label in str(line.get('text', '')).lower() for label in labels):
                        x1, y1, x2, y2 = [int(value) for value in line['box']]
                        height, image_width, image_height = max(1, y2 - y1), loaded[0].shape[1], loaded[0].shape[0]
                        roi_geometry = [max(0, x1 - height), max(0, y2), min(image_width, max(x2 + height * 10, int(image_width * .75))), min(image_height, y2 + height * 3)]
                        break
            if weak:
                retry_started = time.perf_counter()
                metadata = retry_philid_field_rois(loaded, metadata, TESSERACT, weak, 45, profile)
                retry_seconds = time.perf_counter() - retry_started
                fields = extract_document_fields(expected, text, metadata)['fields']
            else:
                retry_seconds = 0.0
            quality = analyze_document_quality(loaded, 80, text, ocr_confidence, fields)
            identity = fields.get(identity_key, {})
            exposed = [marker for marker in PRIVATE_MARKERS.get(filename, ()) if marker.lower() in str(fields).lower()]
            row = {
                'sample': filename, 'format': path.suffix[1:].upper(), 'condition': condition, 'expected_slot': expected,
                'detected_type': classification.get('detected_type'), 'subtype': classification.get('document_subtype'),
                'document_check': classification.get('decision'), 'confidence_band': classification.get('confidence_band'),
                'ocr_confidence': round(ocr_confidence, 2), 'image_quality': quality.get('decision'),
                'text_readability': quality.get('ocr_readability'), 'identity_key': identity_key,
                'expected_identity': expected_name, 'extracted_identity': identity.get('normalized'),
                'identity_status': identity.get('status'), 'roi_calls': metadata.get('roi_retry_calls', 0),
                'initial_identity': initial_identity.get('normalized'), 'initial_identity_status': initial_identity.get('status'),
                'roi_geometry': roi_geometry, 'roi_psm': '--psm 7' if weak else None,
                'roi_result': (metadata.get('roi_values', {}).get(identity_key, {}) or {}).get('value'),
                'roi_confidence': (metadata.get('roi_values', {}).get(identity_key, {}) or {}).get('confidence'),
                'privacy_pass': not exposed, 'privacy_exposed': exposed,
                'load_seconds': round(load_seconds, 3), 'ocr_seconds': round(ocr_seconds, 3),
                'roi_seconds': round(retry_seconds, 3), 'total_seconds': round(load_seconds + ocr_seconds + retry_seconds, 3),
            }
            rows.append(row)
            if output:
                output.parent.mkdir(parents=True, exist_ok=True)
                with output.open('a', encoding='utf-8') as handle:
                    handle.write(json.dumps(row) + '\n')
            print(json.dumps(row), flush=True)
    return rows


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--sample', action='append', dest='samples')
    parser.add_argument('--output', type=Path, default=Path(os.environ.get('TEMP', tempfile.gettempdir())) / 'aics-fictional-ocr-validation-results.jsonl')
    args = parser.parse_args()
    run(set(args.samples or ()), args.output)
