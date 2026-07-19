#!/usr/bin/env python3
"""Unified fuzzy gate orchestrator — scores, exit code, HTML report bundle."""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from gate import run_pixel_gate
from gate_assets import run_asset_gate
from gate_dom import run_dom_gate
from lib.annotations import load_annotations
from lib.diff_regions import load_rgb_array, rects_from_manifest_zones
from lib.drift_classify import (
    DriftRecord,
    classify_asset_failures,
    classify_dom_failures,
    classify_visual_boxes,
    count_by_status,
    write_drifts_json,
)
from lib.drift_overlay import (
    MergedOverlays,
    asset_ids_from_overlay,
    dom_nodes_from_overlay,
    visual_zones_from_overlay,
)
from lib.manifest import effective_fuzz_zones, load_defaults, load_fuzz_manifest
from lib.overlay import draw_gate_annotation
from lib.page_registry import load_pages_registry
from lib.report_html import copy_page_artifacts, render_summary_table, write_report_bundle, write_summary_json
from lib.reproduce import write_reproduce_md
from lib.scoring import Thresholds, build_page_summary, build_run_summary
from lib.tool_paths import DEFAULT_TOOL_ROOT, defaults_path, pages_manifest_path, resolve_tool_root

TOOL_ROOT = DEFAULT_TOOL_ROOT


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
    drifts: list[DriftRecord] = field(default_factory=list)

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
            "drifts": [drift.as_dict() for drift in self.drifts],
        }


def _baseline_root(tool_root: Path, profile: str | None) -> Path:
    if profile:
        return tool_root / "baselines" / profile
    return tool_root / "baselines"


def _manifest_root(tool_root: Path, profile: str | None) -> Path:
    if profile:
        return tool_root / "manifests" / profile
    return tool_root / "manifests"


def run_page_gate(
    *,
    page_id: str,
    phase: str,
    tool_root: Path,
    defaults: dict,
    profile: str | None = None,
    thresholds: Thresholds | None = None,
    visual_diff_out: Path | None = None,
    run_dir: Path | None = None,
    overlays: MergedOverlays | None = None,
) -> PageGateResult:
    cal_dir = tool_root / "calibrations" / page_id
    baselines = _baseline_root(tool_root, profile)
    manifests = _manifest_root(tool_root, profile)
    profile_name = profile or "test"

    active_thresholds = thresholds or Thresholds.from_defaults(defaults)
    assets_min = active_thresholds.assets_min
    dom_min = active_thresholds.dom_min
    visual_min = active_thresholds.visual_min
    max_outside = int(defaults.get("gateMaxOutsideDiffPx", 500))
    color_threshold = int(
        defaults.get("gateColorThreshold", defaults.get("colorThreshold", 20))
    )
    compare_script_bodies = bool(defaults.get("domCompareScriptBodies", False))
    strip_query = bool(defaults.get("assetStripQuery", False))

    layers: list[LayerResult] = []
    page_drifts: list[DriftRecord] = []
    asset_diff_dir = (run_dir / "diffs" / page_id) if run_dir else (
        tool_root / "reports" / f"{page_id}-asset-diffs"
    )

    visual_entries = (
        overlays.for_page(page_id=page_id, profile=profile_name, layer="visual")
        if overlays
        else []
    )
    dom_entries = (
        overlays.for_page(page_id=page_id, profile=profile_name, layer="dom")
        if overlays
        else []
    )
    asset_entries = (
        overlays.for_page(page_id=page_id, profile=profile_name, layer="assets")
        if overlays
        else []
    )

    if phase in {"assets", "all"}:
        raw_asset = run_asset_gate(
            baseline_path=baselines / f"{page_id}.assets.json",
            candidate_path=cal_dir / "candidate.assets.json",
            assets_min_score=0.0,
            calibration_dir=None,
            diff_dir=None,
            tool_root=tool_root,
            strip_query=strip_query,
        )
        page_drifts.extend(
            classify_asset_failures(
                page_id=page_id,
                profile=profile_name,
                changed_ids=raw_asset.changed_ids,
                missing_ids=raw_asset.missing_ids,
                extra_ids=raw_asset.extra_ids,
                overlay_entries=asset_entries,
            )
        )
        allowed = asset_ids_from_overlay(asset_entries)
        asset_result = run_asset_gate(
            baseline_path=baselines / f"{page_id}.assets.json",
            candidate_path=cal_dir / "candidate.assets.json",
            assets_min_score=assets_min,
            calibration_dir=cal_dir,
            diff_dir=asset_diff_dir,
            tool_root=tool_root,
            strip_query=strip_query,
            allowed_asset_ids=allowed or None,
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
        calibrated_dom = run_dom_gate(
            baseline_path=baselines / f"{page_id}.dom.json",
            candidate_path=cal_dir / "candidate.dom.html",
            manifest_path=manifests / f"{page_id}.dom-fuzz.json",
            dom_min_score=0.0,
            compare_script_bodies=compare_script_bodies,
        )
        page_drifts.extend(
            classify_dom_failures(
                page_id=page_id,
                profile=profile_name,
                failures=calibrated_dom.get("failures", []),
                overlay_entries=dom_entries,
            )
        )
        extra_nodes = dom_nodes_from_overlay(dom_entries)
        dom_payload = run_dom_gate(
            baseline_path=baselines / f"{page_id}.dom.json",
            candidate_path=cal_dir / "candidate.dom.html",
            manifest_path=manifests / f"{page_id}.dom-fuzz.json",
            dom_min_score=dom_min,
            compare_script_bodies=compare_script_bodies,
            extra_fuzz_nodes=extra_nodes or None,
        )
        if run_dir is not None:
            dom_diff_path = run_dir / "data" / f"{page_id}-dom-diff.json"
            dom_diff_path.parent.mkdir(parents=True, exist_ok=True)
            with dom_diff_path.open("w", encoding="utf-8") as handle:
                json.dump({"failures": calibrated_dom["failures"]}, handle, indent=2)
                handle.write("\n")
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
        calibrated = run_pixel_gate(
            baseline_path=baselines / f"{page_id}.png",
            candidate_path=cal_dir / "candidate.png",
            manifest=manifest,
            color_threshold=color_threshold,
            max_outside_diff=max_outside,
            visual_min_score=0.0,
            min_area_px=int(defaults.get("minAreaPx", 64)),
            pad_px=int(defaults.get("padPx", 4)),
            morphology_kernel_px=int(defaults.get("morphologyKernelPx", 5)),
        )
        page_drifts.extend(
            classify_visual_boxes(
                page_id=page_id,
                profile=profile_name,
                failure_boxes=calibrated.failure_boxes,
                overlay_entries=visual_entries,
            )
        )
        extra_zones = visual_zones_from_overlay(visual_entries)
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
            extra_fuzz_zones=extra_zones or None,
        )
        if visual_diff_out is not None:
            candidate = load_rgb_array(cal_dir / "candidate.png")
            fuzz_rects = rects_from_manifest_zones(
                effective_fuzz_zones(manifest) + extra_zones
            )
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
    return PageGateResult(
        page_id=page_id, passed=passed, layers=layers, drifts=page_drifts
    )


def run_batch_gate(
    *,
    page_ids: list[str],
    phase: str,
    tool_root: Path,
    defaults: dict,
    run_dir: Path,
    profile: str | None = None,
    thresholds: Thresholds | None = None,
    overlays: MergedOverlays | None = None,
    overlay_cli_args: str = "",
    setpoint_bundle: str | None = None,
) -> tuple[list[PageGateResult], int]:
    active_thresholds = thresholds or Thresholds.from_defaults(defaults)
    page_results: list[PageGateResult] = []
    registry: dict[str, Any] | None = None
    try:
        registry = load_pages_registry(pages_manifest_path(tool_root))
    except (OSError, ValueError):
        registry = None
    pages_by_id = {
        page["id"]: page
        for page in (registry or {}).get("pages", [])
        if isinstance(page, dict) and "id" in page
    }
    reg_defaults = (registry or {}).get("defaults", {}) if registry else {}

    for page_id in page_ids:
        visual_diff_out = run_dir / "data" / f"{page_id}-annotated.png"
        try:
            page_result = run_page_gate(
                page_id=page_id,
                phase=phase,
                tool_root=tool_root,
                defaults=defaults,
                profile=profile,
                thresholds=active_thresholds,
                visual_diff_out=visual_diff_out,
                run_dir=run_dir,
                overlays=overlays,
            )
        except (ValueError, FileNotFoundError) as exc:
            print(f"gate_run: [{page_id}] {exc}", file=sys.stderr)
            return page_results, 2

        copy_page_artifacts(
            run_dir=run_dir,
            page_id=page_id,
            tool_root=tool_root,
            profile=profile,
        )
        write_reproduce_md(
            run_dir,
            page_id=page_id,
            profile=profile or "test",
            page=pages_by_id.get(page_id),
            defaults=reg_defaults if isinstance(reg_defaults, dict) else {},
            setpoint_bundle=setpoint_bundle,
            overlay_args=overlay_cli_args,
        )
        page_results.append(page_result)

    exit_code = 0 if all(result.passed for result in page_results) else 1
    return page_results, exit_code


def collect_drifts(page_results: list[PageGateResult]) -> list[DriftRecord]:
    drifts: list[DriftRecord] = []
    for result in page_results:
        drifts.extend(result.drifts)
    return drifts


def write_run_summary(
    *,
    run_dir: Path,
    phase: str,
    page_results: list[PageGateResult],
    exit_code: int,
    thresholds: Thresholds,
    profile: str | None = None,
    profiles: list[str] | None = None,
) -> Path:
    run_id = run_dir.name.removeprefix("run-")
    page_summaries = [
        build_page_summary(
            page_id=result.page_id,
            layers=result.as_dict()["layers"],
            thresholds=thresholds,
            report_path=f"pages/{result.page_id}.html",
        )
        for result in page_results
    ]
    summary = build_run_summary(
        run_id=run_id,
        phase=phase,
        page_summaries=page_summaries,
        thresholds=thresholds,
        exit_code=exit_code,
        profile=profile,
        profiles=profiles,
    )
    summary["generatedAt"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    summary["pagesDetailed"] = [result.as_dict() for result in page_results]
    drifts = collect_drifts(page_results)
    if drifts:
        summary["driftCounts"] = count_by_status(drifts)
    return write_summary_json(run_dir, summary)


def print_stdout_summary(
    *,
    run_dir: Path,
    page_results: list[PageGateResult],
    exit_code: int,
    thresholds: Thresholds,
    profile: str | None = None,
    drifts: list[DriftRecord] | None = None,
) -> None:
    run_id = run_dir.name.removeprefix("run-")
    pass_count = sum(1 for result in page_results if result.passed)
    fail_count = len(page_results) - pass_count
    overall = "PASS" if exit_code == 0 else "FAIL"
    active_drifts = drifts if drifts is not None else collect_drifts(page_results)
    counts = count_by_status(active_drifts)

    print(f"Fuzzy UI Gate — {overall}")
    prefix = f"[{profile}] " if profile else ""
    unexpected = [d for d in active_drifts if d.status == "unexpected"]
    expected = [d for d in active_drifts if d.status == "expected"]
    if unexpected:
        print("Unexpected drifts:")
        for drift in unexpected:
            print(f"  ! {drift.drift_id} {drift.layer} {drift.location}")
    if expected:
        print("Expected drifts:")
        for drift in expected:
            print(f"  · {drift.drift_id} [{drift.class_}] {drift.layer}")

    for result in page_results:
        scores = {layer.layer: layer.score for layer in result.layers}
        assets = scores.get("assets", 1.0)
        dom = scores.get("dom", 1.0)
        visual = scores.get("visual", 1.0)
        status = "PASS" if result.passed else "FAIL"
        threshold_note = ""
        if not result.passed:
            if visual < thresholds.visual_min:
                threshold_note = f"  (visual threshold {thresholds.visual_min:.2f})"
            elif dom < thresholds.dom_min:
                threshold_note = f"  (dom threshold {thresholds.dom_min:.2f})"
            elif assets < thresholds.assets_min:
                threshold_note = f"  (assets threshold {thresholds.assets_min:.2f})"
        print(
            f"  {prefix}{result.page_id:<22} {status}  "
            f"assets={assets:.2f} dom={dom:.2f} visual={visual:.3f}{threshold_note}"
        )

    profile_suffix = f" profiles={','.join([profile])}" if profile else ""
    print(
        f"FUZZ_GATE run={run_id}{profile_suffix} pages={len(page_results)} "
        f"pass={pass_count} fail={fail_count} "
        f"unexpected={counts['unexpected']} "
        f"expectedNatural={counts['expectedNatural']} "
        f"expectedIntentional={counts['expectedIntentional']} "
        f"exit={exit_code}"
    )
    print(f"Report: {run_dir / 'index.html'}")


def finalize_run(
    *,
    run_dir: Path,
    phase: str,
    page_results: list[PageGateResult],
    exit_code: int,
    thresholds: Thresholds,
    profile: str | None = None,
    profile_label: str | None = None,
    profiles: list[str] | None = None,
    profile_sections: list[dict] | None = None,
    overlays: MergedOverlays | None = None,
    setpoint: str | None = None,
    annotations_path: Path | None = None,
    mirror_age_days: float | None = None,
    mirror_override_reason: str | None = None,
) -> Path:
    write_run_summary(
        run_dir=run_dir,
        phase=phase,
        page_results=page_results,
        exit_code=exit_code,
        thresholds=thresholds,
        profile=profile,
        profiles=profiles,
    )
    drifts = collect_drifts(page_results)
    write_drifts_json(
        run_dir,
        run_id=run_dir.name.removeprefix("run-"),
        setpoint=setpoint,
        overlays=overlays,
        drifts=drifts,
        mirror_age_days=mirror_age_days,
        mirror_override_reason=mirror_override_reason,
    )
    annotations = load_annotations(annotations_path) if annotations_path else None
    if annotations is None:
        annotations = load_annotations(run_dir / "annotations.json")

    index_path = write_report_bundle(
        run_dir=run_dir,
        run_id=run_dir.name.removeprefix("run-"),
        phase=phase,
        page_results=[result.as_dict() for result in page_results],
        thresholds=thresholds,
        run_pass=exit_code == 0,
        profile=profile,
        profile_label=profile_label,
        profiles=profiles,
        profile_sections=profile_sections,
        drifts=[drift.as_dict() for drift in drifts],
        annotations=annotations,
    )
    print_stdout_summary(
        run_dir=run_dir,
        page_results=page_results,
        exit_code=exit_code,
        thresholds=thresholds,
        profile=profile,
        drifts=drifts,
    )
    return index_path


def finalize_multi_profile_run(
    *,
    run_dir: Path,
    phase: str,
    profile_runs: list[dict],
    exit_code: int,
    profiles: list[str],
    overlays: MergedOverlays | None = None,
    setpoint: str | None = None,
    annotations_path: Path | None = None,
    mirror_age_days: float | None = None,
    mirror_override_reason: str | None = None,
) -> Path:
    profile_sections: list[dict] = []
    all_drifts: list[DriftRecord] = []
    for entry in profile_runs:
        profile_name = entry["profile"]
        profile_label = entry.get("label", profile_name)
        page_results = entry["page_results"]
        thresholds: Thresholds = entry["thresholds"]
        all_drifts.extend(collect_drifts(page_results))
        page_summaries = [
            build_page_summary(
                page_id=result.page_id,
                layers=result.as_dict()["layers"],
                thresholds=thresholds,
                report_path=f"pages/{profile_name}/{result.page_id}.html",
            )
            for result in page_results
        ]
        profile_sections.append(
            {
                "profile": profile_name,
                "label": profile_label,
                "table_html": render_summary_table(page_summaries, thresholds),
            }
        )
        profile_pages_dir = run_dir / "pages" / profile_name
        profile_pages_dir.mkdir(parents=True, exist_ok=True)
        write_report_bundle(
            run_dir=run_dir,
            run_id=run_dir.name.removeprefix("run-"),
            phase=phase,
            page_results=[result.as_dict() for result in page_results],
            thresholds=thresholds,
            run_pass=all(result.passed for result in page_results),
            profile=profile_name,
            profile_label=profile_label,
            drifts=[drift.as_dict() for drift in collect_drifts(page_results)],
        )
        for page_file in (run_dir / "pages").glob("*.html"):
            if page_file.parent == run_dir / "pages":
                page_file.replace(profile_pages_dir / page_file.name)

    write_run_summary(
        run_dir=run_dir,
        phase=phase,
        page_results=[],
        exit_code=exit_code,
        thresholds=Thresholds(),
        profiles=profiles,
    )
    write_drifts_json(
        run_dir,
        run_id=run_dir.name.removeprefix("run-"),
        setpoint=setpoint,
        overlays=overlays,
        drifts=all_drifts,
        mirror_age_days=mirror_age_days,
        mirror_override_reason=mirror_override_reason,
    )
    annotations = load_annotations(annotations_path) if annotations_path else None
    if annotations is None:
        annotations = load_annotations(run_dir / "annotations.json")

    counts = count_by_status(all_drifts)
    index_path = write_report_bundle(
        run_dir=run_dir,
        run_id=run_dir.name.removeprefix("run-"),
        phase=phase,
        page_results=[],
        thresholds=Thresholds(),
        run_pass=exit_code == 0,
        profiles=profiles,
        profile_sections=profile_sections,
        drifts=[drift.as_dict() for drift in all_drifts],
        annotations=annotations,
    )

    print(f"Fuzzy UI Gate — {'PASS' if exit_code == 0 else 'FAIL'}")
    unexpected = [d for d in all_drifts if d.status == "unexpected"]
    expected = [d for d in all_drifts if d.status == "expected"]
    if unexpected:
        print("Unexpected drifts:")
        for drift in unexpected:
            print(f"  ! {drift.drift_id}")
    if expected:
        print("Expected drifts:")
        for drift in expected:
            print(f"  · {drift.drift_id} [{drift.class_}]")

    for entry in profile_runs:
        profile_name = entry["profile"]
        for result in entry["page_results"]:
            scores = {layer.layer: layer.score for layer in result.layers}
            assets = scores.get("assets", 1.0)
            dom = scores.get("dom", 1.0)
            visual = scores.get("visual", 1.0)
            status = "PASS" if result.passed else "FAIL"
            print(
                f"  [{profile_name}] {result.page_id:<22} {status}  "
                f"assets={assets:.2f} dom={dom:.2f} visual={visual:.3f}"
            )

    run_id = run_dir.name.removeprefix("run-")
    page_count = len(profile_runs[0]["page_results"]) if profile_runs else 0
    pass_pages = page_count * len(profiles) if exit_code == 0 else 0
    fail_pages = page_count * len(profiles) - pass_pages if exit_code != 0 else 0
    print(
        f"FUZZ_GATE run={run_id} profiles={','.join(profiles)} "
        f"pages={page_count} pass={pass_pages} fail={fail_pages} "
        f"unexpected={counts['unexpected']} "
        f"expectedNatural={counts['expectedNatural']} "
        f"expectedIntentional={counts['expectedIntentional']} "
        f"exit={exit_code}"
    )
    print(f"Report: {run_dir / 'index.html'}")
    return index_path


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Unified fuzzy gate orchestrator")
    parser.add_argument("--page-id", help="Single page id (legacy)")
    parser.add_argument("--pages", help="Comma-separated page ids")
    parser.add_argument("--phase", default="all", choices=["visual", "assets", "dom", "all"])
    parser.add_argument(
        "--defaults",
        type=Path,
        default=TOOL_ROOT / "manifests" / "defaults.json5",
    )
    parser.add_argument("--run-dir", type=Path, help="Report run directory")
    parser.add_argument("--profile", help="Database profile name")
    parser.add_argument("--tool-root", type=Path, help="Alternate tool root")
    parser.add_argument("--visual-min-score", type=float)
    parser.add_argument("--dom-min-score", type=float)
    parser.add_argument("--assets-min-score", type=float)
    parser.add_argument("--visual-diff-out", type=Path, help="Annotated visual PNG (single page)")
    parser.add_argument("--skip-report", action="store_true", help="Skip HTML report generation")
    return parser


def _resolve_page_ids(args: argparse.Namespace) -> list[str]:
    if args.pages:
        return [page_id.strip() for page_id in args.pages.split(",") if page_id.strip()]
    if args.page_id:
        return [args.page_id]
    raise SystemExit("gate_run: specify --page-id or --pages")


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    tool_root = resolve_tool_root(args.tool_root)
    defaults_path_resolved = args.defaults if args.defaults else defaults_path(tool_root)
    defaults = load_defaults(defaults_path_resolved)
    page_ids = _resolve_page_ids(args)

    thresholds = Thresholds.from_defaults(defaults).with_overrides(
        assets_min=args.assets_min_score,
        dom_min=args.dom_min_score,
        visual_min=args.visual_min_score,
    )

    run_dir = args.run_dir
    if run_dir is None:
        run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        run_dir = tool_root / "reports" / f"run-{run_id}"

    if len(page_ids) == 1 and args.visual_diff_out is not None:
        visual_out = args.visual_diff_out
    elif len(page_ids) == 1:
        visual_out = run_dir / "data" / f"{page_ids[0]}-annotated.png"
    else:
        visual_out = None

    if len(page_ids) == 1 and not args.skip_report:
        try:
            page_result = run_page_gate(
                page_id=page_ids[0],
                phase=args.phase,
                tool_root=tool_root,
                defaults=defaults,
                profile=args.profile,
                thresholds=thresholds,
                visual_diff_out=visual_out,
                run_dir=run_dir,
            )
        except (ValueError, FileNotFoundError) as exc:
            print(f"gate_run: {exc}", file=sys.stderr)
            return 2

        copy_page_artifacts(
            run_dir=run_dir,
            page_id=page_ids[0],
            tool_root=tool_root,
            profile=args.profile,
        )
        page_results = [page_result]
        exit_code = 0 if page_result.passed else 1
    else:
        page_results, exit_code = run_batch_gate(
            page_ids=page_ids,
            phase=args.phase,
            tool_root=tool_root,
            defaults=defaults,
            run_dir=run_dir,
            profile=args.profile,
            thresholds=thresholds,
        )
        if exit_code == 2:
            return 2

    if args.skip_report:
        for layer in page_results[0].layers if len(page_results) == 1 else []:
            status = "PASS" if layer.passed else "FAIL"
            print(f"gate_run [{page_ids[0]}] {layer.layer}: {status} score={layer.score:.4f}")
        return exit_code

    finalize_run(
        run_dir=run_dir,
        phase=args.phase,
        page_results=page_results,
        exit_code=exit_code,
        thresholds=thresholds,
        profile=args.profile,
    )
    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())
