"""Load defaults and read/write fuzz manifest JSON."""

from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

TOOL_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_DEFAULTS_PATH = TOOL_ROOT / "manifests" / "defaults.json5"


def load_json5(path: Path | str) -> dict[str, Any]:
    """Load JSON5 file (comments not supported; valid JSON subset only)."""
    with Path(path).open(encoding="utf-8") as handle:
        return json.load(handle)


def load_defaults(path: Path | str | None = None) -> dict[str, Any]:
    return load_json5(path or DEFAULT_DEFAULTS_PATH)


def build_fuzz_manifest(
    *,
    page_id: str,
    image_width: int,
    image_height: int,
    fuzz_zones: list[dict],
    params: dict[str, Any],
    calibration_runs: int,
    calibrated_from_commit: str | None = None,
) -> dict[str, Any]:
    manifest: dict[str, Any] = {
        "schemaVersion": 1,
        "pageId": page_id,
        "imageWidth": image_width,
        "imageHeight": image_height,
        "calibratedAt": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "calibrationRuns": calibration_runs,
        "params": params,
        "fuzzZones": fuzz_zones,
        "manualZones": [],
    }
    if calibrated_from_commit:
        manifest["calibratedFromCommit"] = calibrated_from_commit
    return manifest


def save_json(path: Path | str, payload: dict[str, Any]) -> Path:
    out = Path(path)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2)
        handle.write("\n")
    return out


def load_fuzz_manifest(path: Path | str) -> dict[str, Any]:
    return load_json5(path)


def effective_fuzz_zones(manifest: dict[str, Any]) -> list[dict]:
    return list(manifest.get("fuzzZones", [])) + list(manifest.get("manualZones", []))
