"""Additional CLI coverage for record flow and argument resolution."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import patch

import pytest

from fuzzy_validator.cli import TOOL_ROOT, _resolve_page_ids, main


class _Args:
    def __init__(self, **kwargs):
        self.__dict__.update(kwargs)


def _prepare_validate_tool_root(
    tmp_path: Path,
    *,
    page_id: str = "home-anonymous",
    profile: str = "test",
) -> Path:
    tool_root = tmp_path / "tool"
    baseline = tool_root / "baselines" / profile / f"{page_id}.png"
    baseline.parent.mkdir(parents=True, exist_ok=True)
    baseline.write_bytes(b"\x89PNG\r\n\x1a\n")
    return tool_root


@pytest.fixture(autouse=True)
def _skip_profile_activation():
    with patch("fuzzy_validator.runtime.activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        yield activate


def test_resolve_page_ids_from_urls_file(tmp_path):
    urls_file = tmp_path / "urls.txt"
    urls_file.write_text("page:home-anonymous\n# comment\n", encoding="utf-8")
    args = _Args(page=None, pages=None, all=False, urls=str(urls_file))
    assert _resolve_page_ids(args, TOOL_ROOT) == ["home-anonymous"]


def test_resolve_page_ids_missing_selector_exits():
    args = _Args(page=None, pages=None, all=False, urls=None)
    with pytest.raises(SystemExit) as exc:
        _resolve_page_ids(args, TOOL_ROOT)
    assert exc.value.code == 2


def test_record_runs_capture_and_discover():
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        assert (
            main(["record", "--page", "home-anonymous", "--phase", "visual", "--profile", "test"])
            == 0
        )
        assert run_mock.call_count == 3


def test_record_phase_assets_runs_capture_and_calibrate():
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        assert (
            main(["record", "--page", "home-anonymous", "--phase", "assets", "--profile", "test"])
            == 0
        )
        assert run_mock.call_count == 2


def test_validate_phase_assets_runs_gate(tmp_path: Path):
    tool_root = _prepare_validate_tool_root(tmp_path)
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        with patch("fuzzy_validator.validate.run_batch_gate") as gate_mock:
            gate_mock.return_value = ([], 0)
            with patch("fuzzy_validator.validate.finalize_run"):
                assert (
                    main(
                        [
                            "validate",
                            "--page",
                            "home-anonymous",
                            "--phase",
                            "assets",
                            "--profile",
                            "test",
                            "--tool-root",
                            str(tool_root),
                        ]
                    )
                    == 0
                )
                assert run_mock.call_count == 1


def test_record_phase_dom_runs_capture_and_discover():
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        assert (
            main(["record", "--page", "home-anonymous", "--phase", "dom", "--profile", "test"])
            == 0
        )
        assert run_mock.call_count == 2


def test_validate_phase_all_runs_gate(tmp_path: Path):
    tool_root = _prepare_validate_tool_root(tmp_path)
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        with patch("fuzzy_validator.validate.run_batch_gate") as gate_mock:
            gate_mock.return_value = ([], 0)
            with patch("fuzzy_validator.validate.finalize_run"):
                assert (
                    main(
                        [
                            "validate",
                            "--page",
                            "home-anonymous",
                            "--phase",
                            "all",
                            "--profile",
                            "test",
                            "--tool-root",
                            str(tool_root),
                        ]
                    )
                    == 0
                )
                assert run_mock.call_count == 1


def test_validate_phase_dom_runs_gate(tmp_path: Path):
    tool_root = _prepare_validate_tool_root(tmp_path)
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        with patch("fuzzy_validator.validate.run_batch_gate") as gate_mock:
            gate_mock.return_value = ([], 0)
            with patch("fuzzy_validator.validate.finalize_run"):
                assert (
                    main(
                        [
                            "validate",
                            "--page",
                            "home-anonymous",
                            "--phase",
                            "dom",
                            "--profile",
                            "test",
                            "--tool-root",
                            str(tool_root),
                        ]
                    )
                    == 0
                )
                assert run_mock.call_count == 1


def test_record_capture_failure_propagates():
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 1
        assert (
            main(["record", "--page", "home-anonymous", "--phase", "visual", "--profile", "test"])
            == 1
        )
        assert run_mock.call_count == 1


def test_record_discover_failure_propagates():
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.side_effect = [
            type("Result", (), {"returncode": 0})(),
            type("Result", (), {"returncode": 1})(),
        ]
        assert (
            main(["record", "--page", "home-anonymous", "--phase", "visual", "--profile", "test"])
            == 1
        )
