"""Prod mirror freshness probe for validate --require-fresh-mirror and skill 5.1."""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

DEFAULT_MAX_AGE_DAYS = 7


class MirrorFreshnessError(ValueError):
    """Mirror is stale or probe failed — CLI exit 2 when required."""


@dataclass(frozen=True)
class MirrorFreshness:
    extracted_at: datetime
    age_days: float
    manifest_path: Path
    stale: bool
    max_age_days: float

    def as_dict(self) -> dict:
        return {
            "extractedAt": self.extracted_at.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "ageDays": round(self.age_days, 3),
            "stale": self.stale,
            "maxAgeDays": self.max_age_days,
            "manifestPath": str(self.manifest_path),
        }


def _parse_extracted_at(raw: str) -> datetime:
    text = raw.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    parsed = datetime.fromisoformat(text)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def default_manifest_path(repo_root: Path) -> Path:
    return repo_root / "tools" / "ork-db" / "extracted" / "manifest.json"


def probe_mirror_freshness(
    *,
    repo_root: Path | None = None,
    manifest_path: Path | None = None,
    max_age_days: float = DEFAULT_MAX_AGE_DAYS,
    now: datetime | None = None,
) -> MirrorFreshness:
    path = manifest_path
    if path is None:
        if repo_root is None:
            raise MirrorFreshnessError("repo_root or manifest_path required")
        path = default_manifest_path(repo_root)
    if not path.is_file():
        raise MirrorFreshnessError(
            f"mirror manifest not found: {path} "
            "(refresh prod mirror / re-extract via bin/ork-db)"
        )
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise MirrorFreshnessError(f"failed to read mirror manifest {path}: {exc}") from exc

    extracted_raw = payload.get("extracted_at") or payload.get("extractedAt")
    if not isinstance(extracted_raw, str) or not extracted_raw.strip():
        raise MirrorFreshnessError(
            f"manifest {path} missing extracted_at (or extractedAt)"
        )

    extracted_at = _parse_extracted_at(extracted_raw)
    current = now or datetime.now(timezone.utc)
    age_seconds = (current - extracted_at).total_seconds()
    age_days = age_seconds / 86400.0
    return MirrorFreshness(
        extracted_at=extracted_at,
        age_days=age_days,
        manifest_path=path,
        stale=age_days > max_age_days,
        max_age_days=max_age_days,
    )


def require_fresh_mirror(
    *,
    repo_root: Path | None = None,
    manifest_path: Path | None = None,
    max_age_days: float = DEFAULT_MAX_AGE_DAYS,
    override_reason: str | None = None,
) -> MirrorFreshness:
    """Return freshness; raise if stale and no override reason."""
    freshness = probe_mirror_freshness(
        repo_root=repo_root,
        manifest_path=manifest_path,
        max_age_days=max_age_days,
    )
    if freshness.stale and not (override_reason and override_reason.strip()):
        raise MirrorFreshnessError(
            f"prod mirror is {freshness.age_days:.1f} days old "
            f"(threshold {max_age_days} days). "
            f"Refresh the local prod mirror dump, then re-extract "
            f"(see tools/ork-db). Manifest: {freshness.manifest_path}. "
            f"To continue anyway, pass --mirror-stale-ok REASON "
            f"(recorded in the report)."
        )
    return freshness
