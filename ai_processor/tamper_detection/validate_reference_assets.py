"""Read-only Stage 10 validation for supplied DocTamper/ADCD reference assets."""
from __future__ import annotations
import argparse, json, pickle, sys
from pathlib import Path

SETS=('TrainingSet','TestingSet','FCD','SCD')
def describe(value):
    result={'object_type':type(value).__name__}
    try: result['record_count']=len(value)
    except TypeError: pass
    if isinstance(value,(list,tuple)) and value:
        first=value[0]; result['representative_record_type']=type(first).__name__
        if isinstance(first,(list,tuple)): result['representative_record_length']=len(first); result['representative_field_types']=[type(v).__name__ for v in first[:4]]
        elif isinstance(first,dict): result['representative_keys']=sorted(str(k) for k in first)[:12]
    return result
def load_pickle(path):
    with path.open('rb') as handle: return pickle.load(handle)
def main():
    parser=argparse.ArgumentParser(); parser.add_argument('--assets-root',type=Path,required=True); parser.add_argument('--adcd-root',type=Path,required=True); args=parser.parse_args(); root=args.assets_root.resolve()
    sys.path.insert(0,str(args.adcd_root)); from ds import load_qt
    qt_path=root/'qt_table.pk'; qts=load_qt(str(qt_path)); qt={'path':str(qt_path),'file_size_bytes':qt_path.stat().st_size,'loader':'ds.load_qt (official ADCD-Net mechanism)','object_type':type(qts).__name__,'record_count':len(qts),'keys_or_indices':list(qts.keys())[:12] if isinstance(qts,dict) else None}
    if isinstance(qts,dict) and 100 in qts:
        value=qts[100]; qt.update({'qf_100_type':type(value).__name__,'qf_100_shape':list(value.shape),'qf_100_dtype':str(value.dtype),'get_qt_usage':'GeneralValDs selects qts[qfs[-1]] then clamps to [0,63].'})
    pks=[]
    for path in sorted((root/'pks').glob('*.pk')):
        value=load_pickle(path); entry={'filename':path.name,'file_size_bytes':path.stat().st_size,**describe(value)}
        if isinstance(value,dict) and value:
            key=next(iter(value)); record=value[key]; entry.update({'representative_key_type':type(key).__name__,'representative_value_type':type(record).__name__,'representative_value_length':len(record) if hasattr(record,'__len__') else None})
        pks.append(entry)
    ocr={}
    for name in SETS:
        directory=root/'DocTamperOCR'/name; files=[p for p in directory.rglob('*') if p.is_file()]; by_ext={}
        for path in files: by_ext[path.suffix.lower()]=by_ext.get(path.suffix.lower(),0)+1
        sample=next((p for p in files if p.suffix.lower()=='.pkl'),None); entry={'path':str(directory),'file_count':len(files),'subdirectories':sorted(str(p.relative_to(directory)) for p in directory.iterdir() if p.is_dir()),'extensions':by_ext}
        if sample:
            entry.update({'representative_file':str(sample.relative_to(directory)),'official_ds_logic':'pickle.load -> c_bbox; bbox_2_mask(c_bbox, h, w, expand_ratio=0.1)','decodable_by_ds':True,**describe(load_pickle(sample))})
        else: entry.update({'decodable_by_ds':False,'reason':'No .pkl OCR-character-box files found.'})
        ocr[name]=entry
    print(json.dumps({'qt_table':qt,'pks':pks,'ocr':ocr},indent=2,default=str))
if __name__=='__main__': main()
