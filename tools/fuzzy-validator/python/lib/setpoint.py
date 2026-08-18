"""Setpoint bundle capture, publish, and restore for fuzzy-validator baselines."""

from __future__ import annotations

import hashlib
import json
import subprocess
import urllib.error
import urllib.request
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

SCHEMA_VERSION = 1
DRIVE_FOLDER_HINT = "ORK3 Fuzzy Setpoints"


class SetpointError(RuntimeError):
    """Raised when setpoint bundle operations fail."""


def short_git_sha(repo_root: Path) -> str:
    result = subprocess.run(
        ["git", "rev-parse", "--short", "HEAD"],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        raise SetpointError("unable to read git commit; run from a git checkout")
    return result.stdout.strip()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def collect_baseline_files(tool_root: Path) -> list[Path]:
    baselines = tool_root / "baselines"
    if not baselines.is_dir():
        return []
    files: list[Path] = []
    for path in sorted(baselines.rglob("*")):
        if path.is_file() and path.name != ".gitkeep":
            files.append(path)
    return files


def count_pages_in_baselines(tool_root: Path, profiles: list[str]) -> int:
    page_ids: set[str] = set()
    for profile in profiles:
        profile_dir = tool_root / "baselines" / profile
        if not profile_dir.is_dir():
            continue
        for png in profile_dir.glob("*.png"):
            page_ids.add(png.stem)
    return len(page_ids)


def build_bundle_manifest(
    *,
    files: list[str],
    git_sha: str,
    captured_at: str,
    profiles: list[str],
    page_count: int,
) -> dict[str, Any]:
    return {
        "schemaVersion": SCHEMA_VERSION,
        "gitSha": git_sha,
        "capturedAt": captured_at,
        "profiles": profiles,
        "pageCount": page_count,
        "files": files,
    }


def bundle_filename(timestamp: datetime, git_sha: str, content_sha256: str) -> str:
    ts = timestamp.strftime("%Y%m%dT%H%M%SZ")
    return f"{ts}-{git_sha}-{content_sha256[:16]}.zip"


def create_bundle(
    tool_root: Path,
    *,
    out_dir: Path,
    repo_root: Path,
    git_sha: str | None = None,
    captured_at: str | None = None,
    profiles: list[str] | None = None,
) -> Path:
    files = collect_baseline_files(tool_root)
    if not files:
        raise SetpointError("no baseline files to bundle; run record first")

    now = datetime.now(timezone.utc)
    captured_at = captured_at or now.strftime("%Y-%m-%dT%H:%M:%SZ")
    git_sha = git_sha or short_git_sha(repo_root)
    profiles = profiles or ["test", "mirror"]
    page_count = count_pages_in_baselines(tool_root, profiles)
    name_time = datetime.fromisoformat(captured_at.replace("Z", "+00:00"))

    out_dir.mkdir(parents=True, exist_ok=True)
    rel_paths = [str(path.relative_to(tool_root)) for path in files]
    manifest = build_bundle_manifest(
        files=rel_paths,
        git_sha=git_sha,
        captured_at=captured_at,
        profiles=profiles,
        page_count=page_count,
    )

    temp_zip = out_dir / ".tmp-setpoint.zip"
    with zipfile.ZipFile(temp_zip, "w", zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("manifest.json", json.dumps(manifest, indent=2) + "\n")
        for path in files:
            archive.write(path, str(path.relative_to(tool_root)))

    content_sha256 = sha256_file(temp_zip)
    final_name = bundle_filename(name_time, git_sha, content_sha256)
    final_path = out_dir / final_name
    if final_path.exists():
        final_path.unlink()
    temp_zip.rename(final_path)
    return final_path


def setpoint_json_path(tool_root: Path) -> Path:
    return tool_root / "setpoint.json"


def load_setpoint(tool_root: Path) -> dict[str, Any]:
    path = setpoint_json_path(tool_root)
    if not path.is_file():
        return {"schemaVersion": SCHEMA_VERSION, "setpoints": {}}
    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def save_setpoint(tool_root: Path, data: dict[str, Any]) -> None:
    path = setpoint_json_path(tool_root)
    with path.open("w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2)
        handle.write("\n")


def read_bundle_manifest(bundle_path: Path) -> dict[str, Any]:
    with zipfile.ZipFile(bundle_path) as archive:
        if "manifest.json" not in archive.namelist():
            raise SetpointError("bundle missing manifest.json")
        return json.loads(archive.read("manifest.json"))


def publish_bundle(
    tool_root: Path,
    bundle_path: Path,
    *,
    drive_folder: str = DRIVE_FOLDER_HINT,
) -> dict[str, Any]:
    bundle_path = bundle_path.resolve()
    if not bundle_path.is_file():
        raise SetpointError(f"bundle not found: {bundle_path}")

    content_sha256 = sha256_file(bundle_path)
    manifest = read_bundle_manifest(bundle_path)
    filename = bundle_path.name

    data = load_setpoint(tool_root)
    data["schemaVersion"] = SCHEMA_VERSION
    data["latestBundle"] = filename
    data["driveFolder"] = drive_folder
    setpoints = data.setdefault("setpoints", {})
    setpoints[filename] = {
        "gitSha": manifest.get("gitSha", ""),
        "capturedAt": manifest.get("capturedAt", ""),
        "contentSha256": content_sha256,
        "profiles": manifest.get("profiles", []),
        "pageCount": manifest.get("pageCount", 0),
    }
    save_setpoint(tool_root, data)
    return data


def verify_bundle_content_sha(tool_root: Path, bundle_path: Path) -> None:
    filename = bundle_path.name
    actual = sha256_file(bundle_path)
    data = load_setpoint(tool_root)
    entry = data.get("setpoints", {}).get(filename)
    if not entry:
        return
    expected = entry.get("contentSha256")
    if expected and expected != actual:
        raise SetpointError(
            f"bundle content sha256 mismatch for {filename}: "
            f"expected {expected[:16]}…, got {actual[:16]}…"
        )


def restore_bundle(
    tool_root: Path,
    bundle_path: Path,
    *,
    verify_pointer: bool = True,
) -> list[str]:
    bundle_path = bundle_path.resolve()
    if not bundle_path.is_file():
        raise SetpointError(f"bundle not found: {bundle_path}")

    if verify_pointer:
        verify_bundle_content_sha(tool_root, bundle_path)

    extracted: list[str] = []
    with zipfile.ZipFile(bundle_path) as archive:
        for member in archive.namelist():
            if member == "manifest.json" or member.endswith("/"):
                continue
            if not member.startswith("baselines/"):
                continue
            target = tool_root / member
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(archive.read(member))
            extracted.append(member)
    return extracted


def bootstrap_bundle_path(tool_root: Path, filename: str) -> Path:
    return tool_root / "setpoints" / "bootstrap" / filename


def resolve_bundle_path(
    tool_root: Path,
    *,
    bundle: str | None = None,
    base_url: str | None = None,
    use_latest: bool = False,
) -> Path:
    if bundle:
        return Path(bundle).resolve()

    data = load_setpoint(tool_root)
    latest = data.get("latestBundle")
    if not latest:
        raise SetpointError(
            "no bundle specified and setpoint.json has no latestBundle; "
            "use setpoint restore --bundle PATH"
        )

    if base_url:
        cache_dir = tool_root / "setpoints" / "cache"
        cache_dir.mkdir(parents=True, exist_ok=True)
        dest = cache_dir / latest
        if not dest.is_file():
            download_bundle(base_url, latest, dest)
        return dest

    bootstrap = bootstrap_bundle_path(tool_root, latest)
    if bootstrap.is_file():
        return bootstrap

    out_path = tool_root / "setpoints" / "out" / latest
    if out_path.is_file():
        return out_path

    if use_latest:
        raise SetpointError(
            f"latest bundle '{latest}' not found locally. "
            f"Download from Google Drive folder '{data.get('driveFolder', DRIVE_FOLDER_HINT)}' "
            f"or run: bin/fuzzy-validator setpoint restore --bundle path/to/{latest}"
        )

    raise SetpointError(
        f"specify --bundle PATH (latest pointer: {latest})"
    )


def download_bundle(base_url: str, filename: str, dest: Path) -> Path:
    url = f"{base_url.rstrip('/')}/{filename}"
    try:
        with urllib.request.urlopen(url) as response:
            dest.write_bytes(response.read())
    except urllib.error.URLError as exc:
        raise SetpointError(f"failed to download bundle from {url}: {exc}") from exc
    return dest


def missing_baselines_hint(tool_root: Path) -> str:
    data = load_setpoint(tool_root)
    latest = data.get("latestBundle")
    if not latest:
        return "Run bin/fuzzy-validator setpoint restore --bundle path/to/setpoint.zip"
    bootstrap = bootstrap_bundle_path(tool_root, latest)
    if bootstrap.is_file():
        return (
            f"Restore baselines: bin/fuzzy-validator setpoint restore "
            f"--bundle tools/fuzzy-validator/setpoints/bootstrap/{latest}"
        )
    return (
        f"Restore baselines: bin/fuzzy-validator setpoint restore --bundle path/to/{latest} "
        f"(see setpoint.json; upload folder: {data.get('driveFolder', DRIVE_FOLDER_HINT)})"
    )
