"""Agent annotations — display only; never affect gate exit codes."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any


VALID_ASSESSMENTS = frozenset({"reasonable", "suspicious", "unexplained", "out-of-scope"})


def load_annotations(path: Path | str | None) -> dict[str, Any] | None:
    if path is None:
        return None
    annotations_path = Path(path)
    if not annotations_path.is_file():
        return None
    with annotations_path.open(encoding="utf-8") as handle:
        payload = json.load(handle)
    if not isinstance(payload, dict):
        return None
    return payload


def annotations_by_drift_id(payload: dict[str, Any] | None) -> dict[str, dict[str, Any]]:
    if not payload:
        return {}
    items = payload.get("items") or []
    mapping: dict[str, dict[str, Any]] = {}
    for item in items:
        if not isinstance(item, dict):
            continue
        drift_id = item.get("driftId")
        if isinstance(drift_id, str) and drift_id:
            mapping[drift_id] = item
    return mapping


def write_annotations_placeholder(path: Path, *, run_id: str) -> Path:
    """Optional empty shell for agent skills; gate never reads for scoring."""
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "runId": run_id,
        "annotator": "agent",
        "items": [],
        "note": "Display-only. Does not change exit codes or drifts.json.",
    }
    with path.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2)
        handle.write("\n")
    return path
