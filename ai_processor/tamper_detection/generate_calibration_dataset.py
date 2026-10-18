"""Generate a deterministic, fictional-only ADCD-Net calibration corpus."""
from __future__ import annotations
import argparse, json, random
from pathlib import Path
from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont

W, H = 1240, 1754
TYPES = ('amount_modification', 'date_modification', 'name_modification', 'reference_modification', 'text_replacement', 'text_insertion', 'text_removal', 'copy_move', 'pasted_text_region', 'signature_region_edit')
BENIGN = ('jpeg_compression', 'scanner_noise', 'small_blur', 'brightness', 'contrast', 'minor_rotation', 'resizing', 'grayscale', 'perspective_like_resize', 'mobile_compression')

def font(size, bold=False):
    candidates = [r'C:\Windows\Fonts\arialbd.ttf' if bold else r'C:\Windows\Fonts\arial.ttf', r'C:\Windows\Fonts\DejaVuSans-Bold.ttf' if bold else r'C:\Windows\Fonts\DejaVuSans.ttf']
    for candidate in candidates:
        if Path(candidate).is_file(): return ImageFont.truetype(candidate, size)
    return ImageFont.load_default()

def rect_for(draw, xy, text, f):
    box = draw.textbbox(xy, text, font=f)
    return [box[0] - 3, box[1] - 3, box[2] + 3, box[3] + 3]

def base_document(index):
    image = Image.new('RGB', (W, H), 'white'); draw = ImageDraw.Draw(image)
    regular, heading, small = font(29), font(42, True), font(22)
    draw.rectangle((55, 55, W-55, H-55), outline='black', width=3)
    draw.text((90, 92), 'TEST DOCUMENT - NOT VALID', fill='black', font=heading)
    labels = ('AICS SAMPLE ASSISTANCE FORM', 'SYNTHETIC CERTIFICATION RECORD', 'SAMPLE DEVELOPMENT RECEIPT', 'MEDICAL ASSISTANCE SAMPLE FORM', 'BURIAL ASSISTANCE SAMPLE FORM', 'TRANSPORT ASSISTANCE SAMPLE FORM')
    draw.text((90, 160), labels[index % len(labels)], fill='black', font=font(34, True))
    draw.line((90, 220, W-90, 220), fill='black', width=2)
    fields = [
        ('Reference No.:', f'TEST-AICS-{index+1:04d}'), ('Applicant Name:', f'TEST APPLICANT {index+1:03d}'),
        ('Address:', f'SAMPLE ADDRESS {index+1}, ANTIQUE'), ('Assistance Type:', ('MEDICAL', 'BURIAL', 'TRANSPORTATION')[index % 3] + ' ASSISTANCE'),
        ('Date:', f'SEPTEMBER {(index % 20)+1:02d}, 2026'), ('Requested Amount:', f'PHP {((index % 5)+1)*5000:,.2f}')]
    coords = {}; y = 280
    for label, value in fields:
        draw.text((110, y), label, fill='black', font=regular); draw.text((420, y), value, fill='black', font=regular)
        coords[label] = rect_for(draw, (420, y), value, regular); y += 82
    draw.rectangle((100, 820, W-100, 1040), outline='black', width=2)
    purpose = 'Purpose: Synthetic document created only for ADCD-Net development testing.'
    draw.text((120, 855), purpose, fill='black', font=small); coords['Purpose'] = rect_for(draw, (120, 855), purpose, small)
    draw.text((120, 930), 'Status: SAMPLE ONLY', fill='black', font=regular); coords['Status'] = rect_for(draw, (120, 930), 'Status: SAMPLE ONLY', regular)
    draw.line((700, 1290, 1080, 1290), fill='black', width=2); draw.text((720, 1305), 'Fictional Signature', fill='black', font=small); coords['Signature'] = [700, 1230, 1090, 1335]
    draw.rectangle((830, 1430, 1110, 1510), outline='black', width=3); draw.text((856, 1452), 'SAMPLE ONLY', fill='black', font=font(22, True))
    draw.text((100, 1620), 'FOR DEVELOPMENT TESTING ONLY - NO REAL PERSON OR BENEFIT RECORD', fill='black', font=small)
    return image, coords

def altered(image, coords, kind, index):
    out = image.copy(); mask = Image.new('L', image.size, 0); draw, md = ImageDraw.Draw(out), ImageDraw.Draw(mask)
    regular = font(29); target = {'amount_modification':'Requested Amount:', 'date_modification':'Date:', 'name_modification':'Applicant Name:', 'reference_modification':'Reference No.:', 'text_replacement':'Assistance Type:', 'text_removal':'Address:', 'copy_move':'Status', 'signature_region_edit':'Signature'}.get(kind, 'Purpose')
    box = coords[target]
    if kind == 'text_insertion': box = [160, 1090, 890, 1150]; replacement = 'Additional synthetic test note inserted'
    elif kind == 'pasted_text_region': box = [160, 1160, 900, 1225]; replacement = 'PASTED SYNTHETIC TEXT - TEST ONLY'
    elif kind == 'copy_move':
        source = coords['Status']; crop = image.crop(tuple(source)); destination = (300, 1120); out.paste(crop, destination); box = [destination[0], destination[1], destination[0]+crop.width, destination[1]+crop.height]; replacement = None
    elif kind == 'signature_region_edit': replacement = 'TEST SIGNATURE EDIT'
    else:
        replacement = {'amount_modification':'PHP 50,000.00', 'date_modification':'OCTOBER 31, 2026', 'name_modification':'TEST APPLICANT 999', 'reference_modification':'TEST-AICS-9999', 'text_replacement':'SYNTHETIC TEST SUPPORT', 'text_removal':''}.get(kind, 'SYNTHETIC TEXT REPLACED')
    if kind != 'copy_move':
        draw.rectangle(tuple(box), fill='white')
        if replacement: draw.text((box[0]+3, box[1]+3), replacement, fill='black', font=regular if kind != 'signature_region_edit' else font(24, True))
    md.rectangle(tuple(box), fill=255)
    return out, mask

def benign_variant(image, kind, index):
    if kind == 'jpeg_compression' or kind == 'mobile_compression':
        from io import BytesIO
        stream = BytesIO(); image.save(stream, 'JPEG', quality=38 if kind == 'mobile_compression' else 58); return Image.open(BytesIO(stream.getvalue())).convert('RGB')
    if kind == 'small_blur': return image.filter(ImageFilter.GaussianBlur(0.7))
    if kind == 'brightness': return ImageEnhance.Brightness(image).enhance(1.12)
    if kind == 'contrast': return ImageEnhance.Contrast(image).enhance(.82)
    if kind == 'minor_rotation': return image.rotate(1.2, resample=Image.Resampling.BICUBIC, fillcolor='white')
    if kind == 'resizing': return image.resize((1100, 1555), Image.Resampling.LANCZOS).resize((W,H), Image.Resampling.LANCZOS)
    if kind == 'grayscale': return image.convert('L').convert('RGB')
    if kind == 'perspective_like_resize': return image.resize((W-70,H-40), Image.Resampling.BICUBIC).resize((W,H), Image.Resampling.BICUBIC)
    # deterministic scanner-like noise
    import numpy as np
    rng = np.random.default_rng(index); array = np.asarray(image).astype('int16'); return Image.fromarray(np.clip(array + rng.normal(0, 5, array.shape), 0, 255).astype('uint8'))

def main():
    parser = argparse.ArgumentParser(); parser.add_argument('--root', required=True); args = parser.parse_args()
    root = Path(args.root); dirs = {name: root/name for name in ('clean','tampered','benign_variations','masks','metadata','results')}
    for folder in dirs.values(): folder.mkdir(parents=True, exist_ok=True)
    rows = []
    for index in range(20):
        base_id = f'base_{index+1:03d}'; image, coords = base_document(index); clean_path = dirs['clean'] / f'{base_id}.png'; image.save(clean_path)
        rows.append({'sample_id':base_id, 'base_document_id':base_id, 'label':'clean', 'tamper_type':'', 'image_path':str(clean_path), 'ground_truth_mask_path':'', 'benign_transform':'', 'notes':'fictional clean synthetic original'})
        for offset in range(2):
            kind = TYPES[(index*2+offset) % len(TYPES)]; sample_id = f'{base_id}_tamper_{offset+1}_{kind}'; variant, mask = altered(image, coords, kind, index); path = dirs['tampered']/f'{sample_id}.png'; mask_path = dirs['masks']/f'{sample_id}_mask.png'; variant.save(path); mask.save(mask_path)
            rows.append({'sample_id':sample_id, 'base_document_id':base_id, 'label':'tampered', 'tamper_type':kind, 'image_path':str(path), 'ground_truth_mask_path':str(mask_path), 'benign_transform':'', 'notes':'fictional controlled manipulation'})
        kind = BENIGN[index % len(BENIGN)]; sample_id = f'{base_id}_benign_{kind}'; path = dirs['benign_variations']/f'{sample_id}.png'; benign_variant(image, kind, index).save(path)
        rows.append({'sample_id':sample_id, 'base_document_id':base_id, 'label':'clean', 'tamper_type':'', 'image_path':str(path), 'ground_truth_mask_path':'', 'benign_transform':kind, 'notes':'fictional non-tampered image-quality variation'})
    (dirs['metadata']/ 'samples.json').write_text(json.dumps(rows, indent=2), encoding='utf-8')
    print(json.dumps({'clean_originals':20, 'tampered':40, 'benign_variations':20, 'total':len(rows)}))
if __name__ == '__main__': main()
