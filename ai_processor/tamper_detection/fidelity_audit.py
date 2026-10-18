"""Stage 9 isolated geometry and OCR-mask audit; never processes AICS uploads."""
from __future__ import annotations
import argparse, importlib.util, json, shutil, sys
from pathlib import Path
from types import SimpleNamespace
import cv2, numpy as np
from PIL import Image, ImageDraw
from ai_processor.ocr_engine import extract_document_text
from ai_processor.tamper_detection.adapter import analyze_tampering

def mask_from_boxes(shape, boxes, expand=0.0):
    mask=np.zeros(shape[:2],dtype=np.uint8)
    for x1,y1,x2,y2 in boxes:
        w,h=x2-x1,y2-y1; x1=max(0,int(x1-w*expand)); y1=max(0,int(y1-h*expand)); x2=min(shape[1],int(x2+w*expand)); y2=min(shape[0],int(y2+h*expand))
        mask[y1:y2,x1:x2]=255
    return mask
def loc(pred, truth):
    p=pred>0; t=truth>0; i=int((p&t).sum()); ps=int(p.sum()); ts=int(t.sum()); u=int((p|t).sum())
    return {'iou':i/u if u else None,'dice':2*i/(ps+ts) if ps+ts else None,'pixel_precision':i/ps if ps else 0.,'pixel_recall':i/ts if ts else 0.}
def copy_artifacts(page, destination):
    raw=page.pop('_calibration_artifacts'); copied={}
    for label, value in raw.items():
        source=Path(value)
        if source.is_file():
            target=destination/source.name; shutil.copy2(source,target); copied[label]=str(target)
            if label == 'mask_path': request_dir=source.parent
    if 'request_dir' in locals(): shutil.rmtree(request_dir,ignore_errors=True)
    return copied
def main():
    p=argparse.ArgumentParser(); p.add_argument('--dataset-root',type=Path,required=True); p.add_argument('--output-root',type=Path,required=True); p.add_argument('--adcd-root',type=Path,required=True); p.add_argument('--tesseract',required=True); args=p.parse_args()
    out=args.output_root.resolve(); (out/'alignment_debug').mkdir(parents=True,exist_ok=True); (out/'ocr_comparison').mkdir(exist_ok=True); (out/'metrics').mkdir(exist_ok=True)
    sys.path.insert(0, str(args.adcd_root))
    spec=importlib.util.spec_from_file_location('stage9_aics_inference',args.adcd_root/'aics_inference.py'); module=importlib.util.module_from_spec(spec); sys.modules[spec.name]=module; spec.loader.exec_module(module)
    rows=[r for r in json.loads((args.dataset_root/'metadata'/'samples.json').read_text()) if r['label']=='tampered'][:3]
    settings=SimpleNamespace(adcd_enabled=True,adcd_service_url='http://127.0.0.1:5001',adcd_connect_timeout_seconds=3,adcd_read_timeout_seconds=90); records=[]
    for source in rows:
        image=cv2.imread(source['image_path']); _,_,metadata=extract_document_text([image],args.tesseract); boxes=metadata['ocr_boxes_by_page'][0]
        word=mask_from_boxes(image.shape,boxes); expanded=mask_from_boxes(image.shape,boxes,.1); truth=np.asarray(Image.open(source['ground_truth_mask_path']).convert('L'))
        Image.fromarray(word).save(out/'ocr_comparison'/f'{source["sample_id"]}_tesseract_word.png'); Image.fromarray(expanded).save(out/'ocr_comparison'/f'{source["sample_id"]}_expanded_10pct.png')
        _,_,prep=module._prepare_input(Path(source['image_path']),boxes)
        variants={}
        for name, supplied in (('tesseract_word',None),('expanded_tesseract_10pct',expanded)):
            result=analyze_tampering([image],settings,metadata,include_calibration_artifacts=True,calibration_ocr_masks=[supplied] if supplied is not None else None)['providers']['adcd_net']; page=result['pages'][0]; dest=out/'ocr_comparison'/source['sample_id']/name; dest.mkdir(parents=True,exist_ok=True); artifacts=copy_artifacts(page,dest)
            binary=np.asarray(Image.open(artifacts['mask_path']).convert('L')); variants[name]={key:result[key] for key in ('tampered_pixel_ratio','max_tamper_probability','mean_tamper_probability')}; variants[name].update(loc(binary,truth)); variants[name]['artifacts']=artifacts
            if name=='tesseract_word':
                probability=Image.open(artifacts['probability_path']).convert('RGB'); edge=cv2.Canny(truth,50,150)>0; array=np.asarray(probability).copy(); array[edge]=[0,255,0]; Image.fromarray(array).save(out/'alignment_debug'/f'{source["sample_id"]}_prediction_with_gt_boundary.png')
                (out/'alignment_debug'/f'{source["sample_id"]}_ground_truth.png').write_bytes(Path(source['ground_truth_mask_path']).read_bytes())
        records.append({'sample_id':source['sample_id'],'original_dimensions':[int(image.shape[1]),int(image.shape[0])],'resized_dimensions':[prep['resized_width'],prep['resized_height']],'padded_model_dimensions':[prep['model_width'],prep['model_height']],'raw_output_dimensions':[prep['model_width'],prep['model_height']],'output_crop_dimensions':[prep['resized_width'],prep['resized_height']],'restored_dimensions':[int(image.shape[1]),int(image.shape[0])],'ground_truth_dimensions':[int(truth.shape[1]),int(truth.shape[0])],'ocr_box_count':len(boxes),'variants':variants})
    (out/'metrics'/'alignment-and-ocr-audit.json').write_text(json.dumps({'samples':records,'official_paddle_ocr_available':False,'official_precomputed_masks_available':False},indent=2))
    print(json.dumps({'samples':len(records),'output_root':str(out)}))
if __name__=='__main__': main()
