import ast
import copy
from datetime import datetime, timedelta
from pathlib import Path
import threading
import unittest

class Channel:
    def __init__(self, channel_id, name, source, direction, flip_180=False, zoom_factor=1.35):
        self.channel_id, self.name, self.source, self.direction = channel_id, name, source, direction
        self.flip_180, self.zoom_factor = flip_180, zoom_factor
        self.overlay_lock = threading.Lock()
        self.running = False
    def start(self): self.running = True
    def stop(self): self.running = False
    def set_source(self, source, flip_180=False): self.source, self.flip_180 = source, flip_180

source = ast.parse(Path('detect_plate_server.py').read_text(encoding='utf-8-sig'))
cls = next(n for n in source.body if isinstance(n, ast.ClassDef) and n.name == 'PlateDetector')
ns = dict(CameraChannel=Channel, threading=threading, datetime=datetime, timedelta=timedelta,
          DEFAULT_ENTRANCE_RTSP_URL='entrance', DEFAULT_EXIT_RTSP_URL='exit', PLATE_COOLDOWN_SECONDS=30,
          plates_are_similar=lambda a,b:a==b, is_plate_recently_logged_in_db=lambda *a,**k:False,
          check_plate_in_database=lambda p:dict(category='Registered',vehicle_type='Car'))
exec(compile(ast.Module(body=[cls],type_ignores=[]),'<detector>','exec'),ns)

class CameraModeTests(unittest.TestCase):
    def setUp(self):
        self.detector = ns['PlateDetector'].__new__(ns['PlateDetector'])
        self.detector.running = True
        self.detector.channels = {}
        self.detector.config = {}
        self.detector.inference_lock = threading.Lock()
        self.detector.detected_plates = {}
        self.detector.recent_detections = []
        self.cfg = dict(camera_mode='single',single_direction='IN', entrance=dict(source='camera-a'),exit=dict(source='camera-b'))
        self.logs=[]
        ns['log_vehicle_detection']=lambda p,info,d:(self.logs.append(d) or {'direction':d})
    def apply(self):
        self.assertTrue(hasattr(self.detector,'apply_camera_config'),'Missing camera mode configuration')
        self.detector.apply_camera_config(copy.deepcopy(self.cfg))
    def test_automatic_shared_mode_uses_committed_direction(self):
        self.cfg['single_direction']='AUTO'
        self.apply()
        ns['log_vehicle_detection']=lambda p,info,d:(self.logs.append(d) or {'direction':'OUT'})
        self.assertTrue(self.detector.handle_detection(dict(normalized='ABC1234'),self.detector.channels['entrance']))
        self.assertEqual(self.logs,['AUTO'])
        self.assertEqual(self.detector.recent_detections[0]['direction'],'OUT')
    def test_failed_save_is_not_published_as_a_detection(self):
        self.apply()
        ns['log_vehicle_detection']=lambda *a:False
        self.assertFalse(self.detector.handle_detection(dict(normalized='ABC1234'),self.detector.channels['entrance']))
        self.assertEqual(self.detector.recent_detections,[])
        self.assertEqual(self.detector.detected_plates,{})
    def test_shared_camera_uses_one_capture(self):
        self.apply()
        self.assertIs(self.detector.channels['exit'],self.detector.channels['entrance'])
        self.assertEqual(len(set(self.detector.channels.values())),1)
    def test_selected_out_direction_is_logged_once(self):
        self.cfg['single_direction']='OUT'
        self.apply()
        ch=self.detector.channels['entrance']
        self.detector.handle_detection(dict(normalized='ABC1234'),ch)
        self.assertEqual(self.logs,['OUT'])
    def test_switch_to_two_cameras_restores_independent_directions(self):
        self.apply()
        old=self.detector.channels['entrance']
        self.cfg['camera_mode']='dual'
        self.cfg['single_direction']='OUT'
        self.apply()
        self.assertFalse(old.running)
        self.assertIsNot(self.detector.channels['exit'],self.detector.channels['entrance'])
        self.assertEqual(self.detector.channels['entrance'].direction,'IN')
        self.assertEqual(self.detector.channels['exit'].direction,'OUT')
        self.assertEqual(self.detector.channels['exit'].source,'camera-b')
    def test_unavailable_exit_camera_does_not_capture_or_log(self):
        self.cfg['camera_mode']='dual'
        self.cfg['exit']['enabled']=False
        self.apply()
        self.assertTrue(self.detector.channels['entrance'].running)
        self.assertFalse(self.detector.channels['exit'].running)
        self.assertEqual(self.detector.channels['exit'].direction,'OUT')
    def test_two_enabled_channels_cannot_use_the_same_camera_source(self):
        self.cfg['camera_mode']='dual'
        self.cfg['exit']['source']=self.cfg['entrance']['source']
        with self.assertRaises(ValueError): self.detector.apply_camera_config(self.cfg)
    def test_enable_exit_when_second_camera_arrives(self):
        self.cfg['camera_mode']='dual'
        self.cfg['exit']['enabled']=False
        self.apply()
        self.cfg['exit']['enabled']=True
        self.apply()
        self.assertTrue(self.detector.channels['exit'].running)
        self.assertEqual(self.detector.channels['exit'].direction,'OUT')
    def test_invalid_direction_does_not_change_running_configuration(self):
        self.apply()
        old=self.detector.channels['entrance']
        self.cfg['single_direction']='INVALID'
        with self.assertRaises(ValueError): self.detector.apply_camera_config(self.cfg)
        self.assertIs(old,self.detector.channels['entrance'])
    def test_direction_change_allows_same_plate_to_leave(self):
        self.apply()
        self.detector.handle_detection(dict(normalized='ABC1234'),self.detector.channels['entrance'])
        self.cfg['single_direction']='OUT'
        self.apply()
        self.detector.handle_detection(dict(normalized='ABC1234'),self.detector.channels['entrance'])
        self.assertEqual(self.logs,['IN','OUT'])

if __name__=='__main__': unittest.main()
