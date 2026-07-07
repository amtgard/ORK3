"""Generate JaCoCo-style HTML report bundles for fuzzy gate runs."""

from __future__ import annotations

import html
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from jinja2 import Environment, BaseLoader

from lib.scoring import Thresholds, page_passes, score_near_threshold

REPORT_CSS = """
body { font-family: system-ui, sans-serif; margin: 1.5rem; color: #222; }
h1, h2, h3 { margin-top: 1.5rem; }
.banner-pass { background: #d4edda; border: 1px solid #28a745; padding: 1rem; }
.banner-fail { background: #f8d7da; border: 1px solid #dc3545; padding: 1rem; }
.banner-warn { background: #fff3cd; border: 1px solid #ffc107; padding: 0.5rem; }
table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
th, td { border: 1px solid #ccc; padding: 0.4rem 0.6rem; text-align: left; }
th { background: #f5f5f5; }
.badge-pass { color: #155724; font-weight: bold; }
.badge-fail { color: #721c24; font-weight: bold; }
.badge-warn { color: #856404; font-weight: bold; }
a { color: #0366d6; }
pre { background: #f6f8fa; padding: 0.75rem; overflow-x: auto; }
.thumbs { display: flex; gap: 1rem; flex-wrap: wrap; }
.thumbs img { max-width: 240px; border: 1px solid #ddd; }
nav { margin-bottom: 1rem; font-size: 0.9rem; }
.profile-section { border-left: 4px solid #0366d6; padding-left: 1rem; margin: 2rem 0; }
"""

INDEX_TEMPLATE = """<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Fuzzy Gate — {{ run_id }}</title>
<style>{{ css }}</style></head><body>
<h1>Fuzzy UI Gate</h1>
<div class="{{ 'banner-pass' if run_pass else 'banner-fail' }}">
  <strong>{{ 'PASS' if run_pass else 'FAIL' }}</strong>
  — run {{ run_id }} · phase {{ phase }} · {{ generated_at }}
  {% if profile %} · profile <code>{{ profile }}</code>{% endif %}
</div>
{% if profiles %}
<p>Profiles: {{ profiles | join(', ') }}</p>
{% endif %}
<nav><a href="summary.json">summary.json</a></nav>
<h2>Thresholds</h2>
<ul>
  <li>assets ≥ {{ thresholds.assetsMinScore }}</li>
  <li>dom ≥ {{ thresholds.domMinScore }}</li>
  <li>visual ≥ {{ thresholds.visualMinScore }}</li>
</ul>
{% for section in profile_sections %}
<div class="profile-section">
  <h2>Profile: {{ section.profile }} ({{ section.label }})</h2>
  {{ section.table_html | safe }}
</div>
{% else %}
<h2>Pages</h2>
{{ summary_table_html | safe }}
{% endfor %}
<p>Totals: {{ pass_count }} passed, {{ fail_count }} failed</p>
</body></html>"""

PAGE_TEMPLATE = """<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ page_id }} — Fuzzy Gate</title>
<style>{{ css }}</style></head><body>
<nav><a href="../index.html">Summary</a></nav>
<h1>{{ page_id }}</h1>
<div class="{{ 'banner-pass' if page_pass else 'banner-fail' }}">
  {{ verdict }}
</div>
{% if annotated_rel %}
<h2>Annotated screenshot</h2>
<div class="thumbs">
  {% if baseline_rel %}<figure><figcaption>Baseline</figcaption><img src="../{{ baseline_rel }}" alt="baseline"></figure>{% endif %}
  {% if candidate_rel %}<figure><figcaption>Candidate</figcaption><img src="../{{ candidate_rel }}" alt="candidate"></figure>{% endif %}
  <figure><figcaption>Annotated</figcaption><img src="../{{ annotated_rel }}" alt="annotated"></figure>
</div>
{% endif %}
{% for layer in layers %}
<h2>{{ layer.name | capitalize }}</h2>
{% if layer.name == 'visual' %}
<table>
  <tr><th>Metric</th><th>Value</th></tr>
  <tr><td>Outside diff px</td><td>{{ layer.details.outsideDiffPx }}</td></tr>
  <tr><td>Comparable px</td><td>{{ layer.details.comparablePx }}</td></tr>
  <tr><td>Visual score</td><td>{{ layer.score }}</td></tr>
  <tr><td>Threshold</td><td>{{ layer.threshold }}</td></tr>
</table>
{% elif layer.name == 'dom' %}
<p>Score {{ layer.score }} (threshold {{ layer.threshold }}) — failures: {{ layer.details.failurePaths }}</p>
{% if dom_failures %}
<table><tr><th>Path</th><th>Change</th></tr>
{% for row in dom_failures %}<tr><td><code>{{ row.path }}</code></td><td>{{ row.changeType }}</td></tr>{% endfor %}
</table>
{% endif %}
{% elif layer.name == 'assets' %}
<table><tr><th>Status</th><th>Score</th></tr>
<tr><td>{{ 'PASS' if layer.passed else 'FAIL' }}</td><td>{{ layer.score }}</td></tr>
</table>
{% if asset_diffs %}
<h3>Asset diffs</h3>
{% for diff in asset_diffs %}
<details><summary>{{ diff.asset_id }}</summary><pre>{{ diff.content }}</pre></details>
{% endfor %}
{% endif %}
{% endif %}
{% endfor %}
</body></html>"""

LAYER_INDEX_TEMPLATE = """<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ layer }} failures</title>
<style>{{ css }}</style></head><body>
<nav><a href="../index.html">Summary</a></nav>
<h1>{{ layer | capitalize }} failures</h1>
{% if rows %}
<table><tr><th>Page</th><th>Score</th><th>Link</th></tr>
{% for row in rows %}
<tr><td>{{ row.page_id }}</td><td>{{ row.score }}</td><td><a href="../pages/{{ row.page_id }}.html">{{ row.page_id }}</a></td></tr>
{% endfor %}
</table>
{% else %}
<p>No failures in this layer.</p>
{% endif %}
</body></html>"""


def _env() -> Environment:
    return Environment(loader=BaseLoader(), autoescape=True)


def _render(template: str, **context: Any) -> str:
    return _env().from_string(template).render(**context)


def _summary_table_rows(
    page_summaries: list[dict[str, Any]],
    thresholds: Thresholds,
) -> str:
    rows: list[str] = []
    for page in page_summaries:
        scores = page.get("scores", {})
        passed = page.get("pass", False)
        badge = "badge-pass" if passed else "badge-fail"
        status = "PASS" if passed else "FAIL"
        page_id = html.escape(page["pageId"])
        link = html.escape(page.get("reportPath", f"pages/{page['pageId']}.html"))
        assets = scores.get("assets", "—")
        dom = scores.get("dom", "—")
        visual = scores.get("visual", "—")
        warn = ""
        if passed and any(
            score_near_threshold(float(scores.get(k, 1.0)), getattr(thresholds, f"{k}_min"))
            for k in ("assets", "dom", "visual")
            if k in scores
        ):
            warn = ' <span class="badge-warn">near threshold</span>'
        rows.append(
            f"<tr><td><a href=\"{link}\">{page_id}</a></td>"
            f"<td class=\"{badge}\">{status}{warn}</td>"
            f"<td>{assets}</td><td>{dom}</td><td>{visual}</td></tr>"
        )
    header = (
        "<table><tr><th>Page</th><th>Status</th>"
        "<th>Assets</th><th>DOM</th><th>Visual</th></tr>"
    )
    return header + "".join(rows) + "</table>"


def _layer_threshold(layer: str, thresholds: Thresholds) -> float:
    return {
        "assets": thresholds.assets_min,
        "dom": thresholds.dom_min,
        "visual": thresholds.visual_min,
    }[layer]


def _verdict_strip(page_id: str, layers: list[dict], thresholds: Thresholds, passed: bool) -> str:
    if passed:
        return f"PASS — all layers meet thresholds for {page_id}"
    parts: list[str] = []
    scores = {layer["layer"]: layer for layer in layers}
    for name in ("assets", "dom", "visual"):
        if name not in scores:
            continue
        layer = scores[name]
        threshold = _layer_threshold(name, thresholds)
        if layer["score"] < threshold:
            parts.append(f"FAIL — {name} score {layer['score']:.3f} &lt; threshold {threshold:.3f}")
        else:
            parts.append(f"PASS — {name}")
    return " · ".join(parts) if parts else "FAIL"


def write_report_bundle(
    *,
    run_dir: Path,
    run_id: str,
    phase: str,
    page_results: list[dict[str, Any]],
    thresholds: Thresholds,
    run_pass: bool,
    profile: str | None = None,
    profile_label: str | None = None,
    profiles: list[str] | None = None,
    profile_sections: list[dict[str, Any]] | None = None,
) -> Path:
    run_dir.mkdir(parents=True, exist_ok=True)
    generated_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")

    page_summaries: list[dict[str, Any]] = []
    for page in page_results:
        page_id = page["pageId"]
        layers = page["layers"]
        scores = {
            layer["layer"]: layer["score"]
            for layer in layers
            if layer["layer"] in {"assets", "dom", "visual"}
        }
        passed = page_passes(
            {
                "assets": scores.get("assets", 1.0),
                "dom": scores.get("dom", 1.0),
                "visual": scores.get("visual", 1.0),
            },
            thresholds,
        )
        report_rel = f"pages/{page_id}.html"
        page_summaries.append(
            {
                "pageId": page_id,
                "pass": passed,
                "scores": scores,
                "reportPath": report_rel,
            }
        )

    pages_dir = run_dir / "pages"
    pages_dir.mkdir(parents=True, exist_ok=True)
    visual_failures: list[dict[str, Any]] = []
    dom_failures_index: list[dict[str, Any]] = []
    asset_failures_index: list[dict[str, Any]] = []

    for page in page_results:
        page_id = page["pageId"]
        layers = page["layers"]
        scores = {layer["layer"]: layer["score"] for layer in layers}
        passed = page.get("passed", page_passes(
            {
                "assets": scores.get("assets", 1.0),
                "dom": scores.get("dom", 1.0),
                "visual": scores.get("visual", 1.0),
            },
            thresholds,
        ))

        data_dir = run_dir / "data"
        annotated = data_dir / f"{page_id}-annotated.png"
        baseline = data_dir / f"{page_id}-baseline.png"
        candidate = data_dir / f"{page_id}-candidate.png"

        dom_failure_rows: list[dict[str, str]] = []
        dom_layer = next((layer for layer in layers if layer["layer"] == "dom"), None)
        if dom_layer:
            for failure in dom_layer.get("details", {}).get("failures", []):
                dom_failure_rows.append(
                    {
                        "path": failure.get("path", ""),
                        "changeType": failure.get("changeType", failure.get("kind", "")),
                    }
                )

        asset_diffs: list[dict[str, str]] = []
        diff_dir = run_dir / "diffs" / page_id
        if diff_dir.is_dir():
            for diff_path in sorted(diff_dir.glob("*.diff")):
                content = diff_path.read_text(encoding="utf-8", errors="replace")
                if len(content.splitlines()) > 500:
                    content = "\n".join(content.splitlines()[:500]) + "\n… (truncated)"
                asset_diffs.append({"asset_id": diff_path.stem, "content": content})

        layer_views = []
        for layer in layers:
            name = layer["layer"]
            layer_views.append(
                {
                    "name": name,
                    "score": round(layer["score"], 6),
                    "passed": layer["passed"],
                    "details": layer.get("details", {}),
                    "threshold": _layer_threshold(name, thresholds),
                }
            )

        if not passed:
            if "visual" in scores and scores["visual"] < thresholds.visual_min:
                visual_failures.append({"page_id": page_id, "score": scores["visual"]})
            if "dom" in scores and scores["dom"] < thresholds.dom_min:
                dom_failures_index.append({"page_id": page_id, "score": scores["dom"]})
            if "assets" in scores and scores["assets"] < thresholds.assets_min:
                asset_failures_index.append({"page_id": page_id, "score": scores["assets"]})

        page_html = _render(
            PAGE_TEMPLATE,
            css=REPORT_CSS,
            page_id=page_id,
            page_pass=passed,
            verdict=_verdict_strip(page_id, layers, thresholds, passed),
            annotated_rel=f"data/{page_id}-annotated.png" if annotated.exists() else None,
            baseline_rel=f"data/{page_id}-baseline.png" if baseline.exists() else None,
            candidate_rel=f"data/{page_id}-candidate.png" if candidate.exists() else None,
            layers=layer_views,
            dom_failures=dom_failure_rows,
            asset_diffs=asset_diffs,
        )
        (pages_dir / f"{page_id}.html").write_text(page_html, encoding="utf-8")

    pass_count = sum(1 for page in page_summaries if page["pass"])
    fail_count = len(page_summaries) - pass_count

    index_html = _render(
        INDEX_TEMPLATE,
        css=REPORT_CSS,
        run_id=run_id,
        phase=phase,
        generated_at=generated_at,
        run_pass=run_pass,
        profile=profile,
        profiles=profiles,
        thresholds=thresholds.as_dict(),
        summary_table_html=_summary_table_rows(page_summaries, thresholds),
        profile_sections=profile_sections or [],
        pass_count=pass_count,
        fail_count=fail_count,
    )
    index_path = run_dir / "index.html"
    index_path.write_text(index_html, encoding="utf-8")

    for layer_name, rows in (
        ("visual", sorted(visual_failures, key=lambda row: row["score"])),
        ("dom", dom_failures_index),
        ("assets", asset_failures_index),
    ):
        layer_dir = run_dir / layer_name
        layer_dir.mkdir(parents=True, exist_ok=True)
        layer_html = _render(
            LAYER_INDEX_TEMPLATE,
            css=REPORT_CSS,
            layer=layer_name,
            rows=rows,
        )
        (layer_dir / "index.html").write_text(layer_html, encoding="utf-8")

    return index_path


def copy_page_artifacts(
    *,
    run_dir: Path,
    page_id: str,
    tool_root: Path,
    profile: str | None = None,
) -> None:
    """Copy baseline/candidate PNGs and DOM diff JSON into report data/."""
    data_dir = run_dir / "data"
    data_dir.mkdir(parents=True, exist_ok=True)
    baselines = _baseline_root(tool_root, profile)
    cal_dir = tool_root / "calibrations" / page_id

    baseline_png = baselines / f"{page_id}.png"
    candidate_png = cal_dir / "candidate.png"
    if baseline_png.is_file():
        shutil.copy2(baseline_png, data_dir / f"{page_id}-baseline.png")
    if candidate_png.is_file():
        shutil.copy2(candidate_png, data_dir / f"{page_id}-candidate.png")

    dom_diff_src = run_dir / "data" / f"{page_id}-dom-diff.json"
    if not dom_diff_src.is_file():
        dom_payload = cal_dir / "candidate.dom.html"
        manifests = _manifest_root(tool_root, profile)
        dom_diff_alt = tool_root / "reports" / f"{page_id}-dom-diff.json"
        if dom_diff_alt.is_file():
            shutil.copy2(dom_diff_alt, data_dir / f"{page_id}-dom-diff.json")


def _baseline_root(tool_root: Path, profile: str | None) -> Path:
    if profile:
        return tool_root / "baselines" / profile
    return tool_root / "baselines"


def _manifest_root(tool_root: Path, profile: str | None) -> Path:
    if profile:
        return tool_root / "manifests" / profile
    return tool_root / "manifests"


def write_summary_json(run_dir: Path, summary: dict[str, Any]) -> Path:
    run_dir.mkdir(parents=True, exist_ok=True)
    summary_path = run_dir / "summary.json"
    with summary_path.open("w", encoding="utf-8") as handle:
        json.dump(summary, handle, indent=2)
        handle.write("\n")
    return summary_path


render_summary_table = _summary_table_rows
