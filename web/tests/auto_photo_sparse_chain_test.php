<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . '/auto_photo_sparse_chain_' . bin2hex(random_bytes(6));
define('AUTO_PHOTO_SPARSE_OUTPUT_ROOT', $testRoot);
require_once __DIR__ . '/../libs/auto_photo_sparse_chain_lib.php';

function chain_assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function chain_expect(callable $callback, string $expected): void { try { $callback(); } catch (Throwable $e) { chain_assert($e->getMessage() === $expected, "expected {$expected}, got {$e->getMessage()}"); return; } throw new RuntimeException("missing {$expected}"); }

class ChainResult extends mysqli_result {
    private int $offset = 0;
    public function __construct(private array $rows) {}
    public function fetch_assoc(): array|null|false { return $this->rows[$this->offset++] ?? null; }
}
class ChainStatement extends mysqli_stmt {
    public array $bound = []; public string $types = '';
    public function __construct(private ChainDb $db, private string $sql) {}
    public function bind_param(string $types, mixed &...$vars): bool { $this->types=$types; $this->bound=$vars; $this->db->binds[]=['sql'=>$this->sql,'types'=>$types,'bound'=>$vars]; return true; }
    public function execute(?array $params = null): bool {
        $kind=$this->kind();
        if ($this->db->failExecute[$kind] ?? false) return false;
        if ($kind === 'insert') $this->db->inserts[]=['sql'=>$this->sql,'bound'=>$this->bound];
        return true;
    }
    public function get_result(): mysqli_result|false {
        $kind=$this->kind();
        if ($this->db->failResult[$kind] ?? false) return false;
        if ($kind === 'parent') return new ChainResult($this->db->parent === [] ? [] : [$this->db->parent]);
        if ($kind === 'duplicate') return new ChainResult($this->db->matchingCandidates($this->bound));
        return new ChainResult([]);
    }
    public function close(): true { return true; }
    private function kind(): string { if (str_starts_with($this->sql,'INSERT')) return 'insert'; if (str_contains($this->sql,"job_type='COLMAP_SPARSE'")) return 'duplicate'; return 'parent'; }
}
class ChainDb extends mysqli {
    public bool $started=false,$committed=false,$rolledBack=false,$beginOk=true,$commitOk=true;
    public array $failPrepare=[], $failExecute=[], $failResult=[], $binds=[], $inserts=[], $candidates=[];
    public function __construct(public array $parent) {}
    public function begin_transaction(int $flags=0, ?string $name=null): bool { $this->started=true; return $this->beginOk; }
    public function prepare(string $query): mysqli_stmt|false { $kind=str_starts_with($query,'INSERT') ? 'insert' : (str_contains($query,"job_type='COLMAP_SPARSE'") ? 'duplicate' : 'parent'); return ($this->failPrepare[$kind] ?? false) ? false : new ChainStatement($this,$query); }
    public function commit(int $flags=0, ?string $name=null): bool { $this->committed=true; return $this->commitOk; }
    public function rollback(int $flags=0, ?string $name=null): bool { $this->rolledBack=true; return true; }
    /** Implements the same duplicate scope and canonical marker checks as the SQL query. */
    public function matchingCandidates(array $bound): array {
        [$order,$session,$parentRemote,$prepareId,$prepareRemote,$bundle,$uuid]=$bound;
        foreach ($this->candidates as $row) {
            if ((int)($row['order_id']??0)!==(int)$order || (int)($row['capture_session_id']??0)!==(int)$session || ($row['job_type']??'')!=='COLMAP_SPARSE' || (!array_key_exists('pipeline_run_id',$row) || $row['pipeline_run_id'] !== null) || (int)($row['parent_remote_job_id']??0)!==(int)$parentRemote || !in_array($row['status']??'', ['QUEUED','RUNNING','DONE'], true)) continue;
            $p=json_decode((string)($row['parameters_json']??''),true);
            if (!is_array($p) || ($p['source_type']??null)!=='auto_photo_prepare' || ($p['standalone_sparse']??null)!==true) continue;
            $strict=static fn(mixed $v): ?string => is_int($v) && $v>=0 ? (string)$v : (is_string($v) && preg_match('/^(0|[1-9][0-9]*)$/',$v) ? $v : null);
            if ($strict($p['prepare_job_id']??null)!==$prepareId || $strict($p['prepare_remote_job_id']??null)!==$prepareRemote || $strict($p['capture_bundle_id']??null)!==$bundle || ($p['app_bundle_uuid']??null)!==$uuid) continue;
            return [$row];
        }
        return [];
    }
}
function chain_fixture(): array {
    global $testRoot;
    mkdir("{$testRoot}/job_9001/frames",0775,true);
    file_put_contents("{$testRoot}/job_9001/frames/frame_000001.jpg",'one');
    file_put_contents("{$testRoot}/job_9001/frames/frame_000002.jpg",'two');
    file_put_contents("{$testRoot}/job_9001/result.json",json_encode(['schema_version'=>1,'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE,'status'=>'DONE','remote_job_id'=>9001,'capture_bundle_id'=>7,'app_bundle_uuid'=>'bundle-uuid','frames_count'=>2,'frames_directory'=>'frames','warnings'=>[]]));
    return ['id'=>745,'order_id'=>30,'capture_session_id'=>63,'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE,'remote_job_id'=>9001,'output_path'=>"{$testRoot}/job_9001",'result_json_path'=>"{$testRoot}/job_9001/result.json",'status'=>'DONE','parameters_json'=>'{}'];
}
function chain_candidate(string $status='QUEUED', array $replace=[]): array { return array_replace(['id'=>746,'remote_job_id'=>9002,'order_id'=>30,'capture_session_id'=>63,'job_type'=>'COLMAP_SPARSE','pipeline_run_id'=>null,'parent_remote_job_id'=>9001,'status'=>$status,'parameters_json'=>json_encode(['source_type'=>'auto_photo_prepare','standalone_sparse'=>true,'prepare_job_id'=>745,'prepare_remote_job_id'=>9001,'capture_bundle_id'=>7,'app_bundle_uuid'=>'bundle-uuid'])],$replace); }
function chain_run(ChainDb $db, ?callable $id=null, ?callable $remote=null): array { return auto_photo_sparse_chain_enqueue_from_prepare($db,745,$id ?? static fn(mysqli $db): int => 746,$remote ?? static fn(mysqli $db): int => 9002); }

try {
    $parent=chain_fixture();
    $hashes=[]; foreach (['result.json','frames/frame_000001.jpg','frames/frame_000002.jpg'] as $file) $hashes[$file]=hash_file('sha256',"{$testRoot}/job_9001/{$file}");
    $db=new ChainDb($parent); $result=chain_run($db);
    chain_assert($db->started && $db->committed && !$db->rolledBack && count($db->inserts)===1,'valid enqueue transaction');
    chain_assert($result===['duplicate'=>false,'prepare_db_job_id'=>745,'prepare_remote_job_id'=>9001,'sparse_db_job_id'=>746,'sparse_remote_job_id'=>9002,'capture_bundle_id'=>7,'input_images'=>2],'valid result');
    $insert=$db->inserts[0]; chain_assert(str_contains($insert['sql'],'pipeline_run_id') && str_contains($insert['sql'],'NULL') && str_contains($insert['sql'],"'QUEUED'") && str_contains($insert['sql'],',0,'),'insert literals');
    chain_assert($db->binds[array_key_last($db->binds)]['types']==='iisiissssss','insert bind signature');
    chain_assert($insert['bound'][2]==='COLMAP_SPARSE' && $insert['bound'][4]===9001 && $insert['bound'][5]==='/home/makler_storage/output/job_9001/frames','insert parent/input');
    chain_assert($insert['bound'][6]==="{$testRoot}/job_9002" && $insert['bound'][8]==="{$testRoot}/job_9002/result.json" && $insert['bound'][9]==="{$testRoot}/job_9002/logs" && $insert['bound'][7]==='Standalone sparse auto-queued after Auto Photo prepare','insert paths/message');
    chain_assert(json_decode($insert['bound'][10],true)['source_type']==='auto_photo_prepare' && json_decode($insert['bound'][10],true)['standalone_sparse']===true && json_decode($insert['bound'][10],true)['prepare_job_id']===745 && json_decode($insert['bound'][10],true)['prepare_remote_job_id']===9001 && json_decode($insert['bound'][10],true)['capture_bundle_id']===7 && json_decode($insert['bound'][10],true)['app_bundle_uuid']==='bundle-uuid' && json_decode($insert['bound'][10],true)['input_images']===2 && isset(json_decode($insert['bound'][10],true)['settings']['sparse']),'canonical parameters');
    foreach ($hashes as $file=>$hash) chain_assert(hash_file('sha256',"{$testRoot}/job_9001/{$file}")===$hash,"immutable {$file}");
    chain_assert(!file_exists("{$testRoot}/job_9002"),'service does not create output directory');

    foreach (['QUEUED','RUNNING','DONE'] as $status) { $db=new ChainDb($parent); $db->candidates=[chain_candidate($status)]; $r=chain_run($db); chain_assert($r['duplicate'] && $r['sparse_db_job_id']===746 && $r['sparse_remote_job_id']===9002 && !$db->inserts && $db->committed,"{$status} duplicate"); }
    foreach (['ERROR','FAILED','CANCELLED'] as $status) { $db=new ChainDb($parent); $db->candidates=[chain_candidate($status)]; $r=chain_run($db); chain_assert(!$r['duplicate'] && count($db->inserts)===1,"{$status} nonblocking candidate"); }
    $mismatches=['order_id'=>31,'capture_session_id'=>64,'parent_remote_job_id'=>9003,'pipeline_run_id'=>1,'job_type'=>'EXPORT_PLY'];
    foreach ($mismatches as $key=>$value) { $db=new ChainDb($parent); $db->candidates=[chain_candidate('QUEUED',[$key=>$value])]; chain_assert(!chain_run($db)['duplicate'] && count($db->inserts)===1,"scope {$key}"); }
    foreach ([['source_type','wrong'],['standalone_sparse',false],['prepare_job_id',744],['prepare_remote_job_id',9000],['capture_bundle_id',8],['app_bundle_uuid','wrong']] as [$key,$value]) { $p=json_decode(chain_candidate()['parameters_json'],true);$p[$key]=$value;$db=new ChainDb($parent);$db->candidates=[chain_candidate('QUEUED',['parameters_json'=>json_encode($p)])];chain_assert(!chain_run($db)['duplicate']&&count($db->inserts)===1,"marker {$key}"); }
    $db=new ChainDb($parent);$db->candidates=[chain_candidate('QUEUED',['parameters_json'=>'{'])];chain_assert(!chain_run($db)['duplicate']&&count($db->inserts)===1,'malformed candidate');
    foreach ([['id',0],['remote_job_id',0]] as [$key,$value]) {$db=new ChainDb($parent);$db->candidates=[chain_candidate('DONE',[$key=>$value])];chain_expect(fn()=>chain_run($db),'sparse_duplicate_result_invalid');chain_assert($db->rolledBack,'invalid duplicate rollback');}

    chain_expect(fn()=>auto_photo_sparse_chain_enqueue_from_prepare(new ChainDb($parent),0),'prepare_job_id_invalid');
    $failures=[['beginOk',false,'prepare_parent_query_failed',false],['failPrepare.parent',true,'prepare_parent_query_failed',true],['failExecute.parent',true,'prepare_parent_query_failed',true],['failResult.parent',true,'prepare_parent_query_failed',true],['parent',[],'prepare_parent_missing',true]];
    foreach($failures as [$path,$value,$error,$rollback]){$db=new ChainDb($parent);if($path==='parent')$db->parent=$value;else{[$group,$field]=array_pad(explode('.',$path),2,null);if($field===null)$db->$group=$value;else $db->$group[$field]=$value;}chain_expect(fn()=>chain_run($db),$error);chain_assert($db->rolledBack===$rollback,"{$path} rollback");}
    $bad=$parent;$bad['status']='RUNNING';$db=new ChainDb($bad);chain_expect(fn()=>chain_run($db),'prepare_not_done');chain_assert($db->rolledBack,'not done rollback');
    file_put_contents("{$testRoot}/job_9001/result.json",'{');$db=new ChainDb($parent);chain_expect(fn()=>chain_run($db),'prepare_result_invalid');chain_assert($db->rolledBack,'invalid result rollback');
    file_put_contents("{$testRoot}/job_9001/result.json",json_encode(['schema_version'=>1,'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE,'status'=>'DONE','remote_job_id'=>9001,'capture_bundle_id'=>7,'app_bundle_uuid'=>'bundle-uuid','frames_count'=>3,'frames_directory'=>'frames','warnings'=>[]]));$db=new ChainDb($parent);chain_expect(fn()=>chain_run($db),'frames_count_mismatch');chain_assert($db->rolledBack,'frame mismatch rollback');
    file_put_contents("{$testRoot}/job_9001/result.json",json_encode(['schema_version'=>1,'job_type'=>AUTO_PHOTO_PREPARE_JOB_TYPE,'status'=>'DONE','remote_job_id'=>9001,'capture_bundle_id'=>7,'app_bundle_uuid'=>'bundle-uuid','frames_count'=>2,'frames_directory'=>'frames','warnings'=>[]]));
    foreach (['failPrepare.duplicate','failExecute.duplicate','failResult.duplicate'] as $path){$db=new ChainDb($parent);[$group,$field]=explode('.',$path);$db->$group[$field]=true;chain_expect(fn()=>chain_run($db),'sparse_duplicate_query_failed');chain_assert($db->rolledBack,"{$path} rollback");}
    foreach ([[static fn(mysqli $db):int=>0,'sparse_remote_job_id_invalid'],[static fn(mysqli $db):int=>9001,'sparse_remote_job_id_invalid']] as [$factory,$error]){$db=new ChainDb($parent);chain_expect(fn()=>chain_run($db,null,$factory),$error);chain_assert($db->rolledBack,'remote rollback');}
    foreach ([['failPrepare.insert','sparse_insert_failed'],['failExecute.insert','sparse_insert_failed']] as [$path,$error]){$db=new ChainDb($parent);[$group,$field]=explode('.',$path);$db->$group[$field]=true;chain_expect(fn()=>chain_run($db),$error);chain_assert($db->rolledBack,"{$path} rollback");}
    $db=new ChainDb($parent);chain_expect(fn()=>chain_run($db,static fn(mysqli $db):int=>0),'sparse_insert_id_invalid');chain_assert($db->rolledBack,'insert id rollback');
    $db=new ChainDb($parent);$db->commitOk=false;chain_expect(fn()=>chain_run($db),'sparse_commit_failed');chain_assert($db->rolledBack,'commit rollback');
    $worker=(string)file_get_contents(__DIR__.'/../tools/sfm_remote_worker.php');foreach(['auto_photo_sparse_chain_lib.php','auto_photo_sparse_chain_enqueue_from_prepare','auto_photo_sparse_is_standalone_job($job)'] as $needle)chain_assert(str_contains($worker,$needle),"worker {$needle}");
    echo "OK\n";
} finally { $remove=static function(string $path) use (&$remove):void { if(!file_exists($path))return;if(is_file($path)){unlink($path);return;}foreach(scandir($path)?:[] as $entry)if($entry!=='.'&&$entry!=='..')$remove($path.'/'.$entry);rmdir($path);};$remove($testRoot); }
