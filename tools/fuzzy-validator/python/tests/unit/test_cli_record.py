"""Additional CLI coverage for record flow and argument resolution."""

from __future__ import annotations

from unittest.mock import patch

import pytest

from fuzzy_validator.cli import _resolve_page_ids, main


class _Args:
    def __init__(self, **kwargs):
        self.__dict__.update(kwargs)


def test_resolve_page_ids_from_urls_file(tmp_path):
    urls_file = tmp_path / "urls.txt"
    urls_file.write_text("page:home-anonymous\n# comment\n", encoding="utf-8")
    args = _Args(page=None, pages=None, all=False, urls=str(urls_file))
    assert _resolve_page_ids(args) == ["home-anonymous"]


def test_resolve_page_ids_missing_selector_exits():
    args = _Args(page=None, pages=None, all=False, urls=None)
    with pytest.raises(SystemExit) as exc:
        _resolve_page_ids(args)
    assert exc.value.code == 2


def test_record_runs_capture_and_discover():
    with patch("fuzzy_validator.cli.subprocess.run") as run_mock:
        run_mock.return_value.returncode = 0
        assert main(["record", "--page", "home-anonymous", "--phase", "visual"]) == 0
        assert run_mock.call_count == 2


def test_record_phase_assets_not_implemented():
    assert main(["record", "--page", "home-anonymous", "--phase", "assets"]) == 2


def test_validate_phase_all_not_implemented():
    assert main(["validate", "--page", "home-anonymous", "--phase", "all"]) == 2


def test_record_capture_failure_propagates():
    with patch("fuzzy_validator.cli.subprocess.run") as run_mock:
        run_mock.return_value.returncode = 1
        assert main(["record", "--page", "home-anonymous", "--phase", "visual"]) == 1
        assert run_mock.call_count == 1


def test_record_discover_failure_propagates():
    with patch("fuzzy_validator.cli.subprocess.run") as run_mock:
        run_mock.side_effect = [
            type("Result", (), {"returncode": 0})(),
            type("Result", (), {"returncode": 1})(),
        ]
        assert main(["record", "--page", "home-anonymous", "--phase", "visual"]) == 1
