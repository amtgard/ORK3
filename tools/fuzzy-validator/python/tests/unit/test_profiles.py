"""Unit tests for lib/profiles.py."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from lib.profiles import (
    ProfileError,
    assert_profile_baselines_exist,
    load_profiles_config,
    profile_auth_env,
    profile_thresholds,
    resolve_profile_names,
)


def _write_profiles(path: Path) -> None:
    path.write_text(
        json.dumps(
            {
                "profiles": {
                    "test": {
                        "orkDbUse": "dev",
                        "label": "Sandbox",
                        "thresholds": {
                            "assetsMinScore": 1.0,
                            "domMinScore": 1.0,
                            "visualMinScore": 1.0,
                        },
                        "auth": {
                            "username": "megiddo",
                            "passwordDefault": "test-db-player",
                        },
                    },
                    "mirror": {
                        "orkDbUse": "prod",
                        "label": "Mirror",
                        "thresholds": {
                            "assetsMinScore": 1.0,
                            "domMinScore": 0.99,
                            "visualMinScore": 0.98,
                        },
                        "auth": {"usernameEnv": "ORK3_E2E_USERNAME"},
                    },
                },
                "defaultProfiles": ["test", "mirror"],
            }
        ),
        encoding="utf-8",
    )


def test_resolve_profile_names_defaults(tmp_path: Path):
    config_path = tmp_path / "profiles.json5"
    _write_profiles(config_path)
    config = load_profiles_config(config_path)
    assert resolve_profile_names(config=config, profile=None, profiles=None) == [
        "test",
        "mirror",
    ]


def test_profile_thresholds_tiers(tmp_path: Path):
    config_path = tmp_path / "profiles.json5"
    _write_profiles(config_path)
    config = load_profiles_config(config_path)
    test = profile_thresholds(config, "test")
    mirror = profile_thresholds(config, "mirror")
    assert test.visual_min == 1.0
    assert mirror.visual_min == 0.98


def test_assert_profile_baselines_exist(tmp_path: Path):
    baselines = tmp_path / "baselines" / "test"
    baselines.mkdir(parents=True)
    (baselines / "home-anonymous.png").write_bytes(b"png")
    assert_profile_baselines_exist(tmp_path, "test", ["home-anonymous"])


def test_assert_profile_baselines_missing_dir(tmp_path: Path):
    with pytest.raises(ProfileError, match="missing baselines directory"):
        assert_profile_baselines_exist(tmp_path, "mirror", ["home-anonymous"])


def test_profile_auth_env_defaults():
    profile = {"auth": {"username": "megiddo", "passwordDefault": "secret"}}
    env = profile_auth_env(profile)
    assert env["ORK3_E2E_USERNAME"] == "megiddo"
    assert env["ORK3_E2E_PASSWORD"] == "secret"
