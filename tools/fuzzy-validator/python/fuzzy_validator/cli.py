"""Fuzzy Validator CLI — record / validate orchestration (stubs through FU-1)."""

from __future__ import annotations

import argparse
import sys


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


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    args = parser.parse_args(argv)

    if args.command is None:
        parser.print_help()
        return 0

    if args.command == "record":
        print("fuzzy-validator record: not implemented yet (FU-1+).", file=sys.stderr)
        return 2

    if args.command == "validate":
        print("fuzzy-validator validate: not implemented yet (FU-3+).", file=sys.stderr)
        return 2

    parser.print_help()
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
