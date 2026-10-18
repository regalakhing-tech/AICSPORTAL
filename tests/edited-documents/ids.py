"""Synthetic ID cards for the AICS AI scanner tests.

Plain, low-fidelity cards for fictional people: no real seals, logos, or
security features. Each card is "photographed" lying on a table, then edited
the way people alter ID photos (typed-over fields, swapped portrait).
"""
from __future__ import annotations

import cv2
import numpy as np
from PIL import Image, ImageDraw

FINAL_WIDTH = 1040
font = paper_colour = save = type_over = None  # set by build_ids(gen)


CARD_W, CARD_H = 1011, 638
ID_STYLES = {
    'national': ('PHILIPPINE IDENTIFICATION CARD', (232, 240, 248), (20, 60, 120)),
    'drivers': ("DRIVER'S LICENSE", (240, 246, 236), (30, 90, 40)),
    'umid': ('UNIFIED MULTI-PURPOSE ID', (246, 240, 232), (120, 60, 20)),
    'postal': ('POSTAL IDENTITY CARD', (248, 244, 230), (150, 40, 30)),
}
BLACK = (0, 0, 0)


def person(**overrides) -> dict:
    data = {'last': 'DELA CRUZ', 'given': 'JUAN SANTOS', 'birth': 'MARCH 12, 1975',
            'address': 'BRGY. 3, SAN JOSE, ANTIQUE', 'valid': '12 MAR 2029', 'number_label': 'ID Number',
            'number': '1234-5678-9012-3456', 'holder_signature': 'JSDelaCruz',
            'fields': [('last', 'Last Name'), ('given', 'Given Names'), ('birth', 'Date of Birth'),
                       ('address', 'Address'), ('valid', 'Valid Until')]}
    data.update(overrides)
    return data


def id_card(kind: str, who: dict) -> tuple[Image.Image, dict]:
    title, background, accent = ID_STYLES[kind]
    card = Image.new('RGB', (CARD_W, CARD_H), background)
    draw = ImageDraw.Draw(card)
    wave = tuple(max(0, value - 7) for value in background)
    for offset in range(0, 420, 18):  # faint background waves
        draw.line([(x, 110 + offset + 12 * np.sin((x + offset * 3) / 37)) for x in range(0, CARD_W, 12)], fill=wave, width=1)
    draw.rectangle((0, 0, CARD_W, 92), fill=accent)
    draw.text((30, 12), 'REPUBLIC OF THE PHILIPPINES', font=font('arialbd.ttf', 26), fill=(255, 255, 255))
    draw.text((30, 48), title, font=font('arialbd.ttf', 30), fill=(255, 255, 255))
    draw.rectangle((40, 130, 300, 450), fill=(205, 212, 220))       # portrait placeholder
    draw.ellipse((115, 165, 225, 285), fill=(150, 120, 100))
    draw.pieslice((70, 285, 270, 520), 180, 360, fill=(60, 70, 90))
    boxes = {'photo': [40, 130, 300, 450]}
    label_font, value_font = font('arial.ttf', 20), font('arialbd.ttf', 30)
    y = 112
    for key, label in who['fields']:
        draw.text((330, y), label, font=label_font, fill=(80, 80, 80))
        draw.text((330, y + 24), who[key], font=value_font, fill=(15, 15, 15))
        boxes[key] = list(draw.textbbox((330, y + 24), who[key], font=value_font))
        y += 74
    draw.text((40, 470), who['number_label'], font=label_font, fill=(80, 80, 80))
    draw.text((40, 494), who['number'], font=font('arialbd.ttf', 28), fill=(15, 15, 15))
    draw.text((640, 560), who['holder_signature'], font=font('Inkfree.ttf', 44), fill=(30, 40, 120))
    return card, boxes


def photograph_card(card: Image.Image, seed: int, angle: float = 2.0):
    """The card lying on a wooden table, photographed with a phone."""
    rng = np.random.default_rng(seed)
    table = np.full((900, 1300, 3), (120, 95, 70), np.float32) + rng.normal(0, 6, (900, 1300, 3))
    table[130:130 + CARD_H, 145:145 + CARD_W] = np.asarray(card).astype(np.float32) * 0.82 + 26
    yy, xx = np.mgrid[0:900, 0:1300].astype(np.float32)
    table *= (0.88 + 0.12 * (1 - ((xx - 500) ** 2 + (yy - 300) ** 2) / (1300 ** 2 + 900 ** 2)))[..., None]
    table *= np.array((1.0, 0.95, 0.88), np.float32)
    matrix = cv2.getRotationMatrix2D((650, 450), angle, 1.0)
    table = cv2.warpAffine(table, matrix, (1300, 900), borderMode=cv2.BORDER_REPLICATE)
    table = cv2.GaussianBlur(table, (0, 0), 0.9) + rng.normal(0, 3.2, table.shape)
    scale = FINAL_WIDTH / 1300
    pixels = cv2.resize(np.clip(table, 0, 255), (FINAL_WIDTH, round(900 * scale)), interpolation=cv2.INTER_AREA)

    def to_final(box):
        corners = np.array([[box[0] + 145, box[1] + 130, 1], [box[2] + 145, box[1] + 130, 1],
                            [box[0] + 145, box[3] + 130, 1], [box[2] + 145, box[3] + 130, 1]], np.float32)
        moved = corners @ matrix.T * scale
        return [int(moved[:, 0].min()), int(moved[:, 1].min()), int(np.ceil(moved[:, 0].max())), int(np.ceil(moved[:, 1].max()))]

    return np.clip(pixels, 0, 255).astype(np.uint8), to_final


def _shot(kind, who, seed, angle=2.0):
    card, boxes = id_card(kind, who)
    pixels, to_final = photograph_card(card, seed, angle)
    return pixels, boxes, to_final


def build_ids(gen) -> list[dict]:
    """gen: the generate module, so files go to its current output folder."""
    global FINAL_WIDTH, font, paper_colour, save, type_over
    FINAL_WIDTH, font, paper_colour, save, type_over = gen.FINAL_WIDTH, gen.font, gen.paper_colour, gen.save, gen.type_over
    cases = []
    drivers = dict(birth='1975/03/12', valid='2028/03/12', number_label='License No.', number='N01-23-456789')

    pixels, _, _ = _shot('national', person(), 41)
    cases.append({'file': save(pixels, 'id-clean-01-national-id.jpg'), 'edited': False,
                  'description': 'Unedited national ID of the beneficiary.'})
    pixels, _, _ = _shot('drivers', person(**drivers), 43, -1.5)
    cases.append({'file': save(pixels, 'id-clean-02-drivers-license.jpg'), 'edited': False,
                  'description': "Unedited driver's license of the beneficiary."})
    pixels, _, _ = _shot('umid', person(number_label='CRN', number='0111-2222333-4'), 47, 1.0)
    cases.append({'file': save(pixels, 'id-clean-03-umid.jpg'), 'edited': False,
                  'description': 'Unedited UMID of the beneficiary.'})

    other = person(last='REYES', given='PEDRO MANALO', birth='JULY 21, 1990', holder_signature='PMReyes')
    pixels, boxes, to_final = _shot('national', other, 53)
    for key, value in (('last', 'DELA CRUZ'), ('given', 'JUAN SANTOS')):
        box = to_final(boxes[key])
        pixels = type_over(pixels, box, value, font('arialbd.ttf', 25), BLACK, paper_colour(pixels, box), underline=False)
    cases.append({'file': save(pixels, 'id-edit-01-national-name-typed.jpg', quality=92), 'edited': True,
                  'edit': "Another person's national ID with the beneficiary's name typed over the name fields (birth date still 1990).",
                  'expect_any': ['digital_text_overlay', 'name_mismatch_or_internal_conflict']})

    pixels, boxes, to_final = _shot('drivers', person(**{**drivers, 'birth': '1990/07/21'}), 59, -1.5)
    box = to_final(boxes['birth'])
    pixels = type_over(pixels, box, '1975/03/12', font('bahnschrift.ttf', 26), BLACK, paper_colour(pixels, box), underline=False)
    cases.append({'file': save(pixels, 'id-edit-02-drivers-birthdate-typed.jpg', quality=92), 'edited': True,
                  'edit': "Driver's license birth date retyped from 1990/07/21 to match the application (1975/03/12).",
                  'expect_any': ['digital_text_overlay']})

    pixels, boxes, to_final = _shot('postal', person(valid='04 NOV 2017', birth='12 MAR 1975', number_label='PRN',
                                                     number='CA1234567890 B'), 61, 1.2)
    box = to_final(boxes['valid'])
    pixels = type_over(pixels, box, '04 NOV 2027', font('arialbd.ttf', 25), BLACK, paper_colour(pixels, box), underline=False)
    cases.append({'file': save(pixels, 'id-edit-03-postal-expiry-typed.png', xmp_tool='Picsart'), 'edited': True,
                  'edit': 'Expired postal ID (2017) with the validity date retyped as 2027 in a phone editor (Picsart).',
                  'expect_any': ['digital_text_overlay']})

    pixels, boxes, to_final = _shot('umid', person(number_label='CRN', number='0111-2222333-4'), 67, 1.0)
    x1, y1, x2, y2 = to_final(boxes['photo'])
    face = Image.new('RGB', (x2 - x1, y2 - y1), (226, 220, 214))
    face_draw = ImageDraw.Draw(face)
    face_draw.ellipse((int(face.width * .28), int(face.height * .12), int(face.width * .72), int(face.height * .5)), fill=(170, 130, 105))
    face_draw.pieslice((int(face.width * .1), int(face.height * .5), int(face.width * .9), int(face.height * 1.3)), 180, 360, fill=(140, 30, 40))
    encoded = cv2.imencode('.jpg', np.asarray(face)[:, :, ::-1], [cv2.IMWRITE_JPEG_QUALITY, 40])[1]
    pixels = pixels.copy()
    pixels[y1:y2, x1:x2] = cv2.imdecode(encoded, cv2.IMREAD_COLOR)[:, :, ::-1]
    cases.append({'file': save(pixels, 'id-edit-04-umid-photo-swapped.jpg'), 'edited': True,
                  'edit': 'UMID portrait replaced with a sharper, brighter, heavily compressed picture pasted from another image.',
                  'expect_any': ['pasted_region_boundary', 'compression_or_resampling_difference', 'background_texture_discontinuity',
                                 'other_visual_anomaly', 'signature_or_stamp_anomaly']})

    pixels, _, _ = _shot('national', other, 71, -2.2)
    cases.append({'file': save(pixels, 'id-edit-05-someone-elses-id.jpg'), 'edited': True,
                  'edit': 'Unedited national ID of a different person (PEDRO MANALO REYES) uploaded for the beneficiary.',
                  'expect_any': ['name_mismatch_or_internal_conflict']})
    for case in cases:
        case['expected_document_type'] = 'valid_id'
        if not case['edited']:
            # The cards are synthetic illustrations; the AI may correctly call them a mockup.
            case['allow'] = ['sample_or_watermark']
    return cases


def id_back(kind: str, who: dict) -> Image.Image:
    """Back of the card: address, signature box, a QR-like block, and return notice."""
    _, background, accent = ID_STYLES[kind]
    card = Image.new('RGB', (CARD_W, CARD_H), background)
    draw = ImageDraw.Draw(card)
    rng = np.random.default_rng(len(who['last']) + len(kind))
    draw.rectangle((0, CARD_H - 70, CARD_W, CARD_H), fill=accent)
    draw.text((40, 40), 'Address', font=font('arial.ttf', 20), fill=(80, 80, 80))
    draw.text((40, 64), who['address'], font=font('arialbd.ttf', 26), fill=(15, 15, 15))
    draw.text((40, 120), 'In case of emergency, notify', font=font('arial.ttf', 20), fill=(80, 80, 80))
    draw.text((40, 144), 'MARIA DELA CRUZ  0917 000 0000', font=font('arialbd.ttf', 24), fill=(15, 15, 15))
    draw.rectangle((40, 210, 520, 330), outline=(90, 90, 90), width=2)
    draw.text((50, 216), "Holder's Signature", font=font('arial.ttf', 18), fill=(90, 90, 90))
    draw.text((90, 250), who['holder_signature'], font=font('Inkfree.ttf', 52), fill=(30, 40, 120))
    for row in range(25):  # QR-like block (random, encodes nothing)
        for column in range(25):
            if rng.random() > 0.5:
                draw.rectangle((720 + column * 9, 200 + row * 9, 728 + column * 9, 208 + row * 9), fill=(10, 10, 10))
    draw.text((40, 380), 'This card is non-transferable. If found, please return to the', font=font('arial.ttf', 22), fill=(40, 40, 40))
    draw.text((40, 410), 'nearest issuing office.', font=font('arial.ttf', 22), fill=(40, 40, 40))
    draw.text((40, CARD_H - 52), 'REPUBLIC OF THE PHILIPPINES', font=font('arialbd.ttf', 24), fill=(255, 255, 255))
    return card


def build_signup_ids(gen, out_dir) -> list[dict]:
    """Front/back pairs for the sign-up ID check (python -m ai_processor.id_check)."""
    global FINAL_WIDTH, font, paper_colour, save, type_over
    FINAL_WIDTH, font, paper_colour, save, type_over = gen.FINAL_WIDTH, gen.font, gen.paper_colour, gen.save, gen.type_over
    out_dir.mkdir(parents=True, exist_ok=True)

    def write(pixels, name):
        Image.fromarray(pixels).save(out_dir / name, 'JPEG', quality=90)
        return name

    me = person()
    front, boxes, to_final = _shot('national', me, 81)
    back, _ = photograph_card(id_back('national', me), 82, -1.0)
    other = person(last='REYES', given='PEDRO MANALO', birth='JULY 21, 1990', holder_signature='PMReyes')
    other_front, other_boxes, other_final = _shot('national', other, 83)
    typed = other_front
    for key, value in (('last', 'DELA CRUZ'), ('given', 'JUAN SANTOS')):
        box = other_final(other_boxes[key])
        typed = type_over(typed, box, value, font('arialbd.ttf', 25), BLACK, paper_colour(typed, box), underline=False)
    drivers_back, _ = photograph_card(id_back('drivers', person(address='PUROK 2, SIBALOM, ANTIQUE', holder_signature='LGarcia')), 84, 1.5)
    cases = [
        # The AI correctly calls generated cards 'not a real card'; a real ID photo is needed to see 'ok'.
        {'name': 'synthetic-card', 'front': write(front, 'signup-ok-front.jpg'), 'back': write(back, 'signup-ok-back.jpg'), 'expect': 'edited'},
        {'name': 'typed-name', 'front': write(typed, 'signup-typed-name-front.jpg'), 'back': 'signup-ok-back.jpg', 'expect': 'edited'},
        {'name': 'same-photo-twice', 'front': 'signup-ok-front.jpg', 'back': 'signup-ok-front.jpg', 'expect': 'retake'},
        {'name': 'back-of-other-card', 'front': 'signup-ok-front.jpg', 'back': write(drivers_back, 'signup-other-card-back.jpg'), 'expect': 'retake'},
    ]
    return cases
