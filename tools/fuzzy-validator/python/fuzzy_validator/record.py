"""Record (capture + discover) command orchestration."""

from __future__ import annotations

import argparse
import os
import sys

from fuzzy_validator import capture, pages, runtime
from lib.profiles import load_profiles_config, resolve_profile_names
from lib.tool_paths import profiles_config_path


def run_record(args: argparse.Namespace) -> int:
    if args.phase not in {"visual", "assets", "dom", "all"}:
        print(
            f"fuzzy-validator record: unsupported phase '{args.phase}'",
            file=sys.stderr,
        )
        return 2

    tool_root = runtime.resolve_tool_root(args.tool_root)
    page_ids = pages.resolve_page_ids(args, tool_root)
    if args.dry_run:
        config = load_profiles_config(profiles_config_path(tool_root))
        for profile_name in resolve_profile_names(
            config=config,
            profile=args.profile,
            profiles=args.profiles,
        ):
            for page_id in page_ids:
                print(f"{profile_name}:{page_id}")
        return 0

    config = load_profiles_config(profiles_config_path(tool_root))
    profile_names = resolve_profile_names(
        config=config,
        profile=args.profile,
        profiles=args.profiles,
    )
    base_env = os.environ.copy()

    for profile_name in profile_names:
        print(f"fuzzy-validator record: profile={profile_name} tool-root={tool_root}")
        env = runtime.activate_profile(
            profile_name, config, ensure_sandbox=args.ensure_sandbox, env=base_env
        )
        code = capture.run_capture_phase(args, page_ids, env, profile_name, tool_root)
        if code != 0:
            return code

    return 0
