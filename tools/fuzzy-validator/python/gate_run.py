#!/usr/bin/env python3
"""Unified fuzzy gate orchestrator (v1 — scores + exit code, no full HTML report)."""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path

from gate import run_pixel_gate
from gate_assets import run_asset_gate
from gate_dom import run_dom_gate
from lib.diff_regions import load_rgb_array, rects_from_manifest_zones
from lib.manifest import effective_fuzz_zones, load_defaults, load_fuzz_manifest
from lib.overlay import draw_gate_annotation

TOOL_ROOT = Path(__file__).resolve().parents[1]


@dataclass
class LayerResult:
    layer: str
    passed: bool
    score: float
    exit_code: int
    details: dict = field(default_factory=dict)


@dataclass
class PageGateResult:
    page_id: str
    passed: bool
    layers: list[LayerResult] = field(default_factory=list)

    def as_dict(self) -> dict:
        return {
            "pageId": self.page_id,
            "passed": self.passed,
            "layers": [
                {
                    "layer": layer.layer,
                    "passed": layer.passed,
                    "score": round(layer.score, 6),
                    "exitCode": layer.exit_code,
                    "details": layer.details,
                }
                for layer in self.layers
            ],
        }


def run_page_gate(
    *,
    page_id: str,
    phase: str,
    tool_root: Path,
    defaults: dict,
    visual_diff_out: Path | None = None,
) -> PageGateResult:
    cal_dir = tool_root / "calibrations" / page_id
    baselines = tool_root / "baselines"
    manifests = tool_root / "manifests"

    assets_min = float(defaults.get("assetsMinScore", 1.0))
    dom_min = float(defaults.get("domMinScore", 1.0))
    visual_min = float(defaults.get("visualMinScore", 1.0))
    max_outside = int(defaults.get("gateMaxOutsideDiffPx", 500))
    color_threshold = int(
        defaults.get("gateColorThreshold", defaults.get("colorThreshold", 20))
    )
    compare_script_bodies = bool(defaults.get("domCompareScriptBodies", False))

    layers: list[LayerResult] = []

    if phase in {"assets", "all"}:
        asset_result = run_asset_gate(
            baseline_path=baselines / f"{page_id}.assets.json",
            candidate_path=cal_dir / "candidate.assets.json",
            assets_min_score=assets_min,
            calibration_dir=cal_dir,
            diff_dir=tool_root / "reports" / f"{page_id}-asset-diffs",
            tool_root=tool_root,
        )
        layers.append(
            LayerResult(
                layer="assets",
                passed=asset_result.passed,
                score=asset_result.assets_score,
                exit_code=0 if asset_result.passed else 1,
                details=asset_result.as_dict(),
            )
        )

    if phase in {"dom", "all"}:
        dom_payload = run_dom_gate(
            baseline_path=baselines / f"{page_id}.dom.json",
            candidate_path=cal_dir / "candidate.dom.html",
            manifest_path=manifests / f"{page_id}.dom-fuzz.json",
            dom_min_score=dom_min,
            compare_script_bodies=compare_script_bodies,
        )
        layers.append(
            LayerResult(
                layer="dom",
                passed=dom_payload["passed"],
                score=dom_payload["domScore"],
                exit_code=0 if dom_payload["passed"] else 1,
                details=dom_payload,
            )
        )

    if phase in {"visual", "all"}:
        manifest = load_fuzz_manifest(manifests / f"{page_id}.fuzz.json")
        pixel_result = run_pixel_gate(
            baseline_path=baselines / f"{page_id}.png",
            candidate_path=cal_dir / "candidate.png",
            manifest=manifest,
            color_threshold=color_threshold,
            max_outside_diff=max_outside,
            visual_min_score=visual_min,
            min_area_px=int(defaults.get("minAreaPx", 64)),
            pad_px=int(defaults.get("padPx", 4)),
            morphology_kernel_px=int(defaults.get("morphologyKernelPx", 5)),
        )
        if visual_diff_out is not None:
            candidate = load_rgb_array(cal_dir / "candidate.png")
            fuzz_rects = rects_from_manifest_zones(effective_fuzz_zones(manifest))
            draw_gate_annotation(
                candidate,
                fuzz_rects,
                pixel_result.failure_boxes,
                visual_diff_out,
            )
        layers.append(
            LayerResult(
                layer="visual",
                passed=pixel_result.passed,
                score=pixel_result.visual_score,
                exit_code=0 if pixel_result.passed else 1,
                details=pixel_result.as_dict(),
            )
        )

    passed = all(layer.passed for layer in layers) if layers else True
    return PageGateResult(page_id=page_id, passed=passed, layers=layers)


def write_run_summary(
    *,
    run_dir: Path,
    phase: str,
    page_results: list[PageGateResult],
    exit_code: int,
) -> Path:
    run_dir.mkdir(parents=True, exist_ok=True)
    summary = {
        "runId": run_dir.name.removeprefix("run-"),
        "phase": phase,
        "generatedAt": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "exitCode": exit_code,
        "pages": [result.as_dict() for result in page_results],
    }
    summary_path = run_dir / "summary.json"
    with summary_path.open("w", encoding="utf-8") as handle:
        json.dump(summary, handle, indent=2)
        handle.write("\n")
    return summary_path


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Unified fuzzy gate orchestrator")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--phase", default="all", choices=["visual", "assets", "dom", "all"])
    parser.add_argument(
        "--defaults",
        type=Path,
        default=TOOL_ROOT / "manifests" / "defaults.json5",
    )
    parser.add_argument("--run-dir", type=Path, help="Report run directory")
    parser.add_argument("--visual-diff-out", type=Path, help="Annotated visual PNG path")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    defaults = load_defaults(args.defaults)

    run_dir = args.run_dir
    if run_dir is None:
        run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        run_dir = TOOL_ROOT / "reports" / f"run-{run_id}"

    visual_diff_out = args.visual_diff_out
    if visual_diff_out is None and args.phase in {"visual", "all"}:
        visual_diff_out = run_dir / "data" / f"{args.page_id}-annotated.png"

    try:
        page_result = run_page_gate(
            page_id=args.page_id,
            phase=args.phase,
            tool_root=TOOL_ROOT,
            defaults=defaults,
            visual_diff_out=visual_diff_out,
        )
    except (ValueError, FileNotFoundError) as exc:
        print(f"gate_run: {exc}", file=sys.stderr)
        return 2

    exit_code = 0 if page_result.passed else 1
    summary_path = write_run_summary(
        run_dir=run_dir,
        phase=args.phase,
        page_results=[page_result],
        exit_code=exit_code,
    )

    for layer in page_result.layers:
        status = "PASS" if layer.passed else "FAIL"
        print(f"gate_run [{args.page_id}] {layer.layer}: {status} score={layer.score:.4f}")

    run_pass = 1 if page_result.passed else 0
    run_fail = 0 if page_result.passed else 1
    print(
        f"FUZZ_GATE run={run_dir.name.removeprefix('run-')} pages=1 "
        f"pass={run_pass} fail={run_fail} exit={exit_code}"
    )
    print(f"gate_run: summary → {summary_path}")
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
