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
nav { margin-bottom: 1rem; font-size: 0.9rem; }
.profile-section { border-left: 4px solid #0366d6; padding-left: 1rem; margin: 2rem 0; }
.thumbs { display: flex; gap: 1rem; flex-wrap: wrap; margin: 0.75rem 0; }
.thumbs figure { margin: 0; }
.thumbs figcaption { font-size: 0.85rem; color: #444; margin-bottom: 0.25rem; }
.thumbs img, .screenshot-thumb {
  display: block; max-width: 240px; border: 2px solid #ddd; border-radius: 4px;
  cursor: pointer; background: #fafafa;
}
.thumbs img.active, .screenshot-thumb.active { border-color: #0366d6; box-shadow: 0 0 0 1px #0366d6; }
.screenshot-viewer { margin: 1rem 0 2rem; }
.screenshot-toolbar { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; }
.screenshot-expand, .screenshot-lb-tab {
  font: inherit; font-size: 0.9rem; padding: 0.35rem 0.75rem; border: 1px solid #ccc;
  border-radius: 4px; background: #f6f8fa; cursor: pointer;
}
.screenshot-expand { font-weight: 600; }
.screenshot-expand:hover { background: #eef4fc; }
.screenshot-hint { font-size: 0.85rem; color: #666; margin: 0.5rem 0 0; }
.screenshot-hint kbd {
  display: inline-block; padding: 0.1rem 0.35rem; border: 1px solid #ccc;
  border-radius: 3px; background: #f6f8fa; font-size: 0.8rem;
}
.screenshot-lightbox {
  position: fixed; inset: 0; z-index: 9999; background: rgba(0, 0, 0, 0.92);
  display: flex; flex-direction: column;
}
.screenshot-lightbox[hidden] { display: none !important; }
.screenshot-lightbox-bar {
  display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;
  padding: 0.75rem 1rem; background: rgba(0, 0, 0, 0.55); color: #fff;
}
.screenshot-lightbox-bar .screenshot-lb-tab {
  background: rgba(255,255,255,0.12); color: #fff; border-color: rgba(255,255,255,0.25);
}
.screenshot-lightbox-bar .screenshot-lb-tab.active { background: #0366d6; border-color: #0366d6; }
.screenshot-lightbox-bar .screenshot-lb-tab:hover { background: rgba(255,255,255,0.22); }
.screenshot-lightbox-title { flex: 1; font-weight: 600; min-width: 8rem; }
.screenshot-lightbox-close {
  font: inherit; padding: 0.35rem 0.85rem; border: 1px solid rgba(255,255,255,0.35);
  border-radius: 4px; background: transparent; color: #fff; cursor: pointer;
}
.screenshot-lightbox-close:hover { background: rgba(255,255,255,0.15); }
.screenshot-lightbox-body {
  flex: 1; display: flex; align-items: center; justify-content: center;
  overflow: auto; padding: 1rem; min-height: 0; position: relative;
}
.screenshot-lightbox-img {
  max-width: 100%; max-height: calc(100vh - 7rem); width: auto; height: auto;
  object-fit: contain; box-shadow: 0 4px 24px rgba(0,0,0,0.5);
}
.screenshot-lightbox-nav {
  position: absolute; top: 50%; transform: translateY(-50%);
  font-size: 2rem; line-height: 1; padding: 0.5rem 0.85rem; border: none;
  background: rgba(255,255,255,0.12); color: #fff; cursor: pointer; border-radius: 4px;
}
.screenshot-lightbox-nav:hover { background: rgba(255,255,255,0.25); }
.screenshot-lightbox-nav.prev { left: 0.75rem; }
.screenshot-lightbox-nav.next { right: 0.75rem; }
"""

REPORT_JS = r"""
(function () {
  function initViewer(root) {
    var thumbs = Array.prototype.slice.call(root.querySelectorAll('.screenshot-thumb'));
    var expandBtn = root.querySelector('.screenshot-expand');
    var lightbox = root.querySelector('.screenshot-lightbox');
    if (!thumbs.length || !lightbox) return;

    var lbImg = lightbox.querySelector('.screenshot-lightbox-img');
    var lbTitle = lightbox.querySelector('.screenshot-lightbox-title');
    var lbTabs = Array.prototype.slice.call(lightbox.querySelectorAll('.screenshot-lb-tab'));
    var activeIndex = 0;

    function frameAt(index) {
      return thumbs[(index + thumbs.length) % thumbs.length];
    }

    function selectIndex(index, openLb) {
      activeIndex = (index + thumbs.length) % thumbs.length;
      var thumb = frameAt(activeIndex);
      var src = thumb.getAttribute('data-src');
      var label = thumb.getAttribute('data-label') || thumb.alt;
      thumbs.forEach(function (t, i) { t.classList.toggle('active', i === activeIndex); });
      lbTabs.forEach(function (t, i) { t.classList.toggle('active', i === activeIndex); });
      lbImg.src = src;
      lbImg.alt = label;
      if (lbTitle) lbTitle.textContent = label;
      if (openLb) openLightbox();
    }

    function openLightbox() {
      lightbox.hidden = false;
      document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
      lightbox.hidden = true;
      document.body.style.overflow = '';
    }

    thumbs.forEach(function (thumb, index) {
      thumb.addEventListener('click', function () { selectIndex(index, true); });
    });
    lbTabs.forEach(function (tab, index) {
      tab.addEventListener('click', function () { selectIndex(index, true); });
    });
    if (expandBtn) expandBtn.addEventListener('click', function () { openLightbox(); });
    lightbox.querySelector('.screenshot-lightbox-close').addEventListener('click', closeLightbox);
    lightbox.querySelector('.screenshot-lightbox-body').addEventListener('click', function (e) {
      if (e.target === e.currentTarget) closeLightbox();
    });
    var prev = lightbox.querySelector('.screenshot-lightbox-nav.prev');
    var next = lightbox.querySelector('.screenshot-lightbox-nav.next');
    if (prev) prev.addEventListener('click', function (e) { e.stopPropagation(); selectIndex(activeIndex - 1, true); });
    if (next) next.addEventListener('click', function (e) { e.stopPropagation(); selectIndex(activeIndex + 1, true); });

    document.addEventListener('keydown', function (e) {
      if (lightbox.hidden) return;
      if (e.key === 'Escape') { closeLightbox(); e.preventDefault(); return; }
      var num = parseInt(e.key, 10);
      if (num >= 1 && num <= thumbs.length) {
        selectIndex(num - 1, true);
        e.preventDefault();
        return;
      }
      if (e.key === 'ArrowLeft') { selectIndex(activeIndex - 1, true); e.preventDefault(); return; }
      if (e.key === 'ArrowRight') { selectIndex(activeIndex + 1, true); e.preventDefault(); return; }
    });

    selectIndex(0, false);
  }

  document.querySelectorAll('[data-screenshot-viewer]').forEach(initViewer);
})();
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
{% if screenshot_frames %}
<h2>Screenshots</h2>
<div class="screenshot-viewer" data-screenshot-viewer tabindex="0">
  <div class="screenshot-toolbar">
    <button type="button" class="screenshot-expand">Fullscreen</button>
  </div>
  <div class="thumbs">
    {% for frame in screenshot_frames %}
    <figure>
      <figcaption>{{ frame.label }}</figcaption>
      <img class="screenshot-thumb" src="../{{ frame.src }}" alt="{{ frame.label }}"
        data-src="../{{ frame.src }}" data-label="{{ frame.label }}">
    </figure>
    {% endfor %}
  </div>
  <p class="screenshot-hint">
    Click a thumbnail to open fullscreen at that view · <strong>Fullscreen</strong> uses the selected thumb ·
    in fullscreen use <kbd>1</kbd>–<kbd>{{ screenshot_frames | length }}</kbd> or <kbd>←</kbd><kbd>→</kbd> · <kbd>Esc</kbd> close
  </p>
  <div class="screenshot-lightbox" hidden>
    <div class="screenshot-lightbox-bar">
      <span class="screenshot-lightbox-title">{{ screenshot_frames[0].label }}</span>
      {% for frame in screenshot_frames %}
      <button type="button" class="screenshot-lb-tab{% if loop.first %} active{% endif %}"
        data-src="../{{ frame.src }}" data-label="{{ frame.label }}">{{ frame.label }}</button>
      {% endfor %}
      <button type="button" class="screenshot-lightbox-close">Close (Esc)</button>
    </div>
    <div class="screenshot-lightbox-body">
      <button type="button" class="screenshot-lightbox-nav prev" aria-label="Previous">‹</button>
      <img class="screenshot-lightbox-img" src="../{{ screenshot_frames[0].src }}"
        alt="{{ screenshot_frames[0].label }}">
      <button type="button" class="screenshot-lightbox-nav next" aria-label="Next">›</button>
    </div>
  </div>
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
<script>{{ report_js | safe }}</script>
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


def _screenshot_frames(
    *,
    baseline_rel: str | None,
    candidate_rel: str | None,
    annotated_rel: str | None,
) -> list[dict[str, str]]:
    frames: list[dict[str, str]] = []
    if baseline_rel:
        frames.append({"label": "Baseline", "src": baseline_rel})
    if candidate_rel:
        frames.append({"label": "Candidate", "src": candidate_rel})
    if annotated_rel:
        frames.append({"label": "Annotated", "src": annotated_rel})
    return frames


def _thresholds_from_summary(raw: dict[str, Any]) -> Thresholds:
    return Thresholds(
        assets_min=float(raw.get("assetsMinScore", 1.0)),
        dom_min=float(raw.get("domMinScore", 1.0)),
        visual_min=float(raw.get("visualMinScore", 1.0)),
    )


def refresh_report_bundle(run_dir: Path) -> Path | None:
    """Regenerate HTML from an existing summary.json (e.g. evidence reports)."""
    summary_path = run_dir / "summary.json"
    if not summary_path.is_file():
        return None
    with summary_path.open(encoding="utf-8") as handle:
        summary = json.load(handle)
    page_results = summary.get("pagesDetailed") or []
    if not page_results:
        return None
    thresholds = _thresholds_from_summary(summary.get("thresholds", {}))
    return write_report_bundle(
        run_dir=run_dir,
        run_id=str(summary.get("runId", run_dir.name)),
        phase=str(summary.get("phase", "all")),
        page_results=page_results,
        thresholds=thresholds,
        run_pass=bool(summary.get("pass", summary.get("exitCode", 1) == 0)),
        profile=summary.get("profile"),
    )


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
            parts.append(f"FAIL — {name} score {layer['score']:.3f} < threshold {threshold:.3f}")
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

        annotated_rel = f"data/{page_id}-annotated.png" if annotated.exists() else None
        baseline_rel = f"data/{page_id}-baseline.png" if baseline.exists() else None
        candidate_rel = f"data/{page_id}-candidate.png" if candidate.exists() else None
        screenshot_frames = _screenshot_frames(
            baseline_rel=baseline_rel,
            candidate_rel=candidate_rel,
            annotated_rel=annotated_rel,
        )

        page_html = _render(
            PAGE_TEMPLATE,
            css=REPORT_CSS,
            report_js=REPORT_JS,
            page_id=page_id,
            page_pass=passed,
            verdict=_verdict_strip(page_id, layers, thresholds, passed),
            screenshot_frames=screenshot_frames,
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
