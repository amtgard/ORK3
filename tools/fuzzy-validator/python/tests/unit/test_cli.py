"""CLI tests (FU-0 / FU-1 / FU-3)."""

import json
from pathlib import Path
from unittest.mock import patch

import pytest

from fuzzy_validator.cli import TOOL_ROOT, _resolve_page_ids, main
from lib.tool_paths import pages_manifest_path


def _record_args(**kwargs):
    defaults = {
        "page": None,
        "pages": None,
        "all": False,
        "urls": None,
        "phase": "visual",
        "repeat": None,
        "base_url": None,
        "dry_run": False,
    }
    defaults.update(kwargs)
    return argparse_namespace(defaults)


class argparse_namespace:
    def __init__(self, values: dict):
        self.__dict__.update(values)


def _prepare_validate_tool_root(
    tmp_path: Path,
    *,
    page_id: str = "home-anonymous",
    profiles: tuple[str, ...] = ("test", "mirror"),
) -> Path:
    """Minimal tool root with profile baselines so validate can run isolated."""
    tool_root = tmp_path / "tool"
    for profile in profiles:
        baseline = tool_root / "baselines" / profile / f"{page_id}.png"
        baseline.parent.mkdir(parents=True, exist_ok=True)
        baseline.write_bytes(b"\x89PNG\r\n\x1a\n")
    return tool_root


def test_help_exits_zero():
    with pytest.raises(SystemExit) as exc:
        main(["--help"])
    assert exc.value.code == 0


def test_record_help_exits_zero():
    with pytest.raises(SystemExit) as exc:
        main(["record", "--help"])
    assert exc.value.code == 0


def test_validate_help_exits_zero():
    with pytest.raises(SystemExit) as exc:
        main(["validate", "--help"])
    assert exc.value.code == 0


def test_validate_runs_gate_script(tmp_path: Path):
    tool_root = _prepare_validate_tool_root(tmp_path)
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        with patch("fuzzy_validator.runtime.activate_profile") as activate:
            activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
            with patch("fuzzy_validator.validate.run_batch_gate") as gate_mock:
                gate_mock.return_value = ([], 0)
                with patch("fuzzy_validator.validate.finalize_multi_profile_run"):
                    assert (
                        main(
                            [
                                "validate",
                                "--page",
                                "home-anonymous",
                                "--phase",
                                "visual",
                                "--tool-root",
                                str(tool_root),
                            ]
                        )
                        == 0
                    )
                    assert run_mock.call_count >= 1


def test_resolve_page_ids_single_page():
    args = _record_args(page="home-anonymous")
    assert _resolve_page_ids(args, TOOL_ROOT) == ["home-anonymous"]


def test_resolve_page_ids_pages_list():
    args = _record_args(pages="home-anonymous,player-profile")
    assert _resolve_page_ids(args, TOOL_ROOT) == ["home-anonymous", "player-profile"]


def test_resolve_page_ids_all_active_pages():
    args = _record_args(all=True)
    ids = _resolve_page_ids(args, TOOL_ROOT)
    assert "home-authenticated" in ids
    assert "player-profile" in ids
    assert "health-endpoint" not in ids
    assert len(ids) >= 17


def test_pages_manifest_has_at_least_twenty_entries():
    pages_manifest = pages_manifest_path(TOOL_ROOT)
    with pages_manifest.open(encoding="utf-8") as handle:
        registry = json.load(handle)
    assert len(registry["pages"]) >= 20
    page_ids = [page["id"] for page in registry["pages"]]
    assert "home-anonymous" in page_ids
    assert "home-authenticated" in page_ids
    assert "player-profile" in page_ids
