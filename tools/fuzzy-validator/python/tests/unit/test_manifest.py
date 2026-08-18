"""Unit tests for manifest.py."""

from __future__ import annotations

from pathlib import Path

from lib.manifest import (
    build_fuzz_manifest,
    effective_fuzz_zones,
    load_defaults,
    load_fuzz_manifest,
    save_json,
)


def test_load_defaults_contains_pixel_thresholds():
    defaults = load_defaults()
    assert defaults["colorThreshold"] == 20
    assert defaults["minAreaPx"] == 64


def test_build_and_save_fuzz_manifest(tmp_path: Path):
    manifest = build_fuzz_manifest(
        page_id="fixture",
        image_width=100,
        image_height=200,
        fuzz_zones=[{"x": 1, "y": 2, "width": 3, "height": 4, "source": "auto"}],
        params={"colorThreshold": 20, "minAreaPx": 64, "padPx": 4},
        calibration_runs=5,
        calibrated_from_commit="abc1234",
    )
    out = save_json(tmp_path / "fixture.fuzz.json", manifest)
    loaded = load_fuzz_manifest(out)
    assert loaded["pageId"] == "fixture"
    assert loaded["calibratedFromCommit"] == "abc1234"
    zones = effective_fuzz_zones(loaded)
    assert len(zones) == 1
