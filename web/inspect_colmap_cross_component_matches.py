#!/usr/bin/env python3
"""
Inspect cross-component COLMAP feature matches between sparse model 0 and model 1.

Reads:
  - common COLMAP database.db
  - sparse/0/txt/images.txt
  - sparse/1/txt/images.txt

Reports:
  - raw cross-component matches
  - verified two-view geometry inliers
  - matches where both 2D features already belong to 3D points
    (usable as model1 <-> model0 Sim(3) correspondences)

No files are modified.
"""

from __future__ import annotations

import argparse
import json
import sqlite3
import struct
from pathlib import Path
from typing import Any

MAX_IMAGE_ID = 2_147_483_647


def decode_pair_id(pair_id: int) -> tuple[int, int]:
    image_id2 = int(pair_id % MAX_IMAGE_ID)
    image_id1 = int((pair_id - image_id2) // MAX_IMAGE_ID)
    return image_id1, image_id2


def parse_images_txt(path: Path) -> dict[str, dict[str, Any]]:
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    output: dict[str, dict[str, Any]] = {}

    index = 0
    while index < len(lines):
        line = lines[index].strip()
        index += 1

        if not line or line.startswith("#"):
            continue

        parts = line.split(maxsplit=9)
        if len(parts) < 10:
            continue

        try:
            sparse_image_id = int(parts[0])
        except ValueError:
            continue

        name = parts[9]

        # The next physical line is POINTS2D, and may be empty.
        points_line = ""
        if index < len(lines):
            points_line = lines[index].strip()
            index += 1

        point3d_ids: list[int] = []
        if points_line and not points_line.startswith("#"):
            point_parts = points_line.split()
            for offset in range(0, len(point_parts) - 2, 3):
                try:
                    point3d_ids.append(int(point_parts[offset + 2]))
                except ValueError:
                    point3d_ids.append(-1)

        output[name] = {
            "name": name,
            "sparse_image_id": sparse_image_id,
            "point3d_ids": point3d_ids,
            "registered_point2d_count": sum(
                1 for point_id in point3d_ids if point_id >= 0
            ),
        }

    return output


def decode_matches_blob(data: bytes | None, rows: int, cols: int) -> list[tuple[int, int]]:
    if not data or rows <= 0 or cols != 2:
        return []

    expected_size = rows * cols * 4
    if len(data) < expected_size:
        return []

    return list(struct.iter_unpack("<II", data[:expected_size]))


def count_3d3d(
    matches: list[tuple[int, int]],
    left_point3d_ids: list[int],
    right_point3d_ids: list[int],
) -> tuple[int, int, int]:
    both_3d = 0
    left_3d = 0
    right_3d = 0

    for left_index, right_index in matches:
        left_point = (
            left_point3d_ids[left_index]
            if left_index < len(left_point3d_ids)
            else -1
        )
        right_point = (
            right_point3d_ids[right_index]
            if right_index < len(right_point3d_ids)
            else -1
        )

        if left_point >= 0:
            left_3d += 1
        if right_point >= 0:
            right_3d += 1
        if left_point >= 0 and right_point >= 0:
            both_3d += 1

    return both_3d, left_3d, right_3d


def inspect_table(
    connection: sqlite3.Connection,
    table: str,
    db_images: dict[int, str],
    component_by_db_id: dict[int, int],
    sparse_info_by_db_id: dict[int, dict[str, Any]],
) -> list[dict[str, Any]]:
    columns = {
        row[1]
        for row in connection.execute(f"PRAGMA table_info({table})")
    }

    if not {"pair_id", "rows", "cols", "data"}.issubset(columns):
        return []

    output: list[dict[str, Any]] = []

    query = f"""
        SELECT pair_id, rows, cols, data
        FROM {table}
        WHERE rows > 0
    """

    for pair_id, rows, cols, data in connection.execute(query):
        image_id1, image_id2 = decode_pair_id(int(pair_id))

        component1 = component_by_db_id.get(image_id1)
        component2 = component_by_db_id.get(image_id2)

        if component1 is None or component2 is None or component1 == component2:
            continue

        name1 = db_images.get(image_id1, f"<image_id:{image_id1}>")
        name2 = db_images.get(image_id2, f"<image_id:{image_id2}>")

        matches = decode_matches_blob(data, int(rows), int(cols))

        info1 = sparse_info_by_db_id.get(image_id1, {"point3d_ids": []})
        info2 = sparse_info_by_db_id.get(image_id2, {"point3d_ids": []})

        both_3d, side1_3d, side2_3d = count_3d3d(
            matches,
            info1["point3d_ids"],
            info2["point3d_ids"],
        )

        output.append(
            {
                "table": table,
                "pair_id": int(pair_id),
                "image_id1": image_id1,
                "image_id2": image_id2,
                "component1": component1,
                "component2": component2,
                "image_name1": name1,
                "image_name2": name2,
                "rows": int(rows),
                "decoded_matches": len(matches),
                "side1_matches_with_3d": side1_3d,
                "side2_matches_with_3d": side2_3d,
                "matches_with_3d_on_both_sides": both_3d,
            }
        )

    output.sort(
        key=lambda item: (
            item["matches_with_3d_on_both_sides"],
            item["decoded_matches"],
        ),
        reverse=True,
    )
    return output


def summarize(items: list[dict[str, Any]]) -> dict[str, Any]:
    return {
        "cross_component_pairs": len(items),
        "total_matches": sum(item["decoded_matches"] for item in items),
        "total_3d3d_matches": sum(
            item["matches_with_3d_on_both_sides"] for item in items
        ),
        "pairs_with_at_least_3_3d3d": sum(
            1
            for item in items
            if item["matches_with_3d_on_both_sides"] >= 3
        ),
        "pairs_with_at_least_10_3d3d": sum(
            1
            for item in items
            if item["matches_with_3d_on_both_sides"] >= 10
        ),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--job-dir",
        type=Path,
        default=Path(
            "/home/makler/web/remote_station/output/job_972009591"
        ),
    )
    parser.add_argument(
        "--output-json",
        type=Path,
        default=Path(
            "/tmp/colmap_cross_component_matches_972009591.json"
        ),
    )
    parser.add_argument("--top", type=int, default=30)
    args = parser.parse_args()

    database = args.job_dir / "colmap/database.db"
    images0_path = args.job_dir / "colmap/sparse/0/txt/images.txt"
    images1_path = args.job_dir / "colmap/sparse/1/txt/images.txt"

    for required in (database, images0_path, images1_path):
        if not required.is_file():
            raise SystemExit(f"ERROR: missing file: {required}")

    sparse0 = parse_images_txt(images0_path)
    sparse1 = parse_images_txt(images1_path)

    connection = sqlite3.connect(f"file:{database}?mode=ro", uri=True)
    try:
        db_images = {
            int(image_id): str(name)
            for image_id, name in connection.execute(
                "SELECT image_id, name FROM images"
            )
        }

        db_id_by_name = {
            name: image_id for image_id, name in db_images.items()
        }

        missing0 = sorted(set(sparse0) - set(db_id_by_name))
        missing1 = sorted(set(sparse1) - set(db_id_by_name))

        component_by_db_id: dict[int, int] = {}
        sparse_info_by_db_id: dict[int, dict[str, Any]] = {}

        for component, records in ((0, sparse0), (1, sparse1)):
            for name, info in records.items():
                db_id = db_id_by_name.get(name)
                if db_id is None:
                    continue
                component_by_db_id[db_id] = component
                sparse_info_by_db_id[db_id] = info

        raw_matches = inspect_table(
            connection,
            "matches",
            db_images,
            component_by_db_id,
            sparse_info_by_db_id,
        )
        verified_matches = inspect_table(
            connection,
            "two_view_geometries",
            db_images,
            component_by_db_id,
            sparse_info_by_db_id,
        )

        payload = {
            "job_dir": str(args.job_dir),
            "database": str(database),
            "model0_registered_images": len(sparse0),
            "model1_registered_images": len(sparse1),
            "model0_names_missing_from_db": missing0,
            "model1_names_missing_from_db": missing1,
            "raw_matches_summary": summarize(raw_matches),
            "verified_matches_summary": summarize(verified_matches),
            "top_raw_cross_component_pairs": raw_matches[: args.top],
            "top_verified_cross_component_pairs": verified_matches[: args.top],
        }

        args.output_json.parent.mkdir(parents=True, exist_ok=True)
        args.output_json.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        print(
            f"model0_registered_images={len(sparse0)} "
            f"model1_registered_images={len(sparse1)}"
        )
        print(
            "raw:",
            json.dumps(
                payload["raw_matches_summary"],
                ensure_ascii=False,
            ),
        )
        print(
            "verified:",
            json.dumps(
                payload["verified_matches_summary"],
                ensure_ascii=False,
            ),
        )

        print("\nTOP VERIFIED CROSS-COMPONENT PAIRS")
        if not verified_matches:
            print("  none")
        for index, item in enumerate(
            verified_matches[: args.top],
            start=1,
        ):
            print(
                f"{index:02d}. "
                f"3d3d={item['matches_with_3d_on_both_sides']:4d} "
                f"inliers={item['decoded_matches']:4d} "
                f"c{item['component1']}:{item['image_name1']} "
                f"<-> c{item['component2']}:{item['image_name2']}"
            )

        print("\nTOP RAW CROSS-COMPONENT PAIRS")
        if not raw_matches:
            print("  none")
        for index, item in enumerate(raw_matches[: args.top], start=1):
            print(
                f"{index:02d}. "
                f"3d3d={item['matches_with_3d_on_both_sides']:4d} "
                f"matches={item['decoded_matches']:4d} "
                f"c{item['component1']}:{item['image_name1']} "
                f"<-> c{item['component2']}:{item['image_name2']}"
            )

        print(f"\nJSON: {args.output_json}")
        return 0
    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
