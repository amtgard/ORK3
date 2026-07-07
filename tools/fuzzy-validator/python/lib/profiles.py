"""Database profile configuration for dual test/mirror gate runs."""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any

from lib.manifest import load_json5
from lib.scoring import Thresholds

TOOL_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_PROFILES_PATH = TOOL_ROOT / "manifests" / "profiles.json5"


class ProfileError(ValueError):
    """Raised when profile configuration or baselines are invalid."""


def load_profiles_config(path: Path | str | None = None) -> dict[str, Any]:
    config_path = Path(path or DEFAULT_PROFILES_PATH)
    if not config_path.is_file():
        raise ProfileError(f"missing profiles config: {config_path}")
    return load_json5(config_path)


def default_profile_names(config: dict[str, Any]) -> list[str]:
    profiles = config.get("defaultProfiles")
    if profiles:
        return list(profiles)
    return list(config.get("profiles", {}).keys())


def resolve_profile_names(
    *,
    config: dict[str, Any],
    profile: str | None = None,
    profiles: str | None = None,
) -> list[str]:
    if profile:
        return [profile]
    if profiles:
        return [name.strip() for name in profiles.split(",") if name.strip()]
    return default_profile_names(config)


def get_profile(config: dict[str, Any], name: str) -> dict[str, Any]:
    profiles = config.get("profiles", {})
    if name not in profiles:
        raise ProfileError(f"unknown profile '{name}'")
    return profiles[name]


def profile_thresholds(
    config: dict[str, Any],
    profile_name: str,
    *,
    visual_min: float | None = None,
    dom_min: float | None = None,
    assets_min: float | None = None,
) -> Thresholds:
    profile = get_profile(config, profile_name)
    thresholds = profile.get("thresholds", {})
    return Thresholds(
        assets_min=assets_min
        if assets_min is not None
        else float(thresholds.get("assetsMinScore", 1.0)),
        dom_min=dom_min if dom_min is not None else float(thresholds.get("domMinScore", 1.0)),
        visual_min=visual_min
        if visual_min is not None
        else float(thresholds.get("visualMinScore", 1.0)),
    )


def profile_baselines_dir(tool_root: Path, profile_name: str) -> Path:
    return tool_root / "baselines" / profile_name


def profile_manifests_dir(tool_root: Path, profile_name: str) -> Path:
    return tool_root / "manifests" / profile_name


def assert_profile_baselines_exist(tool_root: Path, profile_name: str, page_ids: list[str]) -> None:
    baselines = profile_baselines_dir(tool_root, profile_name)
    if not baselines.is_dir():
        raise ProfileError(
            f"missing baselines directory for profile '{profile_name}': {baselines}"
        )
    missing: list[str] = []
    for page_id in page_ids:
        png = baselines / f"{page_id}.png"
        if not png.is_file():
            missing.append(str(png))
    if missing:
        raise ProfileError(
            f"profile '{profile_name}' missing baselines for: {', '.join(missing)}"
        )


def profile_auth_env(profile: dict[str, Any]) -> dict[str, str]:
    auth = profile.get("auth", {})
    env: dict[str, str] = {}
    username = auth.get("username")
    if username:
        env["ORK3_E2E_USERNAME"] = str(username)
    username_env = auth.get("usernameEnv")
    if username_env and os.environ.get(username_env):
        env["ORK3_E2E_USERNAME"] = os.environ[username_env]

    password_env = auth.get("passwordEnv")
    password_default = auth.get("passwordDefault")
    if password_env and os.environ.get(password_env):
        env["ORK3_E2E_PASSWORD"] = os.environ[password_env]
    elif password_default:
        env["ORK3_E2E_PASSWORD"] = str(password_default)
    return env


def ork_db_use_command(profile: dict[str, Any]) -> list[str]:
    ork_db_use = profile.get("orkDbUse")
    if not ork_db_use:
        raise ProfileError("profile missing orkDbUse")
    return ["bin/ork-db", "use", str(ork_db_use)]
