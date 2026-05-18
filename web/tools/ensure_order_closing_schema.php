<?php
declare(strict_types=1);
require_once __DIR__ . '/../www/bootstrap.php';

$cols=[]; $r=$dbcnx->query("DESCRIBE tour_orders"); while($row=$r->fetch_assoc()){$cols[$row['Field']]=true;} $r->close();
$need=[
 'operator_closed_at'=>"ALTER TABLE tour_orders ADD COLUMN operator_closed_at DATETIME(6) NULL DEFAULT NULL",
 'operator_closed_by'=>"ALTER TABLE tour_orders ADD COLUMN operator_closed_by BIGINT UNSIGNED NULL DEFAULT NULL",
 'broker_closed_at'=>"ALTER TABLE tour_orders ADD COLUMN broker_closed_at DATETIME(6) NULL DEFAULT NULL",
 'broker_closed_by'=>"ALTER TABLE tour_orders ADD COLUMN broker_closed_by BIGINT UNSIGNED NULL DEFAULT NULL",
];
foreach($need as $k=>$sql){ if(!isset($cols[$k])){ $dbcnx->query($sql); echo "added $k\n"; }}
$idx=[]; $r=$dbcnx->query("SHOW INDEX FROM tour_orders"); while($row=$r->fetch_assoc()){$idx[$row['Key_name']]=true;} $r->close();
if(!isset($idx['idx_tour_orders_operator_active'])) $dbcnx->query("CREATE INDEX idx_tour_orders_operator_active ON tour_orders (operator_id, operator_closed_at, status)");
if(!isset($idx['idx_tour_orders_broker_active'])) $dbcnx->query("CREATE INDEX idx_tour_orders_broker_active ON tour_orders (broker_id, broker_closed_at, status)");
echo "done\n";
