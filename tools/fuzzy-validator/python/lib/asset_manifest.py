"""Load and compare CSS/JS asset manifests."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


@dataclass(frozen=True)
class AssetEntry:
    id: str
    kind: str
    url: str | None
    inline: bool
    sha256: str
    byte_length: int
    baseline_path: str | None = None

    def as_dict(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "id": self.id,
            "kind": self.kind,
            "url": self.url,
            "inline": self.inline,
            "sha256": self.sha256,
            "byteLength": self.byte_length,
        }
        if self.baseline_path is not None:
            payload["baselinePath"] = self.baseline_path
        return payload


@dataclass(frozen=True)
class AssetCompareResult:
    passed: bool
    missing_ids: list[str] = field(default_factory=list)
    extra_ids: list[str] = field(default_factory=list)
    changed_ids: list[str] = field(default_factory=list)
    assets_score: float = 1.0

    def as_dict(self) -> dict[str, Any]:
        return {
            "passed": self.passed,
            "missingIds": self.missing_ids,
            "extraIds": self.extra_ids,
            "changedIds": self.changed_ids,
            "assetsScore": round(self.assets_score, 6),
        }


def load_asset_manifest(path: Path | str) -> dict[str, Any]:
    with Path(path).open(encoding="utf-8") as handle:
        return json.load(handle)


def save_asset_manifest(path: Path | str, payload: dict[str, Any]) -> Path:
    out = Path(path)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2)
        handle.write("\n")
    return out


def parse_assets(manifest: dict[str, Any]) -> list[AssetEntry]:
    entries: list[AssetEntry] = []
    for asset in manifest.get("assets", []):
        entries.append(
            AssetEntry(
                id=str(asset["id"]),
                kind=str(asset["kind"]),
                url=asset.get("url"),
                inline=bool(asset.get("inline", False)),
                sha256=str(asset["sha256"]),
                byte_length=int(asset["byteLength"]),
                baseline_path=asset.get("baselinePath"),
            )
        )
    return entries


def asset_fingerprints(manifest: dict[str, Any]) -> dict[str, str]:
    return {entry.id: entry.sha256 for entry in parse_assets(manifest)}


def compare_asset_manifests(
    baseline: dict[str, Any],
    candidate: dict[str, Any],
) -> AssetCompareResult:
    baseline_by_id = {entry.id: entry for entry in parse_assets(baseline)}
    candidate_by_id = {entry.id: entry for entry in parse_assets(candidate)}

    baseline_ids = set(baseline_by_id)
    candidate_ids = set(candidate_by_id)
    missing_ids = sorted(baseline_ids - candidate_ids)
    extra_ids = sorted(candidate_ids - baseline_ids)
    changed_ids: list[str] = []

    for asset_id in sorted(baseline_ids & candidate_ids):
        base_entry = baseline_by_id[asset_id]
        candidate_entry = candidate_by_id[asset_id]
        if (
            base_entry.sha256 != candidate_entry.sha256
            or base_entry.byte_length != candidate_entry.byte_length
        ):
            changed_ids.append(asset_id)

    total = len(baseline_ids)
    mismatches = len(missing_ids) + len(extra_ids) + len(changed_ids)
    if total == 0:
        assets_score = 1.0 if mismatches == 0 else 0.0
    else:
        assets_score = max(0.0, 1.0 - (mismatches / total))

    passed = not missing_ids and not extra_ids and not changed_ids
    return AssetCompareResult(
        passed=passed,
        missing_ids=missing_ids,
        extra_ids=extra_ids,
        changed_ids=changed_ids,
        assets_score=assets_score,
    )


def calibration_asset_manifests(calibration_dir: Path) -> list[Path]:
    return sorted(calibration_dir.glob("run-*.assets.json"))


def assert_calibration_asset_stability(calibration_dir: Path) -> None:
    manifests = calibration_asset_manifests(calibration_dir)
    if len(manifests) < 2:
        raise ValueError(
            f"Need at least 2 asset manifests in {calibration_dir}; found {len(manifests)}"
        )

    reference = asset_fingerprints(load_asset_manifest(manifests[0]))
    for manifest_path in manifests[1:]:
        fingerprints = asset_fingerprints(load_asset_manifest(manifest_path))
        if fingerprints != reference:
            raise ValueError(
                f"Asset manifest drift between calibration runs: {manifest_path.name}"
            )


def build_baseline_asset_manifest(
    *,
    page_id: str,
    source_manifest: dict[str, Any],
    baseline_entries: list[AssetEntry],
    captured_from_commit: str | None = None,
) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "schemaVersion": 1,
        "pageId": page_id,
        "capturedAt": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "assets": [entry.as_dict() for entry in baseline_entries],
    }
    if captured_from_commit:
        payload["capturedFromCommit"] = captured_from_commit
    if "runLabel" in source_manifest:
        payload["sourceRun"] = source_manifest["runLabel"]
    return payload
