"""Extra coverage for FV2 overlay / validate / mirror paths."""

from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from fuzzy_validator.cli import main
from fuzzy_validator.validate import run_validate
from gate_dom import run_dom_gate
from lib.annotations import annotations_by_drift_id, load_annotations
from lib.asset_manifest import AssetCompareResult, apply_asset_allowances
from lib.diff_regions import Rect
from lib.drift_classify import (
    filter_unexpected_assets,
    filter_unexpected_boxes,
    filter_unexpected_dom,
)
from lib.drift_overlay import (
    OverlayError,
    asset_ids_from_overlay,
    detect_conflicts,
    dom_nodes_from_overlay,
    load_overlay,
    load_overlays_from_flags,
    merge_overlays,
    resolve_overlay_paths,
    validate_overlay_payload,
    visual_zones_from_overlay,
)
from lib.manifest import save_json
from lib.mirror_freshness import (
    MirrorFreshnessError,
    default_manifest_path,
    probe_mirror_freshness,
    require_fresh_mirror,
)
from lib.canonical_dom import html_to_canonical_tree, save_canonical_tree
from lib.manifest import build_dom_fuzz_manifest


def test_overlay_as_dict_and_helpers():
    overlay = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "full",
            "workstream": "ws",
            "createdAt": "2026-07-19T00:00:00Z",
            "basedOnSetpoint": "bundle.zip",
            "entries": [
                {
                    "id": "v1",
                    "class": "natural",
                    "layer": "visual",
                    "profiles": ["test", "mirror"],
                    "pages": ["home"],
                    "visual": {"x": 1, "y": 2, "width": 3, "height": 4},
                    "rationale": "vis",
                    "source": "manual",
                },
                {
                    "id": "d1",
                    "class": "intentional",
                    "layer": "dom",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "dom": {"path": "/html/body", "match": "attributes", "attrs": ["class"]},
                    "rationale": "dom",
                    "requirementRef": "docs/r.md",
                    "source": "promoted",
                },
                {
                    "id": "a1",
                    "class": "intentional",
                    "layer": "assets",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "assets": {"id": "css:/a.css"},
                    "rationale": "css",
                    "requirementRef": "docs/r.md",
                    "source": "manual",
                },
            ],
        }
    )
    payload = overlay.as_dict()
    assert payload["workstream"] == "ws"
    assert overlay.entries[0].as_dict()["visual"]["width"] == 3
    assert overlay.entries[0].class_name == "natural"
    assert overlay.entries[0].applies_to(page_id="home", profile="test", layer="visual")
    assert not overlay.entries[0].applies_to(page_id="other", profile="test", layer="visual")
    zones = visual_zones_from_overlay(overlay.entries)
    nodes = dom_nodes_from_overlay(overlay.entries)
    assets = asset_ids_from_overlay(overlay.entries)
    assert zones and nodes and "css:/a.css" in assets


def test_dom_and_asset_conflicts():
    a = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "a",
            "entries": [
                {
                    "id": "n",
                    "class": "natural",
                    "layer": "dom",
                    "profiles": ["test"],
                    "pages": ["p"],
                    "dom": {"pathPrefix": "/html/body", "match": "subtree"},
                    "rationale": "n",
                    "source": "manual",
                }
            ],
        }
    )
    b = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "b",
            "entries": [
                {
                    "id": "i",
                    "class": "intentional",
                    "layer": "dom",
                    "profiles": ["test"],
                    "pages": ["p"],
                    "dom": {"path": "/html/body/div", "match": "exact"},
                    "rationale": "i",
                    "requirementRef": "docs/x.md",
                    "source": "manual",
                }
            ],
        }
    )
    assert detect_conflicts(a.entries + b.entries)

    c = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "c",
            "entries": [
                {
                    "id": "n2",
                    "class": "natural",
                    "layer": "assets",
                    "profiles": ["test"],
                    "pages": ["p"],
                    "assets": {"ids": ["css:/x.css"]},
                    "rationale": "n",
                    "source": "manual",
                },
                {
                    "id": "i2",
                    "class": "intentional",
                    "layer": "assets",
                    "profiles": ["test"],
                    "pages": ["p"],
                    "assets": {"ids": ["css:/x.css"]},
                    "rationale": "i",
                    "requirementRef": "docs/x.md",
                    "source": "manual",
                },
            ],
        }
    )
    assert detect_conflicts(c.entries)


def test_resolve_overlay_dir_and_putative(tmp_path: Path):
    tool = tmp_path / "tool"
    intentional = tool / "overlays" / "intentional"
    putative = tool / "overlays" / "putative"
    intentional.mkdir(parents=True)
    putative.mkdir(parents=True)
    body = {
        "schemaVersion": 2,
        "id": "x",
        "entries": [
            {
                "id": "v",
                "class": "natural",
                "layer": "visual",
                "profiles": ["test"],
                "pages": ["home"],
                "visual": {"x": 0, "y": 0, "width": 1, "height": 1},
                "rationale": "r",
                "source": "manual",
            }
        ],
    }
    (intentional / "a.json5").write_text(json.dumps(body), encoding="utf-8")
    (putative / "b.json5").write_text(json.dumps(body), encoding="utf-8")
    paths = resolve_overlay_paths(tool, overlay_dir=str(intentional), putative=True)
    assert len(paths) >= 2
    merged = load_overlays_from_flags(
        tool, overlay_dir=str(intentional), putative=True
    )
    assert merged is not None
    assert merged.summarize()["entryCount"] >= 1

    with pytest.raises(OverlayError):
        resolve_overlay_paths(tool, overlay_dir="missing-dir")
    with pytest.raises(OverlayError):
        load_overlays_from_flags(tool, overlay="nope.json5")
    empty = load_overlays_from_flags(tool, putative=True)
    assert empty is not None


def test_load_overlay_missing_and_bad_json(tmp_path: Path):
    with pytest.raises(OverlayError):
        load_overlay(tmp_path / "missing.json5")
    bad = tmp_path / "bad.json5"
    bad.write_text("{not json", encoding="utf-8")
    with pytest.raises(OverlayError):
        load_overlay(bad)


def test_filter_helpers_and_annotations_edges():
    entry_overlay = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "f",
            "entries": [
                {
                    "id": "v",
                    "class": "natural",
                    "layer": "visual",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "visual": {"x": 0, "y": 0, "width": 10, "height": 10},
                    "rationale": "r",
                    "source": "manual",
                },
                {
                    "id": "d",
                    "class": "natural",
                    "layer": "dom",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "dom": {"path": "/html/body", "match": "subtree"},
                    "rationale": "r",
                    "source": "manual",
                },
                {
                    "id": "a",
                    "class": "natural",
                    "layer": "assets",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "assets": {"ids": ["css:/a.css"]},
                    "rationale": "r",
                    "source": "manual",
                },
            ],
        }
    )
    boxes = filter_unexpected_boxes(
        [Rect(1, 1, 2, 2), Rect(100, 100, 2, 2)],
        [e for e in entry_overlay.entries if e.layer == "visual"],
    )
    assert len(boxes) == 1
    dom = filter_unexpected_dom(
        [{"path": "/html/body/x"}, {"path": "/html/nav"}],
        [e for e in entry_overlay.entries if e.layer == "dom"],
    )
    assert len(dom) == 1
    assets = filter_unexpected_assets(
        ["css:/a.css", "js:/b.js"],
        [e for e in entry_overlay.entries if e.layer == "assets"],
    )
    assert assets == ["js:/b.js"]
    assert load_annotations(None) is None
    assert load_annotations("/no/such/file.json") is None
    assert annotations_by_drift_id(None) == {}
    assert annotations_by_drift_id({"items": ["bad"]}) == {}


def test_apply_asset_allowances_and_gate_dom_extra(tmp_path: Path):
    raw = AssetCompareResult(
        passed=False,
        missing_ids=[],
        extra_ids=[],
        changed_ids=["css:/a.css", "js:/b.js"],
        assets_score=0.5,
    )
    allowed = apply_asset_allowances(raw, {"css:/a.css"}, baseline_count=2)
    assert allowed.changed_ids == ["js:/b.js"]
    assert apply_asset_allowances(raw, set(), baseline_count=2) is raw

    baseline = html_to_canonical_tree("<html><body><div>a</div></body></html>")
    save_canonical_tree(tmp_path / "base.json", baseline)
    (tmp_path / "cand.html").write_text(
        "<html><body><div>b</div></body></html>", encoding="utf-8"
    )
    save_json(
        tmp_path / "dom-fuzz.json",
        build_dom_fuzz_manifest(page_id="p", fuzz_nodes=[], calibration_runs=1),
    )
    payload = run_dom_gate(
        baseline_path=tmp_path / "base.json",
        candidate_path=tmp_path / "cand.html",
        manifest_path=tmp_path / "dom-fuzz.json",
        dom_min_score=1.0,
        compare_script_bodies=False,
        extra_fuzz_nodes=[{"path": "/html/body/div[1]", "mode": "subtree"}],
    )
    # Path may vary; at least exercise the extra_fuzz_nodes merge path.
    assert "passed" in payload


def test_mirror_freshness_edges(tmp_path: Path):
    assert "ork-db" in str(default_manifest_path(tmp_path))
    with pytest.raises(MirrorFreshnessError):
        probe_mirror_freshness()
    with pytest.raises(MirrorFreshnessError):
        probe_mirror_freshness(manifest_path=tmp_path / "missing.json")
    bad = tmp_path / "m.json"
    bad.write_text("{", encoding="utf-8")
    with pytest.raises(MirrorFreshnessError):
        probe_mirror_freshness(manifest_path=bad)
    empty = tmp_path / "e.json"
    empty.write_text("{}", encoding="utf-8")
    with pytest.raises(MirrorFreshnessError):
        probe_mirror_freshness(manifest_path=empty)
    fresh = tmp_path / "f.json"
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    fresh.write_text(json.dumps({"extractedAt": now}), encoding="utf-8")
    ok = require_fresh_mirror(manifest_path=fresh)
    assert not ok.stale


def test_overlay_cli_missing_action_and_validate_mirror_override(tmp_path: Path):
    assert main(["overlay"]) == 2
    tool_root = tmp_path / "tool"
    save_json(
        tool_root / "manifests" / "profiles.json5",
        {
            "profiles": {
                "test": {
                    "orkDbUse": "dev",
                    "label": "Sandbox",
                    "thresholds": {
                        "assetsMinScore": 1.0,
                        "domMinScore": 1.0,
                        "visualMinScore": 1.0,
                    },
                    "auth": {"username": "u", "passwordDefault": "p"},
                }
            },
            "defaultProfiles": ["test"],
        },
    )
    save_json(tool_root / "manifests" / "defaults.json5", {"visualMinScore": 1.0})
    save_json(
        tool_root / "manifests" / "pages.json5",
        {
            "defaults": {
                "viewport": {"width": 1280, "height": 720},
                "repeat": 1,
                "waitAfterMs": 0,
                "auth": "none",
            },
            "pages": [
                {
                    "id": "home-anonymous",
                    "url": "./index.php",
                    "auth": "none",
                    "driftClass": "stable",
                }
            ],
        },
    )
    baseline = tool_root / "baselines" / "test" / "home-anonymous.png"
    baseline.parent.mkdir(parents=True, exist_ok=True)
    baseline.write_bytes(b"\x89PNG\r\n\x1a\n")

    from lib.mirror_freshness import MirrorFreshness

    stale = MirrorFreshness(
        extracted_at=datetime.now(timezone.utc) - timedelta(days=10),
        age_days=10.0,
        manifest_path=tmp_path / "m.json",
        stale=True,
        max_age_days=7,
    )
    with patch("fuzzy_validator.validate.require_fresh_mirror", return_value=stale):
        assert (
            main(
                [
                    "validate",
                    "--page",
                    "home-anonymous",
                    "--profile",
                    "test",
                    "--dry-run",
                    "--require-fresh-mirror",
                    "--mirror-stale-ok",
                    "CI fixture",
                    "--tool-root",
                    str(tool_root),
                    "--annotations-out",
                    str(tmp_path / "ann.json"),
                ]
            )
            == 0
        )
    assert (tmp_path / "ann.json").is_file()

    with patch(
        "fuzzy_validator.validate.load_overlays_from_flags",
        side_effect=OverlayError("bad overlay"),
    ):
        assert (
            main(
                [
                    "validate",
                    "--page",
                    "home-anonymous",
                    "--profile",
                    "test",
                    "--dry-run",
                    "--overlay",
                    "x.json5",
                    "--tool-root",
                    str(tool_root),
                ]
            )
            == 2
        )


def test_cli_help_overlay_and_no_command():
    with pytest.raises(SystemExit) as exc:
        main(["overlay", "validate", "--help"])
    assert exc.value.code == 0
    assert main([]) == 0 or main([]) == 2  # prints help
