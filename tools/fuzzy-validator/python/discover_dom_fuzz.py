#!/usr/bin/env python3
"""Discover DOM fuzz nodes from calibration HTML runs."""

from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

from lib.canonical_dom import html_to_canonical_tree, save_canonical_tree
from lib.manifest import build_dom_fuzz_manifest, load_defaults, save_json
from lib.tree_diff import discover_fuzz_nodes

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


def load_calibration_trees(
    calibration_dir: Path,
    *,
    compare_script_bodies: bool,
) -> tuple[list[dict], list[Path]]:
    """Canonicalize run-*.dom.html files in sorted order."""
    html_paths = sorted(calibration_dir.glob("run-*.dom.html"))
    if len(html_paths) < 2:
        raise ValueError(
            f"Need at least 2 calibration DOM files in {calibration_dir}; "
            f"found {len(html_paths)}"
        )

    trees: list[dict] = []
    for html_path in html_paths:
        html = html_path.read_text(encoding="utf-8")
        tree = html_to_canonical_tree(
            html,
            compare_script_bodies=compare_script_bodies,
        )
        run_label = html_path.name.removesuffix(".dom.html")
        json_path = calibration_dir / f"{run_label}.dom.json"
        save_canonical_tree(json_path, tree)
        trees.append(tree)
    return trees, html_paths


def write_debug_report(path: Path, fuzz_nodes: list[dict]) -> None:
    lines = ["DOM fuzz discovery report", "=" * 28, ""]
    if not fuzz_nodes:
        lines.append("(no volatile paths discovered)")
    else:
        for node in fuzz_nodes:
            mode = node.get("mode")
            attrs = node.get("attrs")
            label = node.get("label", "")
            line = f"{node['path']}  mode={mode}"
            if attrs:
                line += f"  attrs={','.join(attrs)}"
            if label:
                line += f"  label={label}"
            lines.append(line)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def discover_dom_fuzz(
    calibration_dir: Path,
    *,
    compare_script_bodies: bool,
) -> list[dict]:
    trees, _ = load_calibration_trees(
        calibration_dir,
        compare_script_bodies=compare_script_bodies,
    )
    return discover_fuzz_nodes(trees)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Discover DOM fuzz nodes from calibration runs")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--calibration-dir", type=Path, required=True)
    parser.add_argument(
        "--defaults",
        type=Path,
        default=TOOL_ROOT / "manifests" / "defaults.json5",
    )
    parser.add_argument("--out", type=Path, help="Output dom-fuzz manifest path")
    parser.add_argument("--out-manifest", type=Path, help="Alias for --out")
    parser.add_argument("--debug-out", type=Path, help="Human-readable debug report path")
    parser.add_argument("--baseline-out", type=Path, help="Copy median run dom.json here")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    defaults = load_defaults(args.defaults)
    compare_script_bodies = bool(defaults.get("domCompareScriptBodies", False))

    out_manifest = args.out or args.out_manifest
    if out_manifest is None:
        out_manifest = TOOL_ROOT / "manifests" / f"{args.page_id}.dom-fuzz.json"

    debug_out = args.debug_out
    if debug_out is None:
        debug_out = DEFAULT_REPORTS_DIR / f"{args.page_id}-dom-fuzz.txt"

    try:
        trees, html_paths = load_calibration_trees(
            args.calibration_dir,
            compare_script_bodies=compare_script_bodies,
        )
        fuzz_nodes = discover_fuzz_nodes(trees)
    except ValueError as exc:
        print(f"discover_dom_fuzz: {exc}", file=sys.stderr)
        return 1

    manifest = build_dom_fuzz_manifest(
        page_id=args.page_id,
        fuzz_nodes=fuzz_nodes,
        calibration_runs=len(html_paths),
        calibrated_from_commit=_git_head(),
    )
    save_json(out_manifest, manifest)
    write_debug_report(debug_out, fuzz_nodes)

    if args.baseline_out:
        median_index = len(trees) // 2
        median_json = args.calibration_dir / html_paths[median_index].name.replace(
            ".dom.html", ".dom.json"
        )
        args.baseline_out.parent.mkdir(parents=True, exist_ok=True)
        args.baseline_out.write_text(median_json.read_text(encoding="utf-8"), encoding="utf-8")

    print(f"discover_dom_fuzz: wrote {len(fuzz_nodes)} nodes → {out_manifest}")
    print(f"discover_dom_fuzz: debug report → {debug_out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
