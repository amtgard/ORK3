"""End-to-end gate_run tests on synthetic fixture pages (no docker)."""

from __future__ import annotations

from pathlib import Path

import pytest

from gate_run import run_page_gate
from tests.unit.test_gate_run import _write_unified_fixtures


def test_gate_run_fixture_page_passes_all_layers(tmp_path: Path):
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
        visual_diff_out=tmp_path / "annotated.png",
    )
    assert result.passed is True
    assert (tmp_path / "annotated.png").exists()


def test_gate_run_fixture_dom_failure(tmp_path: Path):
    page_id = _write_unified_fixtures(tmp_path, candidate_color=(200, 200, 200))
    candidate_dom = tmp_path / "calibrations" / page_id / "candidate.dom.html"
    candidate_dom.write_text(
        "<html><body><section>Stable</section></body></html>",
        encoding="utf-8",
    )
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
    dom = next(layer for layer in result.layers if layer.layer == "dom")
    assert dom.passed is False
