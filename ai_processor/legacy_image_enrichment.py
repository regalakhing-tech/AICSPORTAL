"""CLI-only, sequential ADCD enrichment for immutable legacy reviews."""
import argparse, json
from pathlib import Path
from .config import SETTINGS, validate_worker_configuration
from .database import connect
from .document_loader import resolve_path, load_images
from .ocr_engine import extract_document_text
from .tamper_detection.adapter import analyze_tampering
from .tamper_detection.artifacts import persist_private_artifacts
from .tamper_detection.postprocessing import persisted_finding

TERMINAL = ('completed', 'likely_valid', 'needs_staff_review', 'needs_reupload', 'flagged_inconsistency')

def candidates(db):
    with db.cursor() as cur:
        cur.execute("""SELECT ai.id review_id,d.id document_id,d.file_path,d.mime_type,ai.findings
        FROM document_ai_reviews ai JOIN aics_request_documents d ON d.id=ai.request_document_id
        LEFT JOIN document_ai_review_image_supplements s ON s.ai_review_id=ai.id
        WHERE ai.status IN %s AND (s.id IS NULL OR (s.status='unavailable' AND s.error_code='service_disabled')) ORDER BY ai.id""", (TERMINAL,))
        rows=cur.fetchall()
    result=[]
    for row in rows:
        try: raw=json.loads(row.get('findings') or '{}')
        except json.JSONDecodeError: raw={}
        native=raw.get('tamper_detection') if isinstance(raw,dict) else None
        if isinstance(native,dict) and not (native.get('status')=='unavailable' and native.get('error_code')=='service_disabled'): continue
        result.append(row)
    return result

def save(db, review_id, status, tamper, error=None):
    payload=json.dumps(tamper, allow_nan=False)
    with db.cursor() as cur:
        cur.execute("INSERT INTO document_ai_review_image_supplements (ai_review_id,status,tamper_detection,source,error_code) VALUES (%s,%s,%s,'legacy_image_enrichment',%s) ON DUPLICATE KEY UPDATE status=VALUES(status),tamper_detection=VALUES(tamper_detection),source=VALUES(source),error_code=VALUES(error_code),updated_at=NOW()",(review_id,status,payload,error))
    db.commit()

def run(execute=False, limit=0):
    validate_worker_configuration(); db=connect(SETTINGS); rows=candidates(db)
    if limit > 0: rows=rows[:limit]
    report={'legacy_reviews_scanned':len(rows),'eligible':len(rows),'completed':0,'failed':0,'missing_source':0,'already_current':0,'would_process':len(rows)}
    if not execute: print(json.dumps(report,indent=2)); db.close(); return
    for row in rows:
        review_id=int(row['review_id']); document_id=int(row['document_id'])
        try:
            path=resolve_path(SETTINGS.project_root,SETTINGS.upload_root,row['file_path'],document_id)
            images=load_images(path,SETTINGS.max_pdf_pages,SETTINGS.poppler_path,SETTINGS.pdf_timeout_seconds,document_id)
            _,_,ocr=extract_document_text(images,SETTINGS.tesseract_path,SETTINGS.ocr_timeout_seconds)
            result=persist_private_artifacts(analyze_tampering(images,SETTINGS,ocr),review_id,SETTINGS)
            tamper=persisted_finding(result)
            if tamper.get('status') != 'completed':
                save(db,review_id,'unavailable',tamper,tamper.get('error_code')); report['failed']+=1
            else:
                save(db,review_id,'completed',tamper); report['completed']+=1
        except RuntimeError as exc:
            code=str(exc); save(db,review_id,'unavailable',{'model':'ADCD-Net','integration_version':1,'status':'unavailable','error_code':code.lower()},code)
            report['failed']+=1; report['missing_source']+= int(code=='AI_DOCUMENT_FILE_NOT_FOUND')
        except Exception:
            save(db,review_id,'unavailable',{'model':'ADCD-Net','integration_version':1,'status':'unavailable','error_code':'inference_error'},'AI_IMAGE_ENRICHMENT_ERROR'); report['failed']+=1
    print(json.dumps(report,indent=2)); db.close()

def repair_artifacts(execute=False):
    """Recover private PNGs from the exact retained same-pass ADCD result."""
    validate_worker_configuration(); db=connect(SETTINGS)
    with db.cursor() as cur:
        cur.execute("""SELECT ai.id review_id,ai.findings,s.id supplement_id,s.tamper_detection supplement_tamper
        FROM document_ai_reviews ai LEFT JOIN document_ai_review_image_supplements s ON s.ai_review_id=ai.id
        WHERE JSON_UNQUOTE(JSON_EXTRACT(ai.findings,'$.tamper_detection.status'))='completed' OR s.status='completed' ORDER BY ai.id""")
        rows=cur.fetchall()
    service_results=[]
    for result_file in Path(SETTINGS.adcd_output_root).glob('*/result.json'):
        try: service_results.append(json.loads(result_file.read_text(encoding='utf-8')))
        except Exception: continue
    report={'scanned':len(rows),'would_repair':0,'repaired':0,'already_available':0,'same_pass_result_missing':0}
    for row in rows:
        findings=json.loads(row.get('findings') or '{}'); native=findings.get('tamper_detection') if isinstance(findings,dict) else None
        supplement=json.loads(row.get('supplement_tamper') or 'null'); use_native=isinstance(native,dict) and native.get('status')=='completed'; tamper=native if use_native else supplement
        if not isinstance(tamper,dict) or tamper.get('status')!='completed': continue
        pages=tamper.get('pages') if isinstance(tamper.get('pages'),list) else []
        if pages and all(p.get('artifacts',{}).get('signal',{}).get('available') is True and p.get('artifacts',{}).get('threshold',{}).get('available') is True for p in pages): report['already_available']+=1; continue
        matched=[]
        for page in pages:
            found=None
            for item in service_results:
                output=item.get('output',{})
                if all(abs(float(a)-float(b)) < 1e-9 for a,b in ((output.get('max_probability',-1),page.get('max_tamper_probability',-2)),(output.get('mean_probability',-1),page.get('mean_tamper_probability',-2)),(item.get('processing_time_seconds',-1),page.get('processing_time_seconds',-2)))):
                    found=item; break
            if found is None: matched=[]; break
            matched.append({**page,'_internal_artifacts':found.get('artifacts',{})})
        if not matched: report['same_pass_result_missing']+=1; continue
        report['would_repair']+=1
        if not execute: continue
        provider={'status':'completed','pages':matched}
        repaired=persist_private_artifacts({'providers':{'adcd_net':provider}},int(row['review_id']),SETTINGS)['providers']['adcd_net']['pages']
        if not all(p.get('artifacts',{}).get('signal',{}).get('available') is True and p.get('artifacts',{}).get('threshold',{}).get('available') is True for p in repaired): continue
        tamper['pages']=repaired
        with db.cursor() as cur:
            if use_native:
                findings['tamper_detection']=tamper; cur.execute('UPDATE document_ai_reviews SET findings=%s WHERE id=%s',(json.dumps(findings,allow_nan=False),row['review_id']))
            else: cur.execute('UPDATE document_ai_review_image_supplements SET tamper_detection=%s,updated_at=NOW() WHERE id=%s',(json.dumps(tamper,allow_nan=False),row['supplement_id']))
        db.commit(); report['repaired']+=1
    print(json.dumps(report,indent=2)); db.close()

if __name__=='__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--dry-run',action='store_true'); parser.add_argument('--execute',action='store_true'); parser.add_argument('--process',action='store_true'); parser.add_argument('--repair-artifacts',action='store_true'); parser.add_argument('--limit',type=int,default=0); args=parser.parse_args()
    if args.execute and not args.process and not args.repair_artifacts: parser.error('--execute requires --process for the sequential worker')
    repair_artifacts(args.execute) if args.repair_artifacts else run(args.execute,args.limit)
