import ast
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
import cv2
import numpy as np


class FastCaptureTests(unittest.TestCase):
    def test_easyocr_recognizes_known_crop_without_text_detection(self):
        tree = ast.parse(Path('lpr_engine/quad_ensemble.py').read_text(encoding='utf-8'))
        cls = next(n for n in tree.body if isinstance(n, ast.ClassDef) and n.name == 'QuadReaderEnsemble')
        methods = [n for n in cls.body if isinstance(n, ast.FunctionDef) and n.name in ('read_with_easyocr', 'number_region', 'preprocess_crop')]
        ns = dict(cv2=cv2, np=np, Tuple=tuple)
        exec(compile(ast.fix_missing_locations(ast.Module(body=[ast.ClassDef(name='Reader', bases=[], keywords=[], body=methods, decorator_list=[])], type_ignores=[])), '<reader>', 'exec'), ns)
        reader = ns['Reader']()
        box = [[0,0],[200,0],[200,96],[0,96]]
        reader.easyocr = SimpleNamespace(recognize=Mock(return_value=[(box,'ABC1234',.93)]), readtext=Mock(side_effect=AssertionError('Redundant detector')))
        self.assertEqual(reader.read_with_easyocr(np.zeros((80,240,3),np.uint8)), ('ABC1234',.93))
        reader.easyocr.readtext.assert_not_called()
        args = reader.easyocr.recognize.call_args.kwargs
        self.assertEqual(len(args['horizontal_list']), 1)

    def test_localization_is_published_before_ocr_and_capture_matches_frame(self):
        tree = ast.parse(Path('detect_plate_server.py').read_text(encoding='utf-8'))
        cls = next(n for n in tree.body if isinstance(n, ast.ClassDef) and n.name == 'PlateDetector')
        ns = dict(cv2=cv2, IMAGE_SIZE=640, CONFIDENCE_THRESHOLD=.60, DETECTION_CANDIDATE_THRESHOLD=.22, USE_INTEL_GPU=True,
                  DEVICE='cpu', AUTO_CHECK_UPSIDE_DOWN=False, is_valid_plate=lambda p:True)
        exec(compile(ast.Module(body=[cls], type_ignores=[]), '<detector>', 'exec'), ns)
        detector = ns['PlateDetector'].__new__(ns['PlateDetector'])
        box = SimpleNamespace(xyxy=[np.array([20,20,180,80])], conf=[.9])
        detector.model = SimpleNamespace(predict=lambda **kw:[SimpleNamespace(boxes=[box])])
        events = []
        def read(crop):
            events.append('ocr')
            return 'ABC1234', 'ABC1234', .9, 'ACCEPT', 'test', None
        detector._extract_ocr_from_crop = read
        frame = np.full((100,220,3), 120, np.uint8)
        overlays, readings = detector.detect_plates_on_frame(frame, on_boxes=lambda boxes:events.append('box'))
        self.assertEqual(events, ['box','ocr'])
        self.assertTrue(readings[0]['_plate_crop'].size)
        self.assertFalse(np.shares_memory(readings[0]['_plate_crop'],frame))
        detector.channels = {'entrance': SimpleNamespace(direction='IN',draw_overlays=lambda frame, overlays:frame)}
        _, public = detector.process_frame(frame)
        self.assertNotIn('_plate_crop',public[0])
        json.dumps(public)
        # Distant plate boxes below 60% must still reach OCR; final readings stay >=60%.
        box.conf = [.287]
        thresholds = []
        detector.model.predict = lambda **kw:(thresholds.append(kw['conf']) or [SimpleNamespace(boxes=[box])])
        detector._extract_ocr_from_crop = lambda c:('ABC1234','ABC1234',.95,'ACCEPT','test',None)
        _, distant = detector.detect_plates_on_frame(frame)
        self.assertEqual(thresholds, [.22])
        self.assertGreaterEqual(distant[0]['confidence'], .60)
        detector._extract_ocr_from_crop = lambda c:('ABC1234','ABC1234',.1,'NEEDS_REVIEW','test',None)
        self.assertEqual(detector.detect_plates_on_frame(frame), ([], []))

    def test_photo_save_failure_does_not_retry_committed_vehicle_visit(self):
        import test_camera_modes as modes
        fixture = modes.CameraModeTests()
        fixture.setUp()
        fixture.apply()
        with patch.dict(modes.ns, dict(
                save_plate_capture=Mock(side_effect=OSError('disk unavailable')),
                os=__import__('os'), BASE_DIR='.')):
            reading = dict(normalized='ABC1234', confidence=.9, _plate_crop=object())
            self.assertTrue(fixture.detector.handle_detection(reading,fixture.detector.channels['entrance']))
            self.assertEqual(fixture.logs, ['IN'])
            self.assertEqual(fixture.detector.recent_detections[0]['capture_error'], 'disk unavailable')
            fixture.detector.handle_detection(reading,fixture.detector.channels['entrance'])
            self.assertEqual(fixture.logs, ['IN'])

    def test_capture_persists_readable_photo_and_matching_metadata(self):
        import importlib.util
        spec = importlib.util.spec_from_file_location("capture_store", "lpr_engine/capture_store.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        save_plate_capture = module.save_plate_capture
        with tempfile.TemporaryDirectory() as directory:
            crop = np.full((48,160,3), 180, np.uint8)
            reading = dict(plate='ABC1234',confidence=.85,channel='entrance',direction='IN')
            saved = save_plate_capture(directory, crop, reading)
            self.assertTrue(Path(directory, saved['image']).is_file())
            image = cv2.imread(str(Path(directory, saved['image'])))
            self.assertEqual(image.shape, crop.shape)
            metadata = json.loads(Path(directory,saved['metadata']).read_text())
            self.assertEqual(metadata['plate'], 'ABC1234')
            self.assertEqual(metadata['confidence'], .85)
            self.assertNotEqual(saved['image'],save_plate_capture(directory,crop,reading)['image'])

if __name__ == '__main__': unittest.main()
