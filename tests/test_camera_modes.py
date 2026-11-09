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
    def test_exit_without_entry_raises_one_guarded_alert(self):
        self.cfg['camera_mode']='dual'
        self.apply()
        alerts=[]
        state=dict(pending=False, similar_inside=False, recent_out=False)
        class FakeConn:
            def close(self): pass
        ns.update(
            EXIT_ALERT_REPEAT_SECONDS=600,
            is_visitor_pending_or_suppressed=lambda p,**k:state['pending'],
            similar_plate_inside=lambda p:state['similar_inside'],
            is_plate_currently_inside=lambda p,**k:False,
            get_db_connection=lambda:FakeConn(),
            record_exit_without_entry=lambda conn,plate,info,**k:(alerts.append(plate) or dict(id=len(alerts),plate=plate,category='Unknown')),
            attach_alert_image=lambda *a,**k:None,
        )
        ns['is_plate_recently_logged_in_db']=lambda *a,**k:state['recent_out']
        self.detector._capture_vehicle=lambda *a,**k:None
        self.detector.can_scan_plate=lambda plate,direction,use_cache=True:direction!='OUT'
        exit_ch=self.detector.channels['exit']
        try:
            # Low-confidence read: no alarm
            self.assertFalse(self.detector.handle_detection(dict(normalized='REV111',decision='NEEDS_REVIEW'),exit_ch))
            # Just logged OUT and still in view: no alarm
            state['recent_out']=True
            self.assertFalse(self.detector.handle_detection(dict(normalized='LEFT111'),exit_ch))
            state['recent_out']=False
            # Misread of a vehicle that is inside: no alarm
            state['similar_inside']=True
            self.assertFalse(self.detector.handle_detection(dict(normalized='MIS111'),exit_ch))
            state['similar_inside']=False
            # Visitor still waiting for the guard's form: no alarm
            state['pending']=True
            self.assertFalse(self.detector.handle_detection(dict(normalized='PEN111'),exit_ch))
            state['pending']=False
            # Never came in: one alarm, not repeated while it stays in view
            self.assertFalse(self.detector.handle_detection(dict(normalized='GHOST11',text='GHOST 11'),exit_ch))
            self.assertFalse(self.detector.handle_detection(dict(normalized='GHOST11',text='GHOST 11'),exit_ch))
            self.assertEqual(alerts,['GHOST 11'])
            self.assertEqual(self.logs,[])
        finally:
            for k in ('EXIT_ALERT_REPEAT_SECONDS','is_visitor_pending_or_suppressed','similar_plate_inside',
                      'is_plate_currently_inside','get_db_connection','record_exit_without_entry','attach_alert_image'):
                ns.pop(k,None)
            ns['is_plate_recently_logged_in_db']=lambda *a,**k:False
    def test_inside_vehicle_is_not_scanned_on_entrance_and_outside_vehicle_is_not_scanned_on_exit(self):
        self.cfg['camera_mode']='dual'
        self.apply()
        inside_plates={'INSIDE1'}
        self.detector.can_scan_plate=lambda plate,direction,use_cache=True:(
            (plate not in inside_plates) if direction=='IN' else ((plate in inside_plates) if direction=='OUT' else True)
        )
        # Vehicle already INSIDE must not be scanned on Entrance (IN)
        self.assertFalse(self.detector.handle_detection(dict(normalized='INSIDE1'),self.detector.channels['entrance']))
        # Vehicle already OUTSIDE must not be scanned on Exit (OUT)
        self.assertFalse(self.detector.handle_detection(dict(normalized='OUTSIDE1'),self.detector.channels['exit']))
        self.assertEqual(self.logs,[])
        self.assertEqual(self.detector.recent_detections,[])
        # Outside vehicle CAN be scanned on Entrance (IN)
        self.assertTrue(self.detector.handle_detection(dict(normalized='OUTSIDE1'),self.detector.channels['entrance']))
        # Inside vehicle CAN be scanned on Exit (OUT)
        self.assertTrue(self.detector.handle_detection(dict(normalized='INSIDE1'),self.detector.channels['exit']))
        self.assertEqual(self.logs,['IN','OUT'])

if __name__=='__main__': unittest.main()
