import unittest
from ai_processor.tamper_detection.postprocessing import completed, normalize_result, unavailable

PAYLOAD = {'success': True, 'tampered_pixel_ratio': .01, 'max_tamper_probability': .8, 'mean_tamper_probability': .1, 'processing_time_seconds': 2, 'output_width': 512, 'output_height': 512}

class TamperPostprocessingTests(unittest.TestCase):
    def test_unavailable_is_neutral(self): self.assertEqual(unavailable()['providers']['adcd_net']['status'], 'unavailable')
    def test_raw_metrics_are_preserved_without_threshold(self):
        result = normalize_result(PAYLOAD)['providers']['adcd_net']
        self.assertEqual(result['status'], 'completed'); self.assertEqual(result['tampered_pixel_ratio'], .01)
    def test_page_aggregation_is_weighted(self):
        one = normalize_result(PAYLOAD)['providers']['adcd_net']['pages'][0]
        two = dict(one, page=2, output_width=256, output_height=256, tampered_pixel_ratio=.04)
        self.assertGreater(completed([one, two])['providers']['adcd_net']['tampered_pixel_ratio'], .01)

if __name__ == '__main__': unittest.main()
