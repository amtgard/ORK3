"""Playwright capture and discover/calibrate subprocess orchestration."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from fuzzy_validator import runtime
from lib.profiles import profile_baselines_dir, profile_manifests_dir
from lib.tool_paths import pages_manifest_path


def manifest_path(tool_root: Path, profile: str, page_id: str, suffix: str) -> Path:
    return profile_manifests_dir(tool_root, profile) / f"{page_id}{suffix}"


def baseline_path(tool_root: Path, profile: str, page_id: str, suffix: str) -> Path:
    return profile_baselines_dir(tool_root, profile) / f"{page_id}{suffix}"


def run_dom_discover(page_ids: list[str], env: dict, profile: str, tool_root: Path) -> int:
    discover_script = runtime.PYTHON_DIR / "discover_dom_fuzz.py"
    for page_id in page_ids:
        cal_dir = tool_root / "calibrations" / page_id
        dom_manifest_out = manifest_path(tool_root, profile, page_id, ".dom-fuzz.json")
        dom_debug_out = tool_root / "reports" / f"{profile}-{page_id}-dom-fuzz.txt"
        dom_baseline_out = baseline_path(tool_root, profile, page_id, ".dom.json")
        discover = runtime.run_subprocess(
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
            cwd=runtime.REPO_ROOT,
            env=env,
            check=False,
        )
        if discover.returncode != 0:
            return int(discover.returncode)
    return 0


def run_pixel_discover(page_ids: list[str], env: dict, profile: str, tool_root: Path) -> int:
    discover_script = runtime.PYTHON_DIR / "discover_fuzz.py"
    for page_id in page_ids:
        cal_dir = tool_root / "calibrations" / page_id
        manifest_out = manifest_path(tool_root, profile, page_id, ".fuzz.json")
        overlay_out = tool_root / "reports" / f"{profile}-{page_id}-calibration-overlay.png"
        baseline_out = baseline_path(tool_root, profile, page_id, ".png")
        discover = runtime.run_subprocess(
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
            cwd=runtime.REPO_ROOT,
            env=env,
            check=False,
        )
        if discover.returncode != 0:
            return int(discover.returncode)
    return 0


def run_calibrate_assets(page_ids: list[str], env: dict, profile: str, tool_root: Path) -> int:
    calibrate_assets_script = runtime.PYTHON_DIR / "calibrate_assets.py"
    for page_id in page_ids:
        cal_dir = tool_root / "calibrations" / page_id
        calibrate = runtime.run_subprocess(
            [
                sys.executable,
                str(calibrate_assets_script),
                "--page-id",
                page_id,
                "--calibration-dir",
                str(cal_dir),
                "--baseline-out",
                str(baseline_path(tool_root, profile, page_id, ".assets.json")),
            ],
            cwd=runtime.REPO_ROOT,
            env=env,
            check=False,
        )
        if calibrate.returncode != 0:
            return int(calibrate.returncode)
    return 0


def _capture_env_base(
    page_ids: list[str],
    env: dict,
    args: argparse.Namespace,
    tool_root: Path,
) -> dict[str, str]:
    capture_env = env.copy()
    capture_env["FUZZ_PAGES"] = ",".join(page_ids)
    capture_env["FUZZ_TOOL_ROOT"] = str(tool_root)
    capture_env["FUZZ_PAGES_MANIFEST"] = str(pages_manifest_path(tool_root))
    runtime.apply_clock_date(capture_env, tool_root)
    if getattr(args, "repeat", None) is not None:
        capture_env["FUZZ_REPEAT"] = str(args.repeat)
    if args.base_url:
        capture_env["ORK3_E2E_BASE_URL"] = args.base_url
    return capture_env


def run_playwright_capture(
    page_ids: list[str],
    env: dict,
    args: argparse.Namespace,
    tool_root: Path,
) -> int:
    capture_env = _capture_env_base(page_ids, env, args, tool_root)
    result = runtime.run_subprocess(
        ["npx", "playwright", "test", "--project=fuzzy-capture"],
        cwd=runtime.REPO_ROOT,
        env=capture_env,
        check=False,
    )
    return int(result.returncode)


def run_candidate_captures(
    page_ids: list[str],
    env: dict,
    args: argparse.Namespace,
    tool_root: Path,
) -> int:
    """Capture one candidate per page (validate / refuzz mode)."""
    capture_env = _capture_env_base(page_ids, env, args, tool_root)
    capture_env["FUZZ_MODE"] = "candidate"
    for page_id in page_ids:
        capture_env["FUZZ_PAGES"] = page_id
        result = runtime.run_subprocess(
            ["npx", "playwright", "test", "--project=fuzzy-capture"],
            cwd=runtime.REPO_ROOT,
            env=capture_env,
            check=False,
        )
        if result.returncode != 0:
            return int(result.returncode)
    return 0


def run_capture_phase(
    args: argparse.Namespace,
    page_ids: list[str],
    env: dict,
    profile: str,
    tool_root: Path,
) -> int:
    code = run_playwright_capture(page_ids, env, args, tool_root)
    if code != 0:
        return code

    env = runtime.with_python_dir(env)

    if args.phase == "assets":
        return run_calibrate_assets(page_ids, env, profile, tool_root)

    if args.phase in {"visual", "all"}:
        code = run_pixel_discover(page_ids, env, profile, tool_root)
        if code != 0:
            return code

    if args.phase in {"visual", "all"}:
        code = run_calibrate_assets(page_ids, env, profile, tool_root)
        if code != 0:
            return code

    if args.phase in {"dom", "all"}:
        return run_dom_discover(page_ids, env, profile, tool_root)

    return 0
