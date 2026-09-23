"""Resolve fuzzy-validator paths for default and evidence tool roots."""

from __future__ import annotations

from pathlib import Path

DEFAULT_TOOL_ROOT = Path(__file__).resolve().parents[2]


def resolve_tool_root(path: Path | str | None = None) -> Path:
    if path is None:
        return DEFAULT_TOOL_ROOT
    return Path(path).resolve()


def pages_manifest_path(tool_root: Path) -> Path:
    root_pages = tool_root / "pages.json5"
    if root_pages.is_file():
        return root_pages
    return tool_root / "manifests" / "pages.json5"


def defaults_path(tool_root: Path) -> Path:
    local = tool_root / "manifests" / "defaults.json5"
    if local.is_file():
        return local
    return DEFAULT_TOOL_ROOT / "manifests" / "defaults.json5"


def profiles_config_path(tool_root: Path) -> Path:
    local = tool_root / "manifests" / "profiles.json5"
    if local.is_file():
        return local
    return DEFAULT_TOOL_ROOT / "manifests" / "profiles.json5"
