"""Privacy-safe configuration and synthetic capability probe CLI."""
import argparse
import json
from pathlib import Path

from ..config import SETTINGS, external_ai_configuration_health
from .client import ExternalAiClient
from .images import prepare_image_data_url
from .state import ExternalAiStateStore


def configuration_payload(settings=SETTINGS) -> dict:
    return {
        'status': external_ai_configuration_health(settings),
        'image_enabled': bool(settings.external_ai_image_enabled),
    }


def _synthetic_evidence() -> dict:
    return {
        'schema_version': 'external-ai-evidence-v1',
        'expected_document_type': 'valid_id',
        'ocr_text': 'SYNTHETIC TEST DOCUMENT NAME SAMPLE PERSON',
        'fields': {'name': {'status': 'extracted', 'normalized': 'SAMPLE PERSON'}},
        'local_assessments': {'document_match': {'state': 'review'}},
        'beneficiary': {'full_name': 'Sample Person'},
    }


def _synthetic_image():
    import cv2
    import numpy as np
    image = np.full((480, 760, 3), 255, dtype=np.uint8)
    cv2.rectangle(image, (20, 20), (740, 460), (30, 90, 180), 3)
    cv2.putText(image, 'SYNTHETIC TEST DOCUMENT', (55, 130), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (15, 15, 15), 2)
    cv2.putText(image, 'SAMPLE PERSON', (55, 230), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (15, 15, 15), 2)
    return image


def main(argv=None) -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--config-json', action='store_true')
    mode.add_argument('--text', action='store_true')
    mode.add_argument('--image', action='store_true')
    args = parser.parse_args(argv)
    if args.config_json:
        print(json.dumps(configuration_payload(), separators=(',', ':')))
        return 0
    client = ExternalAiClient(SETTINGS)
    image_data_url = prepare_image_data_url(_synthetic_image()) if args.image else None
    result = client.assess(_synthetic_evidence(), image_data_url=image_data_url)
    if result.get('status') == 'completed' and args.image:
        ExternalAiStateStore(Path(SETTINGS.external_ai_state_root)).mark_image_capability(
            SETTINGS.external_ai_endpoint,
            SETTINGS.external_ai_model,
        )
    output = {'status': str(result.get('status') or 'unavailable')}
    if result.get('error_code'):
        output['error_code'] = str(result['error_code'])
    print(json.dumps(output, separators=(',', ':')))
    return 0 if output['status'] == 'completed' else 2


if __name__ == '__main__':
    raise SystemExit(main())
