import tempfile, unittest
from pathlib import Path
from types import SimpleNamespace

from ai_processor.tamper_detection.artifacts import persist_private_artifacts
from ai_processor.tamper_detection.postprocessing import persisted_finding

PNG = b'\x89PNG\r\n\x1a\nfixture'

class PrivateArtifactTests(unittest.TestCase):
    def test_copies_same_pass_pngs_and_persists_only_metadata(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'private'; service = Path(directory) / 'service' / 'job'
            service.mkdir(parents=True)
            overlay = service / 'tamper-overlay.png'; mask = service / 'tamper-mask.png'
            overlay.write_bytes(PNG); mask.write_bytes(PNG)
            result = {'providers': {'adcd_net': {'status': 'completed', 'pages': [{
                'page': 1, 'output_width': 512, 'output_height': 512,
                'tampered_pixel_ratio': 0, 'max_tamper_probability': .49,
                'mean_tamper_probability': .1, 'processing_time_seconds': 1,
                '_internal_artifacts': {'overlay_path': str(overlay), 'mask_path': str(mask)},
            }], 'tampered_pixel_ratio': 0, 'max_tamper_probability': .49, 'mean_tamper_probability': .1, 'processing_time_seconds': 1}}}
            persisted = persist_private_artifacts(result, 700, SimpleNamespace(ai_review_artifact_root=str(root), adcd_output_root=str(service.parent)))
            page = persisted['providers']['adcd_net']['pages'][0]
            self.assertNotIn('_internal_artifacts', page)
            self.assertEqual(page['artifacts'], {'signal': {'available': True}, 'threshold': {'available': True, 'regions_found': False}})
            self.assertEqual((root / 'review_700' / 'page_1' / 'signal-overlay.png').read_bytes(), PNG)
            self.assertEqual((root / 'review_700' / 'page_1' / 'threshold-overlay.png').read_bytes(), PNG)
            stored = persisted_finding(persisted)
            self.assertNotIn(str(root), str(stored)); self.assertNotIn(str(service), str(stored))

    def test_out_of_root_source_is_not_copied(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'private'; source = Path(directory) / 'outside.png'; source.write_bytes(PNG)
            (Path(directory) / 'service').mkdir()
            result = {'providers': {'adcd_net': {'status': 'completed', 'pages': [{'page': 1, 'max_tamper_probability': .6, '_internal_artifacts': {'overlay_path': str(source)}}]}}}
            persisted = persist_private_artifacts(result, 701, SimpleNamespace(ai_review_artifact_root=str(root), adcd_output_root=str(Path(directory) / 'service')))
            self.assertFalse(persisted['providers']['adcd_net']['pages'][0]['artifacts']['signal']['available'])
            self.assertTrue(persisted['providers']['adcd_net']['pages'][0]['artifacts']['threshold']['regions_found'])

    def test_public_project_root_never_receives_overlays(self):
        with tempfile.TemporaryDirectory() as directory:
            project = Path(directory) / 'project'
            private = project / 'publicly_reachable'
            service = Path(directory) / 'service'
            project.mkdir(); service.mkdir()
            source = service / 'overlay.png'; source.write_bytes(PNG)
            result = {'providers': {'adcd_net': {'status': 'completed', 'pages': [
                {'page': 1, '_internal_artifacts': {'overlay_path': str(source)}}]}}}
            settings = SimpleNamespace(ai_review_artifact_root=str(private), project_root=str(project),
                                       adcd_output_root=str(service))
            saved = persist_private_artifacts(result, 702, settings)
            self.assertFalse(private.exists())
            self.assertEqual(saved['providers']['adcd_net']['pages'][0]['artifacts']['artifact_status'], 'unavailable')

if __name__ == '__main__': unittest.main()
