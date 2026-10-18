"""A ready localhost model may run without a stale disabled worker flag."""
import io
import json
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from ai_processor.tamper_detection.activation import adcd_service_ready


class AdcdActivationTests(unittest.TestCase):
    def _settings(self, url='http://127.0.0.1:5001'):
        return SimpleNamespace(adcd_service_url=url, adcd_enabled=False,
                               adcd_auto_activate=True, adcd_connect_timeout_seconds=0.5)

    def test_rejects_non_local_service_without_request(self):
        with patch('ai_processor.tamper_detection.activation.urlopen') as request:
            self.assertFalse(adcd_service_ready(self._settings('https://example.invalid')))
            request.assert_not_called()

    def test_ready_loaded_service_can_auto_activate(self):
        response = io.BytesIO(json.dumps({'service': 'aics-adcd-net', 'status': 'ready',
                                          'model_loaded': True}).encode())
        response.__enter__ = lambda self: self
        response.__exit__ = lambda self, *args: None
        with patch('ai_processor.tamper_detection.activation.urlopen', return_value=response):
            self.assertTrue(adcd_service_ready(self._settings()))

    def test_unavailable_service_does_not_activate(self):
        with patch('ai_processor.tamper_detection.activation.urlopen', side_effect=OSError('offline')):
            self.assertFalse(adcd_service_ready(self._settings()))

    def test_idle_service_loads_its_configured_checkpoints(self):
        idle = io.BytesIO(json.dumps({'service': 'aics-adcd-net', 'status': 'not_loaded',
                                      'model_loaded': False}).encode())
        loaded = io.BytesIO(json.dumps({'success': True, 'model_loaded': True}).encode())
        with patch('ai_processor.tamper_detection.activation.urlopen', side_effect=[idle, loaded]) as request:
            self.assertTrue(adcd_service_ready(self._settings()))
            self.assertEqual(request.call_count, 2)


if __name__ == '__main__':
    unittest.main()
