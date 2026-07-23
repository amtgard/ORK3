"""Unit tests for discover_dom_fuzz.py."""

from __future__ import annotations

from pathlib import Path

from discover_dom_fuzz import discover_dom_fuzz, main


def _write_dom_runs(cal_dir: Path, values: list[str]) -> None:
    cal_dir.mkdir(parents=True, exist_ok=True)
    for index, value in enumerate(values, start=1):
        html = f"<html><body><input value='{value}'></body></html>"
        (cal_dir / f"run-{index:03d}.dom.html").write_text(html, encoding="utf-8")


def test_discover_dom_fuzz_finds_volatile_input(tmp_path: Path):
    cal_dir = tmp_path / "home-authenticated"
    _write_dom_runs(cal_dir, ["a", "b", "c"])
    fuzz_nodes = discover_dom_fuzz(cal_dir, compare_script_bodies=False)
    assert any(node["path"].endswith("/input[0]") for node in fuzz_nodes)


def test_discover_dom_fuzz_cli_writes_manifest_and_debug(tmp_path: Path):
    cal_dir = tmp_path / "fixture-page"
    _write_dom_runs(cal_dir, ["one", "two", "three"])
    out_manifest = tmp_path / "fixture.dom-fuzz.json"
    debug_out = tmp_path / "debug.txt"
    baseline_out = tmp_path / "baseline.dom.json"

    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--calibration-dir",
                str(cal_dir),
                "--out",
                str(out_manifest),
                "--debug-out",
                str(debug_out),
                "--baseline-out",
                str(baseline_out),
            ]
        )
        == 0
    )
    assert out_manifest.exists()
    assert debug_out.exists()
    assert baseline_out.exists()
    assert list(cal_dir.glob("run-*.dom.json"))


def test_discover_dom_fuzz_main_failure_on_missing_runs(tmp_path: Path):
    empty = tmp_path / "empty"
    empty.mkdir()
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--calibration-dir",
                str(empty),
                "--out",
                str(tmp_path / "out.json"),
            ]
        )
        == 1
    )
