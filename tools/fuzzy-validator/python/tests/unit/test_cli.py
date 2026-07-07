"""CLI tests (FU-0 / FU-1 / FU-3)."""

import json
from pathlib import Path
from unittest.mock import patch

import pytest

from fuzzy_validator.cli import PAGES_MANIFEST, _resolve_page_ids, main

TOOL_ROOT = Path(__file__).resolve().parents[2]


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


def test_validate_runs_gate_script():
    with patch("fuzzy_validator.cli.subprocess.run") as run_mock:
        run_mock.return_value.returncode = 0
        assert main(["validate", "--page", "home-anonymous", "--phase", "visual"]) == 0
        command = run_mock.call_args[0][0]
        assert "gate.sh" in command[0]
        assert command[-1] == "visual"


def test_resolve_page_ids_single_page():
    args = _record_args(page="home-anonymous")
    assert _resolve_page_ids(args) == ["home-anonymous"]


def test_resolve_page_ids_pages_list():
    args = _record_args(pages="home-anonymous,player-profile")
    assert _resolve_page_ids(args) == ["home-anonymous", "player-profile"]


def test_resolve_page_ids_all_active_pages():
    args = _record_args(all=True)
    ids = _resolve_page_ids(args)
    assert "home-anonymous" in ids
    assert "player-profile" in ids
    assert "health-endpoint" not in ids
    assert len(ids) >= 20


def test_pages_manifest_has_at_least_twenty_entries():
    with PAGES_MANIFEST.open(encoding="utf-8") as handle:
        registry = json.load(handle)
    assert len(registry["pages"]) >= 20
    page_ids = [page["id"] for page in registry["pages"]]
    assert "home-anonymous" in page_ids
    assert "home-authenticated" in page_ids
    assert "player-profile" in page_ids
