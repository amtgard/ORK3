#!/usr/bin/env python3
"""Augment fuzz manifests from baseline vs candidate pair (cross-session refuzz)."""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any

from lib.canonical_dom import html_to_canonical_tree, load_canonical_tree, save_canonical_tree
from lib.diff_regions import (
    assert_same_shape,
    load_rgb_array,
    masks_to_boxes,
    pairwise_diff_mask,
)
from lib.manifest import load_defaults, load_json5, save_json
from lib.tree_diff import discover_fuzz_nodes_from_pair, merge_fuzz_nodes

TOOL_ROOT = Path(__file__).resolve().parents[1]


def _git_head(repo_root: Path) -> str | None:
    try:
        result = subprocess.run(
            ["git", "rev-parse", "--short", "HEAD"],
            cwd=repo_root,
            capture_output=True,
            text=True,
            check=True,
        )
        return result.stdout.strip() or None
    except (subprocess.CalledProcessError, FileNotFoundError):
        return None


def discover_pixel_zones_from_pair(
    baseline_png: Path,
    candidate_png: Path,
    *,
    color_threshold: int,
    min_area_px: int,
    pad_px: int,
    morphology_kernel_px: int,
) -> tuple[list[dict], int, int]:
    images = [load_rgb_array(baseline_png), load_rgb_array(candidate_png)]
    height, width = assert_same_shape(images)
    mask = pairwise_diff_mask(images[0], images[1], color_threshold)
    boxes = masks_to_boxes(
        mask,
        min_area=min_area_px,
        pad=pad_px,
        kernel_size=morphology_kernel_px,
    )
    return [box.as_dict(source="refuzz") for box in boxes], width, height


def merge_pixel_zones(existing: list[dict], new_zones: list[dict]) -> list[dict]:
    """Append refuzz zones; keep manual zones separate at manifest level."""
    seen = {
        (zone["x"], zone["y"], zone["width"], zone["height"])
        for zone in existing
    }
    merged = list(existing)
    for zone in new_zones:
        key = (zone["x"], zone["y"], zone["width"], zone["height"])
        if key not in seen:
            merged.append(zone)
            seen.add(key)
    return merged


def refuzz_page(
    *,
    page_id: str,
    profile: str,
    tool_root: Path,
    repo_root: Path,
    phase: str = "all",
    defaults: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Merge pair-discovered fuzz into manifests and re-baseline from candidate."""
    defaults = defaults or load_defaults(tool_root / "manifests" / "defaults.json5")
    cal_dir = tool_root / "calibrations" / page_id
    baseline_dir = tool_root / "baselines" / profile
    manifest_dir = tool_root / "manifests" / profile

    baseline_png = baseline_dir / f"{page_id}.png"
    candidate_png = cal_dir / "candidate.png"
    baseline_dom = baseline_dir / f"{page_id}.dom.json"
    candidate_dom_html = cal_dir / "candidate.dom.html"

    if not candidate_png.is_file() or not candidate_dom_html.is_file():
        raise ValueError(
            f"Missing candidate capture for {page_id}; run Playwright capture first"
        )

    summary: dict[str, Any] = {
        "pageId": page_id,
        "profile": profile,
        "domNodesAdded": 0,
        "pixelZonesAdded": 0,
        "rebaselined": False,
    }

    if phase in {"dom", "all"} and baseline_dom.is_file():
        baseline_tree = load_canonical_tree(baseline_dom)
        candidate_tree = html_to_canonical_tree(
            candidate_dom_html.read_text(encoding="utf-8"),
            compare_script_bodies=bool(defaults.get("domCompareScriptBodies", False)),
        )
        save_canonical_tree(cal_dir / "candidate.dom.json", candidate_tree)

        dom_manifest_path = manifest_dir / f"{page_id}.dom-fuzz.json"
        if dom_manifest_path.is_file():
            dom_manifest = load_json5(dom_manifest_path)
        else:
            dom_manifest = {
                "schemaVersion": 1,
                "pageId": page_id,
                "fuzzNodes": [],
                "manualNodes": [],
            }

        existing_auto = list(dom_manifest.get("fuzzNodes", []))
        manual_nodes = list(dom_manifest.get("manualNodes", []))
        new_nodes = discover_fuzz_nodes_from_pair(baseline_tree, candidate_tree)
        merged_auto = merge_fuzz_nodes(existing_auto, new_nodes)
        summary["domNodesAdded"] = len(merged_auto) - len(existing_auto)

        dom_manifest["fuzzNodes"] = merged_auto
        dom_manifest["manualNodes"] = manual_nodes
        dom_manifest["calibrationRuns"] = dom_manifest.get("calibrationRuns", 0)
        from datetime import datetime, timezone

        dom_manifest["refuzzedAt"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        dom_manifest["refuzzedFromCommit"] = _git_head(repo_root)
        save_json(dom_manifest_path, dom_manifest)

        shutil.copy2(cal_dir / "candidate.dom.json", baseline_dom)
        summary["rebaselined"] = True

    if phase in {"visual", "all"} and baseline_png.is_file():
        pixel_manifest_path = manifest_dir / f"{page_id}.fuzz.json"
        if pixel_manifest_path.is_file():
            pixel_manifest = load_json5(pixel_manifest_path)
        else:
            pixel_manifest = {
                "schemaVersion": 1,
                "pageId": page_id,
                "fuzzZones": [],
                "manualZones": [],
                "params": {},
            }

        from datetime import datetime, timezone

        refuzzed_at = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        try:
            new_zones, width, height = discover_pixel_zones_from_pair(
                baseline_png,
                candidate_png,
                color_threshold=int(defaults["colorThreshold"]),
                min_area_px=int(defaults["minAreaPx"]),
                pad_px=int(defaults["padPx"]),
                morphology_kernel_px=int(defaults.get("morphologyKernelPx", 5)),
            )
            existing_zones = list(pixel_manifest.get("fuzzZones", []))
            merged_zones = merge_pixel_zones(existing_zones, new_zones)
            summary["pixelZonesAdded"] = len(merged_zones) - len(existing_zones)
            pixel_manifest["fuzzZones"] = merged_zones
            pixel_manifest["imageWidth"] = width
            pixel_manifest["imageHeight"] = height
        except ValueError as exc:
            if "shape" not in str(exc).lower():
                raise
            cand_img = load_rgb_array(candidate_png)
            height, width = cand_img.shape[:2]
            summary["pixelZonesAdded"] = 0
            pixel_manifest["imageWidth"] = width
            pixel_manifest["imageHeight"] = height
            print(
                f"refuzz: {profile}:{page_id} dimension drift; "
                f"re-baseline to {width}x{height}",
                file=sys.stderr,
            )

        pixel_manifest["refuzzedAt"] = refuzzed_at
        pixel_manifest["refuzzedFromCommit"] = _git_head(repo_root)

        shutil.copy2(candidate_png, baseline_png)
        synced = load_rgb_array(baseline_png)
        sync_h, sync_w = synced.shape[:2]
        pixel_manifest["imageWidth"] = int(sync_w)
        pixel_manifest["imageHeight"] = int(sync_h)
        save_json(pixel_manifest_path, pixel_manifest)
        summary["rebaselined"] = True

    return summary


def build_parser():
    import argparse

    parser = argparse.ArgumentParser(description="Refuzz a page from baseline vs candidate pair")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--profile", required=True)
    parser.add_argument("--tool-root", type=Path, default=TOOL_ROOT)
    parser.add_argument("--phase", default="all", choices=["visual", "dom", "all"])
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    repo_root = args.tool_root.parent.parent
    try:
        summary = refuzz_page(
            page_id=args.page_id,
            profile=args.profile,
            tool_root=args.tool_root,
            repo_root=repo_root,
            phase=args.phase,
        )
    except ValueError as exc:
        print(f"refuzz: {exc}", file=sys.stderr)
        return 1

    print(
        f"refuzz: {summary['profile']}:{summary['pageId']} "
        f"dom_nodes+={summary['domNodesAdded']} "
        f"pixel_zones+={summary['pixelZonesAdded']} "
        f"rebaselined={summary['rebaselined']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
