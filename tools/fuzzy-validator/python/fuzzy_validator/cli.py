"""Fuzzy Validator CLI — record / validate orchestration."""

from __future__ import annotations

import argparse
import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

from gate_run import finalize_multi_profile_run, finalize_run, run_batch_gate
from lib.manifest import load_defaults
from lib.page_registry import active_page_ids, assert_valid_pages_registry, load_pages_registry
from lib.tool_paths import (
    DEFAULT_TOOL_ROOT,
    defaults_path,
    pages_manifest_path,
    profiles_config_path,
    resolve_tool_root,
)
from lib.profiles import (
    ProfileError,
    assert_profile_baselines_exist,
    get_profile,
    load_profiles_config,
    profile_auth_env,
    profile_baselines_dir,
    profile_manifests_dir,
    profile_thresholds,
    resolve_profile_names,
)

TOOL_ROOT = DEFAULT_TOOL_ROOT
REPO_ROOT = TOOL_ROOT.parent.parent
PYTHON_DIR = TOOL_ROOT / "python"


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="fuzzy-validator",
        description="ORK3 refactor-stability harness (pixels, DOM, assets).",
    )
    subparsers = parser.add_subparsers(dest="command", metavar="COMMAND")

    shared = argparse.ArgumentParser(add_help=False)
    shared.add_argument("--profile", metavar="NAME", help="Single profile: test or mirror")
    shared.add_argument(
        "--profiles",
        metavar="LIST",
        default="test,mirror",
        help="Comma-separated profiles (default: test,mirror)",
    )
    shared.add_argument(
        "--ensure-sandbox",
        action="store_true",
        help="Run bin/ork-db deploy-sandbox before test profile",
    )
    shared.add_argument("--base-url", metavar="URL", help="Override ORK3_E2E_BASE_URL")
    shared.add_argument("--dry-run", action="store_true", help="Print targets only")
    shared.add_argument("--run-id", metavar="ID", help="Report directory id")
    shared.add_argument("--visual-min-score", type=float, metavar="SCORE")
    shared.add_argument("--dom-min-score", type=float, metavar="SCORE")
    shared.add_argument("--assets-min-score", type=float, metavar="SCORE")
    shared.add_argument(
        "--tool-root",
        metavar="PATH",
        help="Alternate tool root (e.g. tools/fuzzy-validator/evidence)",
    )
    shared.add_argument(
        "--skip-capture",
        action="store_true",
        help="Validate using existing candidate files in calibrations/ (evidence suite)",
    )

    record = subparsers.add_parser(
        "record",
        parents=[shared],
        help="Capture stabilized renders, learn fuzz zones, write baselines",
    )
    record.add_argument("urls", nargs="*", help="Full page URLs (positional)")
    record.add_argument("--urls", metavar="FILE", help="URL list file (one per line)")
    record.add_argument("--page", metavar="ID", help="Single registry page id")
    record.add_argument("--pages", metavar="IDS", help="Comma-separated registry page ids")
    record.add_argument("--all", action="store_true", help="All non-skipped registry pages")
    record.add_argument("--phase", default="visual", choices=["visual", "assets", "dom", "all"])
    record.add_argument("--repeat", type=int, metavar="N", help="Calibration capture count")

    validate = subparsers.add_parser(
        "validate",
        parents=[shared],
        help="Capture once, compare to baselines, pass/fail + HTML report",
    )
    validate.add_argument("urls", nargs="*", help="Full page URLs (positional)")
    validate.add_argument("--urls", metavar="FILE", help="URL list file (one per line)")
    validate.add_argument("--page", metavar="ID", help="Single registry page id")
    validate.add_argument("--pages", metavar="IDS", help="Comma-separated registry page ids")
    validate.add_argument("--all", action="store_true", help="All non-skipped registry pages")
    validate.add_argument("--phase", default="all", choices=["visual", "assets", "dom", "all"])

    return parser


def _load_registry(tool_root: Path) -> dict:
    registry = load_pages_registry(pages_manifest_path(tool_root))
    assert_valid_pages_registry(registry)
    return registry


def _resolve_page_ids(args: argparse.Namespace, tool_root: Path) -> list[str]:
    if args.page:
        return [args.page]

    if args.pages:
        return [page_id.strip() for page_id in args.pages.split(",") if page_id.strip()]

    if args.all:
        registry = _load_registry(tool_root)
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


def _activate_profile(
    profile_name: str,
    config: dict,
    *,
    ensure_sandbox: bool,
    env: dict[str, str],
) -> dict[str, str]:
    profile = get_profile(config, profile_name)
    if profile_name == "test" and ensure_sandbox:
        deploy = subprocess.run(
            [str(REPO_ROOT / "bin" / "ork-db"), "deploy-sandbox"],
            cwd=REPO_ROOT,
            env=env,
            check=False,
        )
        if deploy.returncode != 0:
            print("fuzzy-validator: deploy-sandbox failed", file=sys.stderr)
            raise SystemExit(deploy.returncode)

    use = subprocess.run(
        [str(REPO_ROOT / "bin" / "ork-db"), "use", str(profile["orkDbUse"])],
        cwd=REPO_ROOT,
        env=env,
        check=False,
    )
    if use.returncode != 0:
        print(f"fuzzy-validator: ork-db use failed for profile '{profile_name}'", file=sys.stderr)
        raise SystemExit(use.returncode)

    merged = env.copy()
    merged.update(profile_auth_env(profile))
    return merged


def _manifest_path(tool_root: Path, profile: str, page_id: str, suffix: str) -> Path:
    return profile_manifests_dir(tool_root, profile) / f"{page_id}{suffix}"


def _baseline_path(tool_root: Path, profile: str, page_id: str, suffix: str) -> Path:
    return profile_baselines_dir(tool_root, profile) / f"{page_id}{suffix}"


def _run_dom_discover(page_ids: list[str], env: dict, profile: str, tool_root: Path) -> int:
    discover_script = PYTHON_DIR / "discover_dom_fuzz.py"
    for page_id in page_ids:
        cal_dir = tool_root / "calibrations" / page_id
        dom_manifest_out = _manifest_path(tool_root, profile, page_id, ".dom-fuzz.json")
        dom_debug_out = tool_root / "reports" / f"{profile}-{page_id}-dom-fuzz.txt"
        dom_baseline_out = _baseline_path(tool_root, profile, page_id, ".dom.json")
        discover = subprocess.run(
            [
                sys.executable,
                str(discover_script),
                "--page-id",
                page_id,
                "--calibration-dir",
                str(cal_dir),
                "--out",
                str(dom_manifest_out),
                "--debug-out",
                str(dom_debug_out),
                "--baseline-out",
                str(dom_baseline_out),
            ],
            cwd=REPO_ROOT,
            env=env,
            check=False,
        )
        if discover.returncode != 0:
            return int(discover.returncode)
    return 0


def _run_pixel_discover(page_ids: list[str], env: dict, profile: str, tool_root: Path) -> int:
    discover_script = PYTHON_DIR / "discover_fuzz.py"
    for page_id in page_ids:
        cal_dir = tool_root / "calibrations" / page_id
        manifest_out = _manifest_path(tool_root, profile, page_id, ".fuzz.json")
        overlay_out = tool_root / "reports" / f"{profile}-{page_id}-calibration-overlay.png"
        baseline_out = _baseline_path(tool_root, profile, page_id, ".png")
        discover = subprocess.run(
            [
                sys.executable,
                str(discover_script),
                "--page-id",
                page_id,
                "--calibration-dir",
                str(cal_dir),
                "--out",
                str(manifest_out),
                "--overlay",
                str(overlay_out),
                "--baseline-out",
                str(baseline_out),
            ],
            cwd=REPO_ROOT,
            env=env,
            check=False,
        )
        if discover.returncode != 0:
            return int(discover.returncode)
    return 0


def _run_playwright_capture(
    page_ids: list[str],
    env: dict,
    args: argparse.Namespace,
    tool_root: Path,
) -> int:
    capture_env = env.copy()
    capture_env["FUZZ_PAGES"] = ",".join(page_ids)
    capture_env["FUZZ_TOOL_ROOT"] = str(tool_root)
    capture_env["FUZZ_PAGES_MANIFEST"] = str(pages_manifest_path(tool_root))
    if args.repeat is not None:
        capture_env["FUZZ_REPEAT"] = str(args.repeat)
    if args.base_url:
        capture_env["ORK3_E2E_BASE_URL"] = args.base_url

    result = subprocess.run(
        ["npx", "playwright", "test", "--project=fuzzy-capture"],
        cwd=REPO_ROOT,
        env=capture_env,
        check=False,
    )
    return int(result.returncode)


def _run_calibrate_assets(page_ids: list[str], env: dict, profile: str, tool_root: Path) -> int:
    calibrate_assets_script = PYTHON_DIR / "calibrate_assets.py"
    for page_id in page_ids:
        cal_dir = tool_root / "calibrations" / page_id
        calibrate = subprocess.run(
            [
                sys.executable,
                str(calibrate_assets_script),
                "--page-id",
                page_id,
                "--calibration-dir",
                str(cal_dir),
                "--baseline-out",
                str(_baseline_path(tool_root, profile, page_id, ".assets.json")),
            ],
            cwd=REPO_ROOT,
            env=env,
            check=False,
        )
        if calibrate.returncode != 0:
            return int(calibrate.returncode)
    return 0


def _run_capture_phase(
    args: argparse.Namespace,
    page_ids: list[str],
    env: dict,
    profile: str,
    tool_root: Path,
) -> int:
    code = _run_playwright_capture(page_ids, env, args, tool_root)
    if code != 0:
        return code

    env["PYTHONPATH"] = f"{env.get('PYTHONPATH', '')}:{PYTHON_DIR}"

    if args.phase == "assets":
        return _run_calibrate_assets(page_ids, env, profile, tool_root)

    if args.phase in {"visual", "all"}:
        code = _run_pixel_discover(page_ids, env, profile, tool_root)
        if code != 0:
            return code

    if args.phase in {"visual", "all"}:
        code = _run_calibrate_assets(page_ids, env, profile, tool_root)
        if code != 0:
            return code

    if args.phase in {"dom", "all"}:
        return _run_dom_discover(page_ids, env, profile, tool_root)

    return 0


def _run_capture(args: argparse.Namespace) -> int:
    if args.phase not in {"visual", "assets", "dom", "all"}:
        print(
            f"fuzzy-validator record: unsupported phase '{args.phase}'",
            file=sys.stderr,
        )
        return 2

    tool_root = resolve_tool_root(args.tool_root)
    page_ids = _resolve_page_ids(args, tool_root)
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
        env = _activate_profile(profile_name, config, ensure_sandbox=args.ensure_sandbox, env=base_env)
        code = _run_capture_phase(args, page_ids, env, profile_name, tool_root)
        if code != 0:
            return code

    return 0


def _threshold_overrides(args: argparse.Namespace) -> dict[str, float | None]:
    return {
        "visual_min": args.visual_min_score,
        "dom_min": args.dom_min_score,
        "assets_min": args.assets_min_score,
    }


def _run_validate(args: argparse.Namespace) -> int:
    tool_root = resolve_tool_root(args.tool_root)
    page_ids = _resolve_page_ids(args, tool_root)
    config = load_profiles_config(profiles_config_path(tool_root))
    profile_names = resolve_profile_names(
        config=config,
        profile=args.profile,
        profiles=args.profiles,
    )

    if args.dry_run:
        for profile_name in profile_names:
            for page_id in page_ids:
                print(f"{profile_name}:{page_id}")
        return 0

    run_id = args.run_id or datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    run_dir = tool_root / "reports" / f"run-{run_id}"
    base_env = os.environ.copy()
    base_env["PYTHONPATH"] = f"{base_env.get('PYTHONPATH', '')}:{PYTHON_DIR}"
    defaults = load_defaults(defaults_path(tool_root))
    overrides = _threshold_overrides(args)

    overall_exit = 0
    profile_runs: list[dict] = []

    for profile_name in profile_names:
        try:
            assert_profile_baselines_exist(tool_root, profile_name, page_ids)
        except ProfileError as exc:
            print(f"fuzzy-validator: {exc}", file=sys.stderr)
            return 2

        print(f"fuzzy-validator validate: profile={profile_name} tool-root={tool_root}")
        env = _activate_profile(profile_name, config, ensure_sandbox=args.ensure_sandbox, env=base_env)

        if not args.skip_capture:
            capture_env = env.copy()
            capture_env["FUZZ_MODE"] = "candidate"
            capture_env["FUZZ_TOOL_ROOT"] = str(tool_root)
            capture_env["FUZZ_PAGES_MANIFEST"] = str(pages_manifest_path(tool_root))
            for page_id in page_ids:
                capture_env["FUZZ_PAGES"] = page_id
                capture = subprocess.run(
                    ["npx", "playwright", "test", "--project=fuzzy-capture"],
                    cwd=REPO_ROOT,
                    env=capture_env,
                    check=False,
                )
                if capture.returncode != 0:
                    return int(capture.returncode)

        thresholds = profile_thresholds(config, profile_name, **_threshold_overrides(args))
        page_results, exit_code = run_batch_gate(
            page_ids=page_ids,
            phase=args.phase,
            tool_root=tool_root,
            defaults=defaults,
            run_dir=run_dir,
            profile=profile_name,
            thresholds=thresholds,
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
        )
    else:
        finalize_multi_profile_run(
            run_dir=run_dir,
            phase=args.phase,
            profile_runs=profile_runs,
            exit_code=overall_exit,
            profiles=profile_names,
        )

    return overall_exit


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    args = parser.parse_args(argv)

    if args.command is None:
        parser.print_help()
        return 0

    if args.command == "record":
        return _run_capture(args)

    if args.command == "validate":
        return _run_validate(args)

    parser.print_help()
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
