"""Fuzzy Validator CLI — record / validate orchestration."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path

TOOL_ROOT = Path(__file__).resolve().parents[2]
REPO_ROOT = TOOL_ROOT.parent.parent
PAGES_MANIFEST = TOOL_ROOT / "manifests" / "pages.json5"


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="fuzzy-validator",
        description="ORK3 refactor-stability harness (pixels, DOM, assets).",
    )
    subparsers = parser.add_subparsers(dest="command", metavar="COMMAND")

    record = subparsers.add_parser(
        "record",
        help="Capture stabilized renders, learn fuzz zones, write baselines",
    )
    record.add_argument("urls", nargs="*", help="Full page URLs (positional)")
    record.add_argument("--urls", metavar="FILE", help="URL list file (one per line)")
    record.add_argument("--page", metavar="ID", help="Single registry page id")
    record.add_argument("--pages", metavar="IDS", help="Comma-separated registry page ids")
    record.add_argument("--all", action="store_true", help="All non-skipped registry pages")
    record.add_argument("--phase", default="visual", choices=["visual", "assets", "dom", "all"])
    record.add_argument("--repeat", type=int, metavar="N", help="Calibration capture count")
    record.add_argument("--base-url", metavar="URL", help="Override ORK3_E2E_BASE_URL")
    record.add_argument("--dry-run", action="store_true", help="Print targets only")

    validate = subparsers.add_parser(
        "validate",
        help="Capture once, compare to baselines, pass/fail + HTML report",
    )
    validate.add_argument("urls", nargs="*", help="Full page URLs (positional)")
    validate.add_argument("--urls", metavar="FILE", help="URL list file (one per line)")
    validate.add_argument("--page", metavar="ID", help="Single registry page id")
    validate.add_argument("--pages", metavar="IDS", help="Comma-separated registry page ids")
    validate.add_argument("--all", action="store_true", help="All non-skipped registry pages")
    validate.add_argument("--phase", default="all", choices=["visual", "assets", "dom", "all"])
    validate.add_argument("--base-url", metavar="URL", help="Override ORK3_E2E_BASE_URL")
    validate.add_argument("--dry-run", action="store_true", help="Print targets only")

    return parser


def _load_registry() -> dict:
    with PAGES_MANIFEST.open(encoding="utf-8") as handle:
        return json.load(handle)


def _resolve_page_ids(args: argparse.Namespace) -> list[str]:
    if args.page:
        return [args.page]

    if args.pages:
        return [page_id.strip() for page_id in args.pages.split(",") if page_id.strip()]

    if args.all:
        registry = _load_registry()
        return [page["id"] for page in registry["pages"] if not page.get("skip")]

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

    print("fuzzy-validator record: specify --page, --pages, or --all", file=sys.stderr)
    raise SystemExit(2)


def _run_capture(args: argparse.Namespace) -> int:
    if args.phase not in {"visual", "all"}:
        print(
            f"fuzzy-validator record: phase '{args.phase}' not implemented until FU-6+",
            file=sys.stderr,
        )
        return 2

    page_ids = _resolve_page_ids(args)
    if args.dry_run:
        for page_id in page_ids:
            print(page_id)
        return 0

    env = os.environ.copy()
    env["FUZZ_PAGES"] = ",".join(page_ids)
    if args.repeat is not None:
        env["FUZZ_REPEAT"] = str(args.repeat)
    if args.base_url:
        env["ORK3_E2E_BASE_URL"] = args.base_url

    result = subprocess.run(
        ["npx", "playwright", "test", "--project=fuzzy-capture"],
        cwd=REPO_ROOT,
        env=env,
        check=False,
    )
    return int(result.returncode)


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    args = parser.parse_args(argv)

    if args.command is None:
        parser.print_help()
        return 0

    if args.command == "record":
        return _run_capture(args)

    if args.command == "validate":
        print("fuzzy-validator validate: not implemented yet (FU-3+).", file=sys.stderr)
        return 2

    parser.print_help()
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
