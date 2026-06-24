<?php
declare(strict_types=1);
$path = $_GET['diagnostics'] ?? '';
$diagnostics = null;
if ($path !== '' && is_file($path) && is_readable($path)) {
    $decoded = json_decode((string)file_get_contents($path), true);
    if (is_array($decoded)) { $diagnostics = $decoded; }
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<meta charset="utf-8">
<title>SfM trajectory diagnostics</title>
<style>
body{font-family:system-ui,sans-serif;background:#111;color:#eee;margin:0}#c{width:100vw;height:86vh;display:block}.panel{padding:12px;background:#1d1d1d}button{margin-right:8px}.tip{position:fixed;right:12px;top:12px;background:#222;border:1px solid #555;padding:10px;max-width:360px;white-space:pre-wrap}
</style>
<canvas id="c"></canvas><div class="panel"><button id="togglePath">Camera path</button><button id="toggleSuspicious">Suspicious poses</button><span>green=normal, red=position jump, orange=rotation jump, purple=IMU mismatch, yellow=secondary cluster</span></div><div id="tip" class="tip">Click a camera pose.</div>
<script>
const diagnostics = <?= json_encode($diagnostics ?: ['images'=>[]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const canvas=document.getElementById('c'),ctx=canvas.getContext('2d'),tip=document.getElementById('tip');
let showPath=true, showSuspicious=true;
function resize(){canvas.width=innerWidth;canvas.height=Math.floor(innerHeight*.86);draw()} addEventListener('resize',resize);
function color(img){const w=img.warnings||[]; if(w.includes('VISUAL_IMU_ROTATION_MISMATCH'))return '#b25cff'; if(w.includes('POSITION_JUMP'))return '#ff3333'; if(w.includes('ROTATION_JUMP'))return '#ff9b22'; if(w.includes('SECONDARY_POSE_CLUSTER'))return '#e7d84f'; return '#35d35b'}
function pts(){const a=diagnostics.images||[]; if(!a.length)return[]; const xs=a.map(i=>i.camera_center[0]), zs=a.map(i=>i.camera_center[2]); const minx=Math.min(...xs),maxx=Math.max(...xs),minz=Math.min(...zs),maxz=Math.max(...zs); const sx=(canvas.width-80)/Math.max(1e-9,maxx-minx), sz=(canvas.height-80)/Math.max(1e-9,maxz-minz), s=Math.min(sx,sz); return a.map((i,n)=>({img:i,x:40+(i.camera_center[0]-minx)*s,y:40+(i.camera_center[2]-minz)*s,n}))}
let drawn=[]; function draw(){ctx.fillStyle='#111';ctx.fillRect(0,0,canvas.width,canvas.height); drawn=pts(); if(showPath){ctx.strokeStyle='#4a8';ctx.beginPath();drawn.forEach((p,i)=>i?ctx.lineTo(p.x,p.y):ctx.moveTo(p.x,p.y));ctx.stroke()} drawn.forEach(p=>{const suspicious=(p.img.warnings||[]).length||p.img.suspicion_score>=.35; if(!showSuspicious && suspicious)return; ctx.fillStyle=color(p.img);ctx.beginPath();ctx.arc(p.x,p.y,suspicious?5:3,0,Math.PI*2);ctx.fill()})}
canvas.onclick=e=>{const r=canvas.getBoundingClientRect(),x=e.clientX-r.left,y=e.clientY-r.top; let best=null,bd=1e9; for(const p of drawn){const d=(p.x-x)**2+(p.y-y)**2;if(d<bd){bd=d;best=p}} if(best&&bd<100){const i=best.img; tip.textContent=`${i.name}\ntime: ${i.timestamp_sec}\nposition step: ${Number(i.position_step_from_previous||0).toFixed(4)}\nrotation step: ${Number(i.rotation_step_deg_from_previous||0).toFixed(2)} deg\nreprojection median: ${Number(i.median_reprojection_error||0).toFixed(2)} px\nsuspicion: ${Number(i.suspicion_score||0).toFixed(2)}\nwarnings: ${(i.warnings||[]).join(', ')||'none'}`}};
document.getElementById('togglePath').onclick=()=>{showPath=!showPath;draw()}; document.getElementById('toggleSuspicious').onclick=()=>{showSuspicious=!showSuspicious;draw()}; resize();
</script>