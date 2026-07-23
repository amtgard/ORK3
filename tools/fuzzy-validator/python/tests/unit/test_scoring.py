"""Unit tests for lib/scoring.py."""

from __future__ import annotations

from lib.scoring import (
    Thresholds,
    build_page_summary,
    build_run_summary,
    layer_scores_from_details,
    page_passes,
    page_stability_score,
    score_near_threshold,
)


def test_thresholds_from_defaults():
    thresholds = Thresholds.from_defaults(
        {"assetsMinScore": 1.0, "domMinScore": 0.99, "visualMinScore": 0.98}
    )
    assert thresholds.visual_min == 0.98


def test_thresholds_with_overrides():
    base = Thresholds()
    updated = base.with_overrides(visual_min=0.97)
    assert updated.visual_min == 0.97
    assert updated.dom_min == 1.0


def test_page_passes_all_layers():
    scores = {"assets": 1.0, "dom": 1.0, "visual": 0.99}
    assert page_passes(scores, Thresholds(visual_min=0.98)) is True


def test_page_fails_visual_threshold():
    scores = {"assets": 1.0, "dom": 1.0, "visual": 0.97}
    assert page_passes(scores, Thresholds(visual_min=0.98)) is False


def test_page_stability_score_is_minimum():
    assert page_stability_score({"assets": 1.0, "dom": 0.95, "visual": 0.99}) == 0.95


def test_layer_scores_from_details():
    layers = [
        {"layer": "assets", "score": 1.0, "details": {"assetsScore": 1.0}},
        {"layer": "dom", "score": 0.99, "details": {"domScore": 0.99}},
        {"layer": "visual", "score": 0.98, "details": {"visualScore": 0.98}},
    ]
    assert layer_scores_from_details(layers) == {
        "assets": 1.0,
        "dom": 0.99,
        "visual": 0.98,
    }


def test_score_near_threshold():
    assert score_near_threshold(0.991, 1.0) is True
    assert score_near_threshold(0.95, 0.98) is False


def test_build_page_summary():
    layers = [{"layer": "visual", "score": 0.99, "details": {"visualScore": 0.99}}]
    summary = build_page_summary(
        page_id="home-anonymous",
        layers=layers,
        thresholds=Thresholds(visual_min=0.98),
        report_path="pages/home-anonymous.html",
    )
    assert summary["pass"] is True
    assert summary["reportPath"] == "pages/home-anonymous.html"


def test_build_run_summary_includes_profile():
    summary = build_run_summary(
        run_id="demo",
        phase="all",
        page_summaries=[],
        thresholds=Thresholds(),
        exit_code=0,
        profile="test",
        profiles=["test", "mirror"],
    )
    assert summary["profile"] == "test"
    assert summary["profiles"] == ["test", "mirror"]
