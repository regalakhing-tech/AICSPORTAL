"""Private same-pass ADCD visualization artifact handling."""
from __future__ import annotations
import shutil, uuid
from pathlib import Path


def persist_review_pages(images: list, review_id: int, settings) -> list[dict]:
    """Store rendered pages behind the authenticated artifact endpoint."""
    if review_id <= 0:
        return []
    import cv2
    root = Path(settings.ai_review_artifact_root).resolve()
    project = str(getattr(settings, 'project_root', '')).strip()
    if project and _inside(root, Path(project)):
        return [{'page': index, 'available': False} for index in range(1, len(images) + 1)]
    pages = []
    for index, image in enumerate(images, 1):
        available = False
        try:
            if image is not None and getattr(image, 'size', 0):
                ok, encoded = cv2.imencode('.png', image)
                if ok and len(encoded) <= 20_000_000:
                    directory = root / f'review_{review_id}' / f'page_{index}'
                    directory.mkdir(parents=True, exist_ok=True)
                    temporary = directory / ('original-' + uuid.uuid4().hex + '.tmp')
                    temporary.write_bytes(encoded.tobytes())
                    temporary.replace(directory / 'original-page.png')
                    available = True
        except (OSError, cv2.error):
            available = False
        pages.append({'page': index, 'available': available})
    return pages

def _inside(path: Path, root: Path) -> bool:
    try: path.resolve().relative_to(root.resolve()); return True
    except ValueError: return False

def persist_private_artifacts(result: dict, review_id: int, settings) -> dict:
    provider = result.get('providers', {}).get('adcd_net', {})
    if provider.get('status') != 'completed' or review_id <= 0: return result
    root = Path(settings.ai_review_artifact_root).resolve()
    project = str(getattr(settings, 'project_root', '')).strip()
    if project and _inside(root, Path(project)):
        for page in provider.get('pages', []): page['artifacts'] = {'artifact_status': 'unavailable'}
        return result
    configured_service_root = str(getattr(settings, 'adcd_output_root', '')).strip()
    if not configured_service_root:
        for page in provider.get('pages', []): page['artifacts'] = {'artifact_status': 'unavailable'}
        return result
    service_root = Path(configured_service_root).resolve()
    if not service_root.is_dir():
        for page in provider.get('pages', []): page['artifacts'] = {'artifact_status': 'unavailable'}
        return result
    # Keep staging alongside, rather than inside, the destination.  Otherwise
    # the final replacement would delete its own staging directory.
    staging = root / ('.staging_review_' + str(review_id) + '_' + uuid.uuid4().hex)
    final = root / f'review_{review_id}'
    try:
        pages = provider.get('pages', [])
        for page in pages:
            raw = page.pop('_internal_artifacts', {})
            dest = staging / f"page_{int(page['page'])}"
            dest.mkdir(parents=True, exist_ok=True)
            signal = Path(str(raw.get('overlay_path', '')))
            threshold = Path(str(raw.get('mask_path', '')))
            metadata = {'signal': {'available': False}, 'threshold': {'available': False, 'regions_found': float(page.get('max_tamper_probability', 0)) >= .5}}
            for source, name, key in ((signal, 'signal-overlay.png', 'signal'), (threshold, 'threshold-overlay.png', 'threshold')):
                if source.suffix.lower() == '.png' and source.is_file() and _inside(source, service_root):
                    shutil.copyfile(source, dest / name); metadata[key]['available'] = True
            page['artifacts'] = metadata
        if final.exists(): shutil.rmtree(final)
        staging.rename(final)
    except Exception:
        shutil.rmtree(staging, ignore_errors=True)
        for page in provider.get('pages', []): page['artifacts'] = {'artifact_status': 'unavailable'}
    return result
