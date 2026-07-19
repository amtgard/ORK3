#!/usr/bin/env python3
"""Pixel regression gate — compare candidate screenshot to baseline minus fuzz zones."""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import dataclass
from pathlib import Path

from lib.diff_regions import (
    Rect,
    load_rgb_array,
    masks_to_boxes,
    pairwise_diff_mask,
    rect_mask,
    rects_from_manifest_zones,
)
from lib.manifest import effective_fuzz_zones, load_defaults, load_fuzz_manifest
from lib.overlay import draw_gate_annotation

TOOL_ROOT = Path(__file__).resolve().parents[1]


@dataclass(frozen=True)
class GateResult:
    passed: bool
    outside_diff_px: int
    comparable_px: int
    visual_score: float
    failure_boxes: list[Rect]

    def as_dict(self) -> dict:
        return {
            "passed": self.passed,
            "outsideDiffPx": self.outside_diff_px,
            "comparablePx": self.comparable_px,
            "visualScore": round(self.visual_score, 6),
            "failureBoxCount": len(self.failure_boxes),
        }


def run_pixel_gate(
    *,
    baseline_path: Path,
    candidate_path: Path,
    manifest: dict,
    color_threshold: int,
    max_outside_diff: int,
    visual_min_score: float,
    min_area_px: int = 1,
    pad_px: int = 0,
    morphology_kernel_px: int = 1,
) -> GateResult:
    baseline = load_rgb_array(baseline_path)
    candidate = load_rgb_array(candidate_path)

    if baseline.shape != candidate.shape:
        bh, bw = baseline.shape[:2]
        ch, cw = candidate.shape[:2]
        # Full-page screenshots occasionally drift by 1px in height.
        if bw == cw and abs(bh - ch) == 1:
            h = min(bh, ch)
            baseline = baseline[:h]
            candidate = candidate[:h]
        else:
            raise ValueError(
                f"Dimension mismatch: baseline {(bh, bw)} "
                f"vs candidate {(ch, cw)}"
            )

    expected_h = int(manifest["imageHeight"])
    expected_w = int(manifest["imageWidth"])
    actual_h, actual_w = baseline.shape[:2]
    # Allow the same 1px height drift against the fuzz manifest metadata.
    if actual_w != expected_w or abs(actual_h - expected_h) > 1:
        raise ValueError(
            f"Manifest dimensions {expected_w}x{expected_h} "
            f"!= image {actual_w}x{actual_h}"
        )

    full_diff = pairwise_diff_mask(baseline, candidate, color_threshold)
    fuzz_rects = rects_from_manifest_zones(effective_fuzz_zones(manifest))
    fuzz_coverage = rect_mask(full_diff.shape, fuzz_rects)
    outside_mask = full_diff & ~fuzz_coverage

    outside_diff_px = int(outside_mask.sum())
    total_px = outside_mask.size
    comparable_px = int((~fuzz_coverage).sum())
    if comparable_px <= 0:
        visual_score = 1.0 if outside_diff_px == 0 else 0.0
    else:
        visual_score = 1.0 - (outside_diff_px / comparable_px)

    failure_boxes = masks_to_boxes(
        outside_mask,
        min_area=min_area_px,
        pad=pad_px,
        kernel_size=morphology_kernel_px,
    )

    passed = (
        outside_diff_px <= max_outside_diff
        and visual_score >= visual_min_score
    )
    return GateResult(
        passed=passed,
        outside_diff_px=outside_diff_px,
        comparable_px=comparable_px,
        visual_score=visual_score,
        failure_boxes=failure_boxes,
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Pixel regression gate")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    parser.add_argument("--candidate", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument(
        "--defaults",
        type=Path,
        default=TOOL_ROOT / "manifests" / "defaults.json5",
    )
    parser.add_argument("--max-outside-diff", type=int)
    parser.add_argument("--visual-min-score", type=float)
    parser.add_argument("--diff-out", type=Path, help="Annotated gate PNG output")
    parser.add_argument("--json-out", type=Path, help="Gate result JSON output")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    defaults = load_defaults(args.defaults)
    manifest = load_fuzz_manifest(args.manifest)

    max_outside = args.max_outside_diff
    if max_outside is None:
        max_outside = int(defaults.get("gateMaxOutsideDiffPx", 500))

    visual_min = args.visual_min_score
    if visual_min is None:
        visual_min = float(defaults.get("visualMinScore", 1.0))

    color_threshold = int(
        defaults.get("gateColorThreshold", defaults.get("colorThreshold", 20))
    )

    try:
        result = run_pixel_gate(
            baseline_path=args.baseline,
            candidate_path=args.candidate,
            manifest=manifest,
            color_threshold=color_threshold,
            max_outside_diff=max_outside,
            visual_min_score=visual_min,
            min_area_px=int(defaults.get("minAreaPx", 64)),
            pad_px=int(defaults.get("padPx", 4)),
            morphology_kernel_px=int(defaults.get("morphologyKernelPx", 5)),
        )
    except (ValueError, FileNotFoundError) as exc:
        print(f"gate: {exc}", file=sys.stderr)
        return 2

    if args.json_out:
        args.json_out.parent.mkdir(parents=True, exist_ok=True)
        with args.json_out.open("w", encoding="utf-8") as handle:
            json.dump(result.as_dict(), handle, indent=2)
            handle.write("\n")

    if args.diff_out:
        candidate = load_rgb_array(args.candidate)
        fuzz_rects = rects_from_manifest_zones(effective_fuzz_zones(manifest))
        draw_gate_annotation(
            candidate,
            fuzz_rects,
            result.failure_boxes,
            args.diff_out,
        )

    status = "PASS" if result.passed else "FAIL"
    print(
        f"gate [{args.page_id}]: {status} "
        f"outside_diff_px={result.outside_diff_px} "
        f"visual_score={result.visual_score:.4f}"
    )
    return 0 if result.passed else 1


if __name__ == "__main__":
    raise SystemExit(main())
