"""Unit tests for lib/report_html.py."""

from __future__ import annotations

from pathlib import Path

from lib.report_html import refresh_report_bundle, write_report_bundle, write_summary_json
from lib.scoring import Thresholds


def _sample_page(*, passed: bool, visual_score: float = 1.0) -> dict:
    return {
        "pageId": "fixture-page",
        "passed": passed,
        "layers": [
            {
                "layer": "assets",
                "passed": True,
                "score": 1.0,
                "exitCode": 0,
                "details": {"assetsScore": 1.0},
            },
            {
                "layer": "dom",
                "passed": True,
                "score": 1.0,
                "exitCode": 0,
                "details": {"domScore": 1.0, "failurePaths": 0, "failures": []},
            },
            {
                "layer": "visual",
                "passed": passed,
                "score": visual_score,
                "exitCode": 0 if passed else 1,
                "details": {
                    "visualScore": visual_score,
                    "outsideDiffPx": 0 if passed else 100,
                    "comparablePx": 1000,
                },
            },
        ],
    }


def test_write_report_bundle_creates_index_and_pages(tmp_path: Path):
    run_dir = tmp_path / "run-demo"
    index = write_report_bundle(
        run_dir=run_dir,
        run_id="demo",
        phase="all",
        page_results=[_sample_page(passed=True)],
        thresholds=Thresholds(),
        run_pass=True,
    )
    assert index.exists()
    assert (run_dir / "pages" / "fixture-page.html").exists()
    assert (run_dir / "visual" / "index.html").exists()
    assert (run_dir / "assets" / "index.html").exists()
    assert (run_dir / "dom" / "index.html").exists()
    html = index.read_text(encoding="utf-8")
    assert "PASS" in html
    assert "fixture-page" in html


def test_write_report_bundle_marks_failures(tmp_path: Path):
    run_dir = tmp_path / "run-fail"
    write_report_bundle(
        run_dir=run_dir,
        run_id="fail",
        phase="all",
        page_results=[_sample_page(passed=False, visual_score=0.95)],
        thresholds=Thresholds(visual_min=1.0),
        run_pass=False,
    )
    page_html = (run_dir / "pages" / "fixture-page.html").read_text(encoding="utf-8")
    assert "FAIL" in page_html
    visual_index = (run_dir / "visual" / "index.html").read_text(encoding="utf-8")
    assert "fixture-page" in visual_index


def test_write_summary_json(tmp_path: Path):
    path = write_summary_json(tmp_path, {"runId": "demo", "pass": True})
    assert path.exists()
    assert '"runId": "demo"' in path.read_text(encoding="utf-8")


def test_page_report_includes_screenshot_viewer(tmp_path: Path):
    run_dir = tmp_path / "run-visual"
    data_dir = run_dir / "data"
    data_dir.mkdir(parents=True)
    # Minimal 1x1 PNG
    png = (
        b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01"
        b"\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde\x00\x00\x00\x0cIDATx\x9cc``\x00\x00"
        b"\x00\x02\x00\x01\xe2!\xbc3\x00\x00\x00\x00IEND\xaeB`\x82"
    )
    for suffix in ("baseline", "candidate", "annotated"):
        (data_dir / f"fixture-page-{suffix}.png").write_bytes(png)

    write_report_bundle(
        run_dir=run_dir,
        run_id="visual",
        phase="visual",
        page_results=[_sample_page(passed=True)],
        thresholds=Thresholds(),
        run_pass=True,
    )
    page_html = (run_dir / "pages" / "fixture-page.html").read_text(encoding="utf-8")
    assert "data-screenshot-viewer" in page_html
    assert "screenshot-lightbox" in page_html
    assert "screenshot-thumb" in page_html
    assert "Fullscreen" in page_html
    assert "fixture-page-annotated.png" in page_html


def test_refresh_report_bundle_from_summary(tmp_path: Path):
    run_dir = tmp_path / "bundle"
    run_dir.mkdir()
    summary = {
        "runId": "demo",
        "phase": "visual",
        "pass": True,
        "exitCode": 0,
        "thresholds": {"assetsMinScore": 1.0, "domMinScore": 1.0, "visualMinScore": 1.0},
        "pagesDetailed": [_sample_page(passed=True)],
    }
    write_summary_json(run_dir, summary)
    assert refresh_report_bundle(run_dir) is not None
    assert (run_dir / "pages" / "fixture-page.html").exists()
