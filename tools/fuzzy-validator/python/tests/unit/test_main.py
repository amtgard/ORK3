"""Smoke test for fuzzy_validator __main__ entry."""

import runpy
from unittest.mock import patch

import pytest


def test_main_module_entry():
    with patch("fuzzy_validator.cli.main", return_value=0):
        with pytest.raises(SystemExit) as exc:
            runpy.run_module("fuzzy_validator.__main__", run_name="__main__")
        assert exc.value.code == 0
