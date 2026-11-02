import ast
from pathlib import Path
import unittest
import cv2
import numpy as np

source = ast.parse(Path('detect_plate_server.py').read_text(encoding='utf-8'))
channel = next(n for n in source.body if isinstance(n,ast.ClassDef) and n.name == 'CameraChannel')
ns = {'cv2':cv2}
exec(compile(ast.Module(body=[channel],type_ignores=[]), '<channel>', 'exec'),ns)

class FrameResolutionTests(unittest.TestCase):
    def test_4k_is_reduced_to_1080p(self):
        frame = np.zeros((2160,3840,3),np.uint8)
        result = ns['CameraChannel'].fit_processing_frame(frame)
        self.assertEqual(result.shape,(1080,1920,3))

    def test_lower_resolution_is_not_upscaled(self):
        frame = np.zeros((720,1280,3),np.uint8)
        self.assertIs(ns['CameraChannel'].fit_processing_frame(frame),frame)

    def test_portrait_preserves_aspect_ratio(self):
        frame = np.zeros((2160,1440,3),np.uint8)
        self.assertEqual(ns['CameraChannel'].fit_processing_frame(frame).shape,(1080,720,3))

if __name__ == '__main__': unittest.main()
