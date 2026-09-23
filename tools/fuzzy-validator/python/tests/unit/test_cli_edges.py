"""CLI coverage for refuzz, activate_profile, and setpoint error exits."""

from __future__ import annotations

from pathlib import Path
from unittest.mock import patch

import pytest

from fuzzy_validator.cli import main
from lib.manifest import save_json
from lib.setpoint import SetpointError


def _write_profiles(tool_root: Path) -> None:
    profiles = tool_root / "manifests" / "profiles.json5"
    profiles.parent.mkdir(parents=True, exist_ok=True)
    profiles.write_text(
        '{"profiles":{"test":{"orkDbUse":"dev","label":"Sandbox","thresholds":'
        '{"assetsMinScore":1.0,"domMinScore":1.0,"visualMinScore":1.0},'
        '"auth":{"username":"megiddo","passwordDefault":"pw"}}},'
        '"defaultProfiles":["test"]}',
        encoding="utf-8",
    )
    save_json(tool_root / "manifests" / "defaults.json5", {"visualMinScore": 1.0, "clockDate": "2026-07-19"})


def _write_pages(tool_root: Path) -> None:
    pages = tool_root / "manifests" / "pages.json5"
    pages.parent.mkdir(parents=True, exist_ok=True)
    save_json(
        pages,
        {
            "defaults": {
                "viewport": {"width": 1280, "height": 720},
                "repeat": 1,
                "waitAfterMs": 0,
                "auth": "none",
            },
            "pages": [
                {"id": "home-anonymous", "url": "./index.php", "auth": "none", "driftClass": "stable"},
                {
                    "id": "natural-page",
                    "url": "./natural.php",
                    "auth": "none",
                    "driftClass": "natural",
                },
            ],
        },
    )


def test_refuzz_dry_run(tmp_path: Path):
    _write_profiles(tmp_path)
    _write_pages(tmp_path)
    assert (
        main(
            [
                "refuzz",
                "--page",
                "home-anonymous",
                "--profile",
                "test",
                "--dry-run",
                "--tool-root",
                str(tmp_path),
            ]
        )
        == 0
    )


def test_refuzz_natural_pages(tmp_path: Path):
    _write_profiles(tmp_path)
    _write_pages(tmp_path)
    assert (
        main(
            [
                "refuzz",
                "--natural",
                "--profile",
                "test",
                "--dry-run",
                "--tool-root",
                str(tmp_path),
            ]
        )
        == 0
    )


def test_refuzz_runs_capture_and_script(tmp_path: Path):
    _write_profiles(tmp_path)
    _write_pages(tmp_path)
    with patch("fuzzy_validator.runtime.activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
            run_mock.return_value.returncode = 0
            assert (
                main(
                    [
                        "refuzz",
                        "--page",
                        "home-anonymous",
                        "--profile",
                        "test",
                        "--phase",
                        "visual",
                        "--tool-root",
                        str(tmp_path),
                    ]
                )
                == 0
            )
            assert run_mock.call_count == 2


def test_refuzz_no_pages_exits_two(tmp_path: Path):
    _write_profiles(tmp_path)
    _write_pages(tmp_path)
    assert (
        main(
            [
                "refuzz",
                "--pages",
                ",,",
                "--profile",
                "test",
                "--tool-root",
                str(tmp_path),
            ]
        )
        == 2
    )


def test_activate_profile_ork_db_use_failure(tmp_path: Path):
    from fuzzy_validator import cli as cli_module

    _write_profiles(tmp_path)
    config = {
        "profiles": {
            "test": {
                "orkDbUse": "dev",
                "auth": {"username": "u", "passwordDefault": "p"},
            }
        }
    }
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 7
        with pytest.raises(SystemExit) as exc:
            cli_module._activate_profile("test", config, ensure_sandbox=False, env={})
        assert exc.value.code == 7


def test_activate_profile_deploy_sandbox_failure():
    from fuzzy_validator import cli as cli_module

    config = {
        "profiles": {
            "test": {
                "orkDbUse": "dev",
                "auth": {"username": "u", "passwordDefault": "p"},
            }
        }
    }
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 9
        with pytest.raises(SystemExit) as exc:
            cli_module._activate_profile("test", config, ensure_sandbox=True, env={})
        assert exc.value.code == 9


def test_activate_profile_success_merges_auth():
    from fuzzy_validator import cli as cli_module

    config = {
        "profiles": {
            "mirror": {
                "orkDbUse": "prod-mirror",
                "auth": {"username": "meg", "passwordDefault": "secret"},
            }
        }
    }
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        env = cli_module._activate_profile("mirror", config, ensure_sandbox=False, env={"KEEP": "1"})
    assert env["KEEP"] == "1"
    assert env["ORK3_E2E_USERNAME"] == "meg"
    assert env["ORK3_E2E_PASSWORD"] == "secret"


def test_setpoint_capture_dry_run():
    assert main(["setpoint", "capture", "--dry-run"]) == 0


def test_setpoint_publish_missing_bundle(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr("lib.tool_paths.DEFAULT_TOOL_ROOT", tmp_path)
    assert main(["setpoint", "publish"]) == 2


def test_setpoint_publish_setpoint_error(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr("lib.tool_paths.DEFAULT_TOOL_ROOT", tmp_path)
    with patch("fuzzy_validator.setpoint_cli.publish_bundle", side_effect=SetpointError("boom")):
        assert main(["setpoint", "publish", "--bundle", str(tmp_path / "x.zip")]) == 2


def test_setpoint_restore_error(tmp_path: Path):
    with patch("fuzzy_validator.setpoint_cli.resolve_bundle_path", side_effect=SetpointError("missing")):
        assert main(["setpoint", "restore", "--tool-root", str(tmp_path)]) == 2


def test_setpoint_capture_create_bundle_error(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr("lib.tool_paths.DEFAULT_TOOL_ROOT", tmp_path)
    _write_profiles(tmp_path)
    with patch("fuzzy_validator.record.run_record", return_value=0):
        with patch("fuzzy_validator.setpoint_cli.create_bundle", side_effect=SetpointError("empty")):
            assert main(["setpoint", "capture", "--profiles", "test"]) == 2


def test_setpoint_no_subcommand():
    assert main(["setpoint"]) == 2


def test_validate_missing_baselines_exits_two(tmp_path: Path):
    _write_profiles(tmp_path)
    assert (
        main(
            [
                "validate",
                "--page",
                "home-anonymous",
                "--profile",
                "test",
                "--tool-root",
                str(tmp_path),
            ]
        )
        == 2
    )


def test_main_no_command_prints_help():
    assert main([]) == 0


def test_validate_gate_failure_exit_one(tmp_path: Path):
    _write_profiles(tmp_path)
    baseline = tmp_path / "baselines" / "test" / "home-anonymous.png"
    baseline.parent.mkdir(parents=True)
    baseline.write_bytes(b"png")
    with patch("fuzzy_validator.runtime.activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
            run_mock.return_value.returncode = 0
            with patch("fuzzy_validator.validate.run_batch_gate") as gate_mock:
                gate_mock.return_value = ([], 1)
                with patch("fuzzy_validator.validate.finalize_run"):
                    assert (
                        main(
                            [
                                "validate",
                                "--page",
                                "home-anonymous",
                                "--profile",
                                "test",
                                "--phase",
                                "visual",
                                "--tool-root",
                                str(tmp_path),
                            ]
                        )
                        == 1
                    )


def test_validate_gate_hard_fail_exit_two(tmp_path: Path):
    _write_profiles(tmp_path)
    baseline = tmp_path / "baselines" / "test" / "home-anonymous.png"
    baseline.parent.mkdir(parents=True)
    baseline.write_bytes(b"png")
    with patch("fuzzy_validator.runtime.activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
            run_mock.return_value.returncode = 0
            with patch("fuzzy_validator.validate.run_batch_gate") as gate_mock:
                gate_mock.return_value = ([], 2)
                assert (
                    main(
                        [
                            "validate",
                            "--page",
                            "home-anonymous",
                            "--profile",
                            "test",
                            "--skip-capture",
                            "--tool-root",
                            str(tmp_path),
                        ]
                    )
                    == 2
                )


def test_validate_capture_failure(tmp_path: Path):
    _write_profiles(tmp_path)
    baseline = tmp_path / "baselines" / "test" / "home-anonymous.png"
    baseline.parent.mkdir(parents=True)
    baseline.write_bytes(b"png")
    with patch("fuzzy_validator.runtime.activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
            run_mock.return_value.returncode = 5
            assert (
                main(
                    [
                        "validate",
                        "--page",
                        "home-anonymous",
                        "--profile",
                        "test",
                        "--tool-root",
                        str(tmp_path),
                    ]
                )
                == 5
            )


def test_setpoint_capture_success(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr("lib.tool_paths.DEFAULT_TOOL_ROOT", tmp_path)
    _write_profiles(tmp_path)
    bundle = tmp_path / "setpoints" / "out" / "bundle.zip"
    bundle.parent.mkdir(parents=True)
    bundle.write_bytes(b"zip")
    with patch("fuzzy_validator.record.run_record", return_value=0):
        with patch("fuzzy_validator.setpoint_cli.create_bundle", return_value=bundle):
            assert main(["setpoint", "capture", "--profile", "test"]) == 0


def test_setpoint_publish_newest_bundle(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr("lib.tool_paths.DEFAULT_TOOL_ROOT", tmp_path)
    out = tmp_path / "setpoints" / "out"
    out.mkdir(parents=True)
    older = out / "older.zip"
    newer = out / "newer.zip"
    older.write_bytes(b"old")
    newer.write_bytes(b"new")
    import os
    import time

    older_mtime = time.time() - 100
    newer_mtime = time.time()
    os.utime(older, (older_mtime, older_mtime))
    os.utime(newer, (newer_mtime, newer_mtime))
    with patch("fuzzy_validator.setpoint_cli.publish_bundle") as publish:
        publish.return_value = {"latestBundle": newer.name}
        assert main(["setpoint", "publish"]) == 0
        assert publish.call_args.args[1] == newer


def test_refuzz_capture_failure(tmp_path: Path):
    _write_profiles(tmp_path)
    _write_pages(tmp_path)
    with patch("fuzzy_validator.runtime.activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
            run_mock.return_value.returncode = 3
            assert (
                main(
                    [
                        "refuzz",
                        "--page",
                        "home-anonymous",
                        "--profile",
                        "test",
                        "--tool-root",
                        str(tmp_path),
                    ]
                )
                == 3
            )


def test_refuzz_script_failure(tmp_path: Path):
    _write_profiles(tmp_path)
    _write_pages(tmp_path)
    with patch("fuzzy_validator.runtime.activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
            run_mock.side_effect = [
                type("Result", (), {"returncode": 0})(),
                type("Result", (), {"returncode": 4})(),
            ]
            assert (
                main(
                    [
                        "refuzz",
                        "--page",
                        "home-anonymous",
                        "--profile",
                        "test",
                        "--tool-root",
                        str(tmp_path),
                    ]
                )
                == 4
            )


def test_activate_profile_with_sandbox_success():
    from fuzzy_validator import cli as cli_module

    config = {
        "profiles": {
            "test": {
                "orkDbUse": "dev",
                "auth": {"username": "u", "passwordDefault": "p"},
            }
        }
    }
    with patch("fuzzy_validator.runtime.run_subprocess") as run_mock:
        run_mock.return_value.returncode = 0
        env = cli_module._activate_profile("test", config, ensure_sandbox=True, env={})
    assert env["ORK3_E2E_USERNAME"] == "u"
    assert run_mock.call_count == 2


def test_record_unsupported_phase_via_namespace():
    from fuzzy_validator.record import run_record

    args = type(
        "Args",
        (),
        {
            "phase": "nope",
            "tool_root": None,
            "page": "home-anonymous",
            "pages": None,
            "all": False,
            "urls": None,
            "dry_run": False,
            "profile": "test",
            "profiles": "test",
            "ensure_sandbox": False,
            "repeat": None,
            "base_url": None,
        },
    )()
    assert run_record(args) == 2


def test_refuzz_unsupported_phase_via_namespace():
    from fuzzy_validator.refuzz import run_refuzz

    args = type(
        "Args",
        (),
        {
            "phase": "assets",
            "tool_root": None,
            "page": "home-anonymous",
            "pages": None,
            "all": False,
            "natural": False,
            "urls": None,
            "dry_run": False,
            "profile": "test",
            "profiles": "test",
            "ensure_sandbox": False,
            "base_url": None,
        },
    )()
    assert run_refuzz(args) == 2


def test_setpoint_capture_record_failure(tmp_path: Path, monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setattr("lib.tool_paths.DEFAULT_TOOL_ROOT", tmp_path)
    _write_profiles(tmp_path)
    with patch("fuzzy_validator.record.run_record", return_value=3):
        assert main(["setpoint", "capture", "--profile", "test"]) == 3


def test_apply_clock_date_skips_when_set(tmp_path: Path):
    from fuzzy_validator.runtime import apply_clock_date

    env = {"ORK3_CLOCK_DATE": "2020-01-01"}
    apply_clock_date(env, tmp_path)
    assert env["ORK3_CLOCK_DATE"] == "2020-01-01"


def test_build_parser_reexport():
    from fuzzy_validator.cli import _build_parser

    parser = _build_parser()
    assert parser.prog == "fuzzy-validator"
