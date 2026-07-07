"""Setpoint bundle capture, publish, and restore tests."""

from __future__ import annotations

import json
import zipfile
from pathlib import Path
from unittest.mock import patch

import pytest

from fuzzy_validator.cli import main
from lib.setpoint import (
    SetpointError,
    create_bundle,
    load_setpoint,
    missing_baselines_hint,
    publish_bundle,
    restore_bundle,
    sha256_file,
    verify_bundle_content_sha,
)


def _write_baseline(tool_root: Path, profile: str, page_id: str) -> None:
    path = tool_root / "baselines" / profile / f"{page_id}.png"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(b"\x89PNG\r\n\x1a\n" + page_id.encode())


def test_create_bundle_and_publish(tmp_path: Path):
    tool_root = tmp_path / "tool"
    repo_root = tmp_path / "repo"
    repo_root.mkdir()
    _write_baseline(tool_root, "test", "home-anonymous")
    _write_baseline(tool_root, "mirror", "home-anonymous")

    with patch("lib.setpoint.short_git_sha", return_value="abc12345"):
        bundle = create_bundle(
            tool_root,
            out_dir=tool_root / "setpoints" / "out",
            repo_root=repo_root,
            git_sha="abc12345",
            captured_at="2026-07-07T12:00:00Z",
            profiles=["test", "mirror"],
        )

    assert bundle.name.startswith("20260707T120000Z-abc12345-")
    assert bundle.suffix == ".zip"
    with zipfile.ZipFile(bundle) as archive:
        names = archive.namelist()
        assert "manifest.json" in names
        assert "baselines/test/home-anonymous.png" in names

    data = publish_bundle(tool_root, bundle)
    assert data["latestBundle"] == bundle.name
    assert bundle.name in data["setpoints"]
    assert (tool_root / "setpoint.json").is_file()


def test_restore_bundle_extracts_baselines(tmp_path: Path):
    tool_root = tmp_path / "tool"
    repo_root = tmp_path / "repo"
    repo_root.mkdir()
    _write_baseline(tool_root, "test", "player-profile")

    with patch("lib.setpoint.short_git_sha", return_value="deadbeef"):
        bundle = create_bundle(
            tool_root,
            out_dir=tool_root / "out",
            repo_root=repo_root,
            git_sha="deadbeef",
            profiles=["test"],
        )
    publish_bundle(tool_root, bundle)

    for path in tool_root.rglob("*.png"):
        path.unlink()

    extracted = restore_bundle(tool_root, bundle)
    assert "baselines/test/player-profile.png" in extracted
    assert (tool_root / "baselines/test/player-profile.png").is_file()


def test_verify_bundle_content_sha_mismatch(tmp_path: Path):
    tool_root = tmp_path / "tool"
    repo_root = tmp_path / "repo"
    repo_root.mkdir()
    _write_baseline(tool_root, "test", "home-anonymous")

    with patch("lib.setpoint.short_git_sha", return_value="abc12345"):
        bundle = create_bundle(
            tool_root,
            out_dir=tool_root / "out",
            repo_root=repo_root,
            git_sha="abc12345",
            profiles=["test"],
        )
    publish_bundle(tool_root, bundle)

    bundle.write_bytes(bundle.read_bytes() + b"x")
    with pytest.raises(SetpointError, match="sha256 mismatch"):
        verify_bundle_content_sha(tool_root, bundle)


def test_create_bundle_requires_files(tmp_path: Path):
    tool_root = tmp_path / "tool"
    tool_root.mkdir()
    repo_root = tmp_path / "repo"
    repo_root.mkdir()
    with pytest.raises(SetpointError, match="no baseline files"):
        create_bundle(tool_root, out_dir=tool_root / "out", repo_root=repo_root)


def test_missing_baselines_hint_with_bootstrap(tmp_path: Path):
    tool_root = tmp_path / "tool"
    bootstrap = tool_root / "setpoints" / "bootstrap"
    bootstrap.mkdir(parents=True)
    bundle_name = "20260707T120000Z-abc12345-deadbeef01234567.zip"
    (bootstrap / bundle_name).write_bytes(b"zip")
    (tool_root / "setpoint.json").write_text(
        json.dumps({"latestBundle": bundle_name}),
        encoding="utf-8",
    )
    hint = missing_baselines_hint(tool_root)
    assert bundle_name in hint
    assert "setpoint restore" in hint


def test_setpoint_restore_cli(tmp_path: Path):
    tool_root = tmp_path / "tool"
    repo_root = tmp_path / "repo"
    repo_root.mkdir()
    _write_baseline(tool_root, "test", "home-anonymous")

    with patch("lib.setpoint.short_git_sha", return_value="abc12345"):
        bundle = create_bundle(
            tool_root,
            out_dir=tool_root / "setpoints" / "bootstrap",
            repo_root=repo_root,
            git_sha="abc12345",
            profiles=["test"],
        )
    publish_bundle(tool_root, bundle)

    for path in tool_root.rglob("*.png"):
        path.unlink()

    assert (
        main(["setpoint", "restore", "--tool-root", str(tool_root), "--no-verify"])
        == 0
    )
    assert (tool_root / "baselines/test/home-anonymous.png").is_file()


def test_setpoint_publish_cli(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    tool_root = tmp_path / "tool"
    repo_root = tmp_path / "repo"
    repo_root.mkdir()
    _write_baseline(tool_root, "test", "home-anonymous")

    with patch("lib.setpoint.short_git_sha", return_value="abc12345"):
        bundle = create_bundle(
            tool_root,
            out_dir=tool_root / "setpoints" / "out",
            repo_root=repo_root,
            git_sha="abc12345",
            profiles=["test"],
        )

    monkeypatch.setattr("fuzzy_validator.cli.DEFAULT_TOOL_ROOT", tool_root)
    assert main(["setpoint", "publish", "--bundle", str(bundle)]) == 0
    data = load_setpoint(tool_root)
    assert data["latestBundle"] == bundle.name


def test_sha256_file_roundtrip(tmp_path: Path):
    path = tmp_path / "data.bin"
    path.write_bytes(b"hello setpoint")
    digest = sha256_file(path)
    assert len(digest) == 64
