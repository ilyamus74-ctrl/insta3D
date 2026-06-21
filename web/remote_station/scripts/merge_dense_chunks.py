#!/usr/bin/env python3
import argparse, json, math, struct, time
from pathlib import Path

PLY_TYPES = {
    'char': ('b', 'char'), 'int8': ('b', 'char'),
    'uchar': ('B', 'uchar'), 'uint8': ('B', 'uchar'),
    'short': ('h', 'short'), 'int16': ('h', 'short'),
    'ushort': ('H', 'ushort'), 'uint16': ('H', 'ushort'),
    'int': ('i', 'int'), 'int32': ('i', 'int'),
    'uint': ('I', 'uint'), 'uint32': ('I', 'uint'),
    'float': ('f', 'float'), 'float32': ('f', 'float'),
    'double': ('d', 'double'), 'float64': ('d', 'double'),
}
WANTED = {'x', 'y', 'z', 'nx', 'ny', 'nz', 'red', 'green', 'blue', 'alpha'}
DEFAULT_PROPS = [('float', 'x'), ('float', 'y'), ('float', 'z'), ('float', 'nx'), ('float', 'ny'), ('float', 'nz'), ('uchar', 'red'), ('uchar', 'green'), ('uchar', 'blue')]


def read_header(fh):
    first = fh.readline()
    if first.strip() != b'ply':
        raise ValueError('not a PLY file')
    fmt = None; vertex_count = 0; props = []; in_vertex = False
    while True:
        raw = fh.readline()
        if raw == b'':
            raise ValueError('unterminated PLY header')
        line = raw.decode('ascii', 'replace').strip()
        if line.startswith('format '):
            parts = line.split(); fmt = parts[1] if len(parts) >= 3 else None
        elif line.startswith('element '):
            parts = line.split(); in_vertex = len(parts) >= 3 and parts[1] == 'vertex'
            if in_vertex: vertex_count = int(parts[2])
        elif in_vertex and line.startswith('property '):
            parts = line.split()
            if len(parts) == 3 and parts[1] in PLY_TYPES:
                props.append((PLY_TYPES[parts[1]][1], parts[2]))
        elif line == 'end_header':
            break
    if fmt not in ('ascii', 'binary_little_endian'):
        raise ValueError(f'unsupported PLY format: {fmt}')
    if vertex_count <= 0:
        return fmt, vertex_count, props
    names = [n for _, n in props]
    for req in ('x', 'y', 'z'):
        if req not in names:
            raise ValueError(f'missing vertex property {req}')
    return fmt, vertex_count, props


def normalize_value(t, name, value):
    if name in ('red', 'green', 'blue', 'alpha'):
        return max(0, min(255, int(round(float(value)))))
    return float(value)


def read_ply(path):
    with open(path, 'rb') as fh:
        fmt, n, props = read_header(fh)
        if n <= 0:
            return fmt, props, []
        prop_names = [name for _, name in props]
        keep = [(i, t, name) for i, (t, name) in enumerate(props) if name in WANTED]
        verts = []
        if fmt == 'ascii':
            for _ in range(n):
                line = fh.readline().decode('ascii', 'replace')
                vals = line.split()
                if len(vals) < len(props):
                    continue
                row = {name: normalize_value(t, name, vals[i]) for i, t, name in keep}
                xyz = [float(row.get(k, float('nan'))) for k in ('x', 'y', 'z')]
                if all(math.isfinite(v) for v in xyz):
                    verts.append(row)
        else:
            fmt_str = '<' + ''.join(PLY_TYPES[t][0] for t, _ in props)
            size = struct.calcsize(fmt_str)
            unpack = struct.Struct(fmt_str).unpack
            for _ in range(n):
                data = fh.read(size)
                if len(data) != size:
                    raise ValueError('truncated binary vertex data')
                vals = unpack(data)
                row = {name: normalize_value(t, name, vals[i]) for i, t, name in keep}
                xyz = [float(row.get(k, float('nan'))) for k in ('x', 'y', 'z')]
                if all(math.isfinite(v) for v in xyz):
                    verts.append(row)
        out_props = [(t, name) for t, name in props if name in WANTED]
        return fmt, out_props, verts


def output_props(input_props):
    names = {n for _, n in input_props}
    props = []
    for t, n in DEFAULT_PROPS:
        if n in names or n in ('x', 'y', 'z'):
            props.append((t, n))
    if 'alpha' in names:
        props.append(('uchar', 'alpha'))
    return props


def write_binary_ply(path, props, verts):
    path.parent.mkdir(parents=True, exist_ok=True)
    header = ['ply', 'format binary_little_endian 1.0', f'element vertex {len(verts)}']
    header += [f'property {t} {n}' for t, n in props]
    header += ['end_header']
    fmt = '<' + ''.join(PLY_TYPES[t][0] for t, _ in props)
    pack = struct.Struct(fmt).pack
    with open(path, 'wb') as fh:
        fh.write(('\n'.join(header) + '\n').encode('ascii'))
        for v in verts:
            values = []
            for t, n in props:
                default = 255 if n == 'alpha' else (255 if n in ('red','green','blue') else 0.0)
                val = v.get(n, default)
                values.append(int(val) if t in ('uchar','char','short','ushort','int','uint') else float(val))
            fh.write(pack(*values))


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('--parent-output-dir', required=True); ap.add_argument('--mode', choices=['preview','hq'], required=True); ap.add_argument('--output-ply', required=True); ap.add_argument('ply', nargs='*'); args = ap.parse_args(); t = time.time()
    chunks = args.ply or [str(p) for p in sorted(Path(args.parent_output_dir).glob('chunks/chunk_*/fused.ply'))]
    allv=[]; warnings=[]; skipped=0; input_formats=[]; first_props=[]; input_total=0
    for c in chunks:
        try:
            fmt, pr, vs = read_ply(c)
            if fmt not in input_formats: input_formats.append(fmt)
            input_total += len(vs)
        except Exception as e:
            warnings.append(f'{c}: {e}'); skipped += 1; continue
        if len(vs) == 0:
            warnings.append(f'{c}: zero vertices'); skipped += 1; continue
        if not first_props: first_props = pr
        allv.extend(vs)
    merged=[]; status='ERROR'
    if allv:
        xs=[v['x'] for v in allv]; ys=[v['y'] for v in allv]; zs=[v['z'] for v in allv]
        diag=math.dist((min(xs),min(ys),min(zs)),(max(xs),max(ys),max(zs))) or 1.0; vox=diag/(2000 if args.mode=='preview' else 5000)
        seen={}
        for v in allv:
            key=(round(v['x']/vox),round(v['y']/vox),round(v['z']/vox))
            if key not in seen: seen[key]=1; merged.append(v)
        status='DONE'
    out=Path(args.output_ply); props=output_props(first_props)
    write_binary_ply(out, props, merged)
    res={'status':status,'mode':args.mode,'chunks_total':len(chunks),'chunks_done':len(chunks)-skipped,'chunks_skipped':skipped,'input_vertices_total':input_total,'merged_vertices':len(merged),'fused_vertices':len(merged),'input_formats':input_formats,'output_format':'binary_little_endian','output_ply':str(out),'duration_sec':round(time.time()-t,3),'warnings':warnings}
    (out.parent/'result.json').write_text(json.dumps(res, indent=2), encoding='utf-8')
    if status == 'ERROR': raise SystemExit(2)
if __name__ == '__main__': main()
