#!/usr/bin/env python3
import argparse, json, math, struct, time
from pathlib import Path

PLY_STRUCT_TYPES = {
    "char": "b", "int8": "b",
    "uchar": "B", "uint8": "B",
    "short": "h", "int16": "h",
    "ushort": "H", "uint16": "H",
    "int": "i", "int32": "i",
    "uint": "I", "uint32": "I",
    "float": "f", "float32": "f",
    "double": "d", "float64": "d",
}
INT_TYPES = {"char", "int8", "uchar", "uint8", "short", "int16", "ushort", "uint16", "int", "int32", "uint", "uint32"}
WANTED = {"x", "y", "z", "nx", "ny", "nz", "red", "green", "blue", "alpha"}
OUTPUT_PROPS = [("float", "x"), ("float", "y"), ("float", "z"), ("float", "nx"), ("float", "ny"), ("float", "nz"), ("uchar", "red"), ("uchar", "green"), ("uchar", "blue")]


def read_ply_header(file_obj):
    first = file_obj.readline()
    if first.strip() != b"ply":
        raise ValueError("Not a PLY file")

    ply_format = None
    vertex_count = None
    properties = []
    in_vertex = False

    while True:
        raw = file_obj.readline()
        if raw == b"":
            raise ValueError("Unterminated PLY header")
        line = raw.decode("ascii", "replace").strip()
        if line == "end_header":
            break
        if not line or line.startswith("comment "):
            continue
        parts = line.split()
        if len(parts) >= 3 and parts[0] == "format":
            ply_format = parts[1]
        elif len(parts) >= 3 and parts[0] == "element":
            in_vertex = parts[1] == "vertex"
            if in_vertex:
                vertex_count = int(parts[2])
        elif in_vertex and len(parts) == 3 and parts[0] == "property":
            prop_type, prop_name = parts[1], parts[2]
            if prop_type not in PLY_STRUCT_TYPES:
                raise ValueError(f"Unsupported PLY property type: {prop_type}")
            properties.append({"type": prop_type, "name": prop_name})
        elif in_vertex and len(parts) >= 5 and parts[0] == "property" and parts[1] == "list":
            raise ValueError("Unsupported list property in vertex element")

    if ply_format == "binary_big_endian":
        raise ValueError("Unsupported PLY format: binary_big_endian")
    if ply_format not in ("ascii", "binary_little_endian"):
        raise ValueError(f"Unsupported PLY format: {ply_format}")
    if vertex_count is None:
        raise ValueError("Missing vertex element in PLY header")

    names = {p["name"] for p in properties}
    for required in ("x", "y", "z"):
        if required not in names:
            raise ValueError(f"Missing vertex property: {required}")

    return {"format": ply_format, "vertex_count": vertex_count, "properties": properties, "header_end_offset": file_obj.tell()}


def _normalise_vertex(values, properties):
    row = {prop["name"]: values[i] for i, prop in enumerate(properties) if prop["name"] in WANTED}
    vertex = {
        "x": float(row["x"]), "y": float(row["y"]), "z": float(row["z"]),
        "nx": float(row.get("nx", 0.0)), "ny": float(row.get("ny", 0.0)), "nz": float(row.get("nz", 0.0)),
        "red": max(0, min(255, int(round(float(row.get("red", 255)))))),
        "green": max(0, min(255, int(round(float(row.get("green", 255)))))),
        "blue": max(0, min(255, int(round(float(row.get("blue", 255)))))),
    }
    if all(math.isfinite(vertex[k]) for k in ("x", "y", "z")):
        return vertex
    return None


def read_ply(path):
    with open(path, "rb") as fh:
        header = read_ply_header(fh)
        n = header["vertex_count"]
        properties = header["properties"]
        vertices = []
        if n == 0:
            return {**header, "vertices": vertices}

        if header["format"] == "ascii":
            for index in range(n):
                raw = fh.readline()
                if raw == b"":
                    raise ValueError("Truncated ASCII PLY vertex section")
                parts = raw.decode("ascii", "replace").split()
                if len(parts) < len(properties):
                    raise ValueError(f"Truncated ASCII PLY vertex record at index {index}")
                values = []
                for prop, value in zip(properties, parts):
                    values.append(int(value) if prop["type"] in INT_TYPES else float(value))
                vertex = _normalise_vertex(values, properties)
                if vertex is not None:
                    vertices.append(vertex)
        else:
            fmt = "<" + "".join(PLY_STRUCT_TYPES[prop["type"]] for prop in properties)
            record = struct.Struct(fmt)
            for _ in range(n):
                data = fh.read(record.size)
                if len(data) != record.size:
                    raise ValueError("Truncated binary PLY vertex section")
                vertex = _normalise_vertex(record.unpack(data), properties)
                if vertex is not None:
                    vertices.append(vertex)

        return {**header, "vertices": vertices}


def write_binary_ply(path, vertices):
    path.parent.mkdir(parents=True, exist_ok=True)
    header = ["ply", "format binary_little_endian 1.0", f"element vertex {len(vertices)}"]
    header.extend(f"property {typ} {name}" for typ, name in OUTPUT_PROPS)
    header.append("end_header")
    record = struct.Struct("<ffffffBBB")
    with open(path, "wb") as fh:
        fh.write(("\n".join(header) + "\n").encode("ascii"))
        for v in vertices:
            fh.write(record.pack(float(v["x"]), float(v["y"]), float(v["z"]), float(v.get("nx", 0.0)), float(v.get("ny", 0.0)), float(v.get("nz", 0.0)), int(v.get("red", 255)), int(v.get("green", 255)), int(v.get("blue", 255))))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--parent-output-dir", required=True)
    ap.add_argument("--mode", choices=["preview", "hq"], required=True)
    ap.add_argument("--output-ply", required=True)
    ap.add_argument("ply", nargs="*")
    args = ap.parse_args()
    started = time.time()

    chunks = args.ply or [str(p) for p in sorted(Path(args.parent_output_dir).glob("chunks/chunk_*/fused.ply"))]
    all_vertices = []
    warnings = []
    skipped = 0
    done = 0
    input_formats = []
    input_total = 0

    for chunk in chunks:
        path = Path(chunk)
        try:
            if not path.is_file():
                raise ValueError("fused.ply does not exist")
            if path.stat().st_size <= 100:
                raise ValueError("fused.ply is too small")
            with open(path, "rb") as fh:
                if fh.read(3) != b"ply":
                    raise ValueError("Invalid PLY header")
            ply = read_ply(path)
            if ply["format"] not in input_formats:
                input_formats.append(ply["format"])
            input_total += int(ply["vertex_count"])
            if int(ply["vertex_count"]) == 0:
                warnings.append(f"{chunk}: zero vertices")
                skipped += 1
                continue
            all_vertices.extend(ply["vertices"])
            done += 1
        except Exception as exc:
            warnings.append(f"{chunk}: {exc}")
            skipped += 1

    merged = []
    if all_vertices:
        xs = [v["x"] for v in all_vertices]; ys = [v["y"] for v in all_vertices]; zs = [v["z"] for v in all_vertices]
        diag = math.dist((min(xs), min(ys), min(zs)), (max(xs), max(ys), max(zs))) or 1.0
        voxel = diag / (2000 if args.mode == "preview" else 5000)
        seen = set()
        for v in all_vertices:
            key = (round(v["x"] / voxel), round(v["y"] / voxel), round(v["z"] / voxel))
            if key not in seen:
                seen.add(key)
                merged.append(v)

    status = "ERROR" if done == 0 else ("DONE_WITH_WARNINGS" if warnings else "DONE")
    out = Path(args.output_ply)
    write_binary_ply(out, merged)
    result = {
        "status": status, "mode": args.mode, "chunks_total": len(chunks), "chunks_done": done, "chunks_skipped": skipped,
        "input_vertices_total": input_total, "merged_vertices": len(merged), "fused_vertices": len(merged),
        "input_formats": input_formats, "output_format": "binary_little_endian", "output_ply": str(out),
        "duration_sec": round(time.time() - started, 3), "warnings": warnings,
    }
    (out.parent / "result.json").write_text(json.dumps(result, indent=2), encoding="utf-8")
    if status == "ERROR":
        raise SystemExit(2)


if __name__ == "__main__":
    main()
