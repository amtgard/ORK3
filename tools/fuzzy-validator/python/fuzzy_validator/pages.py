"""Page registry loading and CLI page-id resolution."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from lib.page_registry import (
    active_page_ids,
    assert_valid_pages_registry,
    load_pages_registry,
    page_ids_by_drift_class,
)
from lib.tool_paths import pages_manifest_path


def load_registry(tool_root: Path) -> dict:
    registry = load_pages_registry(pages_manifest_path(tool_root))
    assert_valid_pages_registry(registry)
    return registry


def resolve_page_ids(args: argparse.Namespace, tool_root: Path) -> list[str]:
    if args.page:
        return [args.page]

    if args.pages:
        return [page_id.strip() for page_id in args.pages.split(",") if page_id.strip()]

    if args.all:
        registry = load_registry(tool_root)
        return active_page_ids(registry)

    if args.urls:
        page_ids: list[str] = []
        with open(args.urls, encoding="utf-8") as handle:
            for line in handle:
                stripped = line.strip()
                if not stripped or stripped.startswith("#"):
                    continue
                if stripped.startswith("page:"):
                    page_ids.append(stripped.removeprefix("page:").strip())
        if page_ids:
            return page_ids

    if args.urls is not None:
        return []

    print("fuzzy-validator: specify --page, --pages, or --all", file=sys.stderr)
    raise SystemExit(2)


def resolve_refuzz_page_ids(args: argparse.Namespace, tool_root: Path) -> list[str]:
    if getattr(args, "natural", False):
        registry = load_registry(tool_root)
        return page_ids_by_drift_class(registry, "natural")

    return resolve_page_ids(args, tool_root)
