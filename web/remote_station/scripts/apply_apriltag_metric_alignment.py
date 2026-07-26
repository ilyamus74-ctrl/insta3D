#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import math
import os
import random
import shutil
import sys
import tempfile
from collections import defaultdict, deque
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

import cv2  # type: ignore
import numpy as np


SUPPORTED_IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}


@dataclass
class Camera:
    camera_id: int
    model: str
    width: int
    height: int
    params: list[float]


@dataclass
class ImageRecord:
    image_id: int
    qvec: np.ndarray
    tvec: np.ndarray
    camera_id: int
    name: str
    points2d: list[tuple[float, float, int]]


@dataclass
class Point3D:
    point_id: int
    xyz: np.ndarray
    rgb: tuple[int, int, int]
    error: float
    track: list[tuple[int, int]]


@dataclass
class Model:
    component: str
    source_dir: Path
    cameras: dict[int, Camera]
    images: dict[int, ImageRecord]
    points3d: dict[int, Point3D]


@dataclass
class EdgeEstimate:
    component: str
    tag_id: int
    matrix_tag_from_component: np.ndarray
    scale: float
    inlier_images: list[str]
    residuals_m: list[float]
    median_residual_m: float
    max_residual_m: float
    pnp_reprojection_median_px: float


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--frames-dir", required=True)
    parser.add_argument("--sparse-dir", required=True)
    parser.add_argument("--assist-json", required=True)
    parser.add_argument("--marker-size-m", type=float, default=0.160)
    parser.add_argument("--min-observations", type=int, default=3)
    parser.add_argument("--max-pnp-error-px", type=float, default=4.0)
    parser.add_argument("--alignment-max-error-m", type=float, default=0.04)
    parser.add_argument("--min-baseline-m", type=float, default=0.05)
    parser.add_argument("--apply", action="store_true")
    return parser.parse_args()


def atomic_write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    temporary.replace(path)


def find_text_model_dir(model_dir: Path) -> Path:
    if (model_dir / "cameras.txt").is_file() and (model_dir / "images.txt").is_file():
        return model_dir
    text_dir = model_dir / "txt"
    if (text_dir / "cameras.txt").is_file() and (text_dir / "images.txt").is_file():
        return text_dir
    raise RuntimeError(f"COLMAP text model is missing: {model_dir}")


def parse_cameras(path: Path) -> dict[int, Camera]:
    cameras: dict[int, Camera] = {}
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 5:
            raise RuntimeError(f"invalid cameras.txt row: {line}")
        camera_id = int(parts[0])
        cameras[camera_id] = Camera(
            camera_id=camera_id,
            model=parts[1],
            width=int(parts[2]),
            height=int(parts[3]),
            params=[float(value) for value in parts[4:]],
        )
    if not cameras:
        raise RuntimeError(f"no cameras found in {path}")
    return cameras


def data_lines_preserve_empty(path: Path) -> list[str]:
    result: list[str] = []
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        if raw.lstrip().startswith("#"):
            continue
        result.append(raw.rstrip())
    return result


def parse_images(path: Path) -> dict[int, ImageRecord]:
    rows = data_lines_preserve_empty(path)
    images: dict[int, ImageRecord] = {}
    index = 0
    while index < len(rows):
        header = rows[index].strip()
        index += 1
        if not header:
            continue
        parts = header.split(maxsplit=9)
        if len(parts) < 10:
            raise RuntimeError(f"invalid images.txt header: {header}")
        points_line = rows[index].strip() if index < len(rows) else ""
        index += 1
        points_tokens = points_line.split()
        if len(points_tokens) % 3 != 0:
            raise RuntimeError(f"invalid images.txt points row for {parts[9]}")
        points2d = [
            (
                float(points_tokens[offset]),
                float(points_tokens[offset + 1]),
                int(points_tokens[offset + 2]),
            )
            for offset in range(0, len(points_tokens), 3)
        ]
        image_id = int(parts[0])
        images[image_id] = ImageRecord(
            image_id=image_id,
            qvec=np.asarray([float(value) for value in parts[1:5]], dtype=np.float64),
            tvec=np.asarray([float(value) for value in parts[5:8]], dtype=np.float64),
            camera_id=int(parts[8]),
            name=parts[9],
            points2d=points2d,
        )
    if not images:
        raise RuntimeError(f"no registered images found in {path}")
    return images


def parse_points3d(path: Path) -> dict[int, Point3D]:
    if not path.is_file():
        return {}
    points: dict[int, Point3D] = {}
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 8 or (len(parts) - 8) % 2 != 0:
            raise RuntimeError(f"invalid points3D.txt row: {line[:120]}")
        point_id = int(parts[0])
        points[point_id] = Point3D(
            point_id=point_id,
            xyz=np.asarray([float(value) for value in parts[1:4]], dtype=np.float64),
            rgb=(int(parts[4]), int(parts[5]), int(parts[6])),
            error=float(parts[7]),
            track=[
                (int(parts[offset]), int(parts[offset + 1]))
                for offset in range(8, len(parts), 2)
            ],
        )
    return points


def load_models(sparse_dir: Path) -> dict[str, Model]:
    models: dict[str, Model] = {}
    directories = [path for path in sparse_dir.iterdir() if path.is_dir()]
    directories.sort(key=lambda path: int(path.name) if path.name.isdigit() else path.name)
    for model_dir in directories:
        text_dir = find_text_model_dir(model_dir)
        models[model_dir.name] = Model(
            component=model_dir.name,
            source_dir=model_dir,
            cameras=parse_cameras(text_dir / "cameras.txt"),
            images=parse_images(text_dir / "images.txt"),
            points3d=parse_points3d(text_dir / "points3D.txt"),
        )
    if not models:
        raise RuntimeError("sparse directory contains no models")
    return models


def qvec_to_rotmat(qvec: np.ndarray) -> np.ndarray:
    q = np.asarray(qvec, dtype=np.float64)
    q = q / np.linalg.norm(q)
    w, x, y, z = q
    return np.asarray(
        [
            [1 - 2 * y * y - 2 * z * z, 2 * x * y - 2 * w * z, 2 * x * z + 2 * w * y],
            [2 * x * y + 2 * w * z, 1 - 2 * x * x - 2 * z * z, 2 * y * z - 2 * w * x],
            [2 * x * z - 2 * w * y, 2 * y * z + 2 * w * x, 1 - 2 * x * x - 2 * y * y],
        ],
        dtype=np.float64,
    )


def rotmat_to_qvec(rotation: np.ndarray) -> np.ndarray:
    matrix = np.asarray(rotation, dtype=np.float64)
    trace = float(np.trace(matrix))
    if trace > 0.0:
        root = math.sqrt(trace + 1.0)
        w = 0.5 * root
        factor = 0.5 / root
        x = (matrix[2, 1] - matrix[1, 2]) * factor
        y = (matrix[0, 2] - matrix[2, 0]) * factor
        z = (matrix[1, 0] - matrix[0, 1]) * factor
    else:
        diagonal = np.diag(matrix)
        index = int(np.argmax(diagonal))
        if index == 0:
            root = math.sqrt(max(0.0, 1.0 + matrix[0, 0] - matrix[1, 1] - matrix[2, 2]))
            x = 0.5 * root
            factor = 0.5 / root if root > 1e-15 else 0.0
            y = (matrix[0, 1] + matrix[1, 0]) * factor
            z = (matrix[0, 2] + matrix[2, 0]) * factor
            w = (matrix[2, 1] - matrix[1, 2]) * factor
        elif index == 1:
            root = math.sqrt(max(0.0, 1.0 - matrix[0, 0] + matrix[1, 1] - matrix[2, 2]))
            y = 0.5 * root
            factor = 0.5 / root if root > 1e-15 else 0.0
            x = (matrix[0, 1] + matrix[1, 0]) * factor
            z = (matrix[1, 2] + matrix[2, 1]) * factor
            w = (matrix[0, 2] - matrix[2, 0]) * factor
        else:
            root = math.sqrt(max(0.0, 1.0 - matrix[0, 0] - matrix[1, 1] + matrix[2, 2]))
            z = 0.5 * root
            factor = 0.5 / root if root > 1e-15 else 0.0
            x = (matrix[0, 2] + matrix[2, 0]) * factor
            y = (matrix[1, 2] + matrix[2, 1]) * factor
            w = (matrix[1, 0] - matrix[0, 1]) * factor
    quaternion = np.asarray([w, x, y, z], dtype=np.float64)
    quaternion /= np.linalg.norm(quaternion)
    if quaternion[0] < 0:
        quaternion *= -1
    return quaternion


def camera_center(image: ImageRecord) -> np.ndarray:
    rotation = qvec_to_rotmat(image.qvec)
    return -rotation.T @ image.tvec


def camera_calibration(camera: Camera) -> tuple[np.ndarray, np.ndarray | None, str]:
    model = camera.model.upper()
    params = camera.params
    if model == "SIMPLE_PINHOLE":
        f, cx, cy = params
        return np.asarray([[f, 0, cx], [0, f, cy], [0, 0, 1]], dtype=np.float64), None, "standard"
    if model == "PINHOLE":
        fx, fy, cx, cy = params
        return np.asarray([[fx, 0, cx], [0, fy, cy], [0, 0, 1]], dtype=np.float64), None, "standard"
    if model == "SIMPLE_RADIAL":
        f, cx, cy, k1 = params
        return np.asarray([[f, 0, cx], [0, f, cy], [0, 0, 1]], dtype=np.float64), np.asarray([k1, 0, 0, 0, 0], dtype=np.float64), "standard"
    if model == "RADIAL":
        f, cx, cy, k1, k2 = params
        return np.asarray([[f, 0, cx], [0, f, cy], [0, 0, 1]], dtype=np.float64), np.asarray([k1, k2, 0, 0, 0], dtype=np.float64), "standard"
    if model == "OPENCV":
        fx, fy, cx, cy, k1, k2, p1, p2 = params
        return np.asarray([[fx, 0, cx], [0, fy, cy], [0, 0, 1]], dtype=np.float64), np.asarray([k1, k2, p1, p2], dtype=np.float64), "standard"
    if model == "FULL_OPENCV":
        fx, fy, cx, cy, *distortion = params
        return np.asarray([[fx, 0, cx], [0, fy, cy], [0, 0, 1]], dtype=np.float64), np.asarray(distortion, dtype=np.float64), "standard"
    if model == "SIMPLE_RADIAL_FISHEYE":
        f, cx, cy, k1 = params
        return np.asarray([[f, 0, cx], [0, f, cy], [0, 0, 1]], dtype=np.float64), np.asarray([k1, 0, 0, 0], dtype=np.float64), "fisheye"
    if model == "RADIAL_FISHEYE":
        f, cx, cy, k1, k2 = params
        return np.asarray([[f, 0, cx], [0, f, cy], [0, 0, 1]], dtype=np.float64), np.asarray([k1, k2, 0, 0], dtype=np.float64), "fisheye"
    if model == "OPENCV_FISHEYE":
        fx, fy, cx, cy, k1, k2, k3, k4 = params
        return np.asarray([[fx, 0, cx], [0, fy, cy], [0, 0, 1]], dtype=np.float64), np.asarray([k1, k2, k3, k4], dtype=np.float64), "fisheye"
    if model == "FOV":
        fx, fy, cx, cy, omega = params
        return np.asarray([[fx, 0, cx], [0, fy, cy], [0, 0, 1]], dtype=np.float64), np.asarray([omega], dtype=np.float64), "fov"
    raise RuntimeError(f"unsupported COLMAP camera model for AprilTag PnP: {camera.model}")


def undistort_fov(points: np.ndarray, camera_matrix: np.ndarray, omega: float) -> np.ndarray:
    fx = camera_matrix[0, 0]
    fy = camera_matrix[1, 1]
    cx = camera_matrix[0, 2]
    cy = camera_matrix[1, 2]
    distorted = np.empty_like(points, dtype=np.float64)
    distorted[:, 0] = (points[:, 0] - cx) / fx
    distorted[:, 1] = (points[:, 1] - cy) / fy
    rd = np.linalg.norm(distorted, axis=1)
    result = distorted.copy()
    if abs(omega) < 1e-12:
        return result
    denominator = 2.0 * math.tan(omega / 2.0)
    valid = rd > 1e-12
    ru = np.tan(rd[valid] * omega) / denominator
    result[valid] *= (ru / rd[valid])[:, None]
    return result


def solve_tag_pose(
    corners: np.ndarray,
    camera: Camera,
    marker_size_m: float,
) -> tuple[np.ndarray, np.ndarray, float]:
    camera_matrix, distortion, mode = camera_calibration(camera)
    image_points = np.asarray(corners, dtype=np.float64).reshape(4, 2)
    solve_matrix = camera_matrix
    solve_distortion: np.ndarray | None = distortion

    if mode == "fisheye":
        image_points = cv2.fisheye.undistortPoints(
            image_points.reshape(-1, 1, 2),
            camera_matrix,
            distortion,
        ).reshape(-1, 2)
        solve_matrix = np.eye(3, dtype=np.float64)
        solve_distortion = None
    elif mode == "fov":
        image_points = undistort_fov(image_points, camera_matrix, float(distortion[0]))
        solve_matrix = np.eye(3, dtype=np.float64)
        solve_distortion = None

    half = marker_size_m / 2.0
    object_points = np.asarray(
        [
            [-half, half, 0.0],
            [half, half, 0.0],
            [half, -half, 0.0],
            [-half, -half, 0.0],
        ],
        dtype=np.float64,
    )

    flag = getattr(cv2, "SOLVEPNP_IPPE_SQUARE", cv2.SOLVEPNP_ITERATIVE)
    result = cv2.solvePnPGeneric(
        object_points,
        image_points,
        solve_matrix,
        solve_distortion,
        flags=flag,
    )
    if not result or not bool(result[0]):
        raise RuntimeError("solvePnPGeneric failed")

    rvecs = list(result[1])
    tvecs = list(result[2])
    candidates: list[tuple[float, np.ndarray, np.ndarray]] = []
    for rvec, tvec in zip(rvecs, tvecs):
        rotation, _ = cv2.Rodrigues(np.asarray(rvec, dtype=np.float64))
        translation = np.asarray(tvec, dtype=np.float64).reshape(3)
        camera_points = (rotation @ object_points.T).T + translation
        if float(np.min(camera_points[:, 2])) <= 0:
            continue
        projected, _ = cv2.projectPoints(
            object_points,
            np.asarray(rvec, dtype=np.float64),
            translation,
            solve_matrix,
            solve_distortion,
        )
        reprojection = float(
            np.sqrt(np.mean(np.sum((projected.reshape(-1, 2) - image_points) ** 2, axis=1)))
        )
        candidates.append((reprojection, rotation, translation))

    if not candidates:
        raise RuntimeError("AprilTag pose has no positive-depth PnP solution")
    candidates.sort(key=lambda item: item[0])
    reprojection, rotation_tag_to_camera, translation_tag_to_camera = candidates[0]
    camera_center_in_tag = -rotation_tag_to_camera.T @ translation_tag_to_camera
    return camera_center_in_tag, rotation_tag_to_camera, reprojection


def umeyama_similarity(source: np.ndarray, target: np.ndarray) -> tuple[float, np.ndarray, np.ndarray]:
    source = np.asarray(source, dtype=np.float64)
    target = np.asarray(target, dtype=np.float64)
    if source.shape != target.shape or source.ndim != 2 or source.shape[1] != 3:
        raise ValueError("similarity inputs must have shape Nx3")
    if len(source) < 3:
        raise ValueError("at least three correspondences are required")
    source_mean = source.mean(axis=0)
    target_mean = target.mean(axis=0)
    source_centered = source - source_mean
    target_centered = target - target_mean
    variance = float(np.sum(source_centered * source_centered) / len(source))
    if variance <= 1e-15:
        raise ValueError("source correspondences have zero variance")
    covariance = (target_centered.T @ source_centered) / len(source)
    u, singular_values, vt = np.linalg.svd(covariance)
    correction = np.eye(3)
    if np.linalg.det(u) * np.linalg.det(vt) < 0:
        correction[-1, -1] = -1
    rotation = u @ correction @ vt
    scale = float(np.sum(singular_values * np.diag(correction)) / variance)
    if not math.isfinite(scale) or scale <= 0:
        raise ValueError("estimated scale is invalid")
    translation = target_mean - scale * (rotation @ source_mean)
    return scale, rotation, translation


def sim3_matrix(scale: float, rotation: np.ndarray, translation: np.ndarray) -> np.ndarray:
    matrix = np.eye(4, dtype=np.float64)
    matrix[:3, :3] = scale * rotation
    matrix[:3, 3] = translation
    return matrix


def matrix_scale(matrix: np.ndarray) -> float:
    return float(np.mean(np.linalg.norm(matrix[:3, :3], axis=0)))


def normalize_rigid(matrix: np.ndarray) -> np.ndarray:
    result = np.asarray(matrix, dtype=np.float64).copy()
    scale = matrix_scale(result)
    if not math.isfinite(scale) or scale <= 0:
        raise ValueError("cannot normalize invalid similarity transform")
    linear = result[:3, :3] / scale
    u, _, vt = np.linalg.svd(linear)
    rotation = u @ vt
    if np.linalg.det(rotation) < 0:
        u[:, -1] *= -1
        rotation = u @ vt
    result[:3, :3] = rotation
    return result


def transform_points(matrix: np.ndarray, points: np.ndarray) -> np.ndarray:
    points = np.asarray(points, dtype=np.float64)
    return points @ matrix[:3, :3].T + matrix[:3, 3]


def nondegenerate(points: np.ndarray, minimum_baseline: float) -> bool:
    points = np.asarray(points, dtype=np.float64)
    if len(points) < 3:
        return False
    distances = np.linalg.norm(points[:, None, :] - points[None, :, :], axis=2)
    if float(np.max(distances)) < minimum_baseline:
        return False
    centered = points - points.mean(axis=0)
    singular_values = np.linalg.svd(centered, compute_uv=False)
    if len(singular_values) < 2 or singular_values[1] < max(1e-9, singular_values[0] * 1e-4):
        return False
    return True


def estimate_similarity_ransac(
    source: np.ndarray,
    target: np.ndarray,
    image_names: list[str],
    max_error_m: float,
    min_baseline_m: float,
) -> tuple[np.ndarray, np.ndarray, list[float]]:
    if len(source) < 3:
        raise ValueError("at least three correspondences are required")
    rng = random.Random(0xA91A7)
    indices = list(range(len(source)))
    best_inliers: np.ndarray | None = None
    best_median = float("inf")

    combinations: list[tuple[int, int, int]] = []
    if len(indices) <= 12:
        for i in range(len(indices) - 2):
            for j in range(i + 1, len(indices) - 1):
                for k in range(j + 1, len(indices)):
                    combinations.append((i, j, k))
    else:
        seen: set[tuple[int, int, int]] = set()
        for _ in range(800):
            sample = tuple(sorted(rng.sample(indices, 3)))
            if sample not in seen:
                seen.add(sample)
                combinations.append(sample)

    for sample in combinations:
        source_sample = source[list(sample)]
        target_sample = target[list(sample)]
        if not nondegenerate(target_sample, min_baseline_m):
            continue
        try:
            scale, rotation, translation = umeyama_similarity(source_sample, target_sample)
        except ValueError:
            continue
        predicted = scale * (source @ rotation.T) + translation
        residuals = np.linalg.norm(predicted - target, axis=1)
        inliers = residuals <= max_error_m
        count = int(np.count_nonzero(inliers))
        if count < 3:
            continue
        median = float(np.median(residuals[inliers]))
        if best_inliers is None or count > int(np.count_nonzero(best_inliers)) or (
            count == int(np.count_nonzero(best_inliers)) and median < best_median
        ):
            best_inliers = inliers
            best_median = median

    if best_inliers is None:
        raise ValueError("RANSAC could not estimate a valid similarity transform")
    source_inliers = source[best_inliers]
    target_inliers = target[best_inliers]
    if not nondegenerate(target_inliers, min_baseline_m):
        raise ValueError("inlier camera trajectory has insufficient metric baseline")
    scale, rotation, translation = umeyama_similarity(source_inliers, target_inliers)
    predicted = scale * (source @ rotation.T) + translation
    residuals = np.linalg.norm(predicted - target, axis=1)
    final_inliers = residuals <= max_error_m
    if int(np.count_nonzero(final_inliers)) >= 3 and not np.array_equal(final_inliers, best_inliers):
        scale, rotation, translation = umeyama_similarity(source[final_inliers], target[final_inliers])
        predicted = scale * (source @ rotation.T) + translation
        residuals = np.linalg.norm(predicted - target, axis=1)
        best_inliers = residuals <= max_error_m
    matrix = sim3_matrix(scale, rotation, translation)
    return matrix, best_inliers, residuals.tolist()


def detection_observations(
    assist: dict[str, Any],
    models: dict[str, Model],
    marker_size_m: float,
    max_pnp_error_px: float,
) -> tuple[dict[tuple[str, int], list[dict[str, Any]]], list[dict[str, Any]]]:
    image_lookup: dict[tuple[str, str], ImageRecord] = {}
    camera_lookup: dict[tuple[str, int], Camera] = {}
    for component, model in models.items():
        for image in model.images.values():
            image_lookup[(component, Path(image.name).name)] = image
        for camera_id, camera in model.cameras.items():
            camera_lookup[(component, camera_id)] = camera

    grouped: dict[tuple[str, int], list[dict[str, Any]]] = defaultdict(list)
    rejected: list[dict[str, Any]] = []
    for raw in assist.get("detections", []):
        if not isinstance(raw, dict):
            continue
        try:
            marker_id = int(raw.get("marker_id"))
        except (TypeError, ValueError):
            continue
        image_name = Path(str(raw.get("image_name") or raw.get("source_path") or "")).name
        corners = raw.get("corners")
        components = raw.get("components")
        if not image_name or not isinstance(corners, list) or len(corners) != 4:
            continue
        if not isinstance(components, list):
            components = []
        for component_value in components:
            component = str(component_value)
            image = image_lookup.get((component, image_name))
            if image is None:
                continue
            camera = camera_lookup.get((component, image.camera_id))
            if camera is None:
                continue
            try:
                tag_center, _, reprojection = solve_tag_pose(
                    np.asarray(corners, dtype=np.float64),
                    camera,
                    marker_size_m,
                )
            except Exception as exc:
                rejected.append(
                    {
                        "component": component,
                        "tag_id": marker_id,
                        "image_name": image_name,
                        "reason": str(exc),
                    }
                )
                continue
            if reprojection > max_pnp_error_px:
                rejected.append(
                    {
                        "component": component,
                        "tag_id": marker_id,
                        "image_name": image_name,
                        "reason": f"PnP reprojection {reprojection:.3f}px exceeds {max_pnp_error_px:.3f}px",
                    }
                )
                continue
            grouped[(component, marker_id)].append(
                {
                    "image_name": image_name,
                    "component_center": camera_center(image),
                    "tag_center_m": tag_center,
                    "pnp_reprojection_px": reprojection,
                }
            )
    return grouped, rejected


def estimate_edges(
    grouped: dict[tuple[str, int], list[dict[str, Any]]],
    minimum_observations: int,
    max_error_m: float,
    min_baseline_m: float,
) -> tuple[list[EdgeEstimate], list[dict[str, Any]]]:
    edges: list[EdgeEstimate] = []
    failures: list[dict[str, Any]] = []
    for (component, tag_id), observations in sorted(grouped.items()):
        unique: dict[str, dict[str, Any]] = {}
        for observation in observations:
            unique[observation["image_name"]] = observation
        observations = list(unique.values())
        if len(observations) < minimum_observations:
            failures.append(
                {
                    "component": component,
                    "tag_id": tag_id,
                    "status": "INSUFFICIENT_OBSERVATIONS",
                    "observations": len(observations),
                }
            )
            continue
        source = np.asarray([item["component_center"] for item in observations], dtype=np.float64)
        target = np.asarray([item["tag_center_m"] for item in observations], dtype=np.float64)
        try:
            matrix, inlier_mask, residuals = estimate_similarity_ransac(
                source,
                target,
                [item["image_name"] for item in observations],
                max_error_m,
                min_baseline_m,
            )
        except Exception as exc:
            failures.append(
                {
                    "component": component,
                    "tag_id": tag_id,
                    "status": "ALIGNMENT_FAILED",
                    "observations": len(observations),
                    "error": str(exc),
                }
            )
            continue
        inlier_names = [
            observations[index]["image_name"]
            for index, keep in enumerate(inlier_mask)
            if bool(keep)
        ]
        inlier_residuals = [
            residuals[index]
            for index, keep in enumerate(inlier_mask)
            if bool(keep)
        ]
        pnp_errors = [
            observations[index]["pnp_reprojection_px"]
            for index, keep in enumerate(inlier_mask)
            if bool(keep)
        ]
        if len(inlier_names) < minimum_observations:
            failures.append(
                {
                    "component": component,
                    "tag_id": tag_id,
                    "status": "INSUFFICIENT_INLIERS",
                    "observations": len(observations),
                    "inliers": len(inlier_names),
                }
            )
            continue
        edges.append(
            EdgeEstimate(
                component=component,
                tag_id=tag_id,
                matrix_tag_from_component=matrix,
                scale=matrix_scale(matrix),
                inlier_images=inlier_names,
                residuals_m=[float(value) for value in inlier_residuals],
                median_residual_m=float(np.median(inlier_residuals)),
                max_residual_m=float(np.max(inlier_residuals)),
                pnp_reprojection_median_px=float(np.median(pnp_errors)),
            )
        )
    return edges, failures


def graph_groups(
    models: dict[str, Model],
    edges: list[EdgeEstimate],
) -> tuple[list[dict[str, Any]], dict[str, np.ndarray]]:
    adjacency: dict[str, list[tuple[str, EdgeEstimate]]] = defaultdict(list)
    for edge in edges:
        component_node = f"c:{edge.component}"
        tag_node = f"t:{edge.tag_id}"
        adjacency[component_node].append((tag_node, edge))
        adjacency[tag_node].append((component_node, edge))

    known_transforms: dict[str, np.ndarray] = {}
    groups: list[dict[str, Any]] = []
    visited: set[str] = set()

    for start in sorted(adjacency):
        if start in visited:
            continue
        nodes: set[str] = set()
        queue = deque([start])
        visited.add(start)
        while queue:
            node = queue.popleft()
            nodes.add(node)
            for neighbor, _ in adjacency[node]:
                if neighbor not in visited:
                    visited.add(neighbor)
                    queue.append(neighbor)
        components = sorted(node[2:] for node in nodes if node.startswith("c:"))
        tags = sorted(int(node[2:]) for node in nodes if node.startswith("t:"))
        tag_scores = {
            tag_id: sum(
                len(edge.inlier_images)
                for edge in edges
                if edge.tag_id == tag_id and edge.component in components
            )
            for tag_id in tags
        }
        anchor_tag = sorted(tags, key=lambda tag_id: (-tag_scores[tag_id], tag_id))[0]
        anchor_node = f"t:{anchor_tag}"
        local: dict[str, np.ndarray] = {anchor_node: np.eye(4, dtype=np.float64)}
        bfs = deque([anchor_node])
        while bfs:
            node = bfs.popleft()
            current = local[node]
            for neighbor, edge in adjacency[node]:
                if neighbor in local:
                    continue
                edge_matrix = edge.matrix_tag_from_component
                if node.startswith("t:") and neighbor.startswith("c:"):
                    transform = current @ edge_matrix
                elif node.startswith("c:") and neighbor.startswith("t:"):
                    transform = normalize_rigid(current @ np.linalg.inv(edge_matrix))
                else:
                    raise RuntimeError("invalid AprilTag graph edge")
                local[neighbor] = transform
                bfs.append(neighbor)
        for node, transform in local.items():
            known_transforms[node] = transform
        groups.append(
            {
                "aligned": True,
                "components": components,
                "tags": tags,
                "anchor_tag": anchor_tag,
                "registered_images": sum(len(models[component].images) for component in components),
            }
        )

    aligned_components = {
        component
        for group in groups
        for component in group["components"]
    }
    for component, model in models.items():
        if component not in aligned_components:
            groups.append(
                {
                    "aligned": False,
                    "components": [component],
                    "tags": [],
                    "anchor_tag": None,
                    "registered_images": len(model.images),
                }
            )

    groups.sort(
        key=lambda group: (
            0 if group["aligned"] else 1,
            -int(group["registered_images"]),
            tuple(group["components"]),
        )
    )
    return groups, known_transforms


def format_float(value: float) -> str:
    if abs(value) < 5e-16:
        value = 0.0
    return f"{value:.17g}"


def transform_image(image: ImageRecord, matrix_global_from_component: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    scale = matrix_scale(matrix_global_from_component)
    rotation_global_from_component = matrix_global_from_component[:3, :3] / scale
    translation_global_from_component = matrix_global_from_component[:3, 3]
    rotation_camera_from_component = qvec_to_rotmat(image.qvec)
    rotation_camera_from_global = rotation_camera_from_component @ rotation_global_from_component.T
    center_global = transform_points(
        matrix_global_from_component,
        camera_center(image).reshape(1, 3),
    )[0]
    translation_camera_from_global = -rotation_camera_from_global @ center_global
    return rotmat_to_qvec(rotation_camera_from_global), translation_camera_from_global


def camera_key(camera: Camera) -> tuple[Any, ...]:
    return (
        camera.model,
        camera.width,
        camera.height,
        tuple(round(value, 14) for value in camera.params),
    )


def write_group_model(
    destination: Path,
    component_names: list[str],
    models: dict[str, Model],
    component_transforms: dict[str, np.ndarray],
) -> dict[str, int]:
    destination.mkdir(parents=True, exist_ok=False)
    camera_id_by_key: dict[tuple[Any, ...], int] = {}
    cameras_out: dict[int, Camera] = {}
    image_maps: dict[str, dict[int, int]] = {}
    point_maps: dict[str, dict[int, int]] = {}
    camera_maps: dict[str, dict[int, int]] = {}
    next_camera_id = 1
    next_image_id = 1
    next_point_id = 1

    for component in component_names:
        model = models[component]
        camera_maps[component] = {}
        for old_camera_id, camera in sorted(model.cameras.items()):
            key = camera_key(camera)
            new_camera_id = camera_id_by_key.get(key)
            if new_camera_id is None:
                new_camera_id = next_camera_id
                next_camera_id += 1
                camera_id_by_key[key] = new_camera_id
                cameras_out[new_camera_id] = Camera(
                    new_camera_id,
                    camera.model,
                    camera.width,
                    camera.height,
                    list(camera.params),
                )
            camera_maps[component][old_camera_id] = new_camera_id
        image_maps[component] = {}
        for old_image_id in sorted(model.images):
            image_maps[component][old_image_id] = next_image_id
            next_image_id += 1
        point_maps[component] = {}
        for old_point_id in sorted(model.points3d):
            point_maps[component][old_point_id] = next_point_id
            next_point_id += 1

    camera_lines = [
        "# Camera list with one line of data per camera:",
        "#   CAMERA_ID, MODEL, WIDTH, HEIGHT, PARAMS[]",
        f"# Number of cameras: {len(cameras_out)}",
    ]
    for camera_id, camera in sorted(cameras_out.items()):
        camera_lines.append(
            " ".join(
                [
                    str(camera_id),
                    camera.model,
                    str(camera.width),
                    str(camera.height),
                    *[format_float(value) for value in camera.params],
                ]
            )
        )
    (destination / "cameras.txt").write_text("\n".join(camera_lines) + "\n", encoding="utf-8")

    image_lines = [
        "# Image list with two lines of data per image:",
        "#   IMAGE_ID, QW, QX, QY, QZ, TX, TY, TZ, CAMERA_ID, NAME",
        "#   POINTS2D[] as (X, Y, POINT3D_ID)",
        f"# Number of images: {sum(len(models[name].images) for name in component_names)}",
    ]
    point_lines = [
        "# 3D point list with one line of data per point:",
        "#   POINT3D_ID, X, Y, Z, R, G, B, ERROR, TRACK[] as (IMAGE_ID, POINT2D_IDX)",
        f"# Number of points: {sum(len(models[name].points3d) for name in component_names)}",
    ]

    for component in component_names:
        model = models[component]
        transform = component_transforms.get(component, np.eye(4, dtype=np.float64))
        for old_image_id, image in sorted(model.images.items()):
            new_image_id = image_maps[component][old_image_id]
            qvec, tvec = transform_image(image, transform)
            new_camera_id = camera_maps[component][image.camera_id]
            image_lines.append(
                " ".join(
                    [
                        str(new_image_id),
                        *[format_float(value) for value in qvec],
                        *[format_float(value) for value in tvec],
                        str(new_camera_id),
                        image.name,
                    ]
                )
            )
            points_tokens: list[str] = []
            for x, y, old_point_id in image.points2d:
                new_point_id = point_maps[component].get(old_point_id, -1) if old_point_id >= 0 else -1
                points_tokens.extend([format_float(x), format_float(y), str(new_point_id)])
            image_lines.append(" ".join(points_tokens))

        for old_point_id, point in sorted(model.points3d.items()):
            new_point_id = point_maps[component][old_point_id]
            xyz = transform_points(transform, point.xyz.reshape(1, 3))[0]
            track_tokens: list[str] = []
            for old_image_id, point2d_index in point.track:
                if old_image_id not in image_maps[component]:
                    continue
                track_tokens.extend(
                    [str(image_maps[component][old_image_id]), str(point2d_index)]
                )
            point_lines.append(
                " ".join(
                    [
                        str(new_point_id),
                        *[format_float(value) for value in xyz],
                        str(point.rgb[0]),
                        str(point.rgb[1]),
                        str(point.rgb[2]),
                        format_float(point.error),
                        *track_tokens,
                    ]
                )
            )

    (destination / "images.txt").write_text("\n".join(image_lines) + "\n", encoding="utf-8")
    (destination / "points3D.txt").write_text("\n".join(point_lines) + "\n", encoding="utf-8")
    return {
        "cameras": len(cameras_out),
        "images": sum(len(models[name].images) for name in component_names),
        "points3D": sum(len(models[name].points3d) for name in component_names),
    }


def apply_groups(
    sparse_dir: Path,
    models: dict[str, Model],
    groups: list[dict[str, Any]],
    known_transforms: dict[str, np.ndarray],
) -> tuple[list[dict[str, Any]], Path]:
    parent = sparse_dir.parent
    staging = Path(tempfile.mkdtemp(prefix="sparse_apriltag_staging_", dir=parent))
    output_groups: list[dict[str, Any]] = []
    try:
        for model_id, group in enumerate(groups):
            component_transforms: dict[str, np.ndarray] = {}
            for component in group["components"]:
                component_transforms[component] = known_transforms.get(
                    f"c:{component}",
                    np.eye(4, dtype=np.float64),
                )
            counts = write_group_model(
                staging / str(model_id),
                list(group["components"]),
                models,
                component_transforms,
            )
            output_groups.append(
                {
                    "model_id": model_id,
                    "aligned": bool(group["aligned"]),
                    "components": list(group["components"]),
                    "tags": list(group["tags"]),
                    "anchor_tag": group["anchor_tag"],
                    "registered_images": counts["images"],
                    "points3D_count": counts["points3D"],
                    "component_transforms": {
                        component: component_transforms[component].tolist()
                        for component in group["components"]
                    },
                }
            )

        backup = parent / "sparse_before_apriltag"
        suffix = 1
        while backup.exists():
            backup = parent / f"sparse_before_apriltag_{suffix}"
            suffix += 1
        sparse_dir.rename(backup)
        try:
            staging.rename(sparse_dir)
        except Exception:
            if sparse_dir.exists():
                shutil.rmtree(sparse_dir, ignore_errors=True)
            backup.rename(sparse_dir)
            raise
        return output_groups, backup
    except Exception:
        shutil.rmtree(staging, ignore_errors=True)
        raise


def build_report(args: argparse.Namespace) -> dict[str, Any]:
    sparse_dir = Path(args.sparse_dir).resolve()
    frames_dir = Path(args.frames_dir).resolve()
    assist_path = Path(args.assist_json).resolve()
    if not frames_dir.is_dir():
        raise RuntimeError(f"frames directory not found: {frames_dir}")
    if not sparse_dir.is_dir():
        raise RuntimeError(f"sparse directory not found: {sparse_dir}")
    assist = json.loads(assist_path.read_text(encoding="utf-8"))
    if not isinstance(assist, dict):
        raise RuntimeError("AprilTag assist JSON must contain an object")
    models = load_models(sparse_dir)
    minimum = max(3, int(args.min_observations))
    grouped, rejected = detection_observations(
        assist,
        models,
        float(args.marker_size_m),
        float(args.max_pnp_error_px),
    )
    edges, failures = estimate_edges(
        grouped,
        minimum,
        float(args.alignment_max_error_m),
        float(args.min_baseline_m),
    )

    edge_payload = [
        {
            "component": edge.component,
            "tag_id": edge.tag_id,
            "scale_m_per_model_unit": edge.scale,
            "inlier_images": edge.inlier_images,
            "inliers": len(edge.inlier_images),
            "median_alignment_error_m": edge.median_residual_m,
            "max_alignment_error_m": edge.max_residual_m,
            "median_pnp_reprojection_px": edge.pnp_reprojection_median_px,
            "matrix_tag_from_component": edge.matrix_tag_from_component.tolist(),
        }
        for edge in edges
    ]

    report = dict(assist)
    report["detection_status"] = assist.get("status")
    report["assist_only"] = True
    report["sim3_applied"] = False
    report["metric_scale_applied"] = False
    report["component_stitching_applied"] = False
    report["alignment_edges"] = edge_payload
    report["alignment_failures"] = failures
    report["pnp_rejected_observations"] = rejected
    report["alignment_settings"] = {
        "marker_size_m": float(args.marker_size_m),
        "min_observations": minimum,
        "max_pnp_error_px": float(args.max_pnp_error_px),
        "alignment_max_error_m": float(args.alignment_max_error_m),
        "min_baseline_m": float(args.min_baseline_m),
    }
    report["models_before"] = len(models)

    if not edges:
        report["status"] = "METRIC_ALIGNMENT_NOT_READY"
        report["completed_with_warnings"] = True
        report["warning_code"] = "METRIC_ALIGNMENT_NOT_READY"
        report["warning_text"] = (
            "AprilTag-метки обнаружены, но метрическое выравнивание не выполнено: "
            "нужно минимум три качественных зарегистрированных наблюдения одной "
            "неподвижной метки с достаточным перемещением камеры. Dense продолжит "
            "работу в исходной системе координат."
        )
        return report

    groups, known_transforms = graph_groups(models, edges)
    aligned_components = sorted(
        component
        for component in models
        if f"c:{component}" in known_transforms
    )
    unaligned_components = sorted(set(models) - set(aligned_components))
    stitched_components = sum(
        max(0, len(group["components"]) - 1)
        for group in groups
        if group["aligned"]
    )

    report["alignment_groups_planned"] = groups
    report["aligned_components"] = aligned_components
    report["unaligned_components"] = unaligned_components
    report["components_stitched"] = stitched_components

    if not args.apply:
        report["status"] = "METRIC_ALIGNMENT_READY"
        report["completed_with_warnings"] = bool(unaligned_components or len(groups) > 1)
        report["warning_code"] = "METRIC_ALIGNMENT_PREVIEW"
        report["warning_text"] = "AprilTag Sim3 рассчитан, но не применён: запущен preview-режим."
        return report

    output_groups, backup = apply_groups(
        sparse_dir,
        models,
        groups,
        known_transforms,
    )
    report["assist_only"] = False
    report["sim3_applied"] = True
    report["metric_scale_applied"] = True
    report["component_stitching_applied"] = stitched_components > 0
    report["models_after"] = len(output_groups)
    report["primary_model_id"] = 0
    report["output_models"] = output_groups
    report["raw_sparse_backup"] = str(backup)

    if not unaligned_components and len(output_groups) == 1:
        report["status"] = (
            "METRIC_ALIGNED_AND_STITCHED"
            if stitched_components > 0
            else "METRIC_ALIGNED"
        )
        report["completed_with_warnings"] = False
        report["warning_code"] = None
        report["warning_text"] = ""
    else:
        report["status"] = "METRIC_PARTIALLY_ALIGNED"
        report["completed_with_warnings"] = True
        report["warning_code"] = "METRIC_PARTIALLY_ALIGNED"
        report["warning_text"] = (
            "Метрический масштаб применён к компонентам, связанным неподвижными "
            "AprilTag-метками, но часть моделей осталась отдельной. Для полной "
            "стыковки одна и та же метка должна наблюдаться в разных компонентах "
            "либо несколько неподвижных меток должны образовывать связную цепочку."
        )
    return report


def main() -> int:
    args = parse_args()
    assist_path = Path(args.assist_json).resolve()
    original: dict[str, Any] = {}
    try:
        if assist_path.is_file():
            loaded = json.loads(assist_path.read_text(encoding="utf-8"))
            if isinstance(loaded, dict):
                original = loaded
        report = build_report(args)
    except Exception as exc:
        report = dict(original)
        report.update(
            {
                "status": "METRIC_ALIGNMENT_ERROR",
                "assist_only": True,
                "sim3_applied": False,
                "metric_scale_applied": False,
                "component_stitching_applied": False,
                "completed_with_warnings": True,
                "warning_code": "METRIC_ALIGNMENT_ERROR",
                "warning_text": (
                    "AprilTag-метки проверены, но метрическое выравнивание завершилось "
                    "с ошибкой. Исходные sparse-модели сохранены; dense может быть "
                    "продолжен без метрического масштаба."
                ),
                "alignment_error": str(exc),
            }
        )
    atomic_write_json(assist_path, report)
    print(
        "APRILTAG_METRIC | "
        + str(report.get("status"))
        + " | sim3="
        + str(bool(report.get("sim3_applied"))).lower()
        + " | models="
        + str(report.get("models_before", "?"))
        + "->"
        + str(report.get("models_after", report.get("models_before", "?")))
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
