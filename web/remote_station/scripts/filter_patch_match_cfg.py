#!/usr/bin/env python3
import argparse, json, shutil
from pathlib import Path


def parse_blocks(text):
    blocks=[]; cur=[]
    for line in text.splitlines():
        if line.strip()=='' and cur:
            blocks.append(cur); cur=[]
        elif line.strip()!='':
            cur.append(line.rstrip('\n'))
    if cur: blocks.append(cur)
    return blocks


def main():
    ap=argparse.ArgumentParser(description='Filter COLMAP stereo/patch-match.cfg to images in current dense chunk')
    ap.add_argument('cfg'); ap.add_argument('images_dir'); ap.add_argument('image_list')
    ap.add_argument('--stats-json', default='')
    args=ap.parse_args()
    cfg=Path(args.cfg); images=Path(args.images_dir); image_list=Path(args.image_list)
    allowed={p.name for p in images.iterdir() if p.is_file()} if images.is_dir() else set()
    chunk={ln.strip() for ln in image_list.read_text().splitlines() if ln.strip()}
    valid=allowed & chunk
    if not cfg.exists():
        stats={'blocks_before':0,'blocks_after':0,'removed_sources':0,'removed_blocks':0}
        print(json.dumps(stats)); return 0
    orig=cfg.with_name(cfg.name+'.original')
    if not orig.exists(): shutil.copy2(cfg, orig)
    blocks=parse_blocks(cfg.read_text())
    out=[]; removed_sources=0; removed_blocks=0
    for b in blocks:
        ref=b[0].strip(); src=[x.strip() for x in b[1:] if x.strip()]
        if ref not in valid:
            removed_blocks += 1; removed_sources += len(src); continue
        kept=[s for s in src if s in valid]
        removed_sources += len(src)-len(kept)
        if not kept:
            removed_blocks += 1; continue
        out.append([ref]+kept)
    cfg.write_text('\n\n'.join('\n'.join(b) for b in out)+('\n' if out else ''))
    stats={'blocks_before':len(blocks),'blocks_after':len(out),'removed_sources':removed_sources,'removed_blocks':removed_blocks}
    if args.stats_json: Path(args.stats_json).write_text(json.dumps(stats, indent=2))
    print(json.dumps(stats))
    return 0
if __name__=='__main__': raise SystemExit(main())