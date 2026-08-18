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

    monkeypatch.setattr("lib.tool_paths.DEFAULT_TOOL_ROOT", tool_root)
    assert main(["setpoint", "publish", "--bundle", str(bundle)]) == 0
    data = load_setpoint(tool_root)
    assert data["latestBundle"] == bundle.name


def test_sha256_file_roundtrip(tmp_path: Path):
    path = tmp_path / "data.bin"
    path.write_bytes(b"hello setpoint")
    digest = sha256_file(path)
    assert len(digest) == 64


def test_resolve_bundle_path_explicit(tmp_path: Path):
    from lib.setpoint import resolve_bundle_path

    bundle = tmp_path / "bundle.zip"
    bundle.write_bytes(b"zip")
    assert resolve_bundle_path(tmp_path, bundle=str(bundle)) == bundle.resolve()


def test_resolve_bundle_path_missing_latest(tmp_path: Path):
    from lib.setpoint import resolve_bundle_path

    (tmp_path / "setpoint.json").write_text("{}", encoding="utf-8")
    with pytest.raises(SetpointError, match="no latestBundle"):
        resolve_bundle_path(tmp_path, use_latest=True)


def test_resolve_bundle_path_bootstrap(tmp_path: Path):
    from lib.setpoint import resolve_bundle_path

    name = "20260707T120000Z-abc12345-deadbeef01234567.zip"
    bootstrap = tmp_path / "setpoints" / "bootstrap"
    bootstrap.mkdir(parents=True)
    (bootstrap / name).write_bytes(b"zip")
    (tmp_path / "setpoint.json").write_text(
        json.dumps({"latestBundle": name}),
        encoding="utf-8",
    )
    assert resolve_bundle_path(tmp_path, use_latest=True) == bootstrap / name


def test_resolve_bundle_path_out_dir(tmp_path: Path):
    from lib.setpoint import resolve_bundle_path

    name = "bundle.zip"
    out_dir = tmp_path / "setpoints" / "out"
    out_dir.mkdir(parents=True)
    (out_dir / name).write_bytes(b"zip")
    (tmp_path / "setpoint.json").write_text(
        json.dumps({"latestBundle": name}),
        encoding="utf-8",
    )
    assert resolve_bundle_path(tmp_path, use_latest=True) == out_dir / name


def test_resolve_bundle_path_use_latest_missing(tmp_path: Path):
    from lib.setpoint import resolve_bundle_path

    (tmp_path / "setpoint.json").write_text(
        json.dumps({"latestBundle": "missing.zip", "driveFolder": "Drive"}),
        encoding="utf-8",
    )
    with pytest.raises(SetpointError, match="not found locally"):
        resolve_bundle_path(tmp_path, use_latest=True)


def test_resolve_bundle_path_requires_bundle_when_not_use_latest(tmp_path: Path):
    from lib.setpoint import resolve_bundle_path

    (tmp_path / "setpoint.json").write_text(
        json.dumps({"latestBundle": "missing.zip"}),
        encoding="utf-8",
    )
    with pytest.raises(SetpointError, match="specify --bundle"):
        resolve_bundle_path(tmp_path, use_latest=False)


def test_resolve_bundle_path_downloads(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    from lib.setpoint import resolve_bundle_path

    name = "remote.zip"
    (tmp_path / "setpoint.json").write_text(
        json.dumps({"latestBundle": name}),
        encoding="utf-8",
    )

    def fake_download(base_url: str, filename: str, dest: Path) -> Path:
        dest.write_bytes(b"downloaded")
        return dest

    monkeypatch.setattr("lib.setpoint.download_bundle", fake_download)
    path = resolve_bundle_path(tmp_path, base_url="https://example.test/folder")
    assert path.is_file()
    assert path.read_bytes() == b"downloaded"


def test_download_bundle_url_error(tmp_path: Path):
    import urllib.error

    from lib.setpoint import download_bundle

    dest = tmp_path / "out.zip"
    with patch(
        "lib.setpoint.urllib.request.urlopen",
        side_effect=urllib.error.URLError("offline"),
    ):
        with pytest.raises(SetpointError, match="failed to download"):
            download_bundle("https://example.test", "x.zip", dest)


def test_download_bundle_success(tmp_path: Path):
    from lib.setpoint import download_bundle

    class _Resp:
        def read(self):
            return b"zip-bytes"

        def __enter__(self):
            return self

        def __exit__(self, *args):
            return False

    dest = tmp_path / "out.zip"
    with patch("lib.setpoint.urllib.request.urlopen", return_value=_Resp()):
        assert download_bundle("https://example.test/dir/", "b.zip", dest) == dest
    assert dest.read_bytes() == b"zip-bytes"


def test_missing_baselines_hint_without_bootstrap(tmp_path: Path):
    (tmp_path / "setpoint.json").write_text(
        json.dumps({"latestBundle": "x.zip", "driveFolder": "Folder"}),
        encoding="utf-8",
    )
    hint = missing_baselines_hint(tmp_path)
    assert "x.zip" in hint
    assert "Folder" in hint


def test_missing_baselines_hint_no_pointer(tmp_path: Path):
    (tmp_path / "setpoint.json").write_text("{}", encoding="utf-8")
    assert "path/to/setpoint.zip" in missing_baselines_hint(tmp_path)


def test_short_git_sha_failure(tmp_path: Path):
    from lib.setpoint import short_git_sha

    with patch("lib.setpoint.subprocess.run") as run_mock:
        run_mock.return_value.returncode = 1
        run_mock.return_value.stdout = ""
        with pytest.raises(SetpointError, match="unable to read git"):
            short_git_sha(tmp_path)


def test_short_git_sha_success(tmp_path: Path):
    from lib.setpoint import short_git_sha

    with patch("lib.setpoint.subprocess.run") as run_mock:
        run_mock.return_value.returncode = 0
        run_mock.return_value.stdout = "abc1234\n"
        assert short_git_sha(tmp_path) == "abc1234"


def test_count_pages_skips_missing_profile(tmp_path: Path):
    from lib.setpoint import count_pages_in_baselines

    _write_baseline(tmp_path, "test", "home-anonymous")
    assert count_pages_in_baselines(tmp_path, ["test", "missing"]) == 1


def test_read_bundle_manifest_requires_manifest(tmp_path: Path):
    from lib.setpoint import read_bundle_manifest

    bundle = tmp_path / "empty.zip"
    with zipfile.ZipFile(bundle, "w") as archive:
        archive.writestr("readme.txt", "nope")
    with pytest.raises(SetpointError, match="missing manifest"):
        read_bundle_manifest(bundle)


def test_publish_bundle_missing_file(tmp_path: Path):
    with pytest.raises(SetpointError, match="bundle not found"):
        publish_bundle(tmp_path, tmp_path / "missing.zip")


def test_restore_bundle_missing_file(tmp_path: Path):
    with pytest.raises(SetpointError, match="bundle not found"):
        restore_bundle(tmp_path, tmp_path / "missing.zip", verify_pointer=False)


def test_verify_bundle_skips_when_no_entry(tmp_path: Path):
    bundle = tmp_path / "orphan.zip"
    bundle.write_bytes(b"zip")
    (tmp_path / "setpoint.json").write_text(
        json.dumps({"setpoints": {}}),
        encoding="utf-8",
    )
    verify_bundle_content_sha(tmp_path, bundle)
