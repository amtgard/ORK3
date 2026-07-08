"""Unit tests for asset_manifest.py."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path

import pytest

from lib.asset_manifest import (
    assert_calibration_asset_stability,
    build_baseline_asset_manifest,
    compare_asset_manifests,
    load_asset_manifest,
    parse_assets,
    save_asset_manifest,
)


def _sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _sample_manifest(page_id: str, css_body: str, js_body: str = "console.log(1);") -> dict:
    return {
        "schemaVersion": 1,
        "pageId": page_id,
        "runLabel": "run-001",
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
        ],
    }


def test_compare_asset_manifests_passes_on_identical_manifests():
    manifest = _sample_manifest("fixture-page", "body{color:red}")
    result = compare_asset_manifests(manifest, manifest)
    assert result.passed
    assert result.assets_score == 1.0


def test_compare_asset_manifests_detects_changed_sha256():
    baseline = _sample_manifest("fixture-page", "body{color:red}")
    candidate = _sample_manifest("fixture-page", "body{color:blue}")
    result = compare_asset_manifests(baseline, candidate)
    assert not result.passed
    assert result.changed_ids == ["css:http://localhost/orkui/revised.css"]
    assert result.assets_score < 1.0


def test_compare_asset_manifests_detects_missing_and_extra_assets():
    baseline = _sample_manifest("fixture-page", "body{}")
    candidate = {
        "pageId": "fixture-page",
        "assets": [
            {
                "id": "js-001",
                "kind": "js",
                "url": "http://localhost/orkui/revised.js",
                "inline": False,
                "sha256": baseline["assets"][1]["sha256"],
                "byteLength": baseline["assets"][1]["byteLength"],
            },
            {
                "id": "js-002",
                "kind": "js",
                "url": "http://localhost/orkui/extra.js",
                "inline": False,
                "sha256": _sha256("extra();"),
                "byteLength": len("extra();"),
            },
        ],
    }
    result = compare_asset_manifests(baseline, candidate)
    assert not result.passed
    assert result.missing_ids == ["css:http://localhost/orkui/revised.css"]
    assert result.extra_ids == ["js:http://localhost/orkui/extra.js"]


def test_compare_asset_manifests_ignores_id_reordering():
    baseline = _sample_manifest("fixture-page", "body{}")
    candidate = {
        "pageId": "fixture-page",
        "assets": [
            {
                "id": "js-999",
                "kind": "js",
                "url": baseline["assets"][1]["url"],
                "inline": False,
                "sha256": baseline["assets"][1]["sha256"],
                "byteLength": baseline["assets"][1]["byteLength"],
            },
            {
                "id": "css-999",
                "kind": "css",
                "url": baseline["assets"][0]["url"],
                "inline": False,
                "sha256": baseline["assets"][0]["sha256"],
                "byteLength": baseline["assets"][0]["byteLength"],
            },
        ],
    }
    result = compare_asset_manifests(baseline, candidate)
    assert result.passed


def test_assert_calibration_asset_stability_passes(tmp_path: Path):
    css = "body{margin:0}"
    manifest = _sample_manifest("fixture-page", css)
    for index in range(1, 4):
        save_asset_manifest(tmp_path / f"run-{index:03d}.assets.json", manifest)
    assert_calibration_asset_stability(tmp_path)


def test_assert_calibration_asset_stability_ignores_id_reordering(tmp_path: Path):
    first = _sample_manifest("fixture-page", "body{}")
    second = {
        "pageId": "fixture-page",
        "assets": [
            {
                "id": "css-001",
                "kind": "css",
                "url": first["assets"][0]["url"],
                "inline": False,
                "sha256": first["assets"][0]["sha256"],
                "byteLength": first["assets"][0]["byteLength"],
            },
            {
                "id": "js-001",
                "kind": "js",
                "url": first["assets"][1]["url"],
                "inline": False,
                "sha256": first["assets"][1]["sha256"],
                "byteLength": first["assets"][1]["byteLength"],
            },
        ],
    }
    save_asset_manifest(tmp_path / "run-001.assets.json", first)
    save_asset_manifest(tmp_path / "run-002.assets.json", second)
    assert_calibration_asset_stability(tmp_path)


def test_assert_calibration_asset_stability_fails_on_drift(tmp_path: Path):
    stable = _sample_manifest("fixture-page", "body{}")
    drift = _sample_manifest("fixture-page", "body{color:black}")
    save_asset_manifest(tmp_path / "run-001.assets.json", stable)
    save_asset_manifest(tmp_path / "run-002.assets.json", drift)
    with pytest.raises(ValueError, match="Asset manifest drift"):
        assert_calibration_asset_stability(tmp_path)


def test_build_baseline_asset_manifest_includes_baseline_paths():
    source = _sample_manifest("fixture-page", "body{}")
    entries = parse_assets(source)
    baseline = build_baseline_asset_manifest(
        page_id="fixture-page",
        source_manifest=source,
        baseline_entries=[
            entries[0].__class__(
                **{**entries[0].__dict__, "baseline_path": "baselines/assets/fixture-page/css-000-revised.css"}
            )
        ],
        captured_from_commit="abc1234",
    )
    assert baseline["capturedFromCommit"] == "abc1234"
    assert baseline["assets"][0]["baselinePath"].endswith("css-000-revised.css")


def test_load_and_save_asset_manifest_round_trip(tmp_path: Path):
    manifest = _sample_manifest("fixture-page", "body{}")
    path = save_asset_manifest(tmp_path / "sample.assets.json", manifest)
    loaded = load_asset_manifest(path)
    assert loaded["assets"][0]["sha256"] == manifest["assets"][0]["sha256"]
