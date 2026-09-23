"""Classification, reproduce templates, mirror freshness, annotations."""

from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pytest

from lib.annotations import load_annotations, write_annotations_placeholder
from lib.diff_regions import Rect
from lib.drift_classify import (
    classify_asset_failures,
    classify_dom_failures,
    classify_visual_boxes,
    count_by_status,
    write_drifts_json,
)
from lib.drift_overlay import OverlayEntry, validate_overlay_payload
from lib.mirror_freshness import MirrorFreshnessError, probe_mirror_freshness, require_fresh_mirror
from lib.reproduce import render_reproduce_md, write_reproduce_md


def _visual_entry() -> OverlayEntry:
    overlay = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "ov",
            "entries": [
                {
                    "id": "box",
                    "class": "intentional",
                    "layer": "visual",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "visual": {"x": 0, "y": 0, "width": 50, "height": 50},
                    "rationale": "header",
                    "requirementRef": "docs/r.md",
                    "source": "putative",
                }
            ],
        }
    )
    return overlay.entries[0]


def test_classify_visual_expected_and_unexpected():
    entry = _visual_entry()
    drifts = classify_visual_boxes(
        page_id="home",
        profile="test",
        failure_boxes=[Rect(10, 10, 20, 20), Rect(200, 200, 10, 10)],
        overlay_entries=[entry],
    )
    assert drifts[0].status == "expected"
    assert drifts[0].class_ == "intentional"
    assert drifts[1].status == "unexpected"
    assert drifts[1].class_ is None
    counts = count_by_status(drifts)
    assert counts["unexpected"] == 1
    assert counts["expectedIntentional"] == 1


def test_classify_dom_and_assets():
    overlay = validate_overlay_payload(
        {
            "schemaVersion": 2,
            "id": "ov2",
            "entries": [
                {
                    "id": "dom1",
                    "class": "natural",
                    "layer": "dom",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "dom": {"pathPrefix": "/html/body/div", "match": "subtree"},
                    "rationale": "volatile",
                    "source": "manual",
                },
                {
                    "id": "css1",
                    "class": "intentional",
                    "layer": "assets",
                    "profiles": ["test"],
                    "pages": ["home"],
                    "assets": {"ids": ["css:/theme.css"]},
                    "rationale": "theme",
                    "requirementRef": "docs/r.md",
                    "source": "manual",
                },
            ],
        }
    )
    dom_drifts = classify_dom_failures(
        page_id="home",
        profile="test",
        failures=[
            {"path": "/html/body/div/span", "reason": "text_mismatch"},
            {"path": "/html/body/nav", "reason": "attribute_mismatch"},
        ],
        overlay_entries=[e for e in overlay.entries if e.layer == "dom"],
    )
    assert dom_drifts[0].status == "expected"
    assert dom_drifts[1].status == "unexpected"

    asset_drifts = classify_asset_failures(
        page_id="home",
        profile="test",
        changed_ids=["css:/theme.css", "js:/app.js"],
        overlay_entries=[e for e in overlay.entries if e.layer == "assets"],
    )
    assert asset_drifts[0].status == "expected"
    assert asset_drifts[1].status == "unexpected"


def test_write_drifts_and_reproduce(tmp_path: Path):
    entry = _visual_entry()
    drifts = classify_visual_boxes(
        page_id="home",
        profile="test",
        failure_boxes=[Rect(1, 1, 5, 5)],
        overlay_entries=[entry],
    )
    out = write_drifts_json(
        tmp_path,
        run_id="demo",
        setpoint=None,
        overlays=None,
        drifts=drifts,
    )
    payload = json.loads(out.read_text(encoding="utf-8"))
    assert payload["drifts"][0]["status"] == "expected"

    md = render_reproduce_md(page_id="home", profile="mirror", page={"url": "index.php"})
    assert "bin/ork-db use prod" in md
    assert "bin/fuzzy-validator validate" in md
    path = write_reproduce_md(tmp_path, page_id="home", profile="test")
    assert path.is_file()


def test_mirror_freshness_stale_and_override(tmp_path: Path):
    manifest = tmp_path / "manifest.json"
    old = (datetime.now(timezone.utc) - timedelta(days=10)).strftime("%Y-%m-%dT%H:%M:%SZ")
    manifest.write_text(json.dumps({"extracted_at": old}), encoding="utf-8")
    freshness = probe_mirror_freshness(manifest_path=manifest, max_age_days=7)
    assert freshness.stale
    with pytest.raises(MirrorFreshnessError):
        require_fresh_mirror(manifest_path=manifest)
    ok = require_fresh_mirror(manifest_path=manifest, override_reason="demo override")
    assert ok.stale


def test_annotations_display_only(tmp_path: Path):
    path = write_annotations_placeholder(tmp_path / "annotations.json", run_id="r1")
    payload = load_annotations(path)
    assert payload is not None
    assert payload["items"] == []
    # Gate must ignore assessment content for exit codes — loader only.
    path.write_text(
        json.dumps(
            {
                "runId": "r1",
                "items": [
                    {
                        "driftId": "test/home/visual/d-000",
                        "assessment": "reasonable",
                        "notes": "looks fine",
                    }
                ],
            }
        ),
        encoding="utf-8",
    )
    loaded = load_annotations(path)
    assert loaded["items"][0]["assessment"] == "reasonable"
