"""Exit-without-entry alerts: stored once per plate per window, in a disposable database."""
import importlib.util
import os
from pathlib import Path
import unittest
import uuid
import mysql.connector


def _connect_test_mysql(**kwargs):
    env_port = os.environ.get('SECSYS_DB_PORT')
    ports = [int(env_port)] if env_port else [3306, 3307]
    last_err = None
    for port in ports:
        try:
            return mysql.connector.connect(host='127.0.0.1', port=port, user='root', password='',
                                           connection_timeout=3, **kwargs)
        except Exception as e:
            last_err = e
    raise last_err


spec = importlib.util.spec_from_file_location('exit_alerts', Path('app/exit_alerts.py'))
exit_alerts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(exit_alerts)


class ExitAlertTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.name = 'secsys_' + uuid.uuid4().hex[:12] + '_test'
        cls.admin = _connect_test_mysql()
        cur = cls.admin.cursor()
        cur.execute('CREATE DATABASE `' + cls.name + '`')
        cur.close()

    @classmethod
    def tearDownClass(cls):
        assert cls.name.startswith('secsys_') and cls.name.endswith('_test')
        cur = cls.admin.cursor()
        cur.execute('DROP DATABASE `' + cls.name + '`')
        cur.close()
        cls.admin.close()

    def setUp(self):
        self.db = _connect_test_mysql(database=self.name)
        exit_alerts.ensure_table(self.db)
        cur = self.db.cursor()
        cur.execute('DELETE FROM exit_without_entry_alerts')
        self.db.commit()
        cur.close()

    def tearDown(self):
        self.db.close()

    def rows(self):
        cur = self.db.cursor(dictionary=True)
        cur.execute('SELECT * FROM exit_without_entry_alerts ORDER BY id')
        out = cur.fetchall()
        cur.close()
        self.db.commit()
        return out

    def test_records_unknown_vehicle_once(self):
        first = exit_alerts.record_exit_without_entry(self.db, 'abc 1234', {}, 'Exit Cam', 0.93)
        self.assertIsNotNone(first)
        self.assertEqual(first['category'], 'Unknown')
        # Same car still in front of the camera: no second alert
        self.assertIsNone(exit_alerts.record_exit_without_entry(self.db, 'ABC1234', {}, 'Exit Cam', 0.95))
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['plate_number'], 'ABC 1234')
        self.assertEqual(rows[0]['normalized_plate'], 'ABC1234')
        self.assertEqual(rows[0]['status'], 'New')
        self.assertEqual(float(rows[0]['confidence']), 93.0)

    def test_alerts_again_after_window(self):
        exit_alerts.record_exit_without_entry(self.db, 'XYZ123', {}, 'Exit Cam', 0.9)
        cur = self.db.cursor()
        cur.execute('UPDATE exit_without_entry_alerts SET detected_at = NOW() - INTERVAL 11 MINUTE')
        self.db.commit()
        cur.close()
        self.assertIsNotNone(exit_alerts.record_exit_without_entry(self.db, 'XYZ123', {}, 'Exit Cam', 0.9))
        self.assertEqual(len(self.rows()), 2)

    def test_registered_category_and_official_plate(self):
        info = {'registered_vehicle_id': 14, 'official_plate': 'NA16155', 'vehicle_type': 'Car'}
        alert = exit_alerts.record_exit_without_entry(self.db, 'NA1615S', info, 'Exit Cam', 0.88)
        self.assertEqual(alert['category'], 'Registered')
        self.assertEqual(alert['plate'], 'NA16155')
        self.assertEqual(self.rows()[0]['vehicle_type'], 'Car')

    def test_empty_plate_is_ignored(self):
        self.assertIsNone(exit_alerts.record_exit_without_entry(self.db, ' - ', {}, 'Exit Cam', 0.9))
        self.assertEqual(self.rows(), [])

    def test_attach_image_whitelists_column(self):
        alert = exit_alerts.record_exit_without_entry(self.db, 'IMG111', {}, 'Exit Cam', 0.9)
        exit_alerts.attach_alert_image(self.db, alert['id'], 'plate_image', 'uploads/exit_alerts/a.jpg')
        exit_alerts.attach_alert_image(self.db, alert['id'], 'status', 'Acknowledged')  # ignored
        row = self.rows()[0]
        self.assertEqual(row['plate_image'], 'uploads/exit_alerts/a.jpg')
        self.assertEqual(row['status'], 'New')


if __name__ == '__main__':
    unittest.main()
