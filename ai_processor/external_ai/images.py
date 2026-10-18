"""Bounded document-image preparation for capability-approved providers."""
import base64


class ExternalAiImageError(RuntimeError):
    pass


def prepare_image_data_url(image, max_edge: int = 1600, jpeg_quality: int = 80) -> str:
    try:
        import cv2
        if image is None or getattr(image, 'size', 0) == 0 or len(image.shape) not in {2, 3}:
            raise ValueError('empty image')
        height, width = image.shape[:2]
        if height <= 0 or width <= 0:
            raise ValueError('invalid dimensions')
        scale = min(1.0, max_edge / max(height, width))
        prepared = image
        if scale < 1.0:
            prepared = cv2.resize(image, (max(1, round(width * scale)), max(1, round(height * scale))), interpolation=cv2.INTER_AREA)
        if len(prepared.shape) == 2:
            prepared = cv2.cvtColor(prepared, cv2.COLOR_GRAY2BGR)
        for quality in (jpeg_quality, 70, 60, 50):
            ok, encoded = cv2.imencode('.jpg', prepared, [int(cv2.IMWRITE_JPEG_QUALITY), int(quality)])
            if ok and 0 < len(encoded) <= 1_500_000:
                return 'data:image/jpeg;base64,' + base64.b64encode(encoded.tobytes()).decode('ascii')
        raise ValueError('encoded image exceeds limit')
    except ExternalAiImageError:
        raise
    except Exception as exc:
        raise ExternalAiImageError('EXTERNAL_AI_IMAGE_PREPARATION_FAILED') from exc
