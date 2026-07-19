"""Unit tests for drift overlay schema / merge / conflicts."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from lib.drift_overlay import (
    OverlayError,
    detect_conflicts,
    load_overlay,
    load_overlays_from_flags,
    merge_overlays,
    resolve_overlay_paths,
    validate_overlay_payload,
)


def _intentional_entry(**overrides):
    entry = {
        "id": "hdr",
        "class": "intentional",
        "layer": "dom",
        "profiles": ["test"],
        "pages": ["home-anonymous"],
        "dom": {"path": "/html/body/h1", "match": "subtree"},
        "rationale": "Planned header change",
        "requirementRef": "docs/req.md#1",
        "source": "putative",
    }
    entry.update(overrides)
    return entry


def _natural_visual(**overrides):
    entry = {
        "id": "weather",
        "class": "natural",
        "layer": "visual",
        "profiles": ["mirror"],
        "pages": ["weather"],
        "visual": {"x": 10, "y": 20, "width": 100, "height": 80},
        "rationale": "Weather tiles",
        "source": "manual",
    }
    entry.update(overrides)
    return entry


def test_validate_overlay_payload_ok():
    overlay = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "demo",
            "entries": [_intentional_entry(), _natural_visual()],
        }
    )
    assert overlay.id == "demo"
    assert len(overlay.entries) == 2
    assert overlay.entries[0].dom["mode"] == "subtree"


def test_intentional_requires_requirement_ref():
    with pytest.raises(OverlayError, match="requirementRef"):
        validate_overlay_payload(
            {
                "schemaVersion": 2,
                "id": "bad",
                "entries": [_intentional_entry(requirementRef=None)],
            }
        )


def test_conflict_different_classes_same_region():
    left = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "a",
            "entries": [
                _natural_visual(id="n1", pages=["home"], profiles=["test"]),
            ],
        }
    )
    right = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "b",
            "entries": [
                {
                    "id": "i1",
                    "class": "intentional",
                    "layer": "visual",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "visual": {"x": 50, "y": 40, "width": 40, "height": 40},
                    "rationale": "overlap",
                    "requirementRef": "docs/x.md",
                    "source": "manual",
                }
            ],
        }
    )
    conflicts = detect_conflicts(left.entries + right.entries)
    assert conflicts
    with pytest.raises(OverlayError, match="conflict"):
        merge_overlays([left, right])


def test_load_overlay_and_cli_resolve(tmp_path: Path):
    path = tmp_path / "overlay.json5"
    path.write_text(
        json.dumps(
            {
                "schemaVersion": 2,
                "id": "file-demo",
                "entries": [_intentional_entry()],
            }
        ),
        encoding="utf-8",
    )
    loaded = load_overlay(path)
    assert loaded.id == "file-demo"
    tool_root = tmp_path / "tool"
    (tool_root / "overlays" / "putative").mkdir(parents=True)
    putative = tool_root / "overlays" / "putative" / "draft.json5"
    putative.write_text(path.read_text(encoding="utf-8"), encoding="utf-8")
    paths = resolve_overlay_paths(tool_root, putative=True)
    assert putative in paths or any(p.name == "draft.json5" for p in paths)
    merged = load_overlays_from_flags(tool_root, overlay=str(path))
    assert merged is not None
    assert merged.summarize()["entryCount"] == 1


def test_assets_layer_requires_ids():
    with pytest.raises(OverlayError, match="assets"):
        validate_overlay_payload(
            {
                "schemaVersion": 2,
                "id": "assets-bad",
                "entries": [
                    {
                        "id": "css",
                        "class": "intentional",
                        "layer": "assets",
                        "profiles": ["test"],
                        "pages": ["home"],
                        "assets": {},
                        "rationale": "css change",
                        "requirementRef": "docs/x.md",
                        "source": "manual",
                    }
                ],
            }
        )


def test_schema_version_must_be_2():
    with pytest.raises(OverlayError, match="schemaVersion"):
        validate_overlay_payload({"schemaVersion": 1, "id": "x", "entries": []})
