import json
import tempfile
import unittest
from pathlib import Path

from PIL import Image

from ai_processor.tamper_detection.file_inspection import inspect_file
from ai_processor.tamper_detection.postprocessing import attach_combined_finding

EXIF_IFD, GPS_IFD = 0x8769, 0x8825


def _save(path: Path, *, camera: bool, size=(400, 300), adobe=False, gps=False):
    image = Image.new('RGB', size, 'white')
    exif = Image.Exif()
    if camera:
        exif[0x010F] = 'Apple'
        exif[0x0110] = 'iPhone 13'
        exif[0x0131] = '17.5.1'
        exif[0x0132] = '2026:09:20 10:15:00'
        exif.get_ifd(EXIF_IFD)[36867] = '2026:09:20 10:14:59'
    if gps:
        exif.get_ifd(GPS_IFD)[2] = (10.0, 45.0, 0.0)
    kwargs = {'exif': exif.tobytes()} if (camera or gps) else {}
    if adobe:
        kwargs['subsampling'] = 0
    image.save(path, 'JPEG', **kwargs)
    if adobe:
        # An Adobe APP14 segment right after SOI, as written by Photoshop exports.
        data = path.read_bytes()
        app14 = b'\xff\xee\x00\x0eAdobe\x00\x64\x00\x00\x00\x00\x01'
        path.write_bytes(data[:2] + app14 + data[2:])


class FileMetadataFactTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)

    def tearDown(self):
        self.folder.cleanup()

    def test_phone_photo_reports_camera_and_date_taken_from_exif_ifd(self):
        path = self.root / 'photo.jpg'
        _save(path, camera=True, size=(4032, 3024))
        facts = inspect_file(path)['facts']
        self.assertEqual(facts['camera_metadata'], 'present')
        self.assertEqual(facts['camera'], 'Apple iPhone 13')
        self.assertEqual(facts['date_taken'], '2026:09:20 10:14:59')
        self.assertEqual(facts['pixel_size'], '4032 × 3024')
        self.assertFalse(facts['saved_with_adobe'])

    def test_stripped_resaved_image_is_described_without_escalating(self):
        path = self.root / 'IMG_5539.jpg'
        _save(path, camera=False, size=(500, 661), adobe=True)
        result = inspect_file(path)
        facts = result['facts']
        self.assertEqual(facts['camera_metadata'], 'missing')
        self.assertIsNone(facts['camera'])
        self.assertIsNone(facts['date_taken'])
        self.assertTrue(facts['saved_with_adobe'])
        self.assertEqual(facts['pixel_size'], '500 × 661')
        self.assertEqual(result['decision'], 'clear')
        self.assertEqual(result['indicators'], [])

    def test_png_exported_from_canva_reports_the_app_without_account_details(self):
        from PIL import PngImagePlugin
        path = self.root / 'edited.png'
        info = PngImagePlugin.PngInfo()
        info.add_itxt('XML:com.adobe.xmp', '<x:xmpmeta><rdf:Description xmp:CreatorTool="Canva doc=DAHWF-8rFdM '
                                           'user=UAEvZASS7p0 brand=Private Team"/></x:xmpmeta>')
        Image.new('RGB', (300, 200), 'white').save(path, 'PNG', pnginfo=info)
        facts = inspect_file(path)['facts']
        self.assertEqual(facts['editing_software'], 'Canva')
        self.assertTrue(facts['design_app'])
        text = json.dumps(facts)
        self.assertNotIn('UAEvZASS7p0', text)
        self.assertNotIn('Private Team', text)
        self.assertNotIn('DAHWF', text)

    def test_camera_name_is_cut_at_binary_garbage(self):
        path = self.root / 'nikon.jpg'
        exif = Image.Exif()
        exif[0x010F] = 'Nikon\x00MM*\x00S'
        Image.new('RGB', (300, 200), 'white').save(path, 'JPEG', exif=exif.tobytes())
        facts = inspect_file(path)['facts']
        self.assertEqual(facts['camera'], 'Nikon')
        self.assertFalse(facts['design_app'])

    def test_gps_location_is_never_read_or_stored(self):
        path = self.root / 'gps.jpg'
        _save(path, camera=True, gps=True)
        text = json.dumps(inspect_file(path), default=str).lower()
        self.assertNotIn('gps', text)
        self.assertNotIn('10.0', text)

    def test_new_facts_survive_persistence_allowlist(self):
        path = self.root / 'photo.jpg'
        _save(path, camera=True, size=(4032, 3024), adobe=True)
        facts = inspect_file(path)['facts']
        combined = {'status': 'completed', 'decision': 'clear', 'indicators': [], 'providers': {},
                    'auxiliary': {'file': facts, 'qr': []}}
        saved = attach_combined_finding({}, combined)['tamper_detection']['auxiliary']['file']
        for key in ('camera', 'date_taken', 'pixel_size', 'camera_metadata', 'saved_with_adobe', 'image_format'):
            self.assertIn(key, saved)
        self.assertEqual(saved['saved_with_adobe'], True)


if __name__ == '__main__':
    unittest.main()
