#!/usr/bin/env python3
"""Discover pixel fuzz zones from calibration PNG runs."""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

from lib.diff_regions import (
    assert_same_shape,
    consecutive_pair_masks,
    intersect_consecutive_masks,
    load_rgb_array,
    masks_to_boxes,
)
from lib.manifest import build_fuzz_manifest, load_defaults, save_json
from lib.overlay import draw_calibration_overlay

TOOL_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_REPORTS_DIR = TOOL_ROOT / "reports"


def _git_head() -> str | None:
    try:
        result = subprocess.run(
            ["git", "rev-parse", "--short", "HEAD"],
            cwd=TOOL_ROOT.parent.parent,
            capture_output=True,
            text=True,
            check=True,
        )
        return result.stdout.strip() or None
    except (subprocess.CalledProcessError, FileNotFoundError):
        return None


def discover_fuzz_zones(
    calibration_dir: Path,
    *,
    color_threshold: int,
    min_area_px: int,
    pad_px: int,
    morphology_kernel_px: int,
) -> tuple[list, int, int, object]:
    """Return fuzz zone dicts, width, height, and base image array."""
    run_paths = sorted(calibration_dir.glob("run-*.png"))
    if len(run_paths) < 2:
        raise ValueError(
            f"Need at least 2 calibration PNGs in {calibration_dir}; found {len(run_paths)}"
        )

    images = [load_rgb_array(path) for path in run_paths]
    height, width = assert_same_shape(images)
    pair_masks = consecutive_pair_masks(images, color_threshold)
    volatile_mask = intersect_consecutive_masks(pair_masks)
    boxes = masks_to_boxes(
        volatile_mask,
        min_area=min_area_px,
        pad=pad_px,
        kernel_size=morphology_kernel_px,
    )

    median_index = len(run_paths) // 2
    base_image = images[median_index]
    zones = [box.as_dict() for box in boxes]
    return zones, width, height, base_image


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Discover pixel fuzz zones from calibration runs")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--calibration-dir", type=Path, required=True)
    parser.add_argument(
        "--defaults",
        type=Path,
        default=TOOL_ROOT / "manifests" / "defaults.json5",
    )
    parser.add_argument("--out", type=Path, help="Output fuzz manifest path")
    parser.add_argument("--out-manifest", type=Path, help="Alias for --out")
    parser.add_argument("--overlay", type=Path, help="Calibration overlay PNG path")
    parser.add_argument("--overlay-out", type=Path, help="Alias for --overlay")
    parser.add_argument("--baseline-out", type=Path, help="Copy median run baseline PNG here")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    defaults = load_defaults(args.defaults)
    out_manifest = args.out or args.out_manifest
    if out_manifest is None:
        out_manifest = TOOL_ROOT / "manifests" / f"{args.page_id}.fuzz.json"

    overlay_path = args.overlay or args.overlay_out
    if overlay_path is None:
        overlay_path = DEFAULT_REPORTS_DIR / f"{args.page_id}-calibration-overlay.png"

    try:
        zones, width, height, base_image = discover_fuzz_zones(
            args.calibration_dir,
            color_threshold=int(defaults["colorThreshold"]),
            min_area_px=int(defaults["minAreaPx"]),
            pad_px=int(defaults["padPx"]),
            morphology_kernel_px=int(defaults.get("morphologyKernelPx", 5)),
        )
    except ValueError as exc:
        print(f"discover_fuzz: {exc}", file=sys.stderr)
        return 1

    params = {
        "colorThreshold": defaults["colorThreshold"],
        "minAreaPx": defaults["minAreaPx"],
        "padPx": defaults["padPx"],
    }
    manifest = build_fuzz_manifest(
        page_id=args.page_id,
        image_width=width,
        image_height=height,
        fuzz_zones=zones,
        params=params,
        calibration_runs=len(list(args.calibration_dir.glob("run-*.png"))),
        calibrated_from_commit=_git_head(),
    )
    save_json(out_manifest, manifest)

    from lib.diff_regions import rects_from_manifest_zones

    draw_calibration_overlay(
        base_image,
        rects_from_manifest_zones(zones),
        overlay_path,
    )

    if args.baseline_out:
        run_paths = sorted(args.calibration_dir.glob("run-*.png"))
        median_run = run_paths[len(run_paths) // 2]
        args.baseline_out.parent.mkdir(parents=True, exist_ok=True)
        args.baseline_out.write_bytes(median_run.read_bytes())

    print(f"discover_fuzz: wrote {len(zones)} zones → {out_manifest}")
    print(f"discover_fuzz: overlay → {overlay_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
