"""Unit tests for gate_assets.py."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

from gate_assets import main, run_asset_gate
from lib.asset_manifest import save_asset_manifest


def _sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _write_manifest(path: Path, css_body: str) -> None:
    manifest = {
        "schemaVersion": 1,
        "pageId": "fixture-page",
        "assets": [
            {
                "id": "css-000",
                "kind": "css",
                "url": "http://localhost/orkui/revised.css",
                "inline": False,
                "sha256": _sha256(css_body),
                "byteLength": len(css_body.encode("utf-8")),
                "baselinePath": "baselines/assets/fixture-page/css-000-revised.css",
            }
        ],
    }
    save_asset_manifest(path, manifest)


def _write_candidate_bytes(calibration_dir: Path, css_body: str) -> None:
    assets_dir = calibration_dir / "assets" / "candidate"
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / "css-000-revised.css").write_text(css_body, encoding="utf-8")


def test_run_asset_gate_passes_on_identical_manifests(tmp_path: Path):
    css = "body{color:red}"
    baseline_path = tmp_path / "baseline.assets.json"
    candidate_path = tmp_path / "candidate.assets.json"
    _write_manifest(baseline_path, css)
    _write_manifest(candidate_path, css)

    result = run_asset_gate(
        baseline_path=baseline_path,
        candidate_path=candidate_path,
        assets_min_score=1.0,
    )
    assert result.passed


def test_run_asset_gate_fails_on_one_byte_change(tmp_path: Path):
    tool_root = tmp_path / "tools" / "fuzzy-validator"
    calibration_dir = tool_root / "calibrations" / "fixture-page"
    baseline_path = tool_root / "baselines" / "fixture-page.assets.json"
    candidate_path = calibration_dir / "candidate.assets.json"

    _write_manifest(baseline_path, "body{color:red}")
    _write_manifest(candidate_path, "body{color:re d}")
    _write_candidate_bytes(calibration_dir, "body{color:re d}")

    baseline_bytes_dir = tool_root / "baselines" / "assets" / "fixture-page"
    baseline_bytes_dir.mkdir(parents=True, exist_ok=True)
    (baseline_bytes_dir / "css-000-revised.css").write_text("body{color:red}", encoding="utf-8")

    diff_dir = tool_root / "reports" / "asset-diffs"
    result = run_asset_gate(
        baseline_path=baseline_path,
        candidate_path=candidate_path,
        assets_min_score=1.0,
        calibration_dir=calibration_dir,
        diff_dir=diff_dir,
        tool_root=tool_root,
    )
    assert not result.passed
    assert result.changed_ids == ["css-000"]
    assert (diff_dir / "css-000.diff").exists()


def test_gate_assets_cli_pass_exit_code(tmp_path: Path):
    css = "body{}"
    baseline_path = tmp_path / "baseline.assets.json"
    candidate_path = tmp_path / "candidate.assets.json"
    _write_manifest(baseline_path, css)
    _write_manifest(candidate_path, css)
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--baseline",
                str(baseline_path),
                "--candidate",
                str(candidate_path),
            ]
        )
        == 0
    )


def test_gate_assets_cli_fail_exit_code(tmp_path: Path):
    baseline_path = tmp_path / "baseline.assets.json"
    candidate_path = tmp_path / "candidate.assets.json"
    _write_manifest(baseline_path, "body{color:red}")
    _write_manifest(candidate_path, "body{color:blue}")
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--baseline",
                str(baseline_path),
                "--candidate",
                str(candidate_path),
                "--json-out",
                str(tmp_path / "result.json"),
            ]
        )
        == 1
    )
    assert "changedIds" in (tmp_path / "result.json").read_text(encoding="utf-8")


def test_run_asset_gate_writes_binary_diff(tmp_path: Path):
    tool_root = tmp_path / "tools" / "fuzzy-validator"
    calibration_dir = tool_root / "calibrations" / "fixture-page"
    baseline_path = tool_root / "baselines" / "fixture-page.assets.json"
    candidate_path = calibration_dir / "candidate.assets.json"

    baseline_bytes = bytes([0, 1, 2, 3])
    candidate_bytes = bytes([0, 1, 2, 4])
    manifest = {
        "schemaVersion": 1,
        "pageId": "fixture-page",
        "assets": [
            {
                "id": "js-000",
                "kind": "js",
                "url": "http://localhost/orkui/revised.js",
                "inline": False,
                "sha256": _sha256("placeholder"),
                "byteLength": len(baseline_bytes),
                "baselinePath": "baselines/assets/fixture-page/js-000-revised.js",
            }
        ],
    }
    save_asset_manifest(baseline_path, manifest)
    save_asset_manifest(
        candidate_path,
        {
            **manifest,
            "assets": [
                {
                    **manifest["assets"][0],
                    "sha256": hashlib.sha256(candidate_bytes).hexdigest(),
                    "byteLength": len(candidate_bytes),
                }
            ],
        },
    )

    baseline_bytes_dir = tool_root / "baselines" / "assets" / "fixture-page"
    candidate_bytes_dir = calibration_dir / "assets" / "candidate"
    baseline_bytes_dir.mkdir(parents=True, exist_ok=True)
    candidate_bytes_dir.mkdir(parents=True, exist_ok=True)
    (baseline_bytes_dir / "js-000-revised.js").write_bytes(baseline_bytes)
    (candidate_bytes_dir / "js-000-revised.js").write_bytes(candidate_bytes)

    diff_dir = tool_root / "reports" / "asset-diffs"
    result = run_asset_gate(
        baseline_path=baseline_path,
        candidate_path=candidate_path,
        assets_min_score=1.0,
        calibration_dir=calibration_dir,
        diff_dir=diff_dir,
        tool_root=tool_root,
    )
    assert not result.passed
    assert (diff_dir / "js-000.diff").exists()
