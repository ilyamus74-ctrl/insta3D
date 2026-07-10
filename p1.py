from pathlib import Path
import re

order = Path("web/www/order.php")
text = order.read_text(encoding="utf-8")

# 1) Add sparse component helper functions after sfm_sparse_model_stats()
if "function sfm_sparse_components(" not in text:
    marker = "function sfm_ply_is_downloadable(int $parentRemoteId): bool {"
    insert = r'''
function sfm_sparse_components(int $sparseJobId): array {
  $path=sfm_remote_output_dir($sparseJobId).'/colmap/sparse_components.json';
  if(!is_file($path)){ $path=sfm_remote_output_dir($sparseJobId).'/sparse_components.json'; }
  $data=is_file($path)?(json_decode((string)file_get_contents($path),true)?:[]):[];
  if(!isset($data['models']) || !is_array($data['models'])){
    $models=[];
    foreach(glob(sfm_remote_output_dir($sparseJobId).'/colmap/sparse/*', GLOB_ONLYDIR) ?: [] as $d){
      $base=basename($d);
      if(!ctype_digit($base)){ continue; }
      $st=sfm_sparse_model_stats($sparseJobId,(int)$base);
      if((int)$st['registered_images']<=0 && (int)$st['points3D']<=0){ continue; }
      $models[]=[
        'model_id'=>(int)$base,
        'registered_images'=>(int)$st['registered_images'],
        'first_frame'=>null,
        'last_frame'=>null,
        'frame_ranges'=>[],
        'points3D_count'=>(int)$st['points3D'],
        'percent_of_extracted_frames'=>null,
        'shared_images_with'=>[],
      ];
    }
    usort($models,fn($a,$b)=>($a['model_id']<=>$b['model_id']));
    $largest=null;
    foreach($models as $m){
      if($largest===null || $m['registered_images']>$largest['registered_images'] || ($m['registered_images']===$largest['registered_images'] && $m['points3D_count']>$largest['points3D_count'])){
        $largest=$m;
      }
    }
    $data=[
      'models_count'=>count($models),
      'largest_model_id'=>$largest['model_id']??null,
      'largest_model_registered_images'=>$largest['registered_images']??0,
      'models'=>$models,
    ];
  }
  if(isset($data['models']) && is_array($data['models'])){
    foreach($data['models'] as &$m){
      $mid=(int)($m['model_id']??0);
      $st=sfm_sparse_model_stats($sparseJobId,$mid);
      $m+=['preview_enabled'=>$st['preview_enabled'],'hq_enabled'=>$st['hq_enabled']];
    }
    unset($m);
  }
  $data['path']=$path;
  return $data;
}

function sfm_sparse_model_ids(int $sparseJobId): array {
  $c=sfm_sparse_components($sparseJobId);
  $ids=[];
  foreach(($c['models'] ?? []) as $m){ $ids[]=(int)($m['model_id'] ?? 0); }
  if(!$ids){
    foreach(glob(sfm_remote_output_dir($sparseJobId).'/colmap/sparse/*', GLOB_ONLYDIR) ?: [] as $d){
      if(ctype_digit(basename($d))){ $ids[]=(int)basename($d); }
    }
  }
  sort($ids);
  return $ids ?: [0,1];
}

function sfm_validate_sparse_model_for_order(mysqli $dbcnx, int $orderId, int $sparseRemoteJobId, int $modelId): array {
  $st=$dbcnx->prepare("SELECT * FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type='COLMAP_SPARSE' LIMIT 1");
  if(!$st){ throw new RuntimeException('DB prepare error: '.$dbcnx->error); }
  $st->bind_param('ii',$orderId,$sparseRemoteJobId);
  $st->execute();
  $job=$st->get_result()->fetch_assoc();
  $st->close();
  if(!$job){ throw new RuntimeException('COLMAP sparse job not found for this order'); }

  $modelDir=sfm_remote_output_dir($sparseRemoteJobId).'/colmap/sparse/'.$modelId;
  if(!is_dir($modelDir)){ throw new RuntimeException('Sparse model directory does not exist: '.$modelDir); }
  if(!is_file($modelDir.'/images.bin')){ throw new RuntimeException('Sparse model images.bin does not exist: '.$modelDir.'/images.bin'); }

  return $job;
}

'''
    if marker not in text:
        raise SystemExit("Cannot find insertion marker: sfm_ply_is_downloadable")
    text = text.replace(marker, insert + "\n" + marker, 1)

# 2) Update sparse selection model ids from [0,1] to dynamic ids
old = r"""foreach($standalone as $si=>$sj){ if((string)$sj['job_type']==='COLMAP_SPARSE'){ $selection=[]; foreach([0,1] as $mid){ $selection[$mid]=['label'=>'Not selected','class'=>'bg-secondary']; }"""
new = r"""foreach($standalone as $si=>$sj){ if((string)$sj['job_type']==='COLMAP_SPARSE'){ $selection=[]; foreach(sfm_sparse_model_ids((int)$sj['remote_job_id']) as $mid){ $selection[$mid]=['label'=>'Not selected','class'=>'bg-secondary']; }"""
if old in text:
    text = text.replace(old, new, 1)

# 3) Replace best_model limited [0,1] with dynamic model ids
text = text.replace(
    "if(isset($_POST['best_model'])){ $model=sfm_best_sparse_model_id($colmap,[0,1]); }",
    "if(isset($_POST['best_model'])){ $model=sfm_best_sparse_model_id($colmap,sfm_sparse_model_ids($colmap)); }"
)

# 4) Strengthen model validation in export PLY, if old simple select still exists
old_export = r"""$colmap=(int)($_POST['colmap_job_id']??0); $model=(int)($_POST['model_id']??0); if($colmap<=0||$model<0){throw new RuntimeException('Bad COLMAP job or model id');}
       $st=$dbcnx->prepare("SELECT capture_session_id FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type='COLMAP_SPARSE' LIMIT 1"); $st->bind_param('ii',$orderId,$colmap); $st->execute(); $parentJob=$st->get_result()->fetch_assoc(); $st->close(); if(!$parentJob){throw new RuntimeException('COLMAP job not found');}"""
new_export = r"""$colmap=(int)($_POST['colmap_job_id']??($_POST['sparse_remote_job_id']??0)); $model=(int)($_POST['model_id']??0); if($colmap<=0||$model<0){throw new RuntimeException('Bad COLMAP job or model id');}
       $parentJob=sfm_validate_sparse_model_for_order($dbcnx,$orderId,$colmap,$model);"""
if old_export in text:
    text = text.replace(old_export, new_export, 1)

# 5) Enrich COLMAP_SPARSE jobs with sparse_components and dynamic dense_model_ids
old_sparse_enrich = r"""if((string)$j['job_type']==='COLMAP_SPARSE'){ foreach($j['dense_model_ids'] as $mid){ $j['sparse_model_stats'][(int)$mid]=sfm_sparse_model_stats((int)$j['remote_job_id'],(int)$mid); } }"""
new_sparse_enrich = r"""if((string)$j['job_type']==='COLMAP_SPARSE'){ $j['sparse_components']=sfm_sparse_components((int)$j['remote_job_id']); $j['dense_model_ids']=sfm_sparse_model_ids((int)$j['remote_job_id']); foreach($j['dense_model_ids'] as $mid){ $j['sparse_model_stats'][(int)$mid]=sfm_sparse_model_stats((int)$j['remote_job_id'],(int)$mid); } }"""
if old_sparse_enrich in text:
    text = text.replace(old_sparse_enrich, new_sparse_enrich, 1)

# 6) Ensure reconstruction action validates requested model.
old_recon_lookup = r"""$st=$dbcnx->prepare("SELECT capture_session_id FROM sfm_remote_jobs WHERE order_id=? AND remote_job_id=? AND job_type='COLMAP_SPARSE' LIMIT 1"); $st->bind_param('ii',$orderId,$colmap); $st->execute(); $parentJob=$st->get_result()->fetch_assoc(); $st->close(); if(!$parentJob){throw new RuntimeException('COLMAP job not found');}
       $mode=$action==='sfm_reconstruction_hq_web'?'hq':'preview';"""
new_recon_lookup = r"""$mode=$action==='sfm_reconstruction_hq_web'?'hq':'preview';
       $parentJob=sfm_validate_sparse_model_for_order($dbcnx,$orderId,$colmap,$model);"""
if old_recon_lookup in text:
    text = text.replace(old_recon_lookup, new_recon_lookup, 1)

order.write_text(text, encoding="utf-8")

tpl = Path("web/templates/sfm_job_card.tpl")
t = tpl.read_text(encoding="utf-8")

old_block_re = re.compile(
    r"""\{if \$rj\.job_type == 'COLMAP_SPARSE'\}\s*
     <div class="border rounded p-2 mt-2 small bg-light-subtle">.*?
     </div>\s*
   \{/if\}""",
    re.S
)

new_block = r'''{if $rj.job_type == 'COLMAP_SPARSE'}
     <div class="border rounded p-2 mt-2 small bg-light-subtle">
       <div class="fw-semibold">Sparse reconstruction components</div>
       <div class="text-muted mb-2">Models are disconnected reconstruction components detected by COLMAP, not quality levels.</div>
       {if $rj.sparse_components.models_count|default:0 > 1}
         <div class="alert alert-warning py-1 mb-2">Sparse split into {$rj.sparse_components.models_count|escape} components. Automatic pipeline selected model {$rj.sparse_components.largest_model_id|escape}.</div>
       {/if}
       <div class="table-responsive">
         <table class="table table-sm align-middle mb-2">
           <thead>
             <tr>
               <th>model_id</th>
               <th>registered_images</th>
               <th>frame_ranges</th>
               <th>points3D_count</th>
               <th>Used by</th>
               <th>Actions</th>
             </tr>
           </thead>
           <tbody>
           {foreach from=$rj.sparse_components.models item=component}
             {assign var=mid value=$component.model_id}
             {assign var=modelStats value=$rj.sparse_model_stats[$mid]}
             <tr{if $component.model_id == $rj.sparse_components.largest_model_id} class="table-warning"{/if}>
               <td><strong>{$component.model_id|escape}</strong>{if $component.model_id == $rj.sparse_components.largest_model_id} <span class="badge bg-warning text-dark">largest / auto</span>{/if}</td>
               <td>{$component.registered_images|default:0|escape}</td>
               <td>{if $component.frame_ranges|@count}{$component.frame_ranges|@implode:', '|escape}{else}-{/if}</td>
               <td>{$component.points3D_count|default:0|number_format:0:'.':','}</td>
               <td>{if $rj.sparse_model_selection[$mid] && $rj.sparse_model_selection[$mid].label != 'Not selected'}<span class="badge {$rj.sparse_model_selection[$mid].class|escape}">{$rj.sparse_model_selection[$mid].label|replace:'Selected for ':''|escape}</span>{else}<span class="badge bg-secondary">Not used</span>{/if}</td>
               <td class="d-flex gap-1 flex-wrap">
                 <form method="post" action="/order.php?id={$order.id}" class="d-inline">
                   <input type="hidden" name="action" value="sfm_reconstruction_preview_web">
                   <input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}">
                   <input type="hidden" name="model_id" value="{$component.model_id}">
                   <button type="submit" class="btn btn-sm btn-outline-primary"{if !$modelStats.preview_enabled} disabled{/if}>Run preview for model {$component.model_id|escape}</button>
                 </form>
                 <form method="post" action="/order.php?id={$order.id}" class="d-inline">
                   <input type="hidden" name="action" value="sfm_reconstruction_hq_web">
                   <input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}">
                   <input type="hidden" name="model_id" value="{$component.model_id}">
                   <button type="submit" class="btn btn-sm btn-outline-primary"{if !$modelStats.hq_enabled} disabled{/if}>Run HQ for model {$component.model_id|escape}</button>
                 </form>
                 <form method="post" action="/order.php?id={$order.id}" class="d-inline">
                   <input type="hidden" name="action" value="sfm_export_ply_web">
                   <input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}">
                   <input type="hidden" name="model_id" value="{$component.model_id}">
                   <button type="submit" class="btn btn-sm btn-outline-success">Export PLY for model {$component.model_id|escape}</button>
                 </form>
               </td>
             </tr>
           {/foreach}
           </tbody>
         </table>
       </div>
     </div>
   {/if}'''

if "Sparse reconstruction components" not in t:
    raise SystemExit("Cannot find sparse components section in template.")

t2, n = old_block_re.subn(new_block, t, count=1)
if n != 1:
    raise SystemExit(f"Template replacement failed, replacements={n}")

tpl.write_text(t2, encoding="utf-8")
