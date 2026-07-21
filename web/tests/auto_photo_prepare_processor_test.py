#!/usr/bin/env python3
import hashlib,json,os,shutil,subprocess,tempfile
root=tempfile.mkdtemp(prefix='auto_prepare_processor_'); stage=root+'/incoming'; out=root+'/output'; status=root+'/status.json'; os.makedirs(stage+'/frames'); os.makedirs(stage+'/sidecars')
data=b'jpeg'; open(stage+'/frames/frame_000001.jpg','wb').write(data); open(stage+'/sidecars/manifest.json','w').write('{}')
def item(n,p):return {'filename':n,'size_bytes':os.path.getsize(p),'sha256':hashlib.sha256(open(p,'rb').read()).hexdigest()}
m={'capture_bundle_id':7,'app_bundle_uuid':'u','frames':[item('frame_000001.jpg',stage+'/frames/frame_000001.jpg')],'sidecars':[item('manifest.json',stage+'/sidecars/manifest.json')]};json.dump(m,open(stage+'/transfer_manifest.json','w'))
script=os.path.join(os.path.dirname(__file__),'../remote_station/scripts/process_auto_photo_prepare.py')
subprocess.check_call(['python3',script,'99',stage,out,status]); assert not os.path.exists(stage) and os.path.isfile(out+'/result.json') and json.load(open(status))['status']=='DONE'
stage=root+'/incoming';os.makedirs(stage+'/frames');os.makedirs(stage+'/sidecars');open(stage+'/frames/frame_000001.jpg','wb').write(data);open(stage+'/sidecars/manifest.json','w').write('{}');json.dump(m,open(stage+'/transfer_manifest.json','w'));subprocess.check_call(['python3',script,'99',stage,out,status]);assert json.load(open(out+'/result.json'))['idempotent'] is True
print('PASS');shutil.rmtree(root)
