"""Unit tests for gate_run.py."""

from __future__ import annotations

from pathlib import Path
import shutil

import numpy as np
import pytest
from PIL import Image

from gate_run import finalize_run, main, run_page_gate, write_run_summary
from lib.scoring import Thresholds
from lib.canonical_dom import html_to_canonical_tree, save_canonical_tree
from lib.manifest import build_dom_fuzz_manifest, save_json


def _write_png(path: Path, color: tuple[int, int, int]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.fromarray(np.full((32, 32, 3), color, dtype=np.uint8)).save(path)


def _write_unified_fixtures(tool_root: Path, *, candidate_color: tuple[int, int, int]) -> str:
    page_id = "fixture-page"
    cal_dir = tool_root / "calibrations" / page_id
    baselines = tool_root / "baselines"
    manifests = tool_root / "manifests"

    _write_png(baselines / f"{page_id}.png", (200, 200, 200))
    _write_png(cal_dir / "candidate.png", candidate_color)

    save_json(
        manifests / f"{page_id}.fuzz.json",
        {
            "schemaVersion": 1,
            "pageId": page_id,
            "imageWidth": 32,
            "imageHeight": 32,
            "calibrationRuns": 3,
            "params": {"colorThreshold": 20, "minAreaPx": 4, "padPx": 0},
            "fuzzZones": [],
            "manualZones": [],
        },
    )

    baseline_tree = html_to_canonical_tree(
        "<html><body><div>Stable</div></body></html>"
    )
    save_canonical_tree(baselines / f"{page_id}.dom.json", baseline_tree)
    cal_dir.mkdir(parents=True, exist_ok=True)
    (cal_dir / "candidate.dom.html").write_text(
        "<html><body><div>Stable</div></body></html>",
        encoding="utf-8",
    )
    save_json(
        manifests / f"{page_id}.dom-fuzz.json",
        build_dom_fuzz_manifest(page_id=page_id, fuzz_nodes=[], calibration_runs=3),
    )

    asset_manifest = {
        "schemaVersion": 1,
        "pageId": page_id,
        "assets": [
            {
                "id": "css-000",
                "kind": "css",
                "url": "http://example/css/app.css",
                "inline": False,
                "sha256": "abc",
                "byteLength": 3,
                "baselinePath": f"baselines/assets/{page_id}/css-000.css",
            }
        ],
    }
    save_json(baselines / f"{page_id}.assets.json", asset_manifest)
    save_json(cal_dir / "candidate.assets.json", asset_manifest)
    asset_dir = baselines / "assets" / page_id
    asset_dir.mkdir(parents=True, exist_ok=True)
    (asset_dir / "css-000.css").write_text("css", encoding="utf-8")
    (cal_dir / "assets" / "candidate" / "css-000.css").parent.mkdir(parents=True, exist_ok=True)
    (cal_dir / "assets" / "candidate" / "css-000.css").write_text("css", encoding="utf-8")

    defaults = tool_root / "manifests" / "defaults.json5"
    if not defaults.exists():
        save_json(
            defaults,
            {
                "colorThreshold": 20,
                "minAreaPx": 4,
                "padPx": 0,
                "gateMaxOutsideDiffPx": 500,
                "assetsMinScore": 1.0,
                "domMinScore": 1.0,
                "visualMinScore": 1.0,
            },
        )

    return page_id


def test_run_page_gate_all_layers_pass(tmp_path: Path):
    page_id = _write_unified_fixtures(tmp_path, candidate_color=(200, 200, 200))
    defaults = {
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
        "gateMaxOutsideDiffPx": 500,
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
    }
    result = run_page_gate(
        page_id=page_id,
        phase="all",
        tool_root=tmp_path,
        defaults=defaults,
    )
    assert result.passed is True
    assert {layer.layer for layer in result.layers} == {"assets", "dom", "visual"}


def test_run_page_gate_visual_failure(tmp_path: Path):
    page_id = _write_unified_fixtures(tmp_path, candidate_color=(0, 0, 0))
    defaults = {
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
        "gateMaxOutsideDiffPx": 500,
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
    }
    result = run_page_gate(
        page_id=page_id,
        phase="all",
        tool_root=tmp_path,
        defaults=defaults,
    )
    assert result.passed is False
    visual = next(layer for layer in result.layers if layer.layer == "visual")
    assert visual.passed is False


def test_gate_run_main_writes_summary(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    import gate_run

    monkeypatch.setattr(gate_run, "TOOL_ROOT", tmp_path)
    page_id = _write_unified_fixtures(tmp_path, candidate_color=(200, 200, 200))
    run_dir = tmp_path / "reports" / "run-test"
    assert (
        main(
            [
                "--page-id",
                page_id,
                "--phase",
                "all",
                "--run-dir",
                str(run_dir),
            ]
        )
        == 0
    )
    assert (run_dir / "summary.json").exists()
    assert (run_dir / "index.html").exists()
    assert (run_dir / "data" / f"{page_id}-annotated.png").exists()


def test_finalize_run_generates_html_report(tmp_path: Path):
    page_id = _write_unified_fixtures(tmp_path, candidate_color=(200, 200, 200))
    defaults = {
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
        "gateMaxOutsideDiffPx": 500,
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
    }
    result = run_page_gate(
        page_id=page_id,
        phase="all",
        tool_root=tmp_path,
        defaults=defaults,
    )
    run_dir = tmp_path / "reports" / "run-finalize"
    index = finalize_run(
        run_dir=run_dir,
        phase="all",
        page_results=[result],
        exit_code=0,
        thresholds=Thresholds.from_defaults(defaults),
    )
    assert index.exists()
    assert (run_dir / "pages" / f"{page_id}.html").exists()


def test_visual_min_score_allows_drift(tmp_path: Path):
    page_id = _write_unified_fixtures(tmp_path, candidate_color=(180, 180, 180))
    defaults = {
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
        "gateMaxOutsideDiffPx": 500,
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
    }
    strict = run_page_gate(
        page_id=page_id,
        phase="visual",
        tool_root=tmp_path,
        defaults=defaults,
        thresholds=Thresholds(visual_min=1.0),
    )
    lenient = run_page_gate(
        page_id=page_id,
        phase="visual",
        tool_root=tmp_path,
        defaults=defaults,
        thresholds=Thresholds(visual_min=0.98),
    )
    visual_strict = next(layer for layer in strict.layers if layer.layer == "visual")
    visual_lenient = next(layer for layer in lenient.layers if layer.layer == "visual")
    if visual_strict.score < 1.0:
        assert visual_strict.passed is False
        assert visual_lenient.passed is True


def test_run_batch_gate_multiple_pages(tmp_path: Path):
    page_a = _write_unified_fixtures(tmp_path, candidate_color=(200, 200, 200))
    # second page reuses fixture helper with different id by manual setup
    page_b = "fixture-page-b"
    for src, dst in [
        (f"calibrations/{page_a}", f"calibrations/{page_b}"),
        (f"baselines/{page_a}.png", f"baselines/{page_b}.png"),
        (f"manifests/{page_a}.fuzz.json", f"manifests/{page_b}.fuzz.json"),
        (f"baselines/{page_a}.dom.json", f"baselines/{page_b}.dom.json"),
        (f"manifests/{page_a}.dom-fuzz.json", f"manifests/{page_b}.dom-fuzz.json"),
        (f"baselines/{page_a}.assets.json", f"baselines/{page_b}.assets.json"),
    ]:
        src_path = tmp_path / src
        dst_path = tmp_path / dst
        dst_path.parent.mkdir(parents=True, exist_ok=True)
        if src_path.is_dir():
            shutil.copytree(src_path, dst_path)
        else:
            shutil.copy2(src_path, dst_path)
    cal_b = tmp_path / "calibrations" / page_b
    (cal_b / "candidate.assets.json").write_text(
        (tmp_path / "calibrations" / page_a / "candidate.assets.json").read_text(),
        encoding="utf-8",
    )

    from gate_run import run_batch_gate

    defaults = {
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
        "gateMaxOutsideDiffPx": 500,
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
    }
    run_dir = tmp_path / "reports" / "run-batch"
    results, exit_code = run_batch_gate(
        page_ids=[page_a, page_b],
        phase="all",
        tool_root=tmp_path,
        defaults=defaults,
        run_dir=run_dir,
    )
    assert len(results) == 2
    assert exit_code == 0
    from gate_run import LayerResult, PageGateResult

    page_result = PageGateResult(
        page_id="fixture-page",
        passed=True,
        layers=[
            LayerResult(
                layer="visual",
                passed=True,
                score=1.0,
                exit_code=0,
            )
        ],
    )
    run_dir = tmp_path / "run-demo"
    run_dir.mkdir(parents=True, exist_ok=True)
    path = write_run_summary(
        run_dir=run_dir,
        phase="visual",
        page_results=[page_result],
        exit_code=0,
        thresholds=Thresholds(),
    )
    assert path.exists()
