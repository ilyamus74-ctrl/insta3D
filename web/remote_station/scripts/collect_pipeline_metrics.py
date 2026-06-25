#!/usr/bin/env python3
import argparse,json,os,subprocess,time,statistics,datetime
from pathlib import Path

def sh(cmd):
    try: return subprocess.check_output(cmd,stderr=subprocess.DEVNULL,text=True).strip()
    except Exception: return ''
def gpu():
    q=sh(['nvidia-smi','--query-gpu=utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,clocks.gr','--format=csv,noheader,nounits'])
    if not q: return {}
    p=[x.strip() for x in q.splitlines()[0].split(',')]
    keys=['utilization_percent','memory_used_mb','memory_total_mb','temperature_c','power_w','graphics_clock_mhz']
    return {k:float(v) if '.' in v else int(float(v)) for k,v in zip(keys,p) if v!=''}
def cpu(prev=None):
    load=os.getloadavg()[0] if hasattr(os,'getloadavg') else 0
    return {'usage_percent':0,'load_1m':load,'iowait_percent':0}
def mem():
    d={}
    for line in Path('/proc/meminfo').read_text().splitlines():
        k,v=line.split(':',1); d[k]=int(v.strip().split()[0])//1024
    total=d.get('MemTotal',0); avail=d.get('MemAvailable',0)
    return {'used_mb':total-avail,'total_mb':total,'swap_used_mb':d.get('SwapTotal',0)-d.get('SwapFree',0)}
def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--pipeline-run-id',type=int,required=True); ap.add_argument('--output-dir',required=True); ap.add_argument('--stage-file'); ap.add_argument('--interval',type=float,default=2.0)
    a=ap.parse_args(); out=Path(a.output_dir); out.mkdir(parents=True,exist_ok=True); jl=out/'pipeline_metrics.jsonl'; rows=[]
    while not (out/'metrics.stop').exists():
        stage='UNKNOWN';
        if a.stage_file and Path(a.stage_file).exists():
            try: stage=json.loads(Path(a.stage_file).read_text()).get('stage',stage)
            except Exception: pass
        row={'timestamp':datetime.datetime.utcnow().isoformat()+'Z','pipeline_run_id':a.pipeline_run_id,'stage':stage,'chunk':None,'substage':None,'gpu':gpu(),'cpu':cpu(),'memory':mem(),'disk':{'read_mb_s':0,'write_mb_s':0}}
        rows.append(row); jl.open('a').write(json.dumps(row)+'\n'); time.sleep(a.interval)
    vals=lambda f:[f(r) for r in rows if f(r) is not None]
    gu=vals(lambda r:r['gpu'].get('utilization_percent') if r.get('gpu') else None); gp=vals(lambda r:r['gpu'].get('power_w') if r.get('gpu') else None); gm=vals(lambda r:r['gpu'].get('memory_used_mb') if r.get('gpu') else None); mu=vals(lambda r:r['memory'].get('used_mb'))
    summ={'samples':len(rows),'gpu_utilization_avg':statistics.mean(gu) if gu else None,'gpu_utilization_p95':sorted(gu)[int(.95*(len(gu)-1))] if gu else None,'gpu_utilization_max':max(gu) if gu else None,'vram_max_mb':max(gm) if gm else None,'gpu_power_avg_w':statistics.mean(gp) if gp else None,'gpu_power_max_w':max(gp) if gp else None,'ram_max_mb':max(mu) if mu else None}
    (out/'pipeline_metrics_summary.json').write_text(json.dumps(summ,indent=2))
if __name__=='__main__': main()