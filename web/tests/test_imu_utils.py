#!/usr/bin/env python3
import json, tempfile, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'remote_station' / 'scripts'))
from imu_utils import parse_imu_jsonl

def write(lines):
    f=tempfile.NamedTemporaryFile('w',delete=False,suffix='.jsonl')
    f.write('\n'.join(lines)); f.close(); return f.name

p=write([
    json.dumps({'type':'metadata','schema_version':2,'video_start_t_ns':1000000000,'imu_start_t_ns':1000000000,'clock':'CLOCK_BOOTTIME'}),
    json.dumps({'t_ns':1000000000,'video_t_sec':0.25,'sensor':'gyro','values':[1,2,3]}),
    'not-json',
    json.dumps({'t_ns':1010000000,'video_t_sec':0.50,'sensor':'gravity','values':[0,0,9.8]}),
    json.dumps({'t_ns':1020000000,'video_t_sec':0.75,'sensor':'rotation_vector','values':[0.1,0.2,0.3,0.9,123]}),
    json.dumps({'t_ns':1030000000,'video_t_sec':1.00,'sensor':'accelerometer','values':[0,0,9.7]}),
])
d=parse_imu_jsonl(p)
assert d.summary()['available'] is True
assert d.counts()=={'gyro':1,'accel':1,'gravity':1,'rotation_vector':1}
assert d.records[0]['t_sec']==0.25
assert d.bad_json_lines==1
assert d.by_sensor('rotation_vector')[0]['quaternion'][0] > d.by_sensor('rotation_vector')[0]['quaternion'][1]

p2=write([
    json.dumps({'type':'metadata','video_start_t_ns':2000000000}),
    json.dumps({'t_ns':2500000000,'sensor':'gyro','values':[0,0,1]}),
    json.dumps({'t_ns':3000000000,'sensor':'gravity','values':[0,0,9.8]}),
])
d2=parse_imu_jsonl(p2)
assert [r['t_sec'] for r in d2.records] == [0.5, 1.0]
assert d2.sync_info['method']=='video_start_t_ns'

p3=write([
    json.dumps({'t_ns':5000000000,'sensor':'gyro','values':[0,0,1]}),
    json.dumps({'t_ns':5500000000,'sensor':'accel','values':[0,0,9.8]}),
])
d3=parse_imu_jsonl(p3)
assert [r['t_sec'] for r in d3.records] == [0.0, 0.5]
print('OK')