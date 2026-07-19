"""Shared runtime paths, profile activation, and subprocess helpers."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path
from typing import Any

from lib.manifest import load_defaults
from lib.profiles import get_profile, profile_auth_env
from lib.tool_paths import DEFAULT_TOOL_ROOT, defaults_path, resolve_tool_root

# Install root is always the real tool tree (scripts live here), even when
# --tool-root points at evidence or another data root.
INSTALL_ROOT = Path(__file__).resolve().parents[2]
REPO_ROOT = INSTALL_ROOT.parent.parent
PYTHON_DIR = INSTALL_ROOT / "python"

# Back-compat alias used by tests and older imports.
TOOL_ROOT = DEFAULT_TOOL_ROOT


def run_subprocess(cmd: list[str], **kwargs: Any) -> subprocess.CompletedProcess:
    """Single seam for subprocess calls so tests can patch one place."""
    return subprocess.run(cmd, **kwargs)


def with_python_dir(env: dict[str, str]) -> dict[str, str]:
    merged = env.copy()
    existing = merged.get("PYTHONPATH", "")
    merged["PYTHONPATH"] = f"{existing}:{PYTHON_DIR}" if existing else str(PYTHON_DIR)
    return merged


def apply_clock_date(capture_env: dict[str, str], tool_root: Path) -> None:
    """Pin server-rendered 'today' for fuzzy capture (EP footer stability)."""
    if capture_env.get("ORK3_CLOCK_DATE"):
        return
    defaults = load_defaults(defaults_path(tool_root))
    clock_date = defaults.get("clockDate")
    if isinstance(clock_date, str) and clock_date:
        capture_env["ORK3_CLOCK_DATE"] = clock_date


def activate_profile(
    profile_name: str,
    config: dict,
    *,
    ensure_sandbox: bool,
    env: dict[str, str],
    repo_root: Path | None = None,
) -> dict[str, str]:
    """Switch ork-db to the profile and merge auth env vars."""
    root = repo_root or REPO_ROOT
    profile = get_profile(config, profile_name)
    if profile_name == "test" and ensure_sandbox:
        deploy = run_subprocess(
            [str(root / "bin" / "ork-db"), "deploy-sandbox"],
            cwd=root,
            env=env,
            check=False,
        )
        if deploy.returncode != 0:
            print("fuzzy-validator: deploy-sandbox failed", file=sys.stderr)
            raise SystemExit(deploy.returncode)

    use = run_subprocess(
        [str(root / "bin" / "ork-db"), "use", str(profile["orkDbUse"])],
        cwd=root,
        env=env,
        check=False,
    )
    if use.returncode != 0:
        print(f"fuzzy-validator: ork-db use failed for profile '{profile_name}'", file=sys.stderr)
        raise SystemExit(use.returncode)

    merged = env.copy()
    merged.update(profile_auth_env(profile))
    return merged


__all__ = [
    "DEFAULT_TOOL_ROOT",
    "INSTALL_ROOT",
    "PYTHON_DIR",
    "REPO_ROOT",
    "TOOL_ROOT",
    "activate_profile",
    "apply_clock_date",
    "resolve_tool_root",
    "run_subprocess",
    "with_python_dir",
]
