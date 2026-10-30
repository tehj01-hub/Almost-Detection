"""Exercise the real visit writer using disposable copies of production table schemas."""
import importlib.util
from pathlib import Path
import unittest
import uuid
import mysql.connector

module_path=Path('app/vehicle_visit_logger.py')
record_vehicle_visit=None
if module_path.exists():
    spec=importlib.util.spec_from_file_location('vehicle_visit_logger',module_path)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    record_vehicle_visit=module.record_vehicle_visit

class AutomaticVisitTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.name='secsys_'+uuid.uuid4().hex[:12]+'_test'
        cls.admin=mysql.connector.connect(host='127.0.0.1',port=3307,user='root',password='')
        cur=cls.admin.cursor();cur.execute('CREATE DATABASE `'+cls.name+'`')
        for table in ['vehicle_logs','vehicle_log_dates','visitor_vehicles']:
            cur.execute('CREATE TABLE `'+cls.name+'`.`'+table+'` LIKE security_system.`'+table+'`')
        cur.close()
    @classmethod
    def tearDownClass(cls):
        assert cls.name.startswith('secsys_') and cls.name.endswith('_test')
        cur=cls.admin.cursor();cur.execute('DROP DATABASE `'+cls.name+'`');cur.close();cls.admin.close()
    def setUp(self):
        self.db=mysql.connector.connect(host='127.0.0.1',port=3307,user='root',password='',database=self.name)
        cur=self.db.cursor()
        for table in ['vehicle_log_dates','vehicle_logs','visitor_vehicles']:cur.execute('DELETE FROM '+table)
        self.db.commit();cur.close()
        self.info=dict(category='Visitor',vehicle_type='Car',purpose='Test visit')
    def tearDown(self):self.db.close()
    def write(self,plate='D566CP',direction='AUTO'):
        self.assertIsNotNone(record_vehicle_visit,'Missing atomic automatic visit writer')
        return record_vehicle_visit(self.db,plate,self.info,direction)
    def rows(self,sql):
        cur=self.db.cursor();cur.execute(sql);rows=cur.fetchall();cur.close();self.db.commit();return rows
    def test_second_appearance_sets_timeout_on_existing_visit(self):
        self.assertEqual(self.write()['direction'],'IN')
        self.rows("UPDATE vehicle_log_dates SET time_in=NOW()-INTERVAL 3 MINUTE")
        before=self.rows('SELECT id,time_in FROM vehicle_log_dates')[0]
        self.assertEqual(self.write()['direction'],'OUT')
        rows=self.rows('SELECT id,time_in,time_out,status,TIMESTAMPDIFF(SECOND,time_in,time_out) FROM vehicle_log_dates')
        self.assertEqual(len(rows),1);self.assertEqual(rows[0][:2],before)
        self.assertIsNotNone(rows[0][2]);self.assertEqual(rows[0][3],'OUT');self.assertGreaterEqual(rows[0][4],180)
        self.assertEqual(self.rows('SELECT direction FROM vehicle_logs ORDER BY id'),[('IN',),('OUT',)])
        self.assertEqual(self.rows('SELECT status,time_out IS NOT NULL FROM visitor_vehicles'),[('OUT',1)])
    def test_camera_presence_then_return_records_in_then_out(self):
        tracker_spec=importlib.util.spec_from_file_location('plate_tracker',Path('lpr_engine/plate_tracker.py'))
        tracker_module=importlib.util.module_from_spec(tracker_spec);tracker_spec.loader.exec_module(tracker_module)
        tracker=tracker_module.PlateTracker()
        reading=dict(normalized='D566CP',text='D566CP',decision='ACCEPT',confidence=.95,bbox=(10,10,110,50))
        directions=[]
        def frame(t,readings):
            for plate in tracker.observe_frame('entrance','AUTO',readings,now=t,require_departure=True):
                directions.append(self.write(plate['normalized'])['direction'])
        for t in range(12):frame(t,[reading])
        self.assertEqual(directions,['IN'])
        for t in range(12,15):frame(t,[])
        for t in range(15,25):frame(t,[reading])
        self.assertEqual(directions,['IN','OUT'])
        self.assertEqual(self.rows('SELECT time_in IS NOT NULL,time_out IS NOT NULL,status FROM vehicle_log_dates'),[(1,1,'OUT')])
    def test_third_appearance_opens_new_visit(self):
        self.assertEqual([self.write()['direction'] for _ in range(3)],['IN','OUT','IN'])
        self.assertEqual(self.rows('SELECT status FROM vehicle_log_dates ORDER BY id'),[('OUT',),('IN',)])
    def test_different_plate_has_independent_state(self):
        self.assertEqual(self.write('D566CP')['direction'],'IN')
        self.assertEqual(self.write('ABC1234')['direction'],'IN')
    def test_plate_formatting_matches_the_same_open_visit(self):
        self.write('D 566-CP')
        self.assertEqual(self.write('d566cp')['direction'],'OUT')
        self.assertEqual(len(self.rows('SELECT id FROM vehicle_log_dates')),1)
    def test_fixed_exit_camera_remains_out_without_fabricated_entry(self):
        self.assertEqual(self.write(direction='OUT')['direction'],'OUT')
        self.assertEqual(self.rows('SELECT time_in,time_out IS NOT NULL,status FROM vehicle_log_dates'),[(None,1,'OUT')])
    def test_failed_visit_write_rolls_back_event_and_visitor(self):
        self.assertIsNotNone(record_vehicle_visit,'Missing atomic automatic visit writer')
        self.rows("CREATE TRIGGER reject_test_visit BEFORE INSERT ON vehicle_log_dates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='intentional test failure'")
        try:
            with self.assertRaises(mysql.connector.Error):self.write()
            for table in ['vehicle_logs','vehicle_log_dates','visitor_vehicles']:
                self.assertEqual(self.rows('SELECT COUNT(*) FROM '+table)[0][0],0)
        finally:self.rows('DROP TRIGGER reject_test_visit')

if __name__=='__main__':unittest.main()
