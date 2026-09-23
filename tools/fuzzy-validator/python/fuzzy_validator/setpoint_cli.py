"""Setpoint capture / publish / restore CLI handlers."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from fuzzy_validator import record as record_cmd
from fuzzy_validator import runtime
from lib.profiles import load_profiles_config, resolve_profile_names
from lib.setpoint import (
    SetpointError,
    create_bundle,
    missing_baselines_hint,
    publish_bundle,
    resolve_bundle_path,
    restore_bundle,
)
from lib.tool_paths import profiles_config_path


def newest_bundle_in_dir(directory: Path) -> Path | None:
    if not directory.is_dir():
        return None
    bundles = sorted(directory.glob("*.zip"), key=lambda path: path.stat().st_mtime, reverse=True)
    return bundles[0] if bundles else None


def run_setpoint_capture(args: argparse.Namespace) -> int:
    if args.dry_run:
        print("setpoint capture: would run record --all --phase all --profiles", args.profiles)
        print(f"setpoint capture: would write zip under {args.out_dir or 'setpoints/out'}")
        return 0

    record_args = argparse.Namespace(
        urls=None,
        page=None,
        pages=None,
        all=True,
        phase="all",
        repeat=None,
        base_url=args.base_url,
        dry_run=False,
        run_id=None,
        visual_min_score=None,
        dom_min_score=None,
        assets_min_score=None,
        tool_root=None,
        skip_capture=False,
        profile=args.profile,
        profiles=args.profiles,
        ensure_sandbox=args.ensure_sandbox,
    )
    code = record_cmd.run_record(record_args)
    if code != 0:
        return code

    tool_root = runtime.resolve_tool_root(None)
    out_dir = Path(args.out_dir) if args.out_dir else tool_root / "setpoints" / "out"
    config = load_profiles_config(profiles_config_path(tool_root))
    profile_names = resolve_profile_names(
        config=config,
        profile=args.profile,
        profiles=args.profiles,
    )
    try:
        bundle_path = create_bundle(
            tool_root,
            out_dir=out_dir,
            repo_root=runtime.REPO_ROOT,
            profiles=profile_names,
        )
    except SetpointError as exc:
        print(f"fuzzy-validator setpoint capture: {exc}", file=sys.stderr)
        return 2

    print(f"setpoint capture: bundle={bundle_path}")
    print("setpoint capture: upload zip to Google Drive, then run setpoint publish --bundle …")
    return 0


def run_setpoint_publish(args: argparse.Namespace) -> int:
    tool_root = runtime.resolve_tool_root(None)
    bundle_path = Path(args.bundle) if args.bundle else None
    if bundle_path is None:
        newest = newest_bundle_in_dir(tool_root / "setpoints" / "out")
        if newest is None:
            print(
                "fuzzy-validator setpoint publish: specify --bundle PATH "
                "or run setpoint capture first",
                file=sys.stderr,
            )
            return 2
        bundle_path = newest

    try:
        data = publish_bundle(tool_root, bundle_path, drive_folder=args.drive_folder)
    except SetpointError as exc:
        print(f"fuzzy-validator setpoint publish: {exc}", file=sys.stderr)
        return 2

    print(f"setpoint publish: latestBundle={data['latestBundle']}")
    print(f"setpoint publish: wrote {tool_root / 'setpoint.json'}")
    return 0


def run_setpoint_restore(args: argparse.Namespace) -> int:
    tool_root = runtime.resolve_tool_root(args.tool_root)
    try:
        bundle_path = resolve_bundle_path(
            tool_root,
            bundle=args.bundle,
            base_url=args.base_url,
            use_latest=not args.bundle and not args.base_url,
        )
        extracted = restore_bundle(
            tool_root,
            bundle_path,
            verify_pointer=not args.no_verify,
        )
    except SetpointError as exc:
        print(f"fuzzy-validator setpoint restore: {exc}", file=sys.stderr)
        print(f"fuzzy-validator: {missing_baselines_hint(tool_root)}", file=sys.stderr)
        return 2

    print(f"setpoint restore: bundle={bundle_path.name} files={len(extracted)}")
    return 0


def run_setpoint(args: argparse.Namespace) -> int:
    if args.setpoint_command == "capture":
        return run_setpoint_capture(args)
    if args.setpoint_command == "publish":
        return run_setpoint_publish(args)
    if args.setpoint_command == "restore":
        return run_setpoint_restore(args)
    print("fuzzy-validator setpoint: specify capture, publish, or restore", file=sys.stderr)
    return 2
