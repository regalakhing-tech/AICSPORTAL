"""Stage 10 offline ADCD-Net reference evaluator; no AICS records or uploads."""
from __future__ import annotations
import argparse, json, pickle, sys, time
from copy import deepcopy
from pathlib import Path

REQUIRED_OCR = ('TrainingSet', 'TestingSet', 'FCD', 'SCD')
REQUIRED_DATASETS = ('TestingSet', 'FCD', 'SCD')

def asset_status(root: Path) -> dict:
    root=root.resolve(); status={'root':str(root),'qt_table':str(root/'qt_table.pk'),'pks':str(root/'pks'),'ocr_root':str(root/'DocTamperOCR')}
    status['qt_table_readable']=(root/'qt_table.pk').is_file()
    status['pks_readable']=(root/'pks').is_dir() and any((root/'pks').glob('*.pk'))
    status['ocr_sets']={name:(root/'DocTamperOCR'/name).is_dir() for name in REQUIRED_OCR}
    status['ready']=status['qt_table_readable'] and status['pks_readable'] and all(status['ocr_sets'].values())
    return status

def require_assets(root: Path):
    status=asset_status(root)
    if not status['ready']: raise FileNotFoundError('REFERENCE_ASSETS_INCOMPLETE: provide qt_table.pk, pks/*.pk, and DocTamperOCR/{TrainingSet,TestingSet,FCD,SCD}.')
    return status

def require_sample_list(path: Path):
    if not path.is_file(): raise FileNotFoundError('REFERENCE_DATASET_UNAVAILABLE: authorized DocTamper images, GT masks, official OCR masks, and an explicit sample-list JSON are required.')
    rows=json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(rows,list) or not rows: raise ValueError('REFERENCE_SAMPLE_LIST_INVALID')
    for row in rows:
        if not isinstance(row,dict) or not all(Path(str(row.get(key,''))).is_file() for key in ('image_path','ground_truth_mask_path','ocr_mask_path')): raise FileNotFoundError('REFERENCE_SAMPLE_FILES_MISSING')
        if not isinstance(row.get('jpeg_record'),list) or not row['jpeg_record']: raise ValueError('REFERENCE_JPEG_RECORD_MISSING')
    return rows

def metrics(probability, truth, threshold=.5):
    import numpy as np
    binary=probability>=threshold; truth=truth.astype(bool); inter=int((binary&truth).sum()); p=int(binary.sum()); t=int(truth.sum()); union=int((binary|truth).sum())
    return {'iou':inter/union if union else None,'dice':2*inter/(p+t) if p+t else None,'pixel_precision':inter/p if p else 0.,'pixel_recall':inter/t if t else 0.,'probability_min':float(probability.min()),'probability_max':float(probability.max()),'probability_mean':float(probability.mean()),'tampered_pixel_ratio':float(binary.mean())}

def literal_prepare(row: dict, adcd_root: Path, assets_root: Path):
    """Mirror GeneralValDs + pad_collate for one authorized reference sample."""
    import albumentations as A, cv2, numpy as np
    from PIL import Image
    sys.path.insert(0, str(adcd_root))
    from ds import load_qt, mask_totsr, img_totsr, multi_jpeg, pad_collate
    image=cv2.imread(row['image_path']); truth=(cv2.imread(row['ground_truth_mask_path'],0)!=0).astype(np.uint8); ocr=(cv2.imread(row['ocr_mask_path'],0)!=0).astype(np.uint8)
    if image is None or truth is None or ocr is None: raise FileNotFoundError('REFERENCE_SAMPLE_FILES_MISSING')
    h,w=image.shape[:2]
    if h>512 or w>512:
        resized=A.LongestMaxSize(512,p=1.0)(image=image,mask=truth,mask2=ocr); image,truth,ocr=resized['image'],resized['mask'],resized['mask2']
    # Official GeneralValDs: BGR array is passed to PIL, then multi_jpeg
    # converts to grayscale before applying the supplied JPEG QF sequence.
    dct, jpeg_image, qfs=multi_jpeg(deepcopy(Image.fromarray(image)),num_jpeg=-1,min_qf=-1,upper_bound=-1,jpeg_record=list(row['jpeg_record']))
    qts=load_qt(str(assets_root/'qt_table.pk')); item={'img':img_totsr(jpeg_image),'dct':np.clip(np.abs(dct),0,20),'qt':qts[qfs[-1]].clamp(0,63),'mask':mask_totsr(image=truth.copy())['image'].long(),'ocr_mask':mask_totsr(image=ocr.copy())['image'].long(),'img_name':str(row.get('sample_id','reference'))}
    batch=pad_collate([item])
    return batch, truth

def load_reference_model(adcd_root: Path):
    """Official ADCDNet constructor, fixed CPU checkpoints, no main.py/DDP."""
    import torch
    sys.path.insert(0,str(adcd_root)); import cfg
    cfg.docres_ckpt_path=str(adcd_root/'checkpoints'/'docres.pkl')
    from model.model import ADCDNet
    state=torch.load(adcd_root/'checkpoints'/'ADCDNet.pth',map_location='cpu',weights_only=True)['model']; model=ADCDNet(); incompatible=model.load_state_dict({k[7:] if k.startswith('module.') else k:v for k,v in state.items()},strict=False)
    if incompatible.missing_keys or incompatible.unexpected_keys: raise RuntimeError('REFERENCE_CHECKPOINT_MISMATCH')
    return model.eval()

def evaluate_literal(row: dict, model, adcd_root: Path, assets_root: Path):
    import torch
    batch,truth=literal_prepare(row,adcd_root,assets_root); img,dct,qt,mask,ocr_mask,_,sizes=batch; started=time.perf_counter()
    with torch.inference_mode(): logits=model(img,dct,qt,mask,ocr_mask,is_train=False)[0]; probability=torch.softmax(logits,dim=1)[0,1].cpu().numpy()
    h,w=(int(v) for v in sizes[0]); probability=probability[:h,:w]
    return {**metrics(probability,truth), 'processing_time_seconds':time.perf_counter()-started, 'probability':probability}

def main():
    parser=argparse.ArgumentParser(); parser.add_argument('--assets-root',type=Path,required=True); parser.add_argument('--sample-list',type=Path); parser.add_argument('--validate-only',action='store_true'); args=parser.parse_args()
    status=asset_status(args.assets_root)
    if args.validate_only: print(json.dumps(status,indent=2)); return
    require_assets(args.assets_root); rows=require_sample_list(args.sample_list)
    # Evaluation execution intentionally waits for authorized assets. The sample
    # list is explicit so no repository path guessing can select wrong data.
    print(json.dumps({'status':'ready_for_literal_reference_evaluation','samples':len(rows),'pixel_threshold':.5,'assets':status},indent=2))
if __name__=='__main__': main()
