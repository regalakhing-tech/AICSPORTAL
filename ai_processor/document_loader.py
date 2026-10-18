import logging
import os
from pathlib import Path, PurePosixPath, PureWindowsPath

logger = logging.getLogger(__name__)

def _is_within(path: Path, root: Path) -> bool:
    try:
        path.relative_to(root)
        return True
    except ValueError:
        return False

def resolve_path(project_root: str, upload_root: str, stored_path: str, document_id: int | None = None) -> Path:
    """Resolve database paths safely below Applicants/uploads.

    Web-facing legacy rows can start with ``AICS/`` even though project_root
    already points to the AICS directory; that prefix is removed centrally.
    """
    project = Path(project_root).resolve()
    root = Path(upload_root).resolve()
    raw = str(stored_path or '').strip()
    if root != project / 'Applicants' / 'uploads' and not _is_within(root, project):
        raise RuntimeError('AI_PATH_OUTSIDE_UPLOAD_ROOT')
    if raw == '':
        logger.error('AI document path unavailable: document_id=%s exists=no', document_id)
        raise RuntimeError('AI_DOCUMENT_FILE_NOT_FOUND')
    if PureWindowsPath(raw).is_absolute() or Path(raw).is_absolute():
        resolved = Path(raw).resolve()
    else:
        normalized = raw.replace('\\', '/').lstrip('/')
        if normalized.lower().startswith('aics/'):
            normalized = normalized[5:]
        path = PurePosixPath(normalized)
        if any(part in ('..', '.') for part in path.parts) or path.is_absolute():
            raise RuntimeError('AI_PATH_OUTSIDE_UPLOAD_ROOT')
        parts = path.parts
        if len(parts) < 3 or parts[0].lower() != 'applicants' or parts[1].lower() != 'uploads':
            raise RuntimeError('AI_PATH_OUTSIDE_UPLOAD_ROOT')
        resolved = (root.joinpath(*parts[2:])).resolve()
    exists, is_file = resolved.exists(), resolved.is_file()
    logger.info('AI document path resolved: document_id=%s suffix=%s exists=%s is_file=%s', document_id, resolved.suffix.lower(), 'yes' if exists else 'no', 'yes' if is_file else 'no')
    if not _is_within(resolved, root):
        raise RuntimeError('AI_PATH_OUTSIDE_UPLOAD_ROOT')
    if not exists or not is_file:
        raise RuntimeError('AI_DOCUMENT_FILE_NOT_FOUND')
    return resolved

def load_images(path: Path, max_pages: int, poppler_path: str = '', timeout_seconds: int = 60, document_id: int | None = None):
    suffix = path.suffix.lower()
    if suffix in ('.jpg', '.jpeg', '.png'):
        import cv2
        import numpy as np
        from PIL import Image, ImageOps
        try:
            with Image.open(path) as source:
                normalized = ImageOps.exif_transpose(source).convert('RGB')
                image = cv2.cvtColor(np.asarray(normalized), cv2.COLOR_RGB2BGR)
        except Exception as exc:
            logger.warning('AI image normalization failed: document_id=%s type=%s', document_id, exc.__class__.__name__)
            raise RuntimeError('AI_IMAGE_LOAD_FAILED') from exc
        if image.size == 0: raise RuntimeError('AI_IMAGE_LOAD_FAILED')
        return [image]
    if suffix == '.pdf':
        try:
            from pdf2image import convert_from_path
            import numpy as np
            raw_poppler_path = str(poppler_path or '').strip()
            resolved_poppler_path = Path(raw_poppler_path).expanduser().resolve() if raw_poppler_path else None
            exe = '.exe' if os.name == 'nt' else ''
            pdfinfo_executable = resolved_poppler_path / f'pdfinfo{exe}' if resolved_poppler_path else None
            pdftoppm_executable = resolved_poppler_path / f'pdftoppm{exe}' if resolved_poppler_path else None
            pdfinfo_exists = bool(pdfinfo_executable and pdfinfo_executable.is_file())
            pdftoppm_exists = bool(pdftoppm_executable and pdftoppm_executable.is_file())
            logger.info(
                'AI PDF render diagnostics: document_id=%s exists=%s is_file=%s size=%s pdfinfo_exists=%s pdftoppm_exists=%s explicit_poppler_mode=yes',
                document_id, 'yes' if path.exists() else 'no', 'yes' if path.is_file() else 'no',
                path.stat().st_size if path.is_file() else 'unavailable',
                'yes' if pdfinfo_exists else 'no', 'yes' if pdftoppm_exists else 'no',
            )
            if not raw_poppler_path or not resolved_poppler_path.is_dir() or not pdfinfo_exists or not pdftoppm_exists:
                logger.error('AI Poppler configuration invalid: document_id=%s', document_id)
                raise RuntimeError('AI_POPPLER_CONFIG_INVALID')
            pages = convert_from_path(
                str(path), first_page=1, last_page=max_pages,
                poppler_path=str(resolved_poppler_path), timeout=timeout_seconds,
            )
            return [np.array(page)[:, :, ::-1] for page in pages]
        except Exception as exc:
            # Preserve PDFInfoNotInstalledError, PDFPageCountError, timeout,
            # OSError, FileNotFoundError, and subprocess details in server logs.
            logger.exception(
                'AI PDF render exception: document_id=%s exception_class=%s',
                document_id, exc.__class__.__name__,
            )
            if isinstance(exc, RuntimeError) and str(exc) == 'AI_POPPLER_CONFIG_INVALID':
                raise
            raise RuntimeError('AI_PDF_RENDER_FAILED') from exc
    raise RuntimeError('AI_UNSUPPORTED_FILE')
