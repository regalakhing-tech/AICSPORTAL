import importlib.util, tempfile, unittest
from pathlib import Path

PATH=Path(__file__).resolve().parents[1]/'tamper_detection'/'evaluate_reference.py'
spec=importlib.util.spec_from_file_location('reference_evaluator_test',PATH); module=importlib.util.module_from_spec(spec); spec.loader.exec_module(module)

class ReferenceEvaluatorTests(unittest.TestCase):
    def test_missing_assets_are_not_accepted(self):
        with tempfile.TemporaryDirectory() as folder:
            status=module.asset_status(Path(folder)); self.assertFalse(status['ready']); self.assertFalse(status['qt_table_readable'])
    def test_complete_asset_layout_is_detected(self):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder); (root/'qt_table.pk').write_bytes(b'test'); (root/'pks').mkdir(); (root/'pks'/'record.pk').write_bytes(b'test')
            for name in module.REQUIRED_OCR: (root/'DocTamperOCR'/name).mkdir(parents=True)
            self.assertTrue(module.asset_status(root)['ready'])
    def test_pixel_metrics_fixture(self):
        import numpy as np
        result=module.metrics(np.array([[.6,.1],[.7,.2]]),np.array([[1,0],[1,0]],dtype=np.uint8))
        self.assertEqual(result['iou'],1.0); self.assertEqual(result['dice'],1.0); self.assertEqual(result['tampered_pixel_ratio'],.5)
