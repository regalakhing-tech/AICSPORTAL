"""Offline Stage 8 evaluator; it never opens AICS uploads or database records."""
from __future__ import annotations
import argparse, csv, json, math, shutil, statistics, time
from pathlib import Path
from types import SimpleNamespace
import numpy as np
from PIL import Image
from ai_processor.ocr_engine import extract_document_text
from ai_processor.tamper_detection.adapter import analyze_tampering

RATIO_THRESHOLDS = (0.00001, 0.00005, 0.0001, 0.0005, 0.001, 0.002, 0.005, 0.01)
PIXEL_THRESHOLDS = (0.40, 0.45, 0.50, 0.55, 0.60)

def finite(value): return float(value) if value is not None and math.isfinite(float(value)) else None
def stats(values):
    values = [float(v) for v in values if finite(v) is not None]
    return {'count':len(values), **({} if not values else {'mean':statistics.mean(values),'median':statistics.median(values),'min':min(values),'max':max(values)})}
def metrics(rows, threshold):
    tp=fp=tn=fn=0
    for row in rows:
        predicted = row['tampered_pixel_ratio'] >= threshold; actual = row['label'] == 'tampered'
        if predicted and actual: tp += 1
        elif predicted: fp += 1
        elif actual: fn += 1
        else: tn += 1
    div=lambda n,d: n/d if d else None
    recall, specificity, precision = div(tp,tp+fn), div(tn,tn+fp), div(tp,tp+fp)
    return {'threshold':threshold,'tp':tp,'fp':fp,'tn':tn,'fn':fn,'recall':recall,'specificity':specificity,'precision':precision,'false_positive_rate':div(fp,fp+tn),'false_negative_rate':div(fn,fn+tp),'f1':div(2*tp,2*tp+fp+fn),'accuracy':div(tp+tn,tp+tn+fp+fn)}
def auc(rows):
    pos=[r['tampered_pixel_ratio'] for r in rows if r['label']=='tampered']; neg=[r['tampered_pixel_ratio'] for r in rows if r['label']=='clean']
    if not pos or not neg: return None
    return sum(1 if p>n else .5 if p==n else 0 for p in pos for n in neg)/(len(pos)*len(neg))
def ocr_mask_pixels(shape, boxes):
    mask=np.zeros(shape[:2], dtype=np.uint8)
    for box in boxes:
        if isinstance(box,list) and len(box)==4:
            x1,y1,x2,y2=(round(v) for v in box); mask[max(0,y1):min(mask.shape[0],y2+1),max(0,x1):min(mask.shape[1],x2+1)]=1
    return int(mask.sum())
def pixels(predicted, truth):
    p=predicted.astype(bool); t=truth.astype(bool); inter=int(np.logical_and(p,t).sum()); ps=int(p.sum()); ts=int(t.sum()); union=int(np.logical_or(p,t).sum())
    return {'iou':inter/union if union else None,'dice':2*inter/(ps+ts) if ps+ts else None,'pixel_precision':inter/ps if ps else 0.0,'pixel_recall':inter/ts if ts else 0.0}
def clean_artifacts(page):
    artifacts=page.pop('_calibration_artifacts', {})
    for value in artifacts.values():
        try:
            path=Path(value)
            if path.is_file(): shutil.rmtree(path.parent, ignore_errors=True)
        except (TypeError, OSError): pass

def main():
    parser=argparse.ArgumentParser(); parser.add_argument('--root', type=Path, required=True); parser.add_argument('--tesseract', required=True); parser.add_argument('--service-url', default='http://127.0.0.1:5001'); args=parser.parse_args()
    root=args.root.resolve(); rows=json.loads((root/'metadata'/'samples.json').read_text(encoding='utf-8')); results=root/'results'; results.mkdir(exist_ok=True)
    settings=SimpleNamespace(adcd_enabled=True,adcd_service_url=args.service_url,adcd_connect_timeout_seconds=3,adcd_read_timeout_seconds=90)
    started=time.perf_counter(); evaluated=[]; locations={threshold:[] for threshold in PIXEL_THRESHOLDS}
    for number, source in enumerate(rows,1):
        print(f'[{number}/{len(rows)}] processing {source["sample_id"]}', flush=True)
        try: image=np.asarray(Image.open(source['image_path']).convert('RGB'))
        except (OSError, ValueError) as exc: raise RuntimeError('CALIBRATION_IMAGE_UNREADABLE') from exc
        # The text return is deliberately discarded: calibration records boxes/counts only.
        _, _, metadata=extract_document_text([image], args.tesseract)
        boxes=metadata['ocr_boxes_by_page'][0]
        response=analyze_tampering([image], settings, metadata, include_calibration_artifacts=True)
        provider=response['providers']['adcd_net']
        if provider['status'] != 'completed': raise RuntimeError(provider.get('error','ADCD_SERVICE_UNAVAILABLE'))
        page=provider['pages'][0]; artifacts=page.get('_calibration_artifacts', {})
        prediction_path=Path(artifacts.get('probability_path','')); probability=np.asarray(Image.open(prediction_path).convert('L')) if prediction_path.is_file() else None
        record={key:source.get(key,'') for key in ('sample_id','base_document_id','label','tamper_type','benign_transform')}
        record.update({key:provider[key] for key in ('tampered_pixel_ratio','max_tamper_probability','mean_tamper_probability','processing_time_seconds')}); record.update({'ocr_box_count':len(boxes),'ocr_mask_nonzero_pixels':ocr_mask_pixels(image.shape,boxes)})
        evaluated.append(record)
        if source['label']=='tampered':
            truth=np.asarray(Image.open(source['ground_truth_mask_path']).convert('L'))
            if probability is None: raise RuntimeError('CALIBRATION_LOCALIZATION_ARTIFACT_UNAVAILABLE')
            if probability.shape != truth.shape: probability=np.asarray(Image.fromarray(probability).resize((truth.shape[1],truth.shape[0]),Image.Resampling.BILINEAR))
            for threshold in PIXEL_THRESHOLDS: locations[threshold].append({'sample_id':source['sample_id'], 'pixel_threshold':threshold, **pixels(probability/255.0 >= threshold, truth > 0)})
        clean_artifacts(page) # transient service files are never retained or persisted.
    fields=list(evaluated[0]);
    with (results/'calibration-results.csv').open('w',newline='',encoding='utf-8') as handle: writer=csv.DictWriter(handle,fieldnames=fields); writer.writeheader(); writer.writerows(evaluated)
    threshold_rows=[metrics(evaluated,t) for t in RATIO_THRESHOLDS]
    with (results/'threshold-metrics.csv').open('w',newline='',encoding='utf-8') as handle: writer=csv.DictWriter(handle,fieldnames=list(threshold_rows[0])); writer.writeheader(); writer.writerows(threshold_rows)
    localization=[row for group in locations.values() for row in group]
    with (results/'localization-metrics.csv').open('w',newline='',encoding='utf-8') as handle: writer=csv.DictWriter(handle,fieldnames=list(localization[0])); writer.writeheader(); writer.writerows(localization)
    per_type=[]
    for kind in sorted({r['tamper_type'] for r in evaluated if r['tamper_type']}):
        group=[r for r in evaluated if r['tamper_type']==kind]; per_type.append({'tamper_type':kind,'sample_count':len(group),'average_tampered_pixel_ratio':statistics.mean(r['tampered_pixel_ratio'] for r in group),'average_max_probability':statistics.mean(r['max_tamper_probability'] for r in group)})
    with (results/'per-tamper-type.csv').open('w',newline='',encoding='utf-8') as handle: writer=csv.DictWriter(handle,fieldnames=list(per_type[0])); writer.writeheader(); writer.writerows(per_type)
    benign=[]
    for kind in sorted({r['benign_transform'] for r in evaluated if r['benign_transform']}):
        group=[r for r in evaluated if r['benign_transform']==kind]; benign.append({'benign_transform':kind,'sample_count':len(group),'average_tampered_pixel_ratio':statistics.mean(r['tampered_pixel_ratio'] for r in group),'average_max_probability':statistics.mean(r['max_tamper_probability'] for r in group)})
    with (results/'benign-transform-results.csv').open('w',newline='',encoding='utf-8') as handle: writer=csv.DictWriter(handle,fieldnames=list(benign[0])); writer.writeheader(); writer.writerows(benign)
    groups={name:[r for r in evaluated if (r['label']=='tampered' if name=='tampered' else r['benign_transform']!='' if name=='benign_variations' else r['label']=='clean' and not r['benign_transform'])] for name in ('clean_originals','benign_variations','tampered')}
    distributions={name:{metric:stats([r[metric] for r in group]) for metric in ('tampered_pixel_ratio','max_tamper_probability','mean_tamper_probability')} for name,group in groups.items()}
    baseline=[row for row in localization if row['pixel_threshold']==.5]
    candidate=next((row for row in threshold_rows if (row['recall'] or 0)>=.8 and (row['specificity'] or 0)>=.8 and (row['precision'] or 0)>=.7),None)
    summary={'stage':'8_offline_calibration','synthetic_only':True,'total_execution_seconds':time.perf_counter()-started,'sample_counts':{name:len(group) for name,group in groups.items()},'distributions':distributions,'threshold_metrics':threshold_rows,'roc_auc_ratio':auc(evaluated),'localization_baseline_0_5':{metric:stats([r[metric] for r in baseline]) for metric in ('iou','dice','pixel_precision','pixel_recall')},'experimental_pixel_thresholds':{str(t):{metric:stats([r[metric] for r in values]) for metric in ('iou','dice','pixel_precision','pixel_recall')} for t,values in locations.items()},'per_tamper_type':per_type,'benign_transforms':benign,'performance_seconds':stats([r['processing_time_seconds'] for r in evaluated]),'candidate_staff_review_threshold':candidate,'recommendation':('Candidate is validation-only and means staff review recommended; no application decision.' if candidate else 'No sufficiently reliable review threshold was identified from the current validation set.')}
    (results/'calibration-summary.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
    print(json.dumps({'samples':len(evaluated),'candidate_staff_review_threshold':candidate['threshold'] if candidate else None,'total_seconds':summary['total_execution_seconds']}),flush=True)
if __name__=='__main__': main()
