import json, socket, unittest
from types import SimpleNamespace
from unittest.mock import patch
from urllib.error import HTTPError, URLError
import numpy as np
from ai_processor.tamper_detection.adapter import analyze_tampering

PAYLOAD = {'success': True, 'tampered_pixel_ratio': .01, 'max_tamper_probability': .8, 'mean_tamper_probability': .1, 'processing_time_seconds': 2, 'output_width': 512, 'output_height': 512}
SETTINGS = SimpleNamespace(adcd_enabled=True, adcd_service_url='http://127.0.0.1:5001', adcd_connect_timeout_seconds=3, adcd_read_timeout_seconds=90)

class Response:
    def __init__(self, payload): self.payload = payload
    def read(self): return json.dumps(self.payload).encode()
    def __enter__(self): return self
    def __exit__(self, *_): return False

class TamperAdapterTests(unittest.TestCase):
    def setUp(self): self.image = np.zeros((32, 32, 3), dtype=np.uint8); self.metadata = {'ocr_boxes_by_page': [[[1, 2, 3, 4]]]}
    def test_disabled_does_not_call_service(self):
        with patch('ai_processor.tamper_detection.adapter.urlopen') as call:
            self.assertEqual(analyze_tampering([self.image], SimpleNamespace(adcd_enabled=False))['providers']['adcd_net']['status'], 'unavailable'); call.assert_not_called()
    def test_nonlocal_url_never_receives_page_bytes(self):
        settings = SimpleNamespace(adcd_enabled=True, adcd_service_url='http://receiver.invalid:5001',
                                   adcd_connect_timeout_seconds=3, adcd_read_timeout_seconds=90)
        with patch('ai_processor.tamper_detection.adapter.urlopen') as call:
            result = analyze_tampering([self.image], settings, self.metadata)['providers']['adcd_net']
            self.assertEqual(result['status'], 'unavailable')
            call.assert_not_called()
    def test_sends_image_bytes_and_ocr_boxes(self):
        with patch('ai_processor.tamper_detection.adapter.urlopen', return_value=Response(PAYLOAD)) as call:
            result = analyze_tampering([self.image], SETTINGS, self.metadata)['providers']['adcd_net']
        request = call.call_args.args[0]
        self.assertIn(b'filename="page.png"', request.data); self.assertIn(b'[[1,2,3,4]]', request.data); self.assertEqual(result['status'], 'completed')
    def test_calibration_artifacts_are_opt_in(self):
        payload = {**PAYLOAD, 'artifacts': {'mask': 'temporary-mask.png'}}
        with patch('ai_processor.tamper_detection.adapter.urlopen', return_value=Response(payload)):
            normal = analyze_tampering([self.image], SETTINGS, self.metadata)['providers']['adcd_net']['pages'][0]
        with patch('ai_processor.tamper_detection.adapter.urlopen', return_value=Response(payload)):
            calibration = analyze_tampering([self.image], SETTINGS, self.metadata, include_calibration_artifacts=True)['providers']['adcd_net']['pages'][0]
        self.assertNotIn('_calibration_artifacts', normal)
        self.assertEqual(calibration['_calibration_artifacts']['mask'], 'temporary-mask.png')
    def test_multipage_pairs_boxes(self):
        metadata = {'ocr_boxes_by_page': [[[1, 1, 2, 2]], [[3, 3, 4, 4]]]}
        with patch('ai_processor.tamper_detection.adapter.urlopen', side_effect=[Response(PAYLOAD), Response(PAYLOAD)]) as call:
            result = analyze_tampering([self.image, self.image], SETTINGS, metadata)['providers']['adcd_net']
        self.assertEqual([page['page'] for page in result['pages']], [1, 2]); self.assertIn(b'[[3,3,4,4]]', call.call_args_list[1].args[0].data)
    def test_http_failures_are_neutral(self):
        for error, code in ((URLError('down'), 'ADCD_SERVICE_UNAVAILABLE'), (HTTPError('x', 503, 'x', {}, None), 'ADCD_SERVICE_HTTP_503'), (HTTPError('x', 400, 'x', {}, None), 'ADCD_SERVICE_HTTP_400'), (HTTPError('x', 500, 'x', {}, None), 'ADCD_SERVICE_HTTP_500'), (socket.timeout(), 'ADCD_SERVICE_TIMEOUT')):
            with self.subTest(code=code), patch('ai_processor.tamper_detection.adapter.urlopen', side_effect=error):
                self.assertEqual(analyze_tampering([self.image], SETTINGS, self.metadata)['providers']['adcd_net']['error'], code)
    def test_malformed_json_and_timeout_are_neutral(self):
        with patch('ai_processor.tamper_detection.adapter.urlopen', return_value=Response({'success': True})):
            result = analyze_tampering([self.image], SETTINGS, self.metadata)['providers']['adcd_net']
            self.assertEqual(result['error'], 'ADCD_SERVICE_MALFORMED_RESPONSE')
        with patch('ai_processor.tamper_detection.adapter.urlopen', side_effect=TimeoutError()):
            self.assertEqual(analyze_tampering([self.image], SETTINGS, self.metadata)['providers']['adcd_net']['error'], 'ADCD_SERVICE_TIMEOUT')
    def test_default_adapter_has_no_subprocess_or_paddleocr(self):
        import inspect, ai_processor.tamper_detection.adapter as adapter
        source = inspect.getsource(adapter); self.assertNotIn('subprocess', source); self.assertNotIn('PaddleOCR', source)

if __name__ == '__main__': unittest.main()
