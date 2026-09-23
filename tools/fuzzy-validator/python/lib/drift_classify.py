"""Classify gate diffs into expected natural / intentional / unexpected."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from lib.diff_regions import Rect
from lib.drift_overlay import MergedOverlays, OverlayEntry


@dataclass
class DriftRecord:
    drift_id: str
    status: str  # expected | unexpected
    class_: str | None  # natural | intentional | None
    page_id: str
    profile: str
    layer: str
    location: dict[str, Any] = field(default_factory=dict)
    overlay_entry_id: str | None = None
    rationale: str | None = None
    score_contribution: float | None = None
    evidence: dict[str, str] = field(default_factory=dict)
    reproduce: dict[str, str] = field(default_factory=dict)

    def as_dict(self) -> dict[str, Any]:
        return {
            "driftId": self.drift_id,
            "status": self.status,
            "class": self.class_,
            "pageId": self.page_id,
            "profile": self.profile,
            "layer": self.layer,
            "location": self.location,
            "overlayEntryId": self.overlay_entry_id,
            "rationale": self.rationale,
            "scoreContribution": self.score_contribution,
            "evidence": self.evidence,
            "reproduce": self.reproduce,
        }


def _match_visual(box: Rect, entries: list[OverlayEntry]) -> OverlayEntry | None:
    for entry in entries:
        rect = entry.visual_rect()
        if rect is not None and box.intersects(rect):
            return entry
    return None


def _match_dom(path: str, entries: list[OverlayEntry]) -> OverlayEntry | None:
    for entry in entries:
        if not entry.dom:
            continue
        fuzz_path = entry.dom["path"]
        mode = entry.dom.get("mode", "exact")
        if mode == "subtree" and (path == fuzz_path or path.startswith(f"{fuzz_path}/")):
            return entry
        if path == fuzz_path:
            return entry
    return None


def _match_asset(asset_id: str, entries: list[OverlayEntry]) -> OverlayEntry | None:
    for entry in entries:
        if entry.assets and asset_id in entry.assets.get("ids", []):
            return entry
    return None


def _evidence_paths(page_id: str, profile: str | None) -> dict[str, str]:
    prefix = f"pages/{profile}/" if profile and False else ""
    # Evidence lives under run data/ with page id; multi-profile uses same names per finalize.
    return {
        "baselinePng": f"data/{page_id}-baseline.png",
        "candidatePng": f"data/{page_id}-candidate.png",
        "diffPng": f"data/{page_id}-annotated.png",
    }


def classify_visual_boxes(
    *,
    page_id: str,
    profile: str,
    failure_boxes: list[Rect],
    overlay_entries: list[OverlayEntry],
    counter_start: int = 0,
) -> list[DriftRecord]:
    drifts: list[DriftRecord] = []
    for index, box in enumerate(failure_boxes, start=counter_start):
        match = _match_visual(box, overlay_entries)
        drift_id = f"{profile}/{page_id}/visual/d-{index:03d}"
        if match:
            drifts.append(
                DriftRecord(
                    drift_id=drift_id,
                    status="expected",
                    class_=match.class_,
                    page_id=page_id,
                    profile=profile,
                    layer="visual",
                    location=box.as_dict(),
                    overlay_entry_id=match.id,
                    rationale=match.rationale,
                    evidence=_evidence_paths(page_id, profile),
                    reproduce={"stepsPath": f"pages/{page_id}/reproduce.md"},
                )
            )
        else:
            drifts.append(
                DriftRecord(
                    drift_id=drift_id,
                    status="unexpected",
                    class_=None,
                    page_id=page_id,
                    profile=profile,
                    layer="visual",
                    location=box.as_dict(),
                    evidence=_evidence_paths(page_id, profile),
                    reproduce={"stepsPath": f"pages/{page_id}/reproduce.md"},
                )
            )
    return drifts


def classify_dom_failures(
    *,
    page_id: str,
    profile: str,
    failures: list[dict[str, Any]],
    overlay_entries: list[OverlayEntry],
    counter_start: int = 0,
) -> list[DriftRecord]:
    drifts: list[DriftRecord] = []
    for index, failure in enumerate(failures, start=counter_start):
        path = str(failure.get("path", ""))
        match = _match_dom(path, overlay_entries)
        drift_id = f"{profile}/{page_id}/dom/d-{index:03d}"
        location = {
            "path": path,
            "reason": failure.get("reason") or failure.get("changeType"),
        }
        if match:
            drifts.append(
                DriftRecord(
                    drift_id=drift_id,
                    status="expected",
                    class_=match.class_,
                    page_id=page_id,
                    profile=profile,
                    layer="dom",
                    location=location,
                    overlay_entry_id=match.id,
                    rationale=match.rationale,
                    evidence={
                        "domDiff": f"data/{page_id}-dom-diff.json",
                        **_evidence_paths(page_id, profile),
                    },
                    reproduce={"stepsPath": f"pages/{page_id}/reproduce.md"},
                )
            )
        else:
            drifts.append(
                DriftRecord(
                    drift_id=drift_id,
                    status="unexpected",
                    class_=None,
                    page_id=page_id,
                    profile=profile,
                    layer="dom",
                    location=location,
                    evidence={
                        "domDiff": f"data/{page_id}-dom-diff.json",
                        **_evidence_paths(page_id, profile),
                    },
                    reproduce={"stepsPath": f"pages/{page_id}/reproduce.md"},
                )
            )
    return drifts


def classify_asset_failures(
    *,
    page_id: str,
    profile: str,
    changed_ids: list[str],
    missing_ids: list[str] | None = None,
    extra_ids: list[str] | None = None,
    overlay_entries: list[OverlayEntry],
    counter_start: int = 0,
) -> list[DriftRecord]:
    drifts: list[DriftRecord] = []
    all_ids = list(changed_ids) + list(missing_ids or []) + list(extra_ids or [])
    # Preserve order, unique
    seen: set[str] = set()
    ordered: list[str] = []
    for asset_id in all_ids:
        if asset_id not in seen:
            seen.add(asset_id)
            ordered.append(asset_id)

    for index, asset_id in enumerate(ordered, start=counter_start):
        match = _match_asset(asset_id, overlay_entries)
        drift_id = f"{profile}/{page_id}/assets/d-{index:03d}"
        location = {"assetId": asset_id}
        if match:
            drifts.append(
                DriftRecord(
                    drift_id=drift_id,
                    status="expected",
                    class_=match.class_,
                    page_id=page_id,
                    profile=profile,
                    layer="assets",
                    location=location,
                    overlay_entry_id=match.id,
                    rationale=match.rationale,
                    evidence={"assetDiff": f"diffs/{page_id}/"},
                    reproduce={"stepsPath": f"pages/{page_id}/reproduce.md"},
                )
            )
        else:
            drifts.append(
                DriftRecord(
                    drift_id=drift_id,
                    status="unexpected",
                    class_=None,
                    page_id=page_id,
                    profile=profile,
                    layer="assets",
                    location=location,
                    evidence={"assetDiff": f"diffs/{page_id}/"},
                    reproduce={"stepsPath": f"pages/{page_id}/reproduce.md"},
                )
            )
    return drifts


def count_by_status(drifts: list[DriftRecord]) -> dict[str, int]:
    unexpected = sum(1 for drift in drifts if drift.status == "unexpected")
    expected_natural = sum(
        1 for drift in drifts if drift.status == "expected" and drift.class_ == "natural"
    )
    expected_intentional = sum(
        1
        for drift in drifts
        if drift.status == "expected" and drift.class_ == "intentional"
    )
    return {
        "unexpected": unexpected,
        "expectedNatural": expected_natural,
        "expectedIntentional": expected_intentional,
        "expected": expected_natural + expected_intentional,
    }


def write_drifts_json(
    run_dir: Path,
    *,
    run_id: str,
    setpoint: str | None,
    overlays: MergedOverlays | None,
    drifts: list[DriftRecord],
    mirror_age_days: float | None = None,
    mirror_override_reason: str | None = None,
) -> Path:
    payload: dict[str, Any] = {
        "runId": run_id,
        "setpoint": setpoint,
        "overlays": [str(path) for path in (overlays.paths if overlays else [])],
        "counts": count_by_status(drifts),
        "drifts": [drift.as_dict() for drift in drifts],
    }
    if mirror_age_days is not None:
        payload["mirrorAgeDays"] = mirror_age_days
    if mirror_override_reason:
        payload["mirrorStaleOverride"] = mirror_override_reason
    out = run_dir / "drifts.json"
    run_dir.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2)
        handle.write("\n")
    return out


def filter_unexpected_boxes(
    failure_boxes: list[Rect], overlay_entries: list[OverlayEntry]
) -> list[Rect]:
    return [box for box in failure_boxes if _match_visual(box, overlay_entries) is None]


def filter_unexpected_dom(
    failures: list[dict[str, Any]], overlay_entries: list[OverlayEntry]
) -> list[dict[str, Any]]:
    return [item for item in failures if _match_dom(str(item.get("path", "")), overlay_entries) is None]


def filter_unexpected_assets(
    asset_ids: list[str], overlay_entries: list[OverlayEntry]
) -> list[str]:
    return [asset_id for asset_id in asset_ids if _match_asset(asset_id, overlay_entries) is None]
