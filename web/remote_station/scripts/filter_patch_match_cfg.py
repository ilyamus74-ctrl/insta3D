
#!/usr/bin/env python3

import argparse
import json
import shutil
from pathlib import Path


def read_non_empty_lines(path: Path) -> list[str]:
    return [
        line.strip()
        for line in path.read_text(encoding="utf-8", errors="replace").splitlines()
        if line.strip()
    ]


def parse_source_line(line: str) -> list[str]:
    return [
        item.strip()
        for item in line.split(",")
        if item.strip()
    ]


def build_neighbour_sources(
    reference: str,
    ordered_images: list[str],
    max_sources: int,
) -> list[str]:
    try:
        index = ordered_images.index(reference)
    except ValueError:
        return []

    result: list[str] = []

    distance = 1
    while len(result) < max_sources:
        added = False

        left = index - distance
        right = index + distance

        if left >= 0:
            result.append(ordered_images[left])
            added = True

            if len(result) >= max_sources:
                break

        if right < len(ordered_images):
            result.append(ordered_images[right])
            added = True

            if len(result) >= max_sources:
                break

        if not added:
            break

        distance += 1

    return result


def main() -> int:
    parser = argparse.ArgumentParser(
        description=(
            "Filter COLMAP patch-match.cfg so every reference image and "
            "source image belongs to the current dense chunk"
        )
    )

    parser.add_argument("cfg")
    parser.add_argument("images_dir")
    parser.add_argument("image_list")
    parser.add_argument("--stats-json", default="")
    parser.add_argument("--max-sources", type=int, default=8)

    args = parser.parse_args()

    cfg_path = Path(args.cfg)
    images_dir = Path(args.images_dir)
    image_list_path = Path(args.image_list)

    if not cfg_path.is_file():
        raise RuntimeError(f"patch-match.cfg not found: {cfg_path}")

    if not images_dir.is_dir():
        raise RuntimeError(f"undistorted images directory not found: {images_dir}")

    if not image_list_path.is_file():
        raise RuntimeError(f"chunk image list not found: {image_list_path}")

    existing_images = {
        path.name
        for path in images_dir.iterdir()
        if path.is_file()
    }

    requested_images = read_non_empty_lines(image_list_path)

    ordered_images = [
        image
        for image in requested_images
        if image in existing_images
    ]

    if len(ordered_images) < 2:
        raise RuntimeError(
            f"Only {len(ordered_images)} usable images found in chunk"
        )

    original_path = cfg_path.with_name(cfg_path.name + ".original")

    if not original_path.exists():
        shutil.copy2(cfg_path, original_path)

    original_lines = read_non_empty_lines(cfg_path)

    original_pairs = 0
    original_sources = 0

    for index in range(0, len(original_lines) - 1, 2):
        original_pairs += 1
        original_sources += len(parse_source_line(original_lines[index + 1]))

    output_lines: list[str] = []
    generated_sources = 0
    removed_references = 0

    for reference in ordered_images:
        sources = build_neighbour_sources(
            reference=reference,
            ordered_images=ordered_images,
            max_sources=max(1, args.max_sources),
        )

        sources = [
            source
            for source in sources
            if source != reference and source in existing_images
        ]

        if not sources:
            removed_references += 1
            continue

        output_lines.append(reference)
        output_lines.append(", ".join(sources))
        generated_sources += len(sources)

    if not output_lines:
        raise RuntimeError(
            "Generated patch-match.cfg contains no usable reference/source pairs"
        )

    cfg_path.write_text(
        "\n".join(output_lines) + "\n",
        encoding="utf-8",
    )

    stats = {
        "original_pairs": original_pairs,
        "original_sources": original_sources,
        "chunk_requested_images": len(requested_images),
        "chunk_existing_images": len(ordered_images),
        "generated_pairs": len(output_lines) // 2,
        "generated_sources": generated_sources,
        "removed_references": removed_references,
        "max_sources": max(1, args.max_sources),
        "config_path": str(cfg_path),
    }

    if args.stats_json:
        stats_path = Path(args.stats_json)
        stats_path.parent.mkdir(parents=True, exist_ok=True)
        stats_path.write_text(
            json.dumps(stats, indent=2),
            encoding="utf-8",
        )

    print(json.dumps(stats))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())