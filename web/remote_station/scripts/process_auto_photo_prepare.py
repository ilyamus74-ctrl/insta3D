#!/usr/bin/env python3
import hashlib,json,os,shutil,signal,sys,tempfile
job,stage,out,status=sys.argv[1:]; tmp=None
def digest(p):
 h=hashlib.sha256()
 with open(p,'rb') as f:
  for b in iter(lambda:f.read(1024*1024),b''): h.update(b)
 return h.hexdigest()
def write(s,p,msg):
 d=os.path.dirname(status);os.makedirs(d,exist_ok=True); t=status+'.tmp.'+str(os.getpid())
 try:
  with open(t,'w') as f: json.dump({'job_id':int(job),'job_type':'MAKLERTOUR_AUTO_PHOTO_PREPARE','status':s,'progress_percent':p,'message':msg},f);f.flush();os.fsync(f.fileno())
  os.replace(t,status)
 finally:
  if os.path.exists(t): os.unlink(t)
def stop(signum,frame): raise SystemExit('signal_'+str(signum))
signal.signal(signal.SIGINT,stop);signal.signal(signal.SIGTERM,stop)
def mismatch(): raise RuntimeError('existing_output_mismatch')
def valid(items,root):
 for f in items:
  n=f['filename'];p=os.path.join(root,n)
  if '/' in n or os.path.basename(n)!=n or os.path.islink(p) or not os.path.isfile(p) or os.path.getsize(p)!=f['size_bytes'] or digest(p)!=f['sha256']: return False
 return True
try:
 write('RUNNING',5,'Validating staged auto-photo bundle');m=json.load(open(os.path.join(stage,'transfer_manifest.json')));frames=m['frames'];sidecars=m.get('sidecars',[])
 if not valid(frames,os.path.join(stage,'frames')) or not valid(sidecars,os.path.join(stage,'sidecars')): raise RuntimeError('staging_validation_failed')
 if sorted(os.listdir(os.path.join(stage,'frames')))!=sorted(f['filename'] for f in frames): raise RuntimeError('frame_list_mismatch')
 expected_root={'frames','result.json'}|{f['filename'] for f in sidecars}
 present={f['filename'] for f in sidecars}; flags={'camera_metadata_present':'camera_metadata.json'in present,'scan_imu_present':'scan_imu.jsonl'in present,'photos_metadata_present':'photos_metadata.jsonl'in present,'manifest_present':'manifest.json'in present,'bundle_manifest_present':'bundle_manifest.json'in present}
 contract={'schema_version':1,'job_type':'MAKLERTOUR_AUTO_PHOTO_PREPARE','remote_job_id':int(job),'capture_bundle_id':m['capture_bundle_id'],'app_bundle_uuid':m['app_bundle_uuid'],'status':'DONE','frames_count':len(frames),'frames_directory':'frames',**flags,'warnings':[]}
 if os.path.exists(out):
  if set(os.listdir(out))!=expected_root or not valid(frames,os.path.join(out,'frames')) or not valid(sidecars,out): mismatch()
  r=json.load(open(os.path.join(out,'result.json')))
  if {k:r.get(k) for k in contract}!={k:v for k,v in contract.items()} or r.get('idempotent') not in (False,True): mismatch()
  r['idempotent']=True;t=os.path.join(out,'result.json.tmp.'+str(os.getpid()))
  try:
   with open(t,'w') as f:json.dump(r,f);f.flush();os.fsync(f.fileno())
   os.replace(t,os.path.join(out,'result.json'))
  finally:
   if os.path.exists(t):os.unlink(t)
  write('DONE',100,'Idempotent auto photo prepare completed');raise SystemExit(0)
 tmp=out+'.stage.'+next(tempfile._get_candidate_names());os.makedirs(tmp);shutil.copytree(os.path.join(stage,'frames'),os.path.join(tmp,'frames'))
 for f in sidecars:shutil.copy2(os.path.join(stage,'sidecars',f['filename']),os.path.join(tmp,f['filename']))
 contract['idempotent']=False
 with open(os.path.join(tmp,'result.json'),'w') as f:json.dump(contract,f)
 os.rename(tmp,out);tmp=None;write('DONE',100,'Auto photo prepare completed')
except SystemExit as e:
 if e.code not in (0,None): write('ERROR',0,str(e))
 raise
except Exception as e: write('ERROR',0,str(e));raise
finally:
 if os.path.exists(stage):shutil.rmtree(stage)
 if tmp and os.path.exists(tmp):shutil.rmtree(tmp)
