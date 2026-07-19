"""Refuzz command orchestration."""

from __future__ import annotations

import argparse
import os
import sys

from fuzzy_validator import capture, pages, runtime
from lib.profiles import load_profiles_config, resolve_profile_names
from lib.tool_paths import profiles_config_path


def run_refuzz(args: argparse.Namespace) -> int:
    if args.phase not in {"visual", "dom", "all"}:
        print(f"fuzzy-validator refuzz: unsupported phase '{args.phase}'", file=sys.stderr)
        return 2

    tool_root = runtime.resolve_tool_root(args.tool_root)
    page_ids = pages.resolve_refuzz_page_ids(args, tool_root)
    if not page_ids:
        print("fuzzy-validator refuzz: no pages selected", file=sys.stderr)
        return 2

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
    base_env = runtime.with_python_dir(os.environ.copy())
    refuzz_script = runtime.PYTHON_DIR / "refuzz_page.py"

    for profile_name in profile_names:
        print(f"fuzzy-validator refuzz: profile={profile_name} pages={len(page_ids)}")
        env = runtime.activate_profile(
            profile_name, config, ensure_sandbox=args.ensure_sandbox, env=base_env
        )

        for page_id in page_ids:
            code = capture.run_candidate_captures([page_id], env, args, tool_root)
            if code != 0:
                return code

            refuzz = runtime.run_subprocess(
                [
                    sys.executable,
                    str(refuzz_script),
                    "--page-id",
                    page_id,
                    "--profile",
                    profile_name,
                    "--tool-root",
                    str(tool_root),
                    "--phase",
                    args.phase,
                ],
                cwd=runtime.REPO_ROOT,
                env=env,
                check=False,
            )
            if refuzz.returncode != 0:
                return int(refuzz.returncode)

    return 0
