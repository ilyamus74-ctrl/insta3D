<?php
declare(strict_types=1);

require_once __DIR__ . '/../www/bootstrap.php';

$required = [
'id'=>"`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
'session_id'=>"`session_id` BIGINT UNSIGNED NOT NULL",
'from_photo_point_id'=>"`from_photo_point_id` BIGINT UNSIGNED NOT NULL",
'to_photo_point_id'=>"`to_photo_point_id` BIGINT UNSIGNED NOT NULL",
'yaw_deg'=>"`yaw_deg` DOUBLE NOT NULL DEFAULT 0",
'pitch_deg'=>"`pitch_deg` DOUBLE NOT NULL DEFAULT 0",
'target_yaw_deg'=>"`target_yaw_deg` DECIMAL(8,3) NULL DEFAULT NULL",
'target_pitch_deg'=>"`target_pitch_deg` DECIMAL(8,3) NULL DEFAULT NULL",
'target_hfov'=>"`target_hfov` DECIMAL(8,3) NULL DEFAULT NULL",
'label'=>"`label` VARCHAR(255) NULL",
'created_at'=>"`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
'updated_at'=>"`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
'source'=>"`source` VARCHAR(32) NOT NULL DEFAULT 'MANUAL'",
'shared_markers_json'=>"`shared_markers_json` LONGTEXT NULL",
'confidence'=>"`confidence` DOUBLE NULL",
];

$res=$dbcnx->query('DESCRIBE tour_point_links'); if(!$res){fwrite(STDERR,"Cannot DESCRIBE tour_point_links: {$dbcnx->error}\n");exit(1);} $existing=[]; while($r=$res->fetch_assoc())$existing[$r['Field']]=true; $res->free();
foreach($required as $n=>$def){ if(isset($existing[$n])){echo "OK: {$n} exists\n";continue;} $sql="ALTER TABLE tour_point_links ADD COLUMN {$def}"; if(!$dbcnx->query($sql)){fwrite(STDERR,"FAILED: {$n}: {$dbcnx->error}\n");exit(1);} echo "ADDED: {$n}\n"; }
echo "Done.\n";

