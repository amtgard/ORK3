"""CLI tests for dual database profile support."""

from __future__ import annotations

from unittest.mock import patch

from fuzzy_validator.cli import main


def test_validate_dry_run_profiles():
    assert main(["validate", "--page", "home-anonymous", "--dry-run"]) == 0


def test_record_dry_run_profiles():
    assert main(["record", "--page", "home-anonymous", "--dry-run"]) == 0


def test_validate_single_profile(monkeypatch, tmp_path):
    import fuzzy_validator.cli as cli_module
    from lib.manifest import save_json

    monkeypatch.setattr(cli_module, "TOOL_ROOT", tmp_path)
    profiles = tmp_path / "manifests" / "profiles.json5"
    profiles.parent.mkdir(parents=True)
    profiles.write_text(
        '{"profiles":{"test":{"orkDbUse":"dev","label":"Sandbox","thresholds":{"assetsMinScore":1.0,"domMinScore":1.0,"visualMinScore":1.0},"auth":{"username":"megiddo","passwordDefault":"pw"}}},"defaultProfiles":["test"]}',
        encoding="utf-8",
    )
    save_json(tmp_path / "manifests" / "defaults.json5", {"visualMinScore": 1.0})
    baselines = tmp_path / "baselines" / "test"
    baselines.mkdir(parents=True)
    (baselines / "home-anonymous.png").write_bytes(b"png")

    with patch("fuzzy_validator.cli._activate_profile") as activate:
        activate.side_effect = lambda profile_name, config, *, ensure_sandbox, env: env
        with patch("fuzzy_validator.cli.subprocess.run") as run_mock:
            run_mock.return_value.returncode = 0
            with patch("fuzzy_validator.cli.run_batch_gate") as gate_mock:
                gate_mock.return_value = ([], 0)
                with patch("fuzzy_validator.cli.finalize_run"):
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
                            ]
                        )
                        == 0
                    )
