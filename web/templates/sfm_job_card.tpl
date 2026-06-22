<div class="border rounded p-2 mb-2 bg-white" data-sfm-remote-job="{$rj.id}" data-active-status="{$rj.status|escape}">
  <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
    <div>
      <div><strong>{$rj.ui_title|default:$rj.job_type|escape}</strong> — <span data-job-field="status">{$rj.status|escape}</span> — <span data-job-field="progress">{$rj.progress_percent|default:0}</span>%</div>
      <div class="small text-muted">Job ID: {$rj.remote_job_id|escape}{if $rj.ui_model_id ne null} <span class="badge bg-primary">Model {$rj.ui_model_id|escape}</span>{/if}{if $rj.parent_remote_job_id} · Parent/Sparse job: {$rj.parent_remote_job_id|escape}{/if}{if $rj.reconstruction_mode} · Mode: {$rj.reconstruction_mode|escape}{/if}{if $rj.chunk_index ne null} · Chunk: {$rj.chunk_index|escape}{/if}</div>
      <div class="small text-muted" data-job-field="message">{$rj.message|default:''|escape}</div>
      {if ($rj.job_type == 'COLMAP_RECONSTRUCTION_PREVIEW' || $rj.job_type == 'COLMAP_RECONSTRUCTION_HQ')}
        <div class="small mt-1">
          <span class="badge bg-info text-dark">Mode: {if $rj.job_type == 'COLMAP_RECONSTRUCTION_HQ'}HQ{else}Preview{/if}</span>
          {if $rj.parent_remote_job_id}<span class="ms-1">Sparse parent job ID: {$rj.parent_remote_job_id|escape}</span>{/if}
          {if $rj.ui_model_id ne null}<span class="ms-1">Model ID: {$rj.ui_model_id|escape}</span>{/if}
          {if $rj.ui_sparse_stats.registered_images ne ''}<span class="ms-1">Registered images: {$rj.ui_sparse_stats.registered_images|escape}</span>{/if}
          {if $rj.ui_sparse_stats.points3D ne ''}<span class="ms-1">Sparse points: {$rj.ui_sparse_stats.points3D|escape}</span>{/if}
        </div>
      {/if}
      {if $rj.job_type == 'COLMAP_MESH' && $rj.status == 'DONE'}
        <div class="small mt-1">
          <span class="badge bg-info text-dark">Engine: {if $rj.mesh_engine|lower == 'open3d'}Open3D fallback{elseif $rj.mesh_engine}{$rj.mesh_engine|escape}{else}Unknown{/if}</span>
          {if $rj.mesh_fallback}<span class="badge bg-warning text-dark">Fallback used: Open3D</span>{/if}
          <span class="ms-1">Vertices: {$rj.mesh_vertices|default:'-'|escape}</span>
          <span class="ms-1">Faces: {$rj.mesh_faces|default:'-'|escape}</span>
          <span class="ms-1">Mode: {$rj.mesh_mode|default:$rj.reconstruction_mode|capitalize|escape}</span>
          {if $rj.mesh_duration_sec ne ''}<span class="ms-1">Duration: {$rj.mesh_duration_sec|escape} sec</span>{/if}
        </div>
      {/if}
      <div class="small">updated: <span data-job-field="updated">{$rj.updated_at|default:'-'|escape}</span></div>
    </div>
    <span class="badge {$rj.ui_progress_class|regex_replace:'/ progress-bar[^ ]*/':''|escape}" data-job-field="status_badge">{$rj.status|escape}</span>
  </div>
  <div class="progress my-2"><div class="progress-bar {$rj.ui_progress_class|escape}" data-job-field="progress_bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$rj.progress_percent|default:0}" style="width: {$rj.progress_percent|default:0}%">{$rj.progress_percent|default:0}%</div></div>
  {if $rj.children|@count > 0}
    <div class="small fw-semibold mt-2">Dense / Mesh:</div>
    <div class="small mb-2">
      {foreach from=$rj.children item=ch}
        <div>{if $ch.status == 'DONE'}✓{elseif $ch.status == 'RUNNING'}●{else}○{/if} {if $ch.job_type == 'COLMAP_MESH'}Mesh{else}Dense chunk {($ch.chunk_index|default:0)+1} of {$ch.chunk_count|default:($rj.children|@count)}{/if}{if $ch.ui_model_id ne null} — Model {$ch.ui_model_id|escape}{/if} — {$ch.status|escape}{if $ch.job_type == 'COLMAP_MESH'} — {if $ch.mesh_engine|lower == 'open3d'}Open3D{elseif $ch.mesh_engine}{$ch.mesh_engine|escape}{else}engine pending{/if}{if $ch.mesh_vertices || $ch.mesh_faces} — {$ch.mesh_vertices|default:'-'|escape} vertices / {$ch.mesh_faces|default:'-'|escape} faces{/if}{if $ch.mesh_fallback} — Fallback used: Open3D{/if}{else} — {$ch.progress_percent|default:0}%{/if} <span class="text-muted">Job ID: {$ch.remote_job_id|escape}</span>{if $ch.job_type == 'COLMAP_MESH' && $ch.ui_can_download_mesh} <a class="btn btn-sm btn-outline-success ms-1" href="{$ch.mesh_final_url|escape}" target="_blank">Download mesh PLY</a>{/if}</div>
      {/foreach}
    </div>
  {/if}
  <div class="d-flex gap-1 flex-wrap mt-1">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-job-file-url="{$rj.status_json_url|escape}" data-job-file-target="jobFile{$rj.id}">View status</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-job-file-url="{$rj.result_json_url|escape}" data-job-file-target="jobFile{$rj.id}">View result</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-job-file-url="{$rj.logs_url|escape}" data-job-file-target="jobFile{$rj.id}">View logs</button>
    {if $rj.job_type == 'EXPORT_PLY'}<a class="btn btn-sm btn-outline-success" href="{$rj.ply_url|escape}" target="_blank">Download PLY</a>{/if}
    {if ($rj.job_type == 'COLMAP_RECONSTRUCTION_PREVIEW' || $rj.job_type == 'COLMAP_RECONSTRUCTION_HQ')}
      {if $rj.ui_can_download_merged}<a class="btn btn-sm btn-outline-success" href="{$rj.ply_url|escape}" target="_blank">Download merged point cloud</a>{else}<span class="badge bg-secondary align-self-center">Result not generated</span>{/if}
      <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_generate_mesh_preview_web"><input type="hidden" name="parent_remote_job_id" value="{$rj.remote_job_id}"><button type="submit" class="btn btn-sm btn-outline-primary">Generate preview mesh</button></form>
      <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_generate_mesh_hq_web"><input type="hidden" name="parent_remote_job_id" value="{$rj.remote_job_id}"><button type="submit" class="btn btn-sm btn-outline-primary">Generate HQ mesh</button></form>
    {/if}
    {if $rj.job_type == 'COLMAP_MESH' && $rj.ui_can_download_mesh}<a class="btn btn-sm btn-outline-success" href="{$rj.mesh_final_url|escape}" target="_blank">Download mesh PLY</a>{/if}
    {if $rj.job_type == 'EXTRACT_FRAMES'}<form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_colmap_sparse_web"><input type="hidden" name="capture_session_id" value="{$s.id}"><input type="hidden" name="extract_job_id" value="{$rj.remote_job_id}"><button type="submit" class="btn btn-sm btn-outline-primary">Run COLMAP sparse</button></form>{/if}
    {if $rj.job_type == 'COLMAP_SPARSE'}
      <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_reconstruction_preview_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="best_model" value="1"><button type="submit" class="btn btn-sm btn-outline-primary">Run Preview best model</button></form>
      <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_reconstruction_hq_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="best_model" value="1"><button type="submit" class="btn btn-sm btn-outline-primary">Run HQ best model</button></form>
    {/if}
    {if $rj.status == 'ERROR' || $rj.status == 'ERROR_EMPTY'}<form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_retry_job"><input type="hidden" name="job_id" value="{$rj.id}"><button type="submit" class="btn btn-sm btn-outline-primary">Retry{if $rj.job_type == 'COLMAP_DENSE_CHUNK'} chunk{else} reconstruction{/if}</button></form>{/if}
    <form method="post" action="/order.php?id={$order.id}" class="d-inline" onsubmit="return confirm('Удалить только результаты этой задачи? Исходное видео, frames и sparse model удалены не будут.');"><input type="hidden" name="action" value="sfm_delete_job_files"><input type="hidden" name="job_id" value="{$rj.id}"><button type="submit" class="btn btn-sm btn-outline-danger">Delete job files</button></form>
    <form method="post" action="/order.php?id={$order.id}" class="d-inline" onsubmit="return confirm('Delete this job record?');"><input type="hidden" name="action" value="sfm_delete_job_record"><input type="hidden" name="job_id" value="{$rj.id}"><button type="submit" class="btn btn-sm btn-outline-danger">Delete job record</button></form>
  </div>
  {if $rj.job_type == 'COLMAP_SPARSE'}
    <div class="border rounded p-2 mt-2 small bg-light-subtle">
      <div class="fw-semibold">Sparse reconstruction components</div>
      <div class="text-muted mb-2">Models are disconnected reconstruction components detected by COLMAP, not quality levels.</div>
      {foreach from=$rj.dense_model_ids item=mid}{assign var=modelStats value=$rj.sparse_model_stats[$mid]}
        <div class="border rounded p-2 mb-2 bg-white">
          <div class="fw-semibold">Model {$mid}</div>
          <div>Registered images: {$modelStats.registered_images|default:0}</div>
          <div>Sparse points: {$modelStats.points3D|default:0|number_format:0:'.':','}</div>
          <div>{if $rj.sparse_model_selection[$mid] && $rj.sparse_model_selection[$mid].label != 'Not selected'}Used by: {$rj.sparse_model_selection[$mid].label|replace:'Selected for ':''|escape}{else}Not used{/if}</div>
          <div class="d-flex gap-1 flex-wrap mt-1">
            <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_reconstruction_preview_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="model_id" value="{$mid}"><button type="submit" class="btn btn-sm btn-outline-primary"{if !$modelStats.preview_enabled} disabled{/if}>Run Preview</button></form>
            <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_reconstruction_hq_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="model_id" value="{$mid}"><button type="submit" class="btn btn-sm btn-outline-primary"{if !$modelStats.hq_enabled} disabled{/if}>Run High quality</button></form>
            <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_export_ply_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="model_id" value="{$mid}"><button type="submit" class="btn btn-sm btn-outline-success">Download sparse PLY</button></form>
          </div>
        </div>
      {/foreach}
    </div>
  {/if}
  <details class="small mt-2"><summary>Details</summary><div>Technical type: <code>{$rj.job_type|escape}</code></div></details>
  <pre id="jobFile{$rj.id}" class="small bg-dark text-light rounded p-2 mt-2 d-none" style="max-height:220px;overflow:auto;white-space:pre-wrap"></pre>
</div>
