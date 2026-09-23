"""Fuzzy Validator CLI — thin dispatch over record / validate / refuzz / setpoint."""

from __future__ import annotations

from fuzzy_validator.overlay_cli import run_overlay as _run_overlay
from fuzzy_validator.parser import build_parser
from fuzzy_validator.pages import resolve_page_ids as _resolve_page_ids
from fuzzy_validator.record import run_record as _run_capture
from fuzzy_validator.refuzz import run_refuzz as _run_refuzz
from fuzzy_validator.runtime import (
    DEFAULT_TOOL_ROOT,
    TOOL_ROOT,
    activate_profile as _activate_profile,
)
from fuzzy_validator.setpoint_cli import run_setpoint as _run_setpoint
from fuzzy_validator.validate import run_validate as _run_validate

# Re-exports kept for older tests / importers.
from gate_run import finalize_multi_profile_run, finalize_run, run_batch_gate  # noqa: F401
from lib.setpoint import create_bundle, publish_bundle, resolve_bundle_path  # noqa: F401


def _build_parser():
    return build_parser()


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    if args.command is None:
        parser.print_help()
        return 0

    if args.command == "record":
        return _run_capture(args)

    if args.command == "validate":
        return _run_validate(args)

    if args.command == "refuzz":
        return _run_refuzz(args)

    if args.command == "setpoint":
        return _run_setpoint(args)

    if args.command == "overlay":
        return _run_overlay(args)

    parser.print_help()
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
