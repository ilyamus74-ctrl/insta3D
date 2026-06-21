<div class="border rounded p-2 mb-2 bg-white" data-sfm-remote-job="{$rj.id}" data-active-status="{$rj.status|escape}">
  <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
    <div>
      <div><strong>{$rj.ui_title|default:$rj.job_type|escape}</strong> — <span data-job-field="status">{$rj.status|escape}</span> — <span data-job-field="progress">{$rj.progress_percent|default:0}</span>%</div>
      <div class="small text-muted">Job ID: {$rj.remote_job_id|escape}{if $rj.parent_remote_job_id} · Parent/Sparse job: {$rj.parent_remote_job_id|escape}{/if}{if $rj.reconstruction_mode} · Mode: {$rj.reconstruction_mode|escape}{/if}{if $rj.chunk_index ne null} · Chunk: {$rj.chunk_index|escape}{/if}</div>
      <div class="small text-muted" data-job-field="message">{$rj.message|default:''|escape}</div>
      <div class="small">updated: <span data-job-field="updated">{$rj.updated_at|default:'-'|escape}</span></div>
    </div>
    <span class="badge {$rj.ui_progress_class|regex_replace:'/ progress-bar[^ ]*/':''|escape}">{$rj.status|escape}</span>
  </div>
  <div class="progress my-2"><div class="progress-bar {$rj.ui_progress_class|escape}" style="width: {$rj.progress_percent|default:0}%">{$rj.progress_percent|default:0}%</div></div>
  {if $rj.children|@count > 0}
    <div class="small fw-semibold mt-2">Chunks:</div>
    <div class="small mb-2">
      {foreach from=$rj.children item=ch}
        <div>{if $ch.status == 'DONE'}✓{elseif $ch.status == 'RUNNING'}●{else}○{/if} Dense chunk {($ch.chunk_index|default:0)+1} of {$ch.chunk_count|default:($rj.children|@count)} — {$ch.status|escape} — {$ch.progress_percent|default:0}% <span class="text-muted">Job ID: {$ch.remote_job_id|escape}</span></div>
      {/foreach}
    </div>
  {/if}
  <div class="d-flex gap-1 flex-wrap mt-1">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-job-file-url="{$rj.status_json_url|escape}" data-job-file-target="jobFile{$rj.id}">View status</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-job-file-url="{$rj.result_json_url|escape}" data-job-file-target="jobFile{$rj.id}">View result</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-job-file-url="{$rj.logs_url|escape}" data-job-file-target="jobFile{$rj.id}">View logs</button>
    {if $rj.job_type == 'EXPORT_PLY'}<a class="btn btn-sm btn-outline-success" href="{$rj.ply_url|escape}" target="_blank">Download PLY</a>{/if}
    {if ($rj.job_type == 'COLMAP_RECONSTRUCTION_PREVIEW' || $rj.job_type == 'COLMAP_RECONSTRUCTION_HQ')}
      {if $rj.ui_can_download_merged}<a class="btn btn-sm btn-outline-success" href="{$rj.ply_url|escape}" target="_blank">Download merged PLY</a>{else}<span class="badge bg-secondary align-self-center">Result not generated</span>{/if}
    {/if}
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
    <div class="row g-2 mt-2">
      {foreach from=$rj.dense_model_ids item=mid}{assign var=modelStats value=$rj.sparse_model_stats[$mid]}
        <div class="col-md-6"><div class="border rounded p-2 small">
          <div class="fw-semibold">Model {$mid}</div><div>Registered images: {$modelStats.registered_images|default:0}</div><div>Points: {$modelStats.points3D|default:0}</div><div>Status: {if $modelStats.preview_enabled}Suitable for Preview/HQ{else}Too few images{/if}</div>
          <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_reconstruction_preview_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="model_id" value="{$mid}"><button type="submit" class="btn btn-sm btn-outline-primary"{if !$modelStats.preview_enabled} disabled{/if}>Run Preview</button></form>
          <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_reconstruction_hq_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="model_id" value="{$mid}"><button type="submit" class="btn btn-sm btn-outline-primary"{if !$modelStats.hq_enabled} disabled{/if}>Run High quality</button></form>
          <form method="post" action="/order.php?id={$order.id}" class="d-inline"><input type="hidden" name="action" value="sfm_export_ply_web"><input type="hidden" name="colmap_job_id" value="{$rj.remote_job_id}"><input type="hidden" name="model_id" value="{$mid}"><button type="submit" class="btn btn-sm btn-outline-primary">Export PLY</button></form>
        </div></div>
      {/foreach}
    </div>
  {/if}
  <details class="small mt-2"><summary>Details</summary><div>Technical type: <code>{$rj.job_type|escape}</code></div></details>
  <pre id="jobFile{$rj.id}" class="small bg-dark text-light rounded p-2 mt-2 d-none" style="max-height:320px;overflow:auto;white-space:pre-wrap"></pre>
</div>