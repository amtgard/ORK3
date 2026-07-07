"""Unit tests for gate_dom.py."""

from __future__ import annotations

from pathlib import Path

from gate_dom import main, run_dom_gate
from lib.canonical_dom import html_to_canonical_tree, save_canonical_tree
from lib.manifest import build_dom_fuzz_manifest, save_json


def _write_dom_fixtures(tmp_path: Path) -> dict[str, Path]:
    page_id = "fixture-page"
    baseline_tree = html_to_canonical_tree(
        "<html><body><div>Stable</div></body></html>"
    )
    candidate_html = "<html><body><div>Stable</div></body></html>"

    baseline_path = tmp_path / "baselines" / f"{page_id}.dom.json"
    save_canonical_tree(baseline_path, baseline_tree)

    manifest_path = tmp_path / "manifests" / f"{page_id}.dom-fuzz.json"
    save_json(
        manifest_path,
        build_dom_fuzz_manifest(
            page_id=page_id,
            fuzz_nodes=[],
            calibration_runs=3,
        ),
    )

    candidate_path = tmp_path / "calibrations" / page_id / "candidate.dom.html"
    candidate_path.parent.mkdir(parents=True, exist_ok=True)
    candidate_path.write_text(candidate_html, encoding="utf-8")

    return {
        "baseline": baseline_path,
        "candidate": candidate_path,
        "manifest": manifest_path,
    }


def test_run_dom_gate_passes_matching_candidate(tmp_path: Path):
    paths = _write_dom_fixtures(tmp_path)
    payload = run_dom_gate(
        baseline_path=paths["baseline"],
        candidate_path=paths["candidate"],
        manifest_path=paths["manifest"],
        dom_min_score=1.0,
        compare_script_bodies=False,
    )
    assert payload["passed"] is True


def test_run_dom_gate_fails_on_tag_rename(tmp_path: Path):
    paths = _write_dom_fixtures(tmp_path)
    paths["candidate"].write_text(
        "<html><body><section>Stable</section></body></html>",
        encoding="utf-8",
    )
    payload = run_dom_gate(
        baseline_path=paths["baseline"],
        candidate_path=paths["candidate"],
        manifest_path=paths["manifest"],
        dom_min_score=1.0,
        compare_script_bodies=False,
    )
    assert payload["passed"] is False


def test_gate_dom_cli_writes_json_outputs(tmp_path: Path):
    paths = _write_dom_fixtures(tmp_path)
    json_out = tmp_path / "result.json"
    diff_out = tmp_path / "diff.json"
    assert (
        main(
            [
                "--page-id",
                "fixture-page",
                "--baseline",
                str(paths["baseline"]),
                "--candidate",
                str(paths["candidate"]),
                "--manifest",
                str(paths["manifest"]),
                "--json-out",
                str(json_out),
                "--diff-out",
                str(diff_out),
            ]
        )
        == 0
    )
    assert json_out.exists()
    assert diff_out.exists()
