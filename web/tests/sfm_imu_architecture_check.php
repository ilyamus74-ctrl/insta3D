<?php
$root=dirname(__DIR__);
$checks=[
  'process_colmap_sparse.sh contains find_imu_jsonl'=>strpos(file_get_contents($root.'/remote_station/scripts/process_colmap_sparse.sh'),'find_imu_jsonl()')!==false,
  'process_colmap_sparse.sh logs IMU path'=>strpos(file_get_contents($root.'/remote_station/scripts/process_colmap_sparse.sh'),'IMU | Using IMU JSONL')!==false,
  'imu_utils.py exists'=>is_file($root.'/remote_station/scripts/imu_utils.py'),
  'analyze_sparse_trajectory.py imports imu_utils'=>strpos(file_get_contents($root.'/remote_station/scripts/analyze_sparse_trajectory.py'),'from imu_utils import')!==false,
  'build_world_alignment.py imports imu_utils'=>strpos(file_get_contents($root.'/remote_station/scripts/build_world_alignment.py'),'from imu_utils import')!==false,
];
$ok=true; foreach($checks as $name=>$pass){ echo ($pass?'OK ':'FAIL ').$name.PHP_EOL; if(!$pass)$ok=false; }
exit($ok?0:1);