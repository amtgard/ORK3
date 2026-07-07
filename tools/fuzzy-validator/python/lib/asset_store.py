"""Read/write baseline asset bytes on disk."""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

from lib.asset_manifest import (
    AssetEntry,
    build_baseline_asset_manifest,
    load_asset_manifest,
    save_asset_manifest,
)

TOOL_ROOT = Path(__file__).resolve().parents[2]


def _git_head(repo_root: Path | None = None) -> str | None:
    root = repo_root or TOOL_ROOT.parent.parent
    try:
        result = subprocess.run(
            ["git", "rev-parse", "--short", "HEAD"],
            cwd=root,
            capture_output=True,
            text=True,
            check=True,
        )
        return result.stdout.strip() or None
    except (subprocess.CalledProcessError, FileNotFoundError):
        return None


def asset_file_name(entry: AssetEntry) -> str:
    ext = ".css" if entry.kind == "css" else ".js"
    if entry.inline:
        return f"{entry.id}{ext}"

    url = entry.url or entry.id
    basename = Path(url.split("?", 1)[0]).name or entry.id
    safe = "".join(ch if ch.isalnum() or ch in "._-" else "-" for ch in basename)
    if safe.endswith(ext):
        return f"{entry.id}-{safe}"
    return f"{entry.id}-{safe}{ext}"


def calibration_assets_dir(calibration_dir: Path, run_label: str) -> Path:
    return calibration_dir / "assets" / run_label


def baseline_assets_dir(page_id: str, tool_root: Path | None = None) -> Path:
    root = tool_root or TOOL_ROOT
    return root / "baselines" / "assets" / page_id


def copy_run_assets_to_baseline(
    *,
    page_id: str,
    calibration_dir: Path,
    run_label: str = "run-003",
    tool_root: Path | None = None,
) -> Path:
    root = tool_root or TOOL_ROOT
    manifest_path = calibration_dir / f"{run_label}.assets.json"
    if not manifest_path.exists():
        raise FileNotFoundError(f"Missing calibration asset manifest: {manifest_path}")

    source_manifest = load_asset_manifest(manifest_path)
    source_assets_dir = calibration_assets_dir(calibration_dir, run_label)
    if not source_assets_dir.is_dir():
        raise FileNotFoundError(f"Missing calibration asset bytes: {source_assets_dir}")

    target_assets_dir = baseline_assets_dir(page_id, root)
    if target_assets_dir.exists():
        shutil.rmtree(target_assets_dir)
    target_assets_dir.mkdir(parents=True, exist_ok=True)

    baseline_entries: list[AssetEntry] = []
    for asset in source_manifest.get("assets", []):
        entry = AssetEntry(
            id=str(asset["id"]),
            kind=str(asset["kind"]),
            url=asset.get("url"),
            inline=bool(asset.get("inline", False)),
            sha256=str(asset["sha256"]),
            byte_length=int(asset["byteLength"]),
        )
        source_name = asset_file_name(entry)
        source_path = source_assets_dir / source_name
        if not source_path.exists():
            matches = sorted(source_assets_dir.glob(f"{entry.id}*"))
            if not matches:
                raise FileNotFoundError(
                    f"Missing asset bytes for {entry.id} in {source_assets_dir}"
                )
            source_path = matches[0]
            source_name = source_path.name

        target_path = target_assets_dir / source_name
        shutil.copy2(source_path, target_path)
        relative = Path("baselines") / "assets" / page_id / source_name
        baseline_entries.append(
            AssetEntry(
                id=entry.id,
                kind=entry.kind,
                url=entry.url,
                inline=entry.inline,
                sha256=entry.sha256,
                byte_length=entry.byte_length,
                baseline_path=str(relative).replace("\\", "/"),
            )
        )

    baseline_manifest = build_baseline_asset_manifest(
        page_id=page_id,
        source_manifest=source_manifest,
        baseline_entries=baseline_entries,
        captured_from_commit=_git_head(root.parent.parent),
    )
    baseline_out = root / "baselines" / f"{page_id}.assets.json"
    save_asset_manifest(baseline_out, baseline_manifest)
    return baseline_out


def read_baseline_bytes(entry: AssetEntry, tool_root: Path | None = None) -> bytes:
    root = tool_root or TOOL_ROOT
    if not entry.baseline_path:
        raise ValueError(f"Asset {entry.id} has no baselinePath")
    path = root / entry.baseline_path
    if not path.is_file():
        raise FileNotFoundError(f"Baseline asset bytes not found: {entry.baseline_path}")
    return path.read_bytes()


def read_candidate_bytes(
    entry: AssetEntry,
    calibration_dir: Path,
    run_label: str = "candidate",
) -> bytes:
    assets_dir = calibration_assets_dir(calibration_dir, run_label)
    direct = assets_dir / asset_file_name(entry)
    if direct.is_file():
        return direct.read_bytes()
    matches = sorted(assets_dir.glob(f"{entry.id}*"))
    if not matches:
        raise FileNotFoundError(f"Candidate asset bytes not found for {entry.id}")
    return matches[0].read_bytes()
