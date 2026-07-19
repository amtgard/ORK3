#!/usr/bin/env python3
"""DOM tree regression gate — compare candidate tree to baseline minus fuzz nodes."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from lib.canonical_dom import html_to_canonical_tree, load_canonical_tree
from lib.manifest import load_defaults, load_dom_fuzz_manifest
from lib.tree_diff import compare_dom_trees

TOOL_ROOT = Path(__file__).resolve().parents[1]


def load_candidate_tree(candidate_path: Path, *, compare_script_bodies: bool) -> dict:
    if candidate_path.suffix == ".html":
        html = candidate_path.read_text(encoding="utf-8")
        return html_to_canonical_tree(
            html,
            compare_script_bodies=compare_script_bodies,
        )
    return load_canonical_tree(candidate_path)


def run_dom_gate(
    *,
    baseline_path: Path,
    candidate_path: Path,
    manifest_path: Path,
    dom_min_score: float,
    compare_script_bodies: bool,
    extra_fuzz_nodes: list[dict] | None = None,
) -> dict:
    baseline = load_canonical_tree(baseline_path)
    candidate = load_candidate_tree(
        candidate_path,
        compare_script_bodies=compare_script_bodies,
    )
    manifest = load_dom_fuzz_manifest(manifest_path)
    if extra_fuzz_nodes:
        merged = dict(manifest)
        merged["manualNodes"] = list(manifest.get("manualNodes", [])) + list(
            extra_fuzz_nodes
        )
        manifest = merged
    result = compare_dom_trees(
        baseline,
        candidate,
        manifest,
        dom_min_score=dom_min_score,
    )
    return result.as_dict()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="DOM tree regression gate")
    parser.add_argument("--page-id", required=True)
    parser.add_argument("--baseline", type=Path, required=True)
    parser.add_argument("--candidate", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument(
        "--defaults",
        type=Path,
        default=TOOL_ROOT / "manifests" / "defaults.json5",
    )
    parser.add_argument("--dom-min-score", type=float)
    parser.add_argument("--json-out", type=Path, help="Gate result JSON output")
    parser.add_argument("--diff-out", type=Path, help="Structured DOM diff JSON output")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    defaults = load_defaults(args.defaults)
    dom_min_score = args.dom_min_score
    if dom_min_score is None:
        dom_min_score = float(defaults.get("domMinScore", 1.0))
    compare_script_bodies = bool(defaults.get("domCompareScriptBodies", False))

    try:
        payload = run_dom_gate(
            baseline_path=args.baseline,
            candidate_path=args.candidate,
            manifest_path=args.manifest,
            dom_min_score=dom_min_score,
            compare_script_bodies=compare_script_bodies,
        )
    except (ValueError, FileNotFoundError) as exc:
        print(f"gate_dom: {exc}", file=sys.stderr)
        return 2

    if args.json_out:
        args.json_out.parent.mkdir(parents=True, exist_ok=True)
        with args.json_out.open("w", encoding="utf-8") as handle:
            json.dump(payload, handle, indent=2)
            handle.write("\n")

    if args.diff_out:
        args.diff_out.parent.mkdir(parents=True, exist_ok=True)
        with args.diff_out.open("w", encoding="utf-8") as handle:
            json.dump({"failures": payload["failures"]}, handle, indent=2)
            handle.write("\n")

    status = "PASS" if payload["passed"] else "FAIL"
    print(
        f"gate_dom [{args.page_id}]: {status} "
        f"dom_score={payload['domScore']:.4f} "
        f"failures={payload['failurePaths']}"
    )
    return 0 if payload["passed"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
