#!/usr/bin/env python3
"""Write top-level evidence/reports/index.html navigation hub."""

from __future__ import annotations

import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
EVIDENCE = SCRIPT_DIR.parent
REPORTS = EVIDENCE / "reports"
PYTHON_DIR = EVIDENCE.parent / "python"
if str(PYTHON_DIR) not in sys.path:
    sys.path.insert(0, str(PYTHON_DIR))

from lib.report_html import refresh_report_bundle

CSS = """
body { font-family: system-ui, sans-serif; margin: 1.5rem; color: #222; max-width: 960px; }
h1, h2 { margin-top: 1.5rem; }
table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
th, td { border: 1px solid #ccc; padding: 0.5rem 0.75rem; text-align: left; vertical-align: top; }
th { background: #f5f5f5; }
a { color: #0366d6; }
.badge-pass { color: #155724; font-weight: bold; }
.badge-fail { color: #721c24; font-weight: bold; }
.lead { color: #444; font-size: 1.05rem; }
nav.meta { font-size: 0.9rem; margin-bottom: 1.5rem; }
section { margin: 2rem 0; border-left: 4px solid #0366d6; padding-left: 1rem; }
ul.compact { margin: 0.25rem 0; padding-left: 1.25rem; }
"""

ROWS = [
    {
        "layer": "Pixel (fuzzy)",
        "page": "player-profile",
        "summary": "Heraldry swap inside learned bbox passes; layout bar outside fails.",
        "index": "pixel-proof/index.html",
        "scenarios": [
            ("PASS — in-zone", "pixel-proof/inzone/index.html", "pass"),
            ("FAIL — out-of-zone", "pixel-proof/outzone/index.html", "fail"),
        ],
        "extra": [
            ("Calibration overlay", "pixel-proof/player-profile-calibration-overlay.png"),
        ],
    },
    {
        "layer": "DOM (fuzzy)",
        "page": "home-authenticated",
        "summary": "Session token drift inside fuzz passes; heading change outside fails.",
        "index": "dom-proof/index.html",
        "scenarios": [
            ("PASS — in-zone", "dom-proof/inzone/index.html", "pass"),
            ("FAIL — out-of-zone", "dom-proof/outzone/index.html", "fail"),
        ],
        "extra": [
            ("DOM fuzz debug", "dom-proof/home-authenticated-dom-fuzz.txt"),
        ],
    },
    {
        "layer": "Assets (hard gate)",
        "page": "home-authenticated",
        "summary": "Zero tolerance: same bytes pass; +1 byte CSS or JS fails.",
        "index": "assets-proof/index.html",
        "scenarios": [
            ("PASS — same commit", "assets-proof/pass/index.html", "pass"),
            ("FAIL — 1-byte CSS", "assets-proof/css-fail/index.html", "fail"),
            ("FAIL — 1-byte JS", "assets-proof/js-fail/index.html", "fail"),
        ],
        "extra": [],
    },
    {
        "layer": "Unified (all phases)",
        "page": "home-authenticated",
        "summary": "Composite pass when only fuzzed DOM drifts; fail on structural change.",
        "index": "unified-proof/index.html",
        "scenarios": [
            ("PASS — in-zone", "unified-proof/inzone/index.html", "pass"),
            ("FAIL — out-of-zone", "unified-proof/outzone/index.html", "fail"),
        ],
        "extra": [],
    },
]


def render_rows() -> str:
    parts: list[str] = []
    for row in ROWS:
        scenario_links = "".join(
            f'<li><a href="{href}">{label}</a> '
            f'<span class="badge-{kind}">{kind.upper()}</span></li>'
            for label, href, kind in row["scenarios"]
        )
        extra_links = "".join(
            f'<li><a href="{href}">{label}</a></li>' for label, href in row["extra"]
        )
        extras = f"<ul class=\"compact\">{extra_links}</ul>" if extra_links else "—"
        parts.append(
            f"""<tr>
  <td><strong>{row["layer"]}</strong><br><code>{row["page"]}</code></td>
  <td>{row["summary"]}<ul class="compact">{scenario_links}</ul></td>
  <td><a href="{row["index"]}">Layer dashboard</a></td>
  <td>{extras}</td>
</tr>"""
        )
    return "\n".join(parts)


def refresh_all_report_bundles() -> int:
    count = 0
    for summary in REPORTS.rglob("summary.json"):
        if refresh_report_bundle(summary.parent):
            count += 1
    return count


def write_index() -> Path:
    out = REPORTS / "index.html"
    REPORTS.mkdir(parents=True, exist_ok=True)
    html = f"""<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Fuzzy Validator — Evidence Reports</title>
  <style>{CSS}</style>
</head>
<body>
  <h1>Fuzzy Validator — Evidence Reports</h1>
  <p class="lead">Integration proof for fuzzy pass/fail behavior (FU-13…FU-15).
  Open this page once, then drill into each layer. Green boxes = allowed fuzz; red = regression.</p>
  <nav class="meta">
    <a href="../README.md">Evidence README</a> ·
    <a href="../mutations.md">Mutation recipes</a> ·
    Re-run: <code>evidence/scripts/run-evidence-suite.sh</code>
  </nav>

  <h2>All layers</h2>
  <table>
    <tr>
      <th>Layer</th>
      <th>Scenarios</th>
      <th>Dashboard</th>
      <th>Artifacts</th>
    </tr>
    {render_rows()}
  </table>

  <section>
    <h2>How to read screenshots</h2>
    <ul>
      <li><strong>Green boxes</strong> — fuzz allowance (diff ignored)</li>
      <li><strong>Red boxes</strong> — regression that caused failure</li>
      <li>Each scenario report has <strong>Visual</strong>, <strong>DOM</strong>, and <strong>Assets</strong> tabs where applicable</li>
    </ul>
  </section>
</body>
</html>
"""
    out.write_text(html, encoding="utf-8")
    return out


def main() -> None:
    refreshed = refresh_all_report_bundles()
    path = write_index()
    print(f"evidence: refreshed {refreshed} report bundle(s)")
    print(f"evidence: wrote {path.relative_to(EVIDENCE.parent.parent)}")


if __name__ == "__main__":
    main()
