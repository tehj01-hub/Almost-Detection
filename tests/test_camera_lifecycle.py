import ast,threading,time,unittest
from pathlib import Path
node=next(n for n in ast.parse(Path('detect_plate_server.py').read_text(encoding='utf-8')).body if isinstance(n,ast.ClassDef) and n.name=='CameraChannel')
ns=dict(threading=threading,time=time)
exec(compile(ast.Module(body=[node],type_ignores=[]),'<channel>','exec'),ns)
class Capture:
 def __init__(self):self.releases=[]
 def release(self):self.releases.append(threading.get_ident())
class CameraLifecycleTests(unittest.TestCase):
 def channel(self):
  c=ns['CameraChannel']('entrance','Camera','old-source');c.cap=Capture();return c
 def test_stop_leaves_release_to_capture_thread(self):
  c=self.channel();cap=c.cap;c.stop();self.assertEqual(cap.releases,[])
 def test_source_change_does_not_release_stream_being_read(self):
  c=self.channel();cap=c.cap;c.set_source('new-source');self.assertEqual(cap.releases,[])
  self.assertTrue(c.reconnect_requested.is_set())
 def test_fallback_does_not_overwrite_new_requested_source(self):
  from types import SimpleNamespace
  c=self.channel();c.source='old/stream1'
  first=Capture();first.isOpened=lambda:True;first.read=lambda:(False,None)
  second=Capture();second.isOpened=lambda:True;second.set=lambda *a:None
  def read_second():c.set_source('new/stream1');return True,object()
  second.read=read_second
  captures=iter([first,second])
  ns['cv2']=SimpleNamespace(CAP_FFMPEG=1,CAP_PROP_BUFFERSIZE=2,CAP_PROP_OPEN_TIMEOUT_MSEC=3,CAP_PROP_READ_TIMEOUT_MSEC=4,VideoCapture=lambda *a:next(captures))
  ns['re']=__import__('re')
  self.assertIs(c._open_capture('old/stream1'),second)
  self.assertEqual(c.source,'new/stream1')
 def test_capture_thread_releases_its_own_stream_on_stop(self):
  c=self.channel();cap=c.cap;entered=threading.Event();finish=threading.Event()
  cap.isOpened=lambda:True
  def read():entered.set();finish.wait(2);return False,None
  cap.read=read;c.running=True
  worker=threading.Thread(target=c.capture_loop);worker.start();self.assertTrue(entered.wait(1))
  c.stop();finish.set();worker.join(2)
  self.assertFalse(worker.is_alive());self.assertEqual(cap.releases,[worker.ident])
if __name__=='__main__':unittest.main()
