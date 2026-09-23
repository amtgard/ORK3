"""Unit tests for discover_fuzz.py."""

from __future__ import annotations

from pathlib import Path

import pytest

from discover_fuzz import discover_fuzz_zones, main


def test_discover_fuzz_zones_from_calibration_dir(tiny_calibration_dir: Path):
    zones, width, height, base_image = discover_fuzz_zones(
        tiny_calibration_dir,
        color_threshold=10,
        min_area_px=4,
        pad_px=1,
        morphology_kernel_px=3,
    )
    assert width == 64
    assert height == 64
    assert base_image.shape == (64, 64, 3)
    assert zones


def test_discover_fuzz_cli_writes_manifest_and_overlay(
    tiny_calibration_dir: Path,
    tmp_path: Path,
):
    out_manifest = tmp_path / "fixture.fuzz.json"
    overlay = tmp_path / "overlay.png"
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--calibration-dir",
                str(tiny_calibration_dir),
                "--out",
                str(out_manifest),
                "--overlay",
                str(overlay),
            ]
        )
        == 0
    )
    assert out_manifest.exists()
    assert overlay.exists()


def test_discover_fuzz_cli_writes_baseline(
    tiny_calibration_dir: Path,
    tmp_path: Path,
):
    out_manifest = tmp_path / "fixture.fuzz.json"
    overlay = tmp_path / "overlay.png"
    baseline = tmp_path / "baseline.png"
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--calibration-dir",
                str(tiny_calibration_dir),
                "--out",
                str(out_manifest),
                "--overlay",
                str(overlay),
                "--baseline-out",
                str(baseline),
            ]
        )
        == 0
    )
    assert baseline.exists()


def test_discover_fuzz_main_failure_exit_code(tmp_path: Path):
    empty_dir = tmp_path / "empty"
    empty_dir.mkdir()
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--calibration-dir",
                str(empty_dir),
                "--out",
                str(tmp_path / "out.json"),
            ]
        )
        == 1
    )
