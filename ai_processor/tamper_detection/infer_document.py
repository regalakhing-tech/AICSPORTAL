"""ADCD-Net inference entry point; prints exactly one provider-neutral JSON object."""
from __future__ import annotations
import argparse, json, pickle, sys, tempfile
from pathlib import Path

def unavailable(message: str) -> int:
    print(json.dumps({'success': False, 'provider': 'adcd_net', 'status': 'unavailable', 'message': message}))
    return 0

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--images-json', required=True); parser.add_argument('--checkpoint', required=True)
    parser.add_argument('--docres-checkpoint', required=True); parser.add_argument('--qt-table', required=True)
    parser.add_argument('--device', default='auto')
    args = parser.parse_args()
    paths = [Path(item) for item in json.loads(args.images_json)]
    if not paths or not all(path.is_file() for path in paths): return unavailable('Input page is unavailable.')
    if not all(Path(value).is_file() for value in (args.checkpoint, args.docres_checkpoint, args.qt_table)): return unavailable('ADCD-Net checkpoint is unavailable.')
    try:
        import cv2, numpy as np, torch
        from PIL import Image
        from jpeg2dct.numpy import load as load_dct
        vendor = Path(__file__).parent / 'vendor' / 'adcd_net'
        sys.path.insert(0, str(vendor))
        import cfg
        cfg.docres_ckpt_path = args.docres_checkpoint
        from model.model import ADCDNet
        device = 'cuda' if args.device == 'auto' and torch.cuda.is_available() else args.device
        if device not in ('cpu', 'cuda') or (device == 'cuda' and not torch.cuda.is_available()): device = 'cpu'
        model = ADCDNet()
        checkpoint = torch.load(args.checkpoint, map_location='cpu', weights_only=True)
        state = checkpoint.get('model', checkpoint)
        state = {key.removeprefix('module.'): value for key, value in state.items()}
        missing, unexpected = model.load_state_dict(state, strict=False)
        if missing or unexpected: return unavailable('ADCD-Net checkpoint is incompatible.')
        model.to(device).eval()
        with open(args.qt_table, 'rb') as handle: qts = pickle.load(handle)
        qts = {int(key): np.asarray(value, dtype=np.int64) for key, value in qts.items()}
        all_regions, total_pixels, suspicious_pixels, peak = [], 0, 0, 0.0
        for page_index, path in enumerate(paths):
            image = Image.open(path).convert('RGB'); scale = min(1.0, 512.0 / max(image.size)); size = (max(8, int(image.width * scale) // 8 * 8), max(8, int(image.height * scale) // 8 * 8)); image = image.resize(size)
            with tempfile.NamedTemporaryFile(suffix='.jpg') as temp:
                image.save(temp.name, 'JPEG', quality=100); dct_blocks, _, _ = load_dct(temp.name, normalized=False)
                dct = np.asarray(dct_blocks).reshape(dct_blocks.shape[0], dct_blocks.shape[1], 8, 8).transpose(0, 2, 1, 3).reshape(size[1], size[0])
                qt = np.clip(qts.get(100, next(iter(qts.values()))), 0, 63)
            ocr_mask = np.zeros((size[1], size[0]), np.float32)
            try:
                from paddleocr import TextDetection
                detector = TextDetection(model_name='PP-OCRv5_server_det', enable_mkldnn=False)
                detected = detector.predict(np.asarray(image))
                for result in detected:
                    for polygon in getattr(result, 'polygons', []) or []: cv2.fillPoly(ocr_mask, [np.asarray(polygon, np.int32)], 1)
            except Exception: return unavailable('PaddleOCR text detection is unavailable.')
            rgb = np.asarray(image, np.float32).transpose(2, 0, 1) / 255.0
            tensors = [torch.from_numpy(rgb)[None].to(device), torch.from_numpy(np.clip(np.abs(dct), 0, 20).astype(np.int64))[None].to(device), torch.from_numpy(qt)[None].to(device), torch.zeros((1, size[1], size[0]), dtype=torch.long, device=device), torch.from_numpy(ocr_mask)[None, None].to(device)]
            with torch.inference_mode(), torch.autocast(device_type='cuda', enabled=device == 'cuda'):
                logits = model(*tensors, is_train=False)[0]
            scores = torch.softmax(logits, dim=1)[0, 1].float().cpu().numpy(); binary = (scores >= 0.5).astype(np.uint8)
            count, labels, stats, _ = cv2.connectedComponentsWithStats(binary, 8)
            total_pixels += binary.size; suspicious_pixels += int(binary.sum()); peak = max(peak, float(scores.max()))
            for label in range(1, count):
                x, y, w, h, area = stats[label]
                if area < 20: continue
                component = labels == label; all_regions.append({'page': page_index + 1, 'x': int(x), 'y': int(y), 'width': int(w), 'height': int(h), 'area_pixels': int(area), 'max_score': float(scores[component].max()), 'mean_score': float(scores[component].mean()), 'ocr_overlap_ratio': float(ocr_mask[component].mean())})
        print(json.dumps({'success': True, 'provider': 'adcd_net', 'status': 'possible_tampering' if all_regions else 'no_significant_tampering', 'suspicious_pixel_ratio': suspicious_pixels / max(1, total_pixels), 'max_tamper_score': peak, 'regions': all_regions, 'mask_available': True}))
        return 0
    except Exception as exc:
        return unavailable(type(exc).__name__)

if __name__ == '__main__':
    raise SystemExit(main())
