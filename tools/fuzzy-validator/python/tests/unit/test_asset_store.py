"""Unit tests for asset_store.py."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

import pytest

from lib.asset_manifest import load_asset_manifest
from lib.asset_store import (
    asset_file_name,
    copy_run_assets_to_baseline,
    read_baseline_bytes,
    read_candidate_bytes,
)
from lib.asset_manifest import AssetEntry


def _sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _write_calibration_run(
    calibration_dir: Path,
    *,
    run_label: str,
    css_body: str,
    js_body: str = "console.log(1);",
) -> None:
    assets_dir = calibration_dir / "assets" / run_label
    assets_dir.mkdir(parents=True, exist_ok=True)
    css_name = "css-000-revised.css"
    js_name = "js-000-revised.js"
    (assets_dir / css_name).write_text(css_body, encoding="utf-8")
    (assets_dir / js_name).write_text(js_body, encoding="utf-8")
    manifest = {
        "schemaVersion": 1,
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
            },
            {
                "id": "js-000",
                "kind": "js",
                "url": "http://localhost/orkui/revised.js",
                "inline": False,
                "sha256": _sha256(js_body),
                "byteLength": len(js_body.encode("utf-8")),
            },
            {
                "id": "inline-style-0",
                "kind": "css",
                "url": None,
                "inline": True,
                "sha256": _sha256(".x{color:red}"),
                "byteLength": len(".x{color:red}".encode("utf-8")),
            },
        ],
    }
    (assets_dir / "inline-style-0.css").write_text(".x{color:red}", encoding="utf-8")
    (calibration_dir / f"{run_label}.assets.json").write_text(
        json.dumps(manifest, indent=2) + "\n",
        encoding="utf-8",
    )


def test_asset_file_name_for_inline_and_network_assets():
    inline = AssetEntry(
        id="inline-style-0",
        kind="css",
        url=None,
        inline=True,
        sha256="abc",
        byte_length=1,
    )
    network = AssetEntry(
        id="css-000",
        kind="css",
        url="http://localhost/orkui/revised.css",
        inline=False,
        sha256="abc",
        byte_length=1,
    )
    assert asset_file_name(inline) == "inline-style-0.css"
    assert asset_file_name(network) == "css-000-revised.css"


def test_copy_run_assets_to_baseline_writes_manifest_and_bytes(tmp_path: Path):
    tool_root = tmp_path / "tools" / "fuzzy-validator"
    calibration_dir = tool_root / "calibrations" / "fixture-page"
    _write_calibration_run(calibration_dir, run_label="run-003", css_body="body{color:red}")

    baseline_manifest_path = copy_run_assets_to_baseline(
        page_id="fixture-page",
        calibration_dir=calibration_dir,
        run_label="run-003",
        tool_root=tool_root,
    )

    assert baseline_manifest_path.is_file()
    baseline = load_asset_manifest(baseline_manifest_path)
    assert baseline["pageId"] == "fixture-page"
    assert len(baseline["assets"]) == 3
    assert all(asset.get("baselinePath") for asset in baseline["assets"])

    css_entry = next(asset for asset in baseline["assets"] if asset["id"] == "css-000")
    css_bytes = read_baseline_bytes(
        AssetEntry(
            id=css_entry["id"],
            kind=css_entry["kind"],
            url=css_entry["url"],
            inline=css_entry["inline"],
            sha256=css_entry["sha256"],
            byte_length=css_entry["byteLength"],
            baseline_path=css_entry["baselinePath"],
        ),
        tool_root=tool_root,
    )
    assert css_bytes.decode("utf-8") == "body{color:red}"


def test_read_candidate_bytes_finds_matching_file(tmp_path: Path):
    calibration_dir = tmp_path / "calibrations" / "fixture-page"
    _write_calibration_run(calibration_dir, run_label="candidate", css_body="body{}")
    entry = AssetEntry(
        id="css-000",
        kind="css",
        url="http://localhost/orkui/revised.css",
        inline=False,
        sha256=_sha256("body{}"),
        byte_length=len("body{}"),
    )
    assert read_candidate_bytes(entry, calibration_dir).decode("utf-8") == "body{}"


def test_copy_run_assets_to_baseline_missing_manifest(tmp_path: Path):
    with pytest.raises(FileNotFoundError, match="Missing calibration asset manifest"):
        copy_run_assets_to_baseline(
            page_id="fixture-page",
            calibration_dir=tmp_path,
            tool_root=tmp_path,
        )


def test_copy_run_assets_to_baseline_uses_glob_fallback(tmp_path: Path):
    tool_root = tmp_path / "tools" / "fuzzy-validator"
    calibration_dir = tool_root / "calibrations" / "fixture-page"
    run_label = "run-003"
    assets_dir = calibration_dir / "assets" / run_label
    assets_dir.mkdir(parents=True, exist_ok=True)
    css_body = "body{}"
    (assets_dir / "css-000-custom-name.css").write_text(css_body, encoding="utf-8")
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

    baseline_manifest_path = copy_run_assets_to_baseline(
        page_id="fixture-page",
        calibration_dir=calibration_dir,
        run_label=run_label,
        tool_root=tool_root,
    )
    assert baseline_manifest_path.is_file()


def test_read_baseline_bytes_requires_baseline_path(tmp_path: Path):
    entry = AssetEntry(
        id="css-000",
        kind="css",
        url="http://localhost/orkui/revised.css",
        inline=False,
        sha256="abc",
        byte_length=1,
    )
    with pytest.raises(ValueError, match="no baselinePath"):
        read_baseline_bytes(entry, tool_root=tmp_path)


def test_read_candidate_bytes_missing_file(tmp_path: Path):
    entry = AssetEntry(
        id="css-000",
        kind="css",
        url="http://localhost/orkui/revised.css",
        inline=False,
        sha256="abc",
        byte_length=1,
    )
    calibration_dir = tmp_path / "calibrations" / "fixture-page"
    (calibration_dir / "assets" / "candidate").mkdir(parents=True)
    with pytest.raises(FileNotFoundError, match="Candidate asset bytes not found"):
        read_candidate_bytes(entry, calibration_dir)
