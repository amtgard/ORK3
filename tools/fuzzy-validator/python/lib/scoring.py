"""Stability scores and pass/fail logic for fuzzy gate runs."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class Thresholds:
    assets_min: float = 1.0
    dom_min: float = 1.0
    visual_min: float = 1.0

    def as_dict(self) -> dict[str, float]:
        return {
            "assetsMinScore": self.assets_min,
            "domMinScore": self.dom_min,
            "visualMinScore": self.visual_min,
        }

    @classmethod
    def from_defaults(cls, defaults: dict[str, Any]) -> Thresholds:
        return cls(
            assets_min=float(defaults.get("assetsMinScore", 1.0)),
            dom_min=float(defaults.get("domMinScore", 1.0)),
            visual_min=float(defaults.get("visualMinScore", 1.0)),
        )

    def with_overrides(
        self,
        *,
        assets_min: float | None = None,
        dom_min: float | None = None,
        visual_min: float | None = None,
    ) -> Thresholds:
        return Thresholds(
            assets_min=assets_min if assets_min is not None else self.assets_min,
            dom_min=dom_min if dom_min is not None else self.dom_min,
            visual_min=visual_min if visual_min is not None else self.visual_min,
        )


def layer_scores_from_details(layers: list[dict[str, Any]]) -> dict[str, float]:
    scores: dict[str, float] = {}
    for layer in layers:
        name = layer["layer"]
        if name == "assets":
            scores["assets"] = float(layer.get("details", {}).get("assetsScore", layer["score"]))
        elif name == "dom":
            scores["dom"] = float(layer.get("details", {}).get("domScore", layer["score"]))
        elif name == "visual":
            scores["visual"] = float(layer.get("details", {}).get("visualScore", layer["score"]))
    return scores


def page_stability_score(scores: dict[str, float]) -> float:
    if not scores:
        return 1.0
    return min(scores.values())


def page_passes(scores: dict[str, float], thresholds: Thresholds) -> bool:
    assets = scores.get("assets", 1.0)
    dom = scores.get("dom", 1.0)
    visual = scores.get("visual", 1.0)
    return (
        assets >= thresholds.assets_min
        and dom >= thresholds.dom_min
        and visual >= thresholds.visual_min
    )


def score_near_threshold(score: float, threshold: float, margin: float = 0.01) -> bool:
    return threshold - margin <= score < threshold


def build_page_summary(
    *,
    page_id: str,
    layers: list[dict[str, Any]],
    thresholds: Thresholds,
    report_path: str | None = None,
) -> dict[str, Any]:
    scores = layer_scores_from_details(layers)
    passed = page_passes(scores, thresholds)
    payload: dict[str, Any] = {
        "pageId": page_id,
        "pass": passed,
        "scores": {key: round(value, 6) for key, value in scores.items()},
        "pageScore": round(page_stability_score(scores), 6),
    }
    if report_path:
        payload["reportPath"] = report_path
    return payload


def build_run_summary(
    *,
    run_id: str,
    phase: str,
    page_summaries: list[dict[str, Any]],
    thresholds: Thresholds,
    exit_code: int,
    profile: str | None = None,
    profiles: list[str] | None = None,
) -> dict[str, Any]:
    summary: dict[str, Any] = {
        "runId": run_id,
        "phase": phase,
        "exitCode": exit_code,
        "pass": exit_code == 0,
        "thresholds": thresholds.as_dict(),
        "pages": page_summaries,
    }
    if profile is not None:
        summary["profile"] = profile
    if profiles is not None:
        summary["profiles"] = profiles
    return summary
