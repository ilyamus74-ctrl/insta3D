#!/usr/bin/env python3
"""Generate a deterministic, bounded set of non-local COLMAP image pairs."""

from __future__ import annotations

import argparse
from pathlib import Path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--frames", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--window", type=int, default=5)
    parser.add_argument("--min-gap", type=int, default=61)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    if args.window < 0 or args.min_gap < 1:
        raise SystemExit("window must be >= 0 and min-gap must be >= 1")

    images = sorted(
        path.name
        for path in args.frames.iterdir()
        if path.is_file() and path.suffix.lower() in {".jpg", ".jpeg", ".png"}
    )
    if len(images) < 2:
        raise SystemExit(f"need at least two images in {args.frames}")

    # Endpoint plus four distributed long-range hypotheses. Small windows make
    # the policy tolerant to a few visually weak anchor frames while remaining
    # O(1) in the sequence length (at most 5 * (2w+1)^2 candidate pairs).
    last = len(images) - 1
    anchors = [
        (0.00, 1.00),
        (0.10, 0.60),
        (0.20, 0.70),
        (0.30, 0.80),
        (0.40, 0.90),
    ]
    pairs: set[tuple[str, str]] = set()
    for left_fraction, right_fraction in anchors:
        left_anchor = round(left_fraction * last)
        right_anchor = round(right_fraction * last)
        left_range = range(max(0, left_anchor - args.window), min(last, left_anchor + args.window) + 1)
        right_range = range(max(0, right_anchor - args.window), min(last, right_anchor + args.window) + 1)
        for left in left_range:
            for right in right_range:
                if right - left >= args.min_gap:
                    pairs.add((images[left], images[right]))

    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(
        "".join(f"{left} {right}\n" for left, right in sorted(pairs)),
        encoding="utf-8",
    )
    print(f"images={len(images)} pairs={len(pairs)} output={args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
