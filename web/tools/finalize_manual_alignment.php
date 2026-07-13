<?php
declare(strict_types=1);
require_once __DIR__.'/../www/bootstrap.php';
require_once __DIR__.'/../libs/sfm_manual_alignment_lib.php';
$opts=getopt('', ['order-id:','anchor-kind:','anchor-id:','source-kind:','source-id:','user-id:']);
$required=['order-id','anchor-id','source-id','user-id'];
foreach($required as $key){ if(!isset($opts[$key]) || (int)$opts[$key]<=0){ fwrite(STDERR,"Missing required --$key\n"); exit(2); } }
try {
    $res=sfm_manual_finalize($dbcnx,(int)$opts['order-id'],(string)($opts['anchor-kind']??'remote'),(int)$opts['anchor-id'],(string)($opts['source-kind']??'remote'),(int)$opts['source-id'],(int)$opts['user-id'],'ADMIN');
    echo json_encode($res,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
} catch(Throwable $e){ fwrite(STDERR,$e->getMessage()."\n"); exit(1); }