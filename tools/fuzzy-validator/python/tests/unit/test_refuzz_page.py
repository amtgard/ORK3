"""Unit tests for refuzz_page.py."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import patch

import numpy as np
import pytest
from PIL import Image

from lib.canonical_dom import html_to_canonical_tree, save_canonical_tree
from lib.manifest import save_json
from refuzz_page import (
    discover_pixel_zones_from_pair,
    main,
    merge_pixel_zones,
    refuzz_page,
)


def _write_png(path: Path, color: tuple[int, int, int], size: tuple[int, int] = (32, 32)) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    width, height = size
    Image.fromarray(np.full((height, width, 3), color, dtype=np.uint8)).save(path)


def _defaults() -> dict:
    return {
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
        "morphologyKernelPx": 3,
        "domCompareScriptBodies": False,
    }


def _seed_refuzz_tree(
    tool_root: Path,
    *,
    page_id: str = "fixture-page",
    profile: str = "test",
    candidate_color: tuple[int, int, int] = (0, 0, 0),
    baseline_html: str = "<html><body><div>Stable</div></body></html>",
    candidate_html: str = "<html><body><div>Changed</div></body></html>",
    image_size: tuple[int, int] = (32, 32),
) -> str:
    cal_dir = tool_root / "calibrations" / page_id
    baseline_dir = tool_root / "baselines" / profile
    manifest_dir = tool_root / "manifests" / profile

    _write_png(baseline_dir / f"{page_id}.png", (200, 200, 200), size=image_size)
    _write_png(cal_dir / "candidate.png", candidate_color, size=image_size)

    save_canonical_tree(
        baseline_dir / f"{page_id}.dom.json",
        html_to_canonical_tree(baseline_html),
    )
    cal_dir.mkdir(parents=True, exist_ok=True)
    (cal_dir / "candidate.dom.html").write_text(candidate_html, encoding="utf-8")

    save_json(
        manifest_dir / f"{page_id}.fuzz.json",
        {
            "schemaVersion": 1,
            "pageId": page_id,
            "imageWidth": image_size[0],
            "imageHeight": image_size[1],
            "fuzzZones": [{"x": 1, "y": 1, "width": 2, "height": 2, "source": "auto"}],
            "manualZones": [],
            "params": {},
        },
    )
    save_json(
        manifest_dir / f"{page_id}.dom-fuzz.json",
        {
            "schemaVersion": 1,
            "pageId": page_id,
            "fuzzNodes": [],
            "manualNodes": [],
            "calibrationRuns": 1,
        },
    )
    save_json(tool_root / "manifests" / "defaults.json5", _defaults())
    return page_id


def test_merge_pixel_zones_dedupes_identical_boxes():
    existing = [{"x": 1, "y": 2, "width": 3, "height": 4, "source": "auto"}]
    new_zones = [
        {"x": 1, "y": 2, "width": 3, "height": 4, "source": "refuzz"},
        {"x": 10, "y": 10, "width": 5, "height": 5, "source": "refuzz"},
    ]
    merged = merge_pixel_zones(existing, new_zones)
    assert len(merged) == 2
    assert merged[1]["x"] == 10


def test_discover_pixel_zones_from_pair(tmp_path: Path):
    baseline = tmp_path / "baseline.png"
    candidate = tmp_path / "candidate.png"
    _write_png(baseline, (200, 200, 200))
    _write_png(candidate, (0, 0, 0))
    zones, width, height = discover_pixel_zones_from_pair(
        baseline,
        candidate,
        color_threshold=20,
        min_area_px=4,
        pad_px=0,
        morphology_kernel_px=3,
    )
    assert width == 32
    assert height == 32
    assert zones
    assert zones[0]["source"] == "refuzz"


def test_refuzz_page_merges_dom_and_pixel(tmp_path: Path):
    page_id = _seed_refuzz_tree(tmp_path)
    summary = refuzz_page(
        page_id=page_id,
        profile="test",
        tool_root=tmp_path,
        repo_root=tmp_path,
        phase="all",
        defaults=_defaults(),
    )
    assert summary["rebaselined"] is True
    assert summary["domNodesAdded"] >= 1
    assert summary["pixelZonesAdded"] >= 1

    pixel_manifest = (tmp_path / "manifests" / "test" / f"{page_id}.fuzz.json").read_text(
        encoding="utf-8"
    )
    assert "refuzzedAt" in pixel_manifest
    assert (tmp_path / "baselines" / "test" / f"{page_id}.png").is_file()
    assert (tmp_path / "baselines" / "test" / f"{page_id}.dom.json").is_file()


def test_refuzz_page_visual_only_skips_dom(tmp_path: Path):
    page_id = _seed_refuzz_tree(tmp_path)
    summary = refuzz_page(
        page_id=page_id,
        profile="test",
        tool_root=tmp_path,
        repo_root=tmp_path,
        phase="visual",
        defaults=_defaults(),
    )
    assert summary["domNodesAdded"] == 0
    assert summary["rebaselined"] is True
    assert summary["pixelZonesAdded"] >= 1


def test_refuzz_page_dom_only_skips_pixel(tmp_path: Path):
    page_id = _seed_refuzz_tree(tmp_path)
    summary = refuzz_page(
        page_id=page_id,
        profile="test",
        tool_root=tmp_path,
        repo_root=tmp_path,
        phase="dom",
        defaults=_defaults(),
    )
    assert summary["pixelZonesAdded"] == 0
    assert summary["domNodesAdded"] >= 1
    assert summary["rebaselined"] is True


def test_refuzz_page_dimension_drift(tmp_path: Path):
    page_id = _seed_refuzz_tree(tmp_path, image_size=(32, 32))
    # Overwrite candidate with different dimensions
    _write_png(
        tmp_path / "calibrations" / page_id / "candidate.png",
        (0, 0, 0),
        size=(40, 40),
    )
    summary = refuzz_page(
        page_id=page_id,
        profile="test",
        tool_root=tmp_path,
        repo_root=tmp_path,
        phase="visual",
        defaults=_defaults(),
    )
    assert summary["pixelZonesAdded"] == 0
    assert summary["rebaselined"] is True
    from lib.manifest import load_json5

    manifest = load_json5(tmp_path / "manifests" / "test" / f"{page_id}.fuzz.json")
    assert manifest["imageWidth"] == 40
    assert manifest["imageHeight"] == 40


def test_refuzz_page_missing_candidates_raises(tmp_path: Path):
    with pytest.raises(ValueError, match="Missing candidate capture"):
        refuzz_page(
            page_id="missing",
            profile="test",
            tool_root=tmp_path,
            repo_root=tmp_path,
            phase="all",
            defaults=_defaults(),
        )


def test_refuzz_page_creates_manifests_when_absent(tmp_path: Path):
    page_id = "new-page"
    cal_dir = tmp_path / "calibrations" / page_id
    baseline_dir = tmp_path / "baselines" / "test"
    _write_png(baseline_dir / f"{page_id}.png", (200, 200, 200))
    _write_png(cal_dir / "candidate.png", (0, 0, 0))
    save_canonical_tree(
        baseline_dir / f"{page_id}.dom.json",
        html_to_canonical_tree("<html><body><div>A</div></body></html>"),
    )
    (cal_dir / "candidate.dom.html").write_text(
        "<html><body><div>B</div></body></html>",
        encoding="utf-8",
    )
    summary = refuzz_page(
        page_id=page_id,
        profile="test",
        tool_root=tmp_path,
        repo_root=tmp_path,
        phase="all",
        defaults=_defaults(),
    )
    assert summary["rebaselined"] is True
    assert (tmp_path / "manifests" / "test" / f"{page_id}.fuzz.json").is_file()
    assert (tmp_path / "manifests" / "test" / f"{page_id}.dom-fuzz.json").is_file()


def test_refuzz_main_success(tmp_path: Path):
    page_id = _seed_refuzz_tree(tmp_path)
    with patch("refuzz_page._git_head", return_value="abc1234"):
        assert (
            main(
                [
                    "--page-id",
                    page_id,
                    "--profile",
                    "test",
                    "--tool-root",
                    str(tmp_path),
                    "--phase",
                    "all",
                ]
            )
            == 0
        )


def test_refuzz_main_missing_files_exits_one(tmp_path: Path):
    save_json(tmp_path / "manifests" / "defaults.json5", _defaults())
    assert (
        main(
            [
                "--page-id",
                "missing",
                "--profile",
                "test",
                "--tool-root",
                str(tmp_path),
            ]
        )
        == 1
    )


def test_git_head_handles_failure(tmp_path: Path):
    from refuzz_page import _git_head

    with patch("refuzz_page.subprocess.run", side_effect=FileNotFoundError):
        assert _git_head(tmp_path) is None
