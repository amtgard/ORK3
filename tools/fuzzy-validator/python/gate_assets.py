#!/usr/bin/env python3
"""Hard CSS/JS asset gate — zero tolerance byte compare."""

from __future__ import annotations

import argparse
import difflib
import json
import sys
from pathlib import Path

from lib.asset_manifest import (
    AssetCompareResult,
    apply_asset_allowances,
    asset_content_key,
    compare_asset_manifests,
    load_asset_manifest,
    parse_assets,
)
from lib.asset_store import read_baseline_bytes, read_candidate_bytes
from lib.manifest import load_defaults

TOOL_ROOT = Path(__file__).resolve().parents[1]


def write_asset_diffs(
    *,
    baseline: dict,
    candidate: dict,
    result: AssetCompareResult,
    calibration_dir: Path,
    diff_dir: Path,
    tool_root: Path,
    strip_query: bool = False,
) -> None:
    baseline_by_key = {
        asset_content_key(entry, strip_query=strip_query): entry
        for entry in parse_assets(baseline)
    }
    candidate_by_key = {
        asset_content_key(entry, strip_query=strip_query): entry
        for entry in parse_assets(candidate)
    }
    diff_dir.mkdir(parents=True, exist_ok=True)

    for asset_key in result.changed_ids:
        base_entry = baseline_by_key[asset_key]
        candidate_entry = candidate_by_key[asset_key]
        try:
            base_text = read_baseline_bytes(base_entry, tool_root).decode("utf-8")
        except UnicodeDecodeError:
            base_text = repr(read_baseline_bytes(base_entry, tool_root))
        try:
            candidate_text = read_candidate_bytes(
                candidate_entry, calibration_dir
            ).decode("utf-8")
        except UnicodeDecodeError:
            candidate_text = repr(read_candidate_bytes(candidate_entry, calibration_dir))

        diff_lines = difflib.unified_diff(
            base_text.splitlines(keepends=True),
            candidate_text.splitlines(keepends=True),
            fromfile=f"baseline/{base_entry.id}",
            tofile=f"candidate/{candidate_entry.id}",
        )
        safe_name = asset_key.replace(":", "_").replace("/", "_")
        diff_path = diff_dir / f"{safe_name}.diff"
        diff_path.write_text("".join(diff_lines), encoding="utf-8")


def run_asset_gate(
    *,
    baseline_path: Path,
    candidate_path: Path,
    assets_min_score: float,
    calibration_dir: Path | None = None,
    diff_dir: Path | None = None,
    tool_root: Path | None = None,
    strip_query: bool = False,
    allowed_asset_ids: set[str] | None = None,
) -> AssetCompareResult:
    root = tool_root or TOOL_ROOT
    baseline = load_asset_manifest(baseline_path)
    candidate = load_asset_manifest(candidate_path)
    result = compare_asset_manifests(baseline, candidate, strip_query=strip_query)

    if allowed_asset_ids:
        result = apply_asset_allowances(
            result,
            allowed_asset_ids,
            baseline_count=len(parse_assets(baseline)),
        )

    if diff_dir is not None and calibration_dir is not None and result.changed_ids:
        write_asset_diffs(
            baseline=baseline,
            candidate=candidate,
            result=result,
            calibration_dir=calibration_dir,
            diff_dir=diff_dir,
            tool_root=root,
            strip_query=strip_query,
        )

    if result.assets_score < assets_min_score:
        return AssetCompareResult(
            passed=False,
            missing_ids=result.missing_ids,
            extra_ids=result.extra_ids,
            changed_ids=result.changed_ids,
            assets_score=result.assets_score,
        )
    return result


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="CSS/JS asset hard gate")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    parser.add_argument("--candidate", type=Path, required=True)
    parser.add_argument(
        "--defaults",
        type=Path,
        default=TOOL_ROOT / "manifests" / "defaults.json5",
    )
    parser.add_argument("--assets-min-score", type=float)
    parser.add_argument(
        "--calibration-dir",
        type=Path,
        help="Directory containing candidate asset bytes",
    )
    parser.add_argument("--diff-dir", type=Path, help="Unified diff output directory")
    parser.add_argument("--json-out", type=Path, help="Gate result JSON output")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    defaults = load_defaults(args.defaults)
    assets_min_score = args.assets_min_score
    if assets_min_score is None:
        assets_min_score = float(defaults.get("assetsMinScore", 1.0))
    strip_query = bool(defaults.get("assetStripQuery", False))

    calibration_dir = args.calibration_dir
    if calibration_dir is None:
        calibration_dir = args.candidate.parent

    try:
        result = run_asset_gate(
            baseline_path=args.baseline,
            candidate_path=args.candidate,
            assets_min_score=assets_min_score,
            calibration_dir=calibration_dir,
            diff_dir=args.diff_dir,
            strip_query=strip_query,
        )
    except (ValueError, FileNotFoundError) as exc:
        print(f"gate_assets: {exc}", file=sys.stderr)
        return 2

    if args.json_out:
        args.json_out.parent.mkdir(parents=True, exist_ok=True)
        with args.json_out.open("w", encoding="utf-8") as handle:
            json.dump(result.as_dict(), handle, indent=2)
            handle.write("\n")

    status = "PASS" if result.passed else "FAIL"
    print(
        f"gate_assets [{args.page_id}]: {status} "
        f"assets_score={result.assets_score:.4f} "
        f"missing={len(result.missing_ids)} "
        f"extra={len(result.extra_ids)} "
        f"changed={len(result.changed_ids)}"
    )
    if result.missing_ids:
        print(f"  missing: {', '.join(result.missing_ids)}")
    if result.extra_ids:
        print(f"  extra: {', '.join(result.extra_ids)}")
    if result.changed_ids:
        print(f"  changed: {', '.join(result.changed_ids)}")
    return 0 if result.passed else 1


if __name__ == "__main__":
    raise SystemExit(main())
