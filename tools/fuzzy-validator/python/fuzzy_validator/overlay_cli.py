"""Overlay validate / summarize CLI handlers."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from lib.drift_overlay import OverlayError, load_overlay, merge_overlays


def run_overlay(args: argparse.Namespace) -> int:
    if args.overlay_command is None:
        print("fuzzy-validator overlay: specify validate or summarize", file=sys.stderr)
        return 2

    paths = [Path(part) for part in args.paths]
    try:
        overlays = [load_overlay(path) for path in paths]
        merged = merge_overlays(overlays)
    except OverlayError as exc:
        print(f"fuzzy-validator overlay: {exc}", file=sys.stderr)
        return 2

    if args.overlay_command == "validate":
        print(
            f"overlay validate: ok overlays={len(merged.overlays)} "
            f"entries={len(merged.entries)}"
        )
        return 0

    if args.overlay_command == "summarize":
        summary = merged.summarize()
        print(json.dumps(summary, indent=2))
        return 0

    print(f"fuzzy-validator overlay: unknown action {args.overlay_command}", file=sys.stderr)
    return 2
