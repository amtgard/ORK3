"""CLI stub tests (FU-0)."""

import pytest

from fuzzy_validator.cli import main


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


def test_record_stub_exits_two():
    assert main(["record", "--page", "home-anonymous"]) == 2
