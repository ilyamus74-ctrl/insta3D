#!/usr/bin/env python3
import importlib.util
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / 'scripts' / 'build_clean_mesh.py'
spec = importlib.util.spec_from_file_location('build_clean_mesh', SCRIPT)
build_clean_mesh = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build_clean_mesh)


class FakeArray(list):
    def __lt__(self, other):
        return FakeArray(value < other for value in self)

    def sum(self):
        return sum(self)


class FakeNumpy:
    @staticmethod
    def asarray(values, dtype=None):
        return FakeArray(float(value) for value in values)

    @staticmethod
    def quantile(values, quantile):
        ordered = sorted(values)
        position = (len(ordered) - 1) * quantile
        lower = int(position)
        upper = min(lower + 1, len(ordered) - 1)
        fraction = position - lower
        return ordered[lower] * (1 - fraction) + ordered[upper] * fraction


class FakeMesh:
    def __init__(self, vertex_count, triangles=None, log=None):
        self.vertices = list(range(vertex_count))
        self.triangles = triangles if triangles is not None else [(0, 1, 2)]
        self.log = log if log is not None else []

    def clone(self):
        return FakeMesh(len(self.vertices), list(self.triangles), self.log)

    def remove_vertices_by_mask(self, mask):
        self.log.append(('remove_vertices_by_mask', len(mask), int(mask.sum())))
        self.vertices = [v for v, remove in zip(self.vertices, mask) if not remove]

    def remove_degenerate_triangles(self):
        self.log.append(('cleanup', len(self.vertices)))

    def remove_duplicated_triangles(self):
        pass

    def remove_duplicated_vertices(self):
        # Simulate a vertex-changing cleanup to prove it happens after masking.
        if self.vertices:
            self.vertices.pop()

    def remove_non_manifold_edges(self):
        pass

    def remove_unreferenced_vertices(self):
        pass


class DensityFilterOrderTest(unittest.TestCase):
    def setUp(self):
        self.original_clone_mesh = build_clean_mesh.clone_mesh

    def tearDown(self):
        build_clean_mesh.clone_mesh = self.original_clone_mesh

    def test_density_mask_is_applied_before_vertex_changing_cleanup(self):
        mesh = FakeMesh(4)
        build_clean_mesh.clone_mesh = lambda m: m.clone()

        filtered, densities, threshold, removed = build_clean_mesh.apply_density_filter_before_cleanup(
            mesh,
            [0.1, 0.2, 0.3, 0.4],
            0.5,
            FakeNumpy,
        )

        self.assertEqual(len(densities), 4)
        self.assertEqual(threshold, 0.25)
        self.assertEqual(removed, 2)
        self.assertEqual(mesh.log[0], ('remove_vertices_by_mask', 4, 2))
        self.assertEqual(mesh.log[1], ('cleanup', 2))
        self.assertEqual(len(filtered.vertices), 1)
        self.assertEqual(len(mesh.vertices), 4)

    def test_density_count_mismatch_reports_both_counts(self):
        mesh = FakeMesh(3)
        with self.assertRaisesRegex(RuntimeError, 'density_count=2, vertex_count=3'):
            build_clean_mesh.apply_density_filter_before_cleanup(mesh, [0.1, 0.2], 0.5, FakeNumpy)


if __name__ == '__main__':
    unittest.main()