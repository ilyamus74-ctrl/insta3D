import json, math, tempfile, unittest
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from imu_utils import parse_imu_jsonl, android_rotation_vector, interpolate_quaternion, integrate_gyro_deg, estimate_gravity, frame_motion_at

class ImuUtilsTest(unittest.TestCase):
    def parse(self, lines):
        f=tempfile.NamedTemporaryFile('w+', delete=False)
        f.write('\n'.join(lines)); f.close()
        return parse_imu_jsonl(f.name)
    def test_android_sensors_and_rotation(self):
        d=self.parse([
            '{"t_ns":1000000000,"sensor":"gyro","values":[1,2,3]}',
            '{"t_ns":1010000000,"sensor":"accel","values":[0,9.8,0]}',
            '{"t_ns":1020000000,"sensor":"gravity","values":[0,0,9.8]}',
            '{"t_ns":1030000000,"sensor":"rotation_vector","values":[0,0,0,2,0]}'
        ])
        self.assertEqual(d.counts()['gyro'],1); self.assertEqual(d.counts()['accel'],1); self.assertEqual(d.counts()['gravity'],1); self.assertEqual(d.counts()['rotation_vector'],1)
        self.assertEqual(d.by_sensor('rotation_vector')[0]['quaternion'], [1,0,0,0])
        self.assertEqual(d.sync_info['method'],'first_imu_sample')
    def test_rotation_vector_len3(self):
        q=android_rotation_vector([0,0,0])
        self.assertEqual(q,[1,0,0,0])
    def test_video_t_sec_and_metadata(self):
        d=self.parse(['{"type":"metadata","schema_version":2,"video_start_t_ns":1000000000}', '{"t_ns":1500000000,"sensor":"gyro","values":[0,0,1]}'])
        self.assertAlmostEqual(d.records[0]['t_sec'],0.5); self.assertEqual(d.sync_info['method'],'video_start_t_ns')
        d2=self.parse(['{"t_ns":5,"video_t_sec":2.5,"sensor":"accel","values":[0,0,9.8]}'])
        self.assertEqual(d2.sync_info['method'],'video_t_sec'); self.assertEqual(d2.records[0]['t_sec'],2.5)
    def test_legacy_corrupt_duplicate(self):
        d=self.parse(['bad','{"timestamp_sec":1,"gyro":[0,0,1]}','{"timestamp_sec":1,"accel":[0,0,9.8]}','{"time":2,"q":[2,0,0,0]}'])
        self.assertEqual(d.bad_json_lines,1); self.assertEqual(len(d.records),3); self.assertEqual(d.by_sensor('rotation_vector')[0]['quaternion'],[1,0,0,0])
    def test_gyro_integration_and_interp(self):
        d=self.parse(['{"timestamp_sec":0,"gyro":[0,0,1]}','{"timestamp_sec":1,"gyro":[0,0,1]}','{"timestamp_sec":0,"q":[1,0,0,0]}','{"timestamp_sec":1,"q":[0,0,0,1]}'])
        self.assertAlmostEqual(integrate_gyro_deg(d.by_sensor('gyro'),0,1), math.degrees(1), places=4)
        q=interpolate_quaternion(d.by_sensor('rotation_vector'),0.5,max_gap=1)
        self.assertIsNotNone(q); self.assertAlmostEqual(sum(x*x for x in q),1,places=5)
    def test_gravity_motion_no_imu(self):
        d=self.parse(['{"timestamp_sec":0,"gravity":[0,0,9.8]}','{"timestamp_sec":1,"gravity":[0,0,9.7]}','{"timestamp_sec":2,"gravity":[0,0,9.9]}','{"timestamp_sec":1,"gyro":[0,0,0.1]}','{"timestamp_sec":1,"accel":[0,0,9.8]}'])
        self.assertEqual(estimate_gravity(d)['source'],'imu_gravity')
        self.assertGreater(frame_motion_at(d,1)['angular_velocity_deg_sec'],0)
        self.assertFalse(parse_imu_jsonl('/no/such/file').records)

if __name__=='__main__': unittest.main()