"""CLI tests for overlay validate/summarize and validate --overlay flags."""

from __future__ import annotations

import json
from pathlib import Path
from unittest.mock import patch

from fuzzy_validator.cli import main
from lib.manifest import save_json


def _write_overlay(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {
                "schemaVersion": 2,
                "id": "cli-demo",
                "entries": [
                    {
                        "id": "n1",
                        "class": "natural",
                        "layer": "visual",
                        "profiles": ["test"],
                        "pages": ["home-anonymous"],
                        "visual": {"x": 0, "y": 0, "width": 10, "height": 10},
                        "rationale": "noise",
                        "source": "manual",
                    }
                ],
            }
        ),
        encoding="utf-8",
    )


def test_overlay_validate_and_summarize(tmp_path: Path, capsys):
    path = tmp_path / "o.json5"
    _write_overlay(path)
    assert main(["overlay", "validate", str(path)]) == 0
    assert main(["overlay", "summarize", str(path)]) == 0
    out = capsys.readouterr().out
    assert "entryCount" in out or "ok overlays" in out


def test_overlay_validate_bad_schema(tmp_path: Path):
    path = tmp_path / "bad.json5"
    path.write_text(json.dumps({"schemaVersion": 1, "id": "x", "entries": []}), encoding="utf-8")
    assert main(["overlay", "validate", str(path)]) == 2


def test_validate_dry_run_with_overlay(tmp_path: Path):
    tool_root = tmp_path / "tool"
    overlay = tool_root / "overlays" / "intentional" / "x.json5"
    _write_overlay(overlay)
    save_json(
        tool_root / "manifests" / "profiles.json5",
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
                    "auth": {"username": "u", "passwordDefault": "p"},
                }
            },
            "defaultProfiles": ["test"],
        },
    )
    save_json(tool_root / "manifests" / "defaults.json5", {"visualMinScore": 1.0})
    save_json(
        tool_root / "manifests" / "pages.json5",
        {
            "defaults": {
                "viewport": {"width": 1280, "height": 720},
                "repeat": 1,
                "waitAfterMs": 0,
                "auth": "none",
            },
            "pages": [
                {
                    "id": "home-anonymous",
                    "url": "./index.php",
                    "auth": "none",
                    "driftClass": "stable",
                }
            ],
        },
    )
    baseline = tool_root / "baselines" / "test" / "home-anonymous.png"
    baseline.parent.mkdir(parents=True, exist_ok=True)
    baseline.write_bytes(b"\x89PNG\r\n\x1a\n")

    assert (
        main(
            [
                "validate",
                "--page",
                "home-anonymous",
                "--profile",
                "test",
                "--dry-run",
                "--overlay",
                str(overlay),
                "--tool-root",
                str(tool_root),
            ]
        )
        == 0
    )


def test_validate_require_fresh_mirror_stale(tmp_path: Path, monkeypatch):
    tool_root = tmp_path / "tool"
    save_json(
        tool_root / "manifests" / "profiles.json5",
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
                    "auth": {"username": "u", "passwordDefault": "p"},
                }
            },
            "defaultProfiles": ["test"],
        },
    )
    save_json(tool_root / "manifests" / "defaults.json5", {"visualMinScore": 1.0})
    save_json(
        tool_root / "manifests" / "pages.json5",
        {
            "defaults": {
                "viewport": {"width": 1280, "height": 720},
                "repeat": 1,
                "waitAfterMs": 0,
                "auth": "none",
            },
            "pages": [
                {
                    "id": "home-anonymous",
                    "url": "./index.php",
                    "auth": "none",
                    "driftClass": "stable",
                }
            ],
        },
    )
    baseline = tool_root / "baselines" / "test" / "home-anonymous.png"
    baseline.parent.mkdir(parents=True, exist_ok=True)
    baseline.write_bytes(b"\x89PNG\r\n\x1a\n")

    with patch(
        "fuzzy_validator.validate.require_fresh_mirror",
        side_effect=__import__(
            "lib.mirror_freshness", fromlist=["MirrorFreshnessError"]
        ).MirrorFreshnessError("stale"),
    ):
        assert (
            main(
                [
                    "validate",
                    "--page",
                    "home-anonymous",
                    "--profile",
                    "test",
                    "--dry-run",
                    "--require-fresh-mirror",
                    "--tool-root",
                    str(tool_root),
                ]
            )
            == 2
        )
