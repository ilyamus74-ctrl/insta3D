<?php
declare(strict_types=1);

function sfm_system_defaults(): array {
    return [
        'extract'=>['fps'=>2.0,'max_frames'=>360,'scale_width'=>1920,'jpeg_quality'=>2],
        'sparse'=>['matcher'=>'sequential','sequential_overlap'=>30,'loop_detection'=>false],
        'preview'=>['max_image_size'=>640,'num_src_images'=>16,'chunk_overlap'=>30,'target_images_per_chunk'=>50,'max_images_per_chunk'=>80,'mesh_depth'=>7,'target_faces'=>100000,'mesh_engine'=>'open3d','density_quantile'=>0.10],
        'standard'=>['max_image_size'=>1600,'num_src_images'=>24,'chunk_overlap'=>30,'target_images_per_chunk'=>45,'max_images_per_chunk'=>70,'mesh_depth'=>8,'target_faces'=>300000,'mesh_engine'=>'open3d','density_quantile'=>0.07],
        'fullhd'=>['max_image_size'=>1920,'num_src_images'=>30,'chunk_overlap'=>30,'target_images_per_chunk'=>35,'max_images_per_chunk'=>55,'mesh_depth'=>9,'target_faces'=>500000,'mesh_engine'=>'open3d','density_quantile'=>0.05],
    ];
}

function sfm_settings_json_encode(array $settings): string { return json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function sfm_json_array(?string $json): array { $v=json_decode((string)$json,true); return is_array($v)?$v:[]; }
function sfm_load_user_settings(mysqli $db,int $userId): array { $st=$db->prepare('SELECT settings_json FROM sfm_user_settings WHERE user_id=? LIMIT 1'); if(!$st){return [];} $st->bind_param('i',$userId); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); return $row?sfm_json_array($row['settings_json']):[]; }
function sfm_save_user_settings(mysqli $db,int $userId,array $settings): void { $json=sfm_settings_json_encode(sfm_validate_settings($settings)); $st=$db->prepare('INSERT INTO sfm_user_settings (user_id,settings_json) VALUES (?,?) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=NOW(6)'); if(!$st){throw new RuntimeException($db->error);} $st->bind_param('is',$userId,$json); $st->execute(); $st->close(); }
function sfm_reset_user_settings(mysqli $db,int $userId): void { $st=$db->prepare('DELETE FROM sfm_user_settings WHERE user_id=?'); if($st){$st->bind_param('i',$userId);$st->execute();$st->close();} }
function sfm_load_session_settings(mysqli $db,int $sessionId,int $userId): array { $st=$db->prepare('SELECT settings_json FROM sfm_session_settings WHERE capture_session_id=? AND user_id=? LIMIT 1'); if(!$st){return [];} $st->bind_param('ii',$sessionId,$userId); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close(); return $row?sfm_json_array($row['settings_json']):[]; }
function sfm_save_session_settings(mysqli $db,int $sessionId,int $userId,array $settings): void { $json=sfm_settings_json_encode(sfm_validate_settings($settings)); $st=$db->prepare('INSERT INTO sfm_session_settings (capture_session_id,user_id,settings_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=NOW(6)'); if(!$st){throw new RuntimeException($db->error);} $st->bind_param('iis',$sessionId,$userId,$json); $st->execute(); $st->close(); }
function sfm_reset_session_settings(mysqli $db,int $sessionId,int $userId): void { $st=$db->prepare('DELETE FROM sfm_session_settings WHERE capture_session_id=? AND user_id=?'); if($st){$st->bind_param('ii',$sessionId,$userId);$st->execute();$st->close();} }
function sfm_merge_settings(array $system,array $user,array $session,array $request): array { return array_replace_recursive($system,$user,$session,$request); }

function sfm_validate_settings(array $settings): array {
    $defaults=sfm_system_defaults(); $out=[]; $errors=[];
    $num=function($path,$min,$max,$float=false) use ($settings,&$out,&$errors){ $parts=explode('.',$path); $v=$settings; foreach($parts as $p){ if(!is_array($v)||!array_key_exists($p,$v)){return;} $v=$v[$p]; } if(!is_numeric($v)){ $errors[$path]='must be numeric'; return; } $n=$float?(float)$v:(int)$v; if($n<$min||$n>$max){$errors[$path]="must be between {$min} and {$max}"; return;} $ref=&$out; foreach($parts as $p){ if(!isset($ref[$p])||!is_array($ref[$p])){$ref[$p]=[];} $ref=&$ref[$p]; } $ref=$n; };
    foreach(['extract.fps'=>[0.5,10,true],'extract.max_frames'=>[30,2000,false],'extract.scale_width'=>[640,3840,false],'extract.jpeg_quality'=>[1,31,false],'sparse.sequential_overlap'=>[5,100,false]] as $p=>$r){$num($p,$r[0],$r[1],$r[2]);}
    if(isset($settings['sparse']['matcher'])){ if(in_array($settings['sparse']['matcher'],['sequential','exhaustive'],true)){$out['sparse']['matcher']=$settings['sparse']['matcher'];} else {$errors['sparse.matcher']='unsupported value';} }
    if(isset($settings['sparse']['loop_detection'])){$out['sparse']['loop_detection']=filter_var($settings['sparse']['loop_detection'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)??false;}
    foreach(['preview','standard','fullhd'] as $mode){ foreach(['max_image_size'=>[320,3840,false],'num_src_images'=>[2,50,false],'chunk_overlap'=>[0,60,false],'target_images_per_chunk'=>[10,200,false],'max_images_per_chunk'=>[10,300,false],'mesh_depth'=>[5,12,false],'target_faces'=>[10000,2000000,false],'density_quantile'=>[0,0.30,true]] as $k=>$r){$num($mode.'.'.$k,$r[0],$r[1],$r[2]);} if(isset($settings[$mode]['mesh_engine'])){ if(in_array($settings[$mode]['mesh_engine'],['open3d','colmap'],true)){$out[$mode]['mesh_engine']=$settings[$mode]['mesh_engine'];} else {$errors[$mode.'.mesh_engine']='unsupported value';} } }
    $merged=array_replace_recursive($defaults,$out);
    foreach(['preview','standard','fullhd'] as $m){ if($merged[$m]['max_images_per_chunk'] < $merged[$m]['target_images_per_chunk']){$errors[$m.'.max_images_per_chunk']='must be >= target_images_per_chunk';} if($merged[$m]['chunk_overlap'] >= $merged[$m]['target_images_per_chunk']){$errors[$m.'.chunk_overlap']='must be < target_images_per_chunk';} if($merged[$m]['num_src_images'] >= $merged[$m]['max_images_per_chunk']){$errors[$m.'.num_src_images']='must be < max_images_per_chunk';} }
    if($errors){ throw new InvalidArgumentException('Invalid SfM settings: '.json_encode($errors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    return $out;
}
function sfm_mode_parameters(array $settings,string $mode): array { $m=$settings[$mode]??[]; return ['extract'=>$settings['extract']??[],'sparse'=>$settings['sparse']??[],'dense'=>['max_image_size'=>$m['max_image_size']??null,'num_src_images'=>$m['num_src_images']??null,'chunk_overlap'=>$m['chunk_overlap']??null,'target_images_per_chunk'=>$m['target_images_per_chunk']??null,'max_images_per_chunk'=>$m['max_images_per_chunk']??null],'mesh'=>['engine'=>$m['mesh_engine']??'open3d','depth'=>$m['mesh_depth']??null,'target_faces'=>$m['target_faces']??null,'density_quantile'=>$m['density_quantile']??null]]; }