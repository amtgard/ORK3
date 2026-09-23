"""Evidence-style: intentional overlay turns controlled visual FAIL→PASS."""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np
from PIL import Image

from gate_run import finalize_run, run_batch_gate, run_page_gate
from lib.drift_overlay import load_overlay, merge_overlays
from lib.manifest import build_dom_fuzz_manifest, save_json
from lib.canonical_dom import html_to_canonical_tree, save_canonical_tree
from lib.scoring import Thresholds


def _write_png(path: Path, array: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.fromarray(array).save(path)


def _fixture_with_corner_mutation(tool_root: Path) -> str:
    page_id = "overlay-fixture"
    cal_dir = tool_root / "calibrations" / page_id
    baselines = tool_root / "baselines" / "test"
    manifests = tool_root / "manifests" / "test"

    base = np.full((64, 64, 3), 200, dtype=np.uint8)
    cand = base.copy()
    cand[0:20, 0:20] = (0, 0, 0)  # intentional corner mutation
    cand[40:50, 40:50] = (255, 0, 0)  # residual unexpected mutation

    _write_png(baselines / f"{page_id}.png", base)
    _write_png(cal_dir / "candidate.png", cand)

    save_json(
        manifests / f"{page_id}.fuzz.json",
        {
            "schemaVersion": 1,
            "pageId": page_id,
            "imageWidth": 64,
            "imageHeight": 64,
            "calibrationRuns": 1,
            "params": {},
            "fuzzZones": [],
            "manualZones": [],
        },
    )

    tree = html_to_canonical_tree("<html><body><div>ok</div></body></html>")
    save_canonical_tree(baselines / f"{page_id}.dom.json", tree)
    cal_dir.mkdir(parents=True, exist_ok=True)
    (cal_dir / "candidate.dom.html").write_text(
        "<html><body><div>ok</div></body></html>", encoding="utf-8"
    )
    save_json(
        manifests / f"{page_id}.dom-fuzz.json",
        build_dom_fuzz_manifest(page_id=page_id, fuzz_nodes=[], calibration_runs=1),
    )

    assets = {
        "schemaVersion": 1,
        "pageId": page_id,
        "assets": [
            {
                "id": "css-000",
                "kind": "css",
                "url": "http://example/a.css",
                "inline": False,
                "sha256": "abc",
                "byteLength": 3,
            }
        ],
    }
    save_json(baselines / f"{page_id}.assets.json", assets)
    save_json(cal_dir / "candidate.assets.json", assets)

    save_json(
        tool_root / "manifests" / "defaults.json5",
        {
            "colorThreshold": 20,
            "minAreaPx": 4,
            "padPx": 0,
            "morphologyKernelPx": 1,
            "gateMaxOutsideDiffPx": 500,
            "assetsMinScore": 1.0,
            "domMinScore": 1.0,
            "visualMinScore": 1.0,
        },
    )
    return page_id


def test_without_overlay_fails_unexpected(tmp_path: Path):
    page_id = _fixture_with_corner_mutation(tmp_path)
    defaults = {
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
        "morphologyKernelPx": 1,
        "gateMaxOutsideDiffPx": 500,
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
    }
    result = run_page_gate(
        page_id=page_id,
        phase="visual",
        tool_root=tmp_path,
        defaults=defaults,
        profile="test",
    )
    assert result.passed is False
    assert any(d.status == "unexpected" for d in result.drifts)


def test_intentional_overlay_covers_region_residual_still_fails(tmp_path: Path):
    page_id = _fixture_with_corner_mutation(tmp_path)
    overlay_path = tmp_path / "intentional.json5"
    overlay_path.write_text(
        json.dumps(
            {
                "schemaVersion": 2,
                "id": "corner-fix",
                "entries": [
                    {
                        "id": "corner",
                        "class": "intentional",
                        "layer": "visual",
                        "profiles": ["test"],
                        "pages": [page_id],
                        "visual": {"x": 0, "y": 0, "width": 22, "height": 22},
                        "rationale": "Planned corner chrome",
                        "requirementRef": "docs/example.md#1",
                        "source": "putative",
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    overlays = merge_overlays([load_overlay(overlay_path)])
    defaults = {
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
        "morphologyKernelPx": 1,
        "gateMaxOutsideDiffPx": 500,
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
    }
    result = run_page_gate(
        page_id=page_id,
        phase="visual",
        tool_root=tmp_path,
        defaults=defaults,
        profile="test",
        overlays=overlays,
    )
    # Corner covered → expected intentional; residual red box still unexpected → FAIL
    assert any(
        d.status == "expected" and d.class_ == "intentional" for d in result.drifts
    )
    assert any(d.status == "unexpected" for d in result.drifts)
    assert result.passed is False


def test_full_coverage_overlay_passes_and_writes_drifts(tmp_path: Path):
    page_id = _fixture_with_corner_mutation(tmp_path)
    # Cover both mutated regions
    overlay_path = tmp_path / "full.json5"
    overlay_path.write_text(
        json.dumps(
            {
                "schemaVersion": 2,
                "id": "full-cover",
                "entries": [
                    {
                        "id": "a",
                        "class": "intentional",
                        "layer": "visual",
                        "profiles": ["test"],
                        "pages": [page_id],
                        "visual": {"x": 0, "y": 0, "width": 22, "height": 22},
                        "rationale": "corner",
                        "requirementRef": "docs/a.md",
                        "source": "manual",
                    },
                    {
                        "id": "b",
                        "class": "natural",
                        "layer": "visual",
                        "profiles": ["test"],
                        "pages": [page_id],
                        "visual": {"x": 38, "y": 38, "width": 14, "height": 14},
                        "rationale": "widget",
                        "source": "manual",
                    },
                ],
            }
        ),
        encoding="utf-8",
    )
    overlays = merge_overlays([load_overlay(overlay_path)])
    defaults = {
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
        "morphologyKernelPx": 1,
        "gateMaxOutsideDiffPx": 500,
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0,
    }
    run_dir = tmp_path / "reports" / "run-overlay-demo"
    page_results, exit_code = run_batch_gate(
        page_ids=[page_id],
        phase="visual",
        tool_root=tmp_path,
        defaults=defaults,
        run_dir=run_dir,
        profile="test",
        thresholds=Thresholds(),
        overlays=overlays,
        overlay_cli_args=f"--overlay {overlay_path}",
    )
    assert exit_code == 0
    assert page_results[0].passed is True
    finalize_run(
        run_dir=run_dir,
        phase="visual",
        page_results=page_results,
        exit_code=0,
        thresholds=Thresholds(),
        profile="test",
        overlays=overlays,
    )
    drifts_path = run_dir / "drifts.json"
    assert drifts_path.is_file()
    payload = json.loads(drifts_path.read_text(encoding="utf-8"))
    assert payload["counts"]["unexpected"] == 0
    assert (run_dir / "pages" / page_id / "reproduce.md").is_file()
    html = (run_dir / "index.html").read_text(encoding="utf-8")
    assert "Expected intentional" in html or "expected" in html.lower()


def test_annotations_do_not_change_exit(tmp_path: Path):
    page_id = _fixture_with_corner_mutation(tmp_path)
    defaults = {
        "colorThreshold": 20,
        "minAreaPx": 4,
        "padPx": 0,
        "morphologyKernelPx": 1,
        "gateMaxOutsideDiffPx": 500,
        "visualMinScore": 1.0,
    }
    run_dir = tmp_path / "reports" / "run-ann"
    page_results, exit_code = run_batch_gate(
        page_ids=[page_id],
        phase="visual",
        tool_root=tmp_path,
        defaults=defaults,
        run_dir=run_dir,
        profile="test",
        thresholds=Thresholds(),
    )
    assert exit_code == 1
    ann = run_dir / "annotations.json"
    ann.write_text(
        json.dumps(
            {
                "runId": "run-ann",
                "items": [
                    {
                        "driftId": page_results[0].drifts[0].drift_id,
                        "assessment": "reasonable",
                        "notes": "should not greenwash",
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    finalize_run(
        run_dir=run_dir,
        phase="visual",
        page_results=page_results,
        exit_code=exit_code,
        thresholds=Thresholds(),
        profile="test",
        annotations_path=ann,
    )
    # Exit unchanged; unexpected still in drifts.json
    payload = json.loads((run_dir / "drifts.json").read_text(encoding="utf-8"))
    assert payload["counts"]["unexpected"] >= 1
    html = (run_dir / "index.html").read_text(encoding="utf-8")
    assert "Unexpected" in html
    assert "reasonable" in html
