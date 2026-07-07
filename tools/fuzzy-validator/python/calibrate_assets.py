#!/usr/bin/env python3
"""Assert calibration asset stability and copy median run to baselines."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from lib.asset_manifest import assert_calibration_asset_stability
from lib.asset_store import copy_run_assets_to_baseline

TOOL_ROOT = Path(__file__).resolve().parents[1]


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Calibrate CSS/JS asset baselines")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--calibration-dir", type=Path, required=True)
    parser.add_argument("--run-label", default="run-003")
    parser.add_argument("--baseline-out", type=Path, help="Asset manifest output path")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    try:
        assert_calibration_asset_stability(args.calibration_dir)
        baselines_root = args.baseline_out.parent if args.baseline_out else None
        baseline_out = copy_run_assets_to_baseline(
            page_id=args.page_id,
            calibration_dir=args.calibration_dir,
            run_label=args.run_label,
            tool_root=TOOL_ROOT,
            baselines_root=baselines_root,
        )
    except (ValueError, FileNotFoundError) as exc:
        print(f"calibrate_assets: {exc}", file=sys.stderr)
        return 1

    print(f"calibrate_assets: stable manifests → {baseline_out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
