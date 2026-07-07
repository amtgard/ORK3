"""Unit tests for calibrate_assets.py."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from unittest.mock import patch

from calibrate_assets import main


def _sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _write_run(calibration_dir: Path, run_label: str, css_body: str) -> None:
    assets_dir = calibration_dir / "assets" / run_label
    assets_dir.mkdir(parents=True, exist_ok=True)
    (assets_dir / "css-000-revised.css").write_text(css_body, encoding="utf-8")
    manifest = {
        "pageId": "fixture-page",
        "runLabel": run_label,
        "assets": [
            {
                "id": "css-000",
                "kind": "css",
                "url": "http://localhost/orkui/revised.css",
                "inline": False,
                "sha256": _sha256(css_body),
                "byteLength": len(css_body.encode("utf-8")),
            }
        ],
    }
    (calibration_dir / f"{run_label}.assets.json").write_text(
        json.dumps(manifest) + "\n",
        encoding="utf-8",
    )


def test_calibrate_assets_cli_success(tmp_path: Path, monkeypatch):
    calibration_dir = tmp_path / "calibrations" / "fixture-page"
    _write_run(calibration_dir, "run-001", "body{}")
    _write_run(calibration_dir, "run-002", "body{}")
    _write_run(calibration_dir, "run-003", "body{}")

    tool_root = tmp_path / "tools" / "fuzzy-validator"
    monkeypatch.setattr("lib.asset_store.TOOL_ROOT", tool_root)
    monkeypatch.setattr("calibrate_assets.TOOL_ROOT", tool_root)

    with patch("lib.asset_store._git_head", return_value="abc1234"):
        assert (
            main(
                [
                    "--page-id",
                    "fixture-page",
                    "--calibration-dir",
                    str(calibration_dir),
                ]
            )
            == 0
        )

    baseline = tool_root / "baselines" / "fixture-page.assets.json"
    assert baseline.is_file()


def test_calibrate_assets_cli_fails_on_drift(tmp_path: Path):
    calibration_dir = tmp_path / "calibrations" / "fixture-page"
    _write_run(calibration_dir, "run-001", "body{}")
    _write_run(calibration_dir, "run-002", "body{color:red}")
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--calibration-dir",
                str(calibration_dir),
            ]
        )
        == 1
    )
