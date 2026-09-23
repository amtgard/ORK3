"""Validate command orchestration."""

from __future__ import annotations

import argparse
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

from gate_run import finalize_multi_profile_run, finalize_run, run_batch_gate
from fuzzy_validator import capture, pages, runtime
from lib.annotations import write_annotations_placeholder
from lib.drift_overlay import OverlayError, load_overlays_from_flags
from lib.manifest import load_defaults
from lib.mirror_freshness import MirrorFreshnessError, require_fresh_mirror
from lib.profiles import (
    ProfileError,
    assert_profile_baselines_exist,
    get_profile,
    load_profiles_config,
    profile_thresholds,
    resolve_profile_names,
)
from lib.setpoint import missing_baselines_hint
from lib.tool_paths import defaults_path, profiles_config_path


def threshold_overrides(args: argparse.Namespace) -> dict[str, float | None]:
    return {
        "visual_min": args.visual_min_score,
        "dom_min": args.dom_min_score,
        "assets_min": args.assets_min_score,
    }


def _overlay_cli_args(args: argparse.Namespace) -> str:
    parts: list[str] = []
    if getattr(args, "overlay", None):
        parts.append(f"--overlay {args.overlay}")
    if getattr(args, "overlay_dir", None):
        parts.append(f"--overlay-dir {args.overlay_dir}")
    if getattr(args, "putative", False):
        parts.append("--putative")
    return " ".join(parts)


def run_validate(args: argparse.Namespace) -> int:
    tool_root = runtime.resolve_tool_root(args.tool_root)
    page_ids = pages.resolve_page_ids(args, tool_root)
    config = load_profiles_config(profiles_config_path(tool_root))
    profile_names = resolve_profile_names(
        config=config,
        profile=args.profile,
        profiles=args.profiles,
    )

    mirror_age_days: float | None = None
    mirror_override: str | None = getattr(args, "mirror_stale_ok", None)
    if getattr(args, "require_fresh_mirror", False):
        try:
            freshness = require_fresh_mirror(
                repo_root=runtime.REPO_ROOT,
                override_reason=mirror_override,
            )
            mirror_age_days = freshness.age_days
            if freshness.stale and mirror_override:
                print(
                    f"fuzzy-validator: mirror stale ({freshness.age_days:.1f}d) "
                    f"override={mirror_override!r}",
                    file=sys.stderr,
                )
        except MirrorFreshnessError as exc:
            print(f"fuzzy-validator: {exc}", file=sys.stderr)
            return 2

    try:
        overlays = load_overlays_from_flags(
            tool_root,
            overlay=getattr(args, "overlay", None),
            overlay_dir=getattr(args, "overlay_dir", None),
            putative=bool(getattr(args, "putative", False)),
        )
    except OverlayError as exc:
        print(f"fuzzy-validator: {exc}", file=sys.stderr)
        return 2

    run_id = args.run_id or datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    annotations_path = Path(args.annotations) if getattr(args, "annotations", None) else None
    if getattr(args, "annotations_out", None):
        write_annotations_placeholder(Path(args.annotations_out), run_id=run_id)

    if args.dry_run:
        for profile_name in profile_names:
            for page_id in page_ids:
                print(f"{profile_name}:{page_id}")
        if overlays:
            print(f"overlays={len(overlays.entries)} entries")
        return 0

    run_dir = tool_root / "reports" / f"run-{run_id}"
    base_env = runtime.with_python_dir(os.environ.copy())
    defaults = load_defaults(defaults_path(tool_root))
    overlay_cli = _overlay_cli_args(args)

    overall_exit = 0
    profile_runs: list[dict] = []

    for profile_name in profile_names:
        try:
            assert_profile_baselines_exist(tool_root, profile_name, page_ids)
        except ProfileError as exc:
            print(f"fuzzy-validator: {exc}", file=sys.stderr)
            print(f"fuzzy-validator: {missing_baselines_hint(tool_root)}", file=sys.stderr)
            return 2

        print(f"fuzzy-validator validate: profile={profile_name} tool-root={tool_root}")
        env = runtime.activate_profile(
            profile_name, config, ensure_sandbox=args.ensure_sandbox, env=base_env
        )

        if not args.skip_capture:
            code = capture.run_candidate_captures(page_ids, env, args, tool_root)
            if code != 0:
                return code

        thresholds = profile_thresholds(config, profile_name, **threshold_overrides(args))
        page_results, exit_code = run_batch_gate(
            page_ids=page_ids,
            phase=args.phase,
            tool_root=tool_root,
            defaults=defaults,
            run_dir=run_dir,
            profile=profile_name,
            thresholds=thresholds,
            overlays=overlays,
            overlay_cli_args=overlay_cli,
        )
        if exit_code == 2:
            return 2
        if exit_code != 0:
            overall_exit = 1

        profile = get_profile(config, profile_name)
        profile_runs.append(
            {
                "profile": profile_name,
                "label": profile.get("label", profile_name),
                "page_results": page_results,
                "thresholds": thresholds,
            }
        )

    if len(profile_names) == 1:
        entry = profile_runs[0]
        finalize_run(
            run_dir=run_dir,
            phase=args.phase,
            page_results=entry["page_results"],
            exit_code=overall_exit,
            thresholds=entry["thresholds"],
            profile=profile_names[0],
            overlays=overlays,
            annotations_path=annotations_path,
            mirror_age_days=mirror_age_days,
            mirror_override_reason=mirror_override,
        )
    else:
        finalize_multi_profile_run(
            run_dir=run_dir,
            phase=args.phase,
            profile_runs=profile_runs,
            exit_code=overall_exit,
            profiles=profile_names,
            overlays=overlays,
            annotations_path=annotations_path,
            mirror_age_days=mirror_age_days,
            mirror_override_reason=mirror_override,
        )

    return overall_exit
