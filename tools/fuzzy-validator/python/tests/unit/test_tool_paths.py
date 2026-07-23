"""Tests for tool_paths helpers."""

from pathlib import Path

from lib.tool_paths import (
    defaults_path,
    pages_manifest_path,
    profiles_config_path,
    resolve_tool_root,
)


def test_resolve_tool_root_default():
    root = resolve_tool_root(None)
    assert (root / "python").is_dir()


def test_pages_manifest_prefers_root_pages_json5(tmp_path: Path):
    tool_root = tmp_path / "evidence"
    tool_root.mkdir()
    (tool_root / "pages.json5").write_text("{}", encoding="utf-8")
    assert pages_manifest_path(tool_root) == tool_root / "pages.json5"


def test_pages_manifest_falls_back_to_manifests(tmp_path: Path):
    tool_root = tmp_path / "tool"
    (tool_root / "manifests").mkdir(parents=True)
    (tool_root / "manifests" / "pages.json5").write_text("{}", encoding="utf-8")
    assert pages_manifest_path(tool_root) == tool_root / "manifests" / "pages.json5"


def test_defaults_and_profiles_fallback_to_parent(tmp_path: Path, monkeypatch):
    import lib.tool_paths as module

    parent = tmp_path / "parent"
    evidence = tmp_path / "evidence"
    (parent / "manifests").mkdir(parents=True)
    (parent / "manifests" / "defaults.json5").write_text("{}", encoding="utf-8")
    (parent / "manifests" / "profiles.json5").write_text("{}", encoding="utf-8")
    monkeypatch.setattr(module, "DEFAULT_TOOL_ROOT", parent)
    assert defaults_path(evidence) == parent / "manifests" / "defaults.json5"
    assert profiles_config_path(evidence) == parent / "manifests" / "profiles.json5"
