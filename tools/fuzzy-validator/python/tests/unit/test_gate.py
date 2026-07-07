"""Unit tests for gate.py."""

from __future__ import annotations

from pathlib import Path

import pytest

from gate import main, run_pixel_gate
from lib.manifest import load_fuzz_manifest
from tests.conftest import make_uniform_image, make_image_with_patch


def test_run_pixel_gate_passes_when_diff_inside_fuzz_zone(gate_fixture_paths: dict):
    manifest = load_fuzz_manifest(gate_fixture_paths["manifest"])
    result = run_pixel_gate(
        baseline_path=gate_fixture_paths["baseline"],
        candidate_path=gate_fixture_paths["candidate"],
        manifest=manifest,
        color_threshold=10,
        max_outside_diff=500,
        visual_min_score=1.0,
        min_area_px=4,
        pad_px=0,
        morphology_kernel_px=1,
    )
    assert not result.passed
    assert result.outside_diff_px == 100


def test_run_pixel_gate_passes_when_diff_covered_by_fuzz(tmp_path: Path):
    width, height = 40, 40
    baseline = make_uniform_image(width, height, (200, 200, 200))
    candidate = baseline.copy()
    candidate[5:15, 5:15] = (0, 0, 0)
    baseline_path = tmp_path / "baseline.png"
    candidate_path = tmp_path / "candidate.png"
    from tests.conftest import _write_rgb

    _write_rgb(baseline_path, baseline)
    _write_rgb(candidate_path, candidate)
    manifest_path = tmp_path / "fuzz.json"
    manifest_path.write_text(
        """
{
  "pageId": "fixture",
  "imageWidth": 40,
  "imageHeight": 40,
  "fuzzZones": [{"x": 0, "y": 0, "width": 20, "height": 20, "source": "auto"}],
  "manualZones": []
}
""".strip()
        + "\n",
        encoding="utf-8",
    )
    manifest = load_fuzz_manifest(manifest_path)
    result = run_pixel_gate(
        baseline_path=baseline_path,
        candidate_path=candidate_path,
        manifest=manifest,
        color_threshold=10,
        max_outside_diff=500,
        visual_min_score=1.0,
        min_area_px=4,
        pad_px=0,
        morphology_kernel_px=1,
    )
    assert result.passed
    assert result.outside_diff_px == 0


def test_gate_cli_fail_exit_code(gate_fixture_paths: dict, tmp_path: Path):
    diff_out = tmp_path / "diff.png"
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--baseline",
                str(gate_fixture_paths["baseline"]),
                "--candidate",
                str(gate_fixture_paths["candidate"]),
                "--manifest",
                str(gate_fixture_paths["manifest"]),
                "--diff-out",
                str(diff_out),
            ]
        )
        == 1
    )
    assert diff_out.exists()


def test_gate_cli_pass_exit_code(tmp_path: Path):
    width, height = 24, 24
    image = make_uniform_image(width, height, (150, 150, 150))
    baseline_path = tmp_path / "baseline.png"
    candidate_path = tmp_path / "candidate.png"
    from tests.conftest import _write_rgb

    _write_rgb(baseline_path, image)
    _write_rgb(candidate_path, image.copy())
    manifest_path = tmp_path / "fuzz.json"
    manifest_path.write_text(
        """
{
  "pageId": "fixture",
  "imageWidth": 24,
  "imageHeight": 24,
  "fuzzZones": [],
  "manualZones": []
}
""".strip()
        + "\n",
        encoding="utf-8",
    )
    assert (
        main(
            [
                "--page-id",
                "fixture",
                "--baseline",
                str(baseline_path),
                "--candidate",
                str(candidate_path),
                "--manifest",
                str(manifest_path),
            ]
        )
        == 0
    )


def test_gate_cli_writes_json_result(gate_fixture_paths: dict, tmp_path: Path):
    json_out = tmp_path / "result.json"
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--baseline",
                str(gate_fixture_paths["baseline"]),
                "--candidate",
                str(gate_fixture_paths["candidate"]),
                "--manifest",
                str(gate_fixture_paths["manifest"]),
                "--json-out",
                str(json_out),
            ]
        )
        == 1
    )
    assert "outsideDiffPx" in json_out.read_text(encoding="utf-8")


def test_gate_cli_manifest_dimension_mismatch(tmp_path: Path):
    width, height = 20, 20
    image = make_uniform_image(width, height, (100, 100, 100))
    from tests.conftest import _write_rgb

    baseline_path = _write_rgb(tmp_path / "baseline.png", image)
    candidate_path = _write_rgb(tmp_path / "candidate.png", image)
    manifest_path = tmp_path / "fuzz.json"
    manifest_path.write_text(
        """
{
  "pageId": "fixture",
  "imageWidth": 10,
  "imageHeight": 10,
  "fuzzZones": [],
  "manualZones": []
}
""".strip()
        + "\n",
        encoding="utf-8",
    )
    assert (
        main(
            [
                "--page-id",
                "fixture",
                "--baseline",
                str(baseline_path),
                "--candidate",
                str(candidate_path),
                "--manifest",
                str(manifest_path),
            ]
        )
        == 2
    )


def test_run_pixel_gate_dimension_mismatch(tmp_path: Path):
    baseline = make_uniform_image(20, 20, (1, 1, 1))
    candidate = make_uniform_image(22, 20, (1, 1, 1))
    baseline_path = tmp_path / "baseline-dim.png"
    candidate_path = tmp_path / "candidate-dim.png"
    from tests.conftest import _write_rgb

    _write_rgb(baseline_path, baseline)
    _write_rgb(candidate_path, candidate)
    manifest = {
        "imageWidth": 20,
        "imageHeight": 20,
        "fuzzZones": [],
        "manualZones": [],
    }
    with pytest.raises(ValueError, match="Dimension mismatch"):
        run_pixel_gate(
            baseline_path=baseline_path,
            candidate_path=candidate_path,
            manifest=manifest,
            color_threshold=10,
            max_outside_diff=500,
            visual_min_score=1.0,
        )
