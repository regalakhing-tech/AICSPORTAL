"""Generate realistic test documents for the AICS AI document scanner.

Each document is a fictional medical certificate rendered with real fonts and
then "photographed" (warm light, uneven lighting, slight tilt, blur, camera
noise) like an applicant's phone photo. Edited versions apply the changes a
forger would make AFTER taking the photo, e.g. typing a new date in Canva.

All names and details are fictional. Run:  python generate.py
"""
from __future__ import annotations

import json
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont, PngImagePlugin

HERE = Path(__file__).resolve().parent
OUT = HERE / 'documents'
FONTS = Path(r'C:\Windows\Fonts')
W, H = 1240, 1754                      # A4 at 150 dpi
FINAL_WIDTH = 1040                     # typical phone photo after messaging-app resize
BENEFICIARY = {'full_name': 'Juan Santos Dela Cruz', 'date_of_birth': '1975-03-12', 'sex': 'Male',
               'address': 'Barangay 3, San Jose, Antique'}


def font(name: str, size: int):
    for candidate in (name, 'arial.ttf'):
        path = FONTS / candidate
        if path.is_file():
            return ImageFont.truetype(str(path), size)
    return ImageFont.load_default()


def certificate(patient: str = 'JUAN S. DELA CRUZ', signed: str = 'April 16, 2021',
                title: str = 'MEDICAL CERTIFICATE') -> tuple[Image.Image, dict]:
    """Render the paper form; returns the image and pixel boxes of key values."""
    image = Image.new('RGB', (W, H), (250, 248, 243))
    draw = ImageDraw.Draw(image)
    regular, bold, small = font('arial.ttf', 26), font('arialbd.ttf', 26), font('arial.ttf', 22)
    boxes: dict[str, list[int]] = {}

    def text(x, y, value, face=regular, fill=(18, 18, 18), key=None, underline=False):
        draw.text((x, y), value, font=face, fill=fill)
        x1, y1, x2, y2 = draw.textbbox((x, y), value, font=face)
        if underline:
            draw.line((x1, y2 + 3, x2, y2 + 3), fill=fill, width=2)
        if key:
            boxes[key] = [x1, y1, x2, y2 + 5]
        return y2

    def centered(y, value, face):
        width = draw.textlength(value, font=face)
        return text((W - width) / 2, y, value, face)

    text(90, 70, 'C.S FORM No. 41', small)
    centered(130, 'REPUBLIC OF THE PHILIPPINES  -  CIVIL SERVICE', bold)
    centered(185, title, font('arialbd.ttf', 52))
    y = 290
    for line in ('I hereby waive all the rights and privileges pertaining to professional confidences between',
                 'physician and patient, and the physician accomplishing this form is authorized to answer in',
                 'detail all questions contained herein.'):
        y = text(90, y, line) + 12
    text(760, y + 30, patient, bold, key='patient_name_top')
    text(800, y + 70, '(Signature of Patient)', small)
    draw.line((90, y + 125, W - 90, y + 125), fill=(40, 40, 40), width=2)
    y += 160
    body = [
        f'{patient} having made application for leave on account of illness, I do hereby',
        'certify that I am the applicant\'s actual attending physician from April 11 - 15, 2021',
        'inclusive and from my professional knowledge of the case, the following statements',
        'are submitted.',
    ]
    for line in body:
        y = text(90, y, line) + 14
    y += 20
    y = text(110, y, 'Nature of Disease: Acute Gastroenteritis with Moderate Dehydration', key='diagnosis') + 14
    y = text(110, y, 'History: vomiting and loose bowel movement, weakness, poor oral intake.') + 30
    draw.line((90, y, W - 90, y), fill=(40, 40, 40), width=2)
    y += 30
    y = text(110, y, 'A laboratory test or examination was made in this case.') + 14
    confined = 'The applicant was confined at the hospital from '
    text(110, y, confined)
    x_dates = 110 + draw.textlength(confined, font=regular)
    y = text(x_dates, y, 'April 11 - 15, 2021', key='confinement_dates', underline=True) + 14
    y = text(110, y, 'inclusive.') + 40
    for line in ('I HEREBY CERTIFY that the above statements are complete and true in every detail, and that',
                 'in consequence of the disease above specified the patient was ill and unable to go on duty',
                 'from April 11 - 15, 2021 inclusive.'):
        y = text(90, y, line) + 14
    y += 90
    # Handwritten signature in blue ballpoint above the physician's name.
    text(760, y - 70, 'MLSantos', font('Inkfree.ttf', 64), fill=(35, 55, 150), key='doctor_signature')
    text(640, y, '(Signature) ', regular)
    text(640 + draw.textlength('(Signature) ', font=regular), y, 'MARIA L. SANTOS, MD.', bold, key='doctor_name', underline=True)
    y += 44
    text(640, y, '(P.O. Address) ', regular)
    text(640 + draw.textlength('(P.O. Address) ', font=regular), y, 'Antique Provincial Hospital', regular, underline=True)
    y += 44
    text(640, y, '(Date) ', regular)
    text(640 + draw.textlength('(Date) ', font=regular), y, signed, regular, key='signed_date', underline=True)
    y += 44
    text(640, y, 'License No. ', regular)
    text(640 + draw.textlength('License No. ', font=regular), y, '0123456', regular, key='license_no', underline=True)
    return image, boxes


def photograph(image: Image.Image, seed: int, cast=(1.0, 0.93, 0.86), angle=0.4, noise=3.2,
               blur=0.9, quality_drop=True) -> tuple[np.ndarray, callable]:
    """Simulate a phone photo. Returns RGB pixels and a function that maps
    rendered-page boxes to final pixel boxes."""
    rng = np.random.default_rng(seed)
    pixels = np.asarray(image).astype(np.float32)
    if quality_drop:
        pixels = pixels * 0.80 + 30  # camera contrast: ink becomes dark grey, never pure black
    yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
    light = 0.90 + 0.10 * (1 - ((xx - W * 0.35) ** 2 + (yy - H * 0.3) ** 2) / (W ** 2 + H ** 2))
    pixels = pixels * np.array(cast, np.float32) * light[..., None]
    matrix = cv2.getRotationMatrix2D((W / 2, H / 2), angle, 1.0)
    pixels = cv2.warpAffine(pixels, matrix, (W, H), borderMode=cv2.BORDER_REPLICATE)
    pixels = cv2.GaussianBlur(pixels, (0, 0), blur)
    pixels = pixels + rng.normal(0, noise, pixels.shape)
    scale = FINAL_WIDTH / W
    pixels = cv2.resize(np.clip(pixels, 0, 255), (FINAL_WIDTH, round(H * scale)), interpolation=cv2.INTER_AREA)

    def to_final(box):
        corners = np.array([[box[0], box[1], 1], [box[2], box[1], 1], [box[0], box[3], 1], [box[2], box[3], 1]], np.float32)
        moved = corners @ matrix.T * scale
        return [int(moved[:, 0].min()), int(moved[:, 1].min()), int(np.ceil(moved[:, 0].max())), int(np.ceil(moved[:, 1].max()))]

    return np.clip(pixels, 0, 255).astype(np.uint8), to_final


def paper_colour(pixels: np.ndarray, box) -> tuple[int, int, int]:
    x1, y1, x2, y2 = box
    patch = pixels[max(0, y1 - 12):y2 + 12, max(0, x1 - 12):x2 + 12].reshape(-1, 3)
    bright = patch[patch.sum(axis=1) > np.percentile(patch.sum(axis=1), 60)]
    return tuple(int(value) for value in np.median(bright, axis=0))


def type_over(pixels: np.ndarray, box, value: str, face, fill, cover: tuple | None, underline=True) -> np.ndarray:
    """Cover the old value with a flat shape and type a new one (what Canva users do)."""
    image = Image.fromarray(pixels)
    draw = ImageDraw.Draw(image)
    x1, y1, x2, y2 = box
    if cover is not None:
        draw.rectangle((x1 - 3, y1 - 3, x2 + 40, y2 + 3), fill=cover)
    draw.text((x1, y1 - 2), value, font=face, fill=fill)
    if underline:
        tx1, _, tx2, ty2 = draw.textbbox((x1, y1 - 2), value, font=face)
        draw.line((tx1, ty2 + 4, tx2, ty2 + 4), fill=fill, width=3)
    return np.asarray(image)


def save(pixels: np.ndarray, name: str, *, xmp_tool: str | None = None, quality: int = 90) -> str:
    OUT.mkdir(parents=True, exist_ok=True)
    path = OUT / name
    image = Image.fromarray(pixels)
    if path.suffix == '.png':
        info = PngImagePlugin.PngInfo()
        if xmp_tool:
            info.add_itxt('XML:com.adobe.xmp', f'<x:xmpmeta><rdf:Description xmp:CreatorTool="{xmp_tool}"/></x:xmpmeta>')
        image.save(path, 'PNG', pnginfo=info)
    else:
        image.save(path, 'JPEG', quality=quality)
    return name


def build_all(out_dir: Path | None = None) -> list[dict]:
    global OUT
    OUT = Path(out_dir) if out_dir else HERE / 'documents'
    cases = []
    paper, boxes = certificate()
    photo, to_final = photograph(paper, seed=11)

    # Clean controls ------------------------------------------------------------
    cases.append({'file': save(photo, 'clean-01-phone-photo.jpg'), 'edited': False,
                  'description': 'Unedited phone photo of the certificate.'})
    scan = np.asarray(certificate()[0])
    cases.append({'file': save(scan, 'clean-02-flat-scan.png'), 'edited': False,
                  'description': 'Unedited clean scan (black text on white, no camera effects).'})
    cool, _ = photograph(paper, seed=29, cast=(0.92, 0.96, 1.0), angle=-0.6, noise=4.0, blur=1.1)
    cases.append({'file': save(cool, 'clean-03-cool-light-photo.jpg', quality=85), 'edited': False,
                  'description': 'Unedited photo under cool light with stronger noise and blur.'})

    # Edited documents ----------------------------------------------------------
    canva = type_over(photo, to_final(boxes['signed_date']), 'September 24, 2026', font('bahnschrift.ttf', 27),
                      (0, 0, 0), paper_colour(photo, to_final(boxes['signed_date'])))
    cases.append({'file': save(canva, 'edit-01-canva-typed-date.png', xmp_tool='Canva doc=TEST user=TEST brand=Test Team'),
                  'edited': True, 'edit': 'Signature date covered and retyped in Canva (flat black, different font); 2021 events vs 2026 date.',
                  'expect_any': ['digital_text_overlay', 'date_context_conflict']})

    other, other_boxes = certificate(patient='ROBERTO C. VILLANUEVA')
    other_photo, other_final = photograph(other, seed=17)
    name_box = other_final(other_boxes['patient_name_top'])
    pasted = type_over(other_photo, name_box, 'JUAN S. DELA CRUZ', font('arialbd.ttf', 22), (0, 0, 0),
                       paper_colour(other_photo, name_box), underline=False)
    cases.append({'file': save(pasted, 'edit-02-pasted-beneficiary-name.jpg', quality=92), 'edited': True,
                  'edit': "Someone else's certificate; the beneficiary's name was typed over the patient name at the top (body still names ROBERTO C. VILLANUEVA).",
                  'expect_any': ['digital_text_overlay', 'name_mismatch_or_internal_conflict']})

    cloned = photo.copy()
    sx1, sy1, sx2, sy2 = to_final(boxes['doctor_signature'])
    tx1, ty1, _, _ = to_final(boxes['patient_name_top'])
    signature = cloned[sy1 - 6:sy2 + 6, sx1 - 6:sx2 + 6].copy()
    cloned[ty1 + 26:ty1 + 26 + signature.shape[0], tx1 + 20:tx1 + 20 + signature.shape[1]] = signature
    cases.append({'file': save(cloned, 'edit-03-cloned-signature.jpg'), 'edited': True,
                  'edit': "The doctor's signature pixels were copied onto the patient signature area (copy-move).",
                  'expect_any': ['copy_move_region', 'repeated_visual_region', 'signature_or_stamp_anomaly']})

    whiteout = photo.copy()
    dates_box = to_final(boxes['confinement_dates'])
    whiteout = type_over(whiteout, dates_box, 'April 11 - 25, 2021', font('arial.ttf', 22), (45, 42, 40), (252, 252, 252))
    cases.append({'file': save(whiteout, 'edit-04-whiteout-retyped-dates.jpg'), 'edited': True,
                  'edit': 'Confinement dates covered with flat white (correction tape) and retyped as 11 - 25 instead of 11 - 15.',
                  'expect_any': ['erasure_or_cover_region', 'pasted_region_boundary', 'value_context_conflict', 'date_context_conflict']})

    spliced = photo.copy()
    dx1, dy1, dx2, dy2 = to_final(boxes['diagnosis'])
    patch = Image.new('RGB', (dx2 - dx1 + 40, dy2 - dy1 + 14), (243, 243, 241))
    ImageDraw.Draw(patch).text((4, 4), 'Nature of Disease: Severe Pneumonia, admitted to ICU', font=font('arial.ttf', 22), fill=(25, 25, 25))
    patch_pixels = cv2.imdecode(cv2.imencode('.jpg', np.asarray(patch), [cv2.IMWRITE_JPEG_QUALITY, 35])[1], cv2.IMREAD_COLOR)
    spliced[dy1 - 4:dy1 - 4 + patch_pixels.shape[0], dx1 - 4:dx1 - 4 + patch_pixels.shape[1]] = patch_pixels
    cases.append({'file': save(spliced, 'edit-05-spliced-diagnosis.jpg'), 'edited': True,
                  'edit': 'Diagnosis line replaced by a cleaner, brighter, heavily compressed patch from another image (splice).',
                  'expect_any': ['pasted_region_boundary', 'compression_or_resampling_difference', 'background_texture_discontinuity',
                                 'digital_text_overlay', 'typography_mismatch']})

    sample = Image.fromarray(photo)
    layer = Image.new('RGBA', sample.size, (0, 0, 0, 0))
    ImageDraw.Draw(layer).text((140, 640), 'SAMPLE ONLY', font=font('arialbd.ttf', 150), fill=(200, 30, 30, 70))
    sample = Image.alpha_composite(sample.convert('RGBA'), layer.rotate(25, center=(520, 740))).convert('RGB')
    ImageDraw.Draw(sample).text((300, sample.height - 50), 'www.free-forms-archive.example', font=font('arial.ttf', 22), fill=(90, 90, 90))
    cases.append({'file': save(np.asarray(sample), 'edit-06-sample-watermark.jpg'), 'edited': True,
                  'edit': 'A sample form downloaded from a website (SAMPLE ONLY watermark and website address).',
                  'expect_any': ['sample_or_watermark']})

    death, _ = certificate(title='CERTIFICATE OF DEATH')
    death_photo, _ = photograph(death, seed=23)
    cases.append({'file': save(death_photo, 'edit-07-wrong-document-type.jpg'), 'edited': True,
                  'edit': 'A death-certificate-titled form uploaded in the Medical Certificate slot.',
                  'expect_any': ['wrong_document']})

    navy = type_over(photo, to_final(boxes['signed_date']), 'September 24, 2026', font('bahnschrift.ttf', 27),
                     (32, 38, 78), paper_colour(photo, to_final(boxes['signed_date'])))
    cases.append({'file': save(navy, 'edit-08-navy-typed-date-hard.jpg', quality=93), 'edited': True,
                  'edit': 'HARD CASE: date retyped in dark navy (not flat black), saved as JPEG without editor metadata.',
                  'expect_any': ['digital_text_overlay', 'date_context_conflict', 'typography_mismatch']})

    for case in cases:
        case.setdefault('expected_document_type', 'med_cert')
        if not case['edited']:
            # Generated fixtures; the AI may correctly note they look computer-made.
            case['allow'] = ['sample_or_watermark']
    import importlib.util
    from types import SimpleNamespace
    spec = importlib.util.spec_from_file_location('edited_documents_ids', HERE / 'ids.py')
    ids = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(ids)
    helpers = SimpleNamespace(FINAL_WIDTH=FINAL_WIDTH, font=font, paper_colour=paper_colour, save=save, type_over=type_over)
    cases.extend(ids.build_ids(helpers))  # IDs reuse the photo and edit helpers above
    signup = ids.build_signup_ids(helpers, OUT / 'signup')
    if out_dir is None:
        (HERE / 'signup-manifest.json').write_text(json.dumps(signup, indent=2), encoding='utf-8')
    if out_dir is None:
        (HERE / 'manifest.json').write_text(json.dumps({'beneficiary': BENEFICIARY, 'documents': cases}, indent=2), encoding='utf-8')
    return cases


if __name__ == '__main__':
    for item in build_all():
        print(('EDITED ' if item['edited'] else 'CLEAN  ') + item['file'])
