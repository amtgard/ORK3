#!/usr/bin/env python3
"""Analyze ORK3 orkui route *templates* vs fuzzy-validator page registry coverage.

Discovers candidate frontend routes from controllers, ``orkui/index.php`` special
cases / legacy redirects, and string literals (UIR / Route=). Normalizes each to
a **route template** (instance ids collapsed), then marks coverage against
``tools/fuzzy-validator/manifests/pages.json5``.

Canonical template form
-----------------------
``Controller[/action[/segment...]]`` with optional ``?Key={id}&…`` query shape.

Equivalence / normalization rules (operator):

* Pure decimal path segments → ``{id}``
  (``Kingdom/index/32`` ≡ ``Kingdom/index/47`` → ``Kingdom/index/{id}``)
* UUID segments → ``{uuid}``
* PHP interpolations (``$id``, ``{$event_id}``, …) → ``{id}``
* Non-numeric opaque tokens after a known action (e.g. SignIn/QR) → ``{token}``
* Distinct static actions stay distinct
  (``Event/calendardetail/index`` ≠ ``Event/calendardetail/create``)
* Bare ``Controller`` ≡ ``Controller/index`` (dispatch when Route has one segment)
* Empty Route (home) → ``(home)``
* ``Login/login/<anything>`` → ``Login/login/{return}`` (return-path wrapper)
* Query values that look like ids → ``{id}``; other values → ``{q}``;
  query keys are sorted for stability

Coverage statuses
-----------------
* **covered** — template matches ≥1 non-``skip`` registry page
* **skipped** — only ``skip: true`` registry pages match
* **missing** — discovered from code, not in the page registry
* **ambiguous** — in the registry, but no matching controller public action
  (literal / __call / stale route)

Exit code is non-zero only on script errors (gaps are expected output).

Usage
-----
From repo root::

    python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py
    python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py \\
        --write tools/fuzzy-validator/analysis/route-coverage-report.txt
    python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py --ui-only

Requires only the stdlib (pages.json5 in this repo is JSON-compatible).
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import defaultdict
from dataclasses import dataclass, field
from pathlib import Path

SCRIPT_PATH = Path(__file__).resolve()
TOOL_ROOT = SCRIPT_PATH.parents[1]
REPO_ROOT = TOOL_ROOT.parents[1]
DEFAULT_PAGES = TOOL_ROOT / "manifests" / "pages.json5"
DEFAULT_CONTROLLERS = REPO_ROOT / "orkui" / "controller"
DEFAULT_INDEX = REPO_ROOT / "orkui" / "index.php"

UUID_RE = re.compile(
    r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$"
)
NUMERIC_RE = re.compile(r"^\d+$")
PHP_VAR_RE = re.compile(r"^\{?\$[A-Za-z_][\w\}\[\]'\"->]*\}?$")
# Rough PHP public method: public function name($a = null, ...)
METHOD_RE = re.compile(
    r"public\s+function\s+(\w+)\s*\(([^)]*)\)",
    re.MULTILINE,
)
CONTROLLER_FILE_RE = re.compile(r"^controller\.(.+)\.php$")
# UIR.'Foo/bar/' or UIR."Foo/bar/$id"
UIR_LITERAL_RE = re.compile(
    r"""UIR\s*\.\s*(?:['"]([A-Za-z][^'"]{0,120})['"]|\"([A-Za-z][^\"]{0,120})\")"""
)
# Route=Foo/bar/1 or ?Route=...
ROUTE_ASSIGN_RE = re.compile(
    r"""(?:Route=|\?Route=)([A-Za-z][A-Za-z0-9_./&?=*%${}-]*)"""
)
# Legacy redirect keys in index.php
LEGACY_REDIRECT_RE = re.compile(
    r"""['\"]([A-Za-z]+/[A-Za-z0-9_]+/)['\"]\s*=>\s*['\"]([A-Za-z]+/[A-Za-z0-9_]+/)['\"]"""
)

SKIP_METHODS = frozenset(
    {
        "__construct",
        "__destruct",
        "__call",
        "__get",
        "__set",
        "__isset",
        "__unset",
        "__toString",
        "__invoke",
        "__clone",
        "__sleep",
        "__wakeup",
        "__debugInfo",
    }
)

# Methods available via system/lib/system/class.Controller.php inheritance.
INHERITED_BASE_METHODS = frozenset({"index"})

# First-parameter names that are form/POST flags, not path segments.
POST_PARAM_NAMES = frozenset({"post", "submit", "duh"})

# Path positions that commonly hold opaque tokens (not named actions).
TOKEN_ACTIONS = frozenset(
    {
        ("SignIn", "index"),
        ("QR", "link"),
        ("SelfReg", "form"),
        ("SelfReg", "check_username"),
    }
)

ID_QUERY_KEYS = frozenset(
    {
        "id",
        "kingdomid",
        "parkid",
        "eventid",
        "playerid",
        "mundaneid",
        "unitid",
        "detailid",
        "tournamentid",
    }
)


@dataclass
class TemplateInfo:
    template: str
    sources: set[str] = field(default_factory=set)
    controller_actions: set[str] = field(default_factory=set)  # "Event.detail"
    registry_pages: list[dict] = field(default_factory=list)

    @property
    def from_controller(self) -> bool:
        return any(s.startswith("controller:") for s in self.sources)

    @property
    def has_known_handler(self) -> bool:
        """True when a real dispatch target exists (controller method, Health, home)."""
        if self.template in {"(home)", "Health"}:
            return True
        if self.controller_actions:
            return True
        return self.from_controller

    @property
    def status(self) -> str:
        active = [p for p in self.registry_pages if not p.get("skip")]
        skipped = [p for p in self.registry_pages if p.get("skip")]
        if active:
            if not self.has_known_handler:
                return "ambiguous"
            return "covered"
        if skipped:
            if not self.has_known_handler:
                return "ambiguous"
            return "skipped"
        return "missing"


def repo_rel(path: Path) -> str:
    try:
        return str(path.resolve().relative_to(REPO_ROOT))
    except ValueError:
        return str(path)


def normalize_segment(seg: str, *, controller: str | None, action: str | None, index: int) -> str:
    s = seg.strip()
    if not s:
        return ""
    if NUMERIC_RE.match(s):
        return "{id}"
    if UUID_RE.match(s):
        return "{uuid}"
    if PHP_VAR_RE.match(s) or "$" in s or s.startswith("{") and "}" in s:
        return "{id}"
    # Opaque tokens: SignIn/index/abc, QR/link/abc
    if (
        index >= 2
        and controller
        and action
        and (controller, action) in TOKEN_ACTIONS
        and not s[0].isupper()
        and "_" not in s
    ):
        # Keep named-looking static segments; collapse short opaque tokens.
        if re.fullmatch(r"[A-Za-z0-9_-]{1,64}", s) and s.lower() not in {
            "index",
            "create",
            "edit",
            "delete",
            "get",
            "list",
            "json",
        }:
            return "{token}"
    return s


def normalize_query(query: str) -> str:
    """Normalize ``a=1&b=foo`` → sorted ``a={id}&b={q}`` (empty → '')."""
    query = query.lstrip("?&")
    if not query:
        return ""
    parts: list[tuple[str, str]] = []
    for piece in query.split("&"):
        if not piece:
            continue
        if "=" in piece:
            key, val = piece.split("=", 1)
        else:
            key, val = piece, ""
        key_l = key.lower()
        if not val:
            norm_val = ""
        elif NUMERIC_RE.match(val) or key_l in ID_QUERY_KEYS:
            norm_val = "{id}"
        elif UUID_RE.match(val):
            norm_val = "{uuid}"
        else:
            norm_val = "{q}"
        parts.append((key, norm_val))
    parts.sort(key=lambda kv: kv[0].lower())
    return "&".join(f"{k}={v}" if v != "" else k for k, v in parts)


def extract_route_string(raw: str) -> str:
    """Pull a Route= path out of a fuzzy-validator url or bare route fragment."""
    s = raw.strip()
    if "Route=" in s:
        s = s.split("Route=", 1)[1]
    # Cut off unrelated URL junk after space
    s = s.split()[0] if s else s
    return s.strip().strip("'\"")


def canonicalize_route(raw: str) -> str | None:
    """Return canonical route template, or None if unusable."""
    s = extract_route_string(raw)
    if s is None:
        return None
    # Split query: first ``&`` after path starts query in ORK Route URLs.
    path, _, query = s.partition("&")
    if "?" in path:
        path, _, q2 = path.partition("?")
        query = "&".join(x for x in (q2, query) if x)

    path = path.strip("/")
    if path == "":
        return "(home)"

    segments = [p for p in path.split("/") if p != ""]
    if not segments:
        return "(home)"

    # Special early-exit probe in orkui/index.php (no controller class).
    if len(segments) == 1 and segments[0].lower() == "health":
        return "Health"

    # Login return-path wrapper: one template.
    if len(segments) >= 2 and segments[0] == "Login" and segments[1] == "login":
        path_t = "Login/login/{return}"
        q = normalize_query(query)
        return f"{path_t}?{q}" if q else path_t

    controller = segments[0]
    # Bare controller → Controller/index
    if len(segments) == 1:
        path_t = f"{controller}/index"
        q = normalize_query(query)
        return f"{path_t}?{q}" if q else path_t

    action = segments[1]
    # Keep named actions; only collapse if the "action" itself is an id/var.
    if NUMERIC_RE.match(action) or UUID_RE.match(action) or PHP_VAR_RE.match(action) or "$" in action:
        action_norm = normalize_segment(action, controller=controller, action=None, index=1)
    else:
        action_norm = action

    rest = [
        normalize_segment(seg, controller=controller, action=action, index=i)
        for i, seg in enumerate(segments[2:], start=2)
    ]
    rest = [r for r in rest if r]
    path_t = "/".join([controller, action_norm, *rest])
    q = normalize_query(query)
    return f"{path_t}?{q}" if q else path_t


def path_without_query(template: str) -> str:
    return template.split("?", 1)[0]


def controller_action_key(template: str) -> str | None:
    """Map template → ``Controller.action`` for method lookup."""
    path = path_without_query(template)
    if path in {"(home)", "Health"}:
        return None
    parts = path.split("/")
    if len(parts) < 2:
        return None
    if parts[0] == "Login" and parts[1] == "login":
        return "Login.login"
    return f"{parts[0]}.{parts[1]}"


def parse_controller_methods(controller_dir: Path) -> dict[str, dict[str, dict]]:
    """Return {ControllerName: {method: {required, optional, params_raw}}}."""
    out: dict[str, dict[str, dict]] = {}
    for path in sorted(controller_dir.glob("controller.*.php")):
        m = CONTROLLER_FILE_RE.match(path.name)
        if not m:
            continue
        name = m.group(1)
        text = path.read_text(encoding="utf-8", errors="replace")
        methods: dict[str, dict] = {}
        for match in METHOD_RE.finditer(text):
            method, params_raw = match.group(1), match.group(2).strip()
            if method in SKIP_METHODS or method.startswith("_"):
                continue
            required = 0
            optional = 0
            if params_raw:
                for part in params_raw.split(","):
                    part = part.strip()
                    if not part:
                        continue
                    if "=" in part:
                        optional += 1
                    else:
                        required += 1
            methods[method] = {
                "required": required,
                "optional": optional,
                "params_raw": params_raw,
                "file": repo_rel(path),
            }
        out[name] = methods
    return out


def _first_param_name(params_raw: str) -> str:
    if not params_raw.strip():
        return ""
    first = params_raw.split(",", 1)[0].strip()
    name = first.split("=", 1)[0].strip()
    return name.lstrip("&").lstrip("$")


def _emits_path_param(meta: dict) -> bool:
    """True when the method's first arg is likely a Route path segment."""
    if meta["required"] >= 1:
        return True
    if meta["optional"] < 1:
        return False
    name = _first_param_name(meta["params_raw"]).lower()
    if name in POST_PARAM_NAMES:
        return False
    return True


def templates_from_controllers(controllers: dict[str, dict[str, dict]]) -> dict[str, TemplateInfo]:
    infos: dict[str, TemplateInfo] = {}

    def add(template: str, controller: str, method: str, file: str) -> None:
        info = infos.setdefault(template, TemplateInfo(template=template))
        info.sources.add(f"controller:{file}")
        info.controller_actions.add(f"{controller}.{method}")

    for controller, methods in controllers.items():
        for method, meta in methods.items():
            file = meta["file"]
            # Always callable as Controller/method when no required args.
            if meta["required"] == 0:
                add(f"{controller}/{method}", controller, method, file)
            # Parameterized dispatch: remaining path segments become $action.
            if _emits_path_param(meta):
                add(f"{controller}/{method}/{{id}}", controller, method, file)
            # Common multi-id pages seen in Event/detail literals.
            if method == "detail" and controller == "Event":
                add("Event/detail/{id}/{id}", controller, method, file)
        # Bare controller hits index (declared or inherited from base Controller).
        if "index" in methods:
            add(f"{controller}/index", controller, "index", methods["index"]["file"])
        else:
            add(
                f"{controller}/index",
                controller,
                "index",
                "system/lib/system/class.Controller.php",
            )

    info = infos.setdefault("Health", TemplateInfo(template="Health"))
    info.sources.add("special:orkui/index.php")
    info.controller_actions.add("Health")
    return infos


def harvest_literals(roots: list[Path]) -> list[tuple[str, str]]:
    """Return list of (raw_route, source_label)."""
    found: list[tuple[str, str]] = []
    globs = ("**/*.php", "**/*.tpl", "**/*.js", "**/*.ts")
    for root in roots:
        if not root.exists():
            continue
        for pattern in globs:
            for path in root.glob(pattern):
                if "node_modules" in path.parts or "vendor" in path.parts:
                    continue
                try:
                    text = path.read_text(encoding="utf-8", errors="replace")
                except OSError:
                    continue
                rel = repo_rel(path)
                for m in UIR_LITERAL_RE.finditer(text):
                    lit = m.group(1) or m.group(2) or ""
                    # Truncate at PHP concat boundary markers left in string.
                    lit = lit.split("{$", 1)[0]
                    lit = re.split(r"\$[A-Za-z_]", lit, maxsplit=1)[0]
                    if lit and re.match(r"^[A-Za-z]", lit):
                        found.append((lit, f"literal:{rel}"))
                for m in ROUTE_ASSIGN_RE.finditer(text):
                    found.append((m.group(1), f"literal:{rel}"))
    return found


def harvest_index_specials(index_path: Path) -> list[tuple[str, str]]:
    found: list[tuple[str, str]] = []
    if not index_path.exists():
        return found
    text = index_path.read_text(encoding="utf-8", errors="replace")
    rel = repo_rel(index_path)
    found.append(("Health", f"special:{rel}"))
    found.append(("", f"special:{rel}"))  # home
    for m in LEGACY_REDIRECT_RE.finditer(text):
        old, new = m.group(1), m.group(2)
        found.append((old + "{id}", f"legacy:{rel}"))
        found.append((new + "{id}", f"legacy:{rel}"))
    # Event/index/{id} redirect mentioned explicitly
    if "Event/index/" in text:
        found.append(("Event/index/{id}", f"special:{rel}"))
    return found


def load_registry(pages_path: Path) -> list[dict]:
    data = json.loads(pages_path.read_text(encoding="utf-8"))
    pages = data.get("pages")
    if not isinstance(pages, list):
        raise ValueError(f"{pages_path}: missing pages array")
    return pages


def merge_template(
    store: dict[str, TemplateInfo],
    template: str | None,
    source: str,
    *,
    controller_action: str | None = None,
) -> None:
    if not template:
        return
    info = store.setdefault(template, TemplateInfo(template=template))
    info.sources.add(source)
    if controller_action:
        info.controller_actions.add(controller_action)


def attach_registry(
    store: dict[str, TemplateInfo],
    pages: list[dict],
    *,
    controllers: dict[str, dict[str, dict]],
) -> list[dict]:
    """Attach registry pages; return orphan page rows (unparseable url)."""
    orphans: list[dict] = []
    for page in pages:
        url = page.get("url") or ""
        template = canonicalize_route(url)
        if not template:
            orphans.append(page)
            continue
        info = store.setdefault(template, TemplateInfo(template=template))
        info.sources.add("registry:pages.json5")
        info.registry_pages.append(
            {
                "id": page.get("id"),
                "url": url,
                "skip": bool(page.get("skip")),
                "auth": page.get("auth"),
            }
        )
        # If controller method exists (or inherited index), mark it.
        key = controller_action_key(template)
        if key:
            ctrl, _, method = key.partition(".")
            if ctrl in controllers and method in controllers[ctrl]:
                info.controller_actions.add(key)
                info.sources.add(
                    f"controller:{controllers[ctrl][method]['file']}"
                )
            elif ctrl in controllers and method in INHERITED_BASE_METHODS:
                info.controller_actions.add(key)
                info.sources.add("controller:system/lib/system/class.Controller.php")
        if template == "Health":
            info.sources.add("special:orkui/index.php")
            info.controller_actions.add("Health")
    return orphans


def is_ajax_template(template: str) -> bool:
    path = path_without_query(template)
    ctrl = path.split("/", 1)[0]
    return ctrl.endswith("Ajax") or ctrl in {"WnAjax", "EventRsvpAjax"}


def explain_delta_category(template: str) -> str:
    """Bucket a non-ui-only (Ajax-filtered-out) or otherwise excluded template."""
    path = path_without_query(template)
    ctrl = path.split("/", 1)[0]
    if is_ajax_template(template):
        return "ajax"
    if path == "Health" or ctrl == "Health":
        return "api-health"
    if ctrl == "EraPhoenice":
        return "api-era"
    if ctrl == "QR":
        return "api-qr"
    if path.startswith("Admin/ajax"):
        return "admin-ajax"
    if path in {"Login/login", "Login/login/{id}"}:
        return "canonicalize-alias"
    if path in {"Event/kingdom/{id}", "Event/park/{id}"}:
        return "dead-ambiguous"
    return "other"


def build_delta_explanation(
    store: dict[str, TemplateInfo],
    *,
    dump_path: Path | None = None,
) -> str:
    """Explain all-discovered vs --ui-only template counts."""
    all_items = list(store.values())
    ui_items = [i for i in all_items if not is_ajax_template(i.template)]
    non_ui = [i for i in all_items if is_ajax_template(i.template)]
    non_ui.sort(key=lambda i: i.template.lower())

    by_cat: dict[str, list[str]] = defaultdict(list)
    for info in non_ui:
        by_cat[explain_delta_category(info.template)].append(info.template)

    lines: list[str] = []
    lines.append("Template count delta: all discovery vs --ui-only")
    lines.append("=" * 72)
    lines.append("")
    lines.append(f"  All discovered templates : {len(all_items)}")
    lines.append(f"  --ui-only templates      : {len(ui_items)}")
    lines.append(f"  Delta (all − ui-only)    : {len(all_items) - len(ui_items)}")
    lines.append("")
    lines.append("Delta is exactly the Ajax filter (`is_ajax_template`): controllers")
    lines.append("whose name ends with Ajax, plus WnAjax / EventRsvpAjax.")
    lines.append("API/Health/EraPhoenice/QR remain inside --ui-only and are excluded")
    lines.append("later by the include-list policy (see REGISTRY-EXPANSION.md).")
    lines.append("")
    lines.append("Non-ui-only templates by category")
    lines.append("---------------------------------")
    for cat in sorted(by_cat.keys()):
        rows = by_cat[cat]
        lines.append(f"  {cat:20} {len(rows):4d}")
    lines.append("")
    lines.append("Examples (up to 8 per category)")
    lines.append("-------------------------------")
    for cat in sorted(by_cat.keys()):
        rows = by_cat[cat]
        lines.append(f"  [{cat}]")
        for t in rows[:8]:
            lines.append(f"    {t}")
        if len(rows) > 8:
            lines.append(f"    … +{len(rows) - 8} more")
        lines.append("")

    if dump_path is not None:
        dump_path.parent.mkdir(parents=True, exist_ok=True)
        dump_path.write_text(
            "\n".join(i.template for i in non_ui) + ("\n" if non_ui else ""),
            encoding="utf-8",
        )
        lines.append(f"Wrote non-ui template list: {dump_path}")
        lines.append("")

    return "\n".join(lines) + "\n"


def build_report(
    store: dict[str, TemplateInfo],
    *,
    pages: list[dict],
    ui_only: bool,
) -> str:
    items = list(store.values())
    if ui_only:
        items = [i for i in items if not is_ajax_template(i.template)]

    by_status: dict[str, list[TemplateInfo]] = defaultdict(list)
    for info in items:
        by_status[info.status].append(info)

    for lst in by_status.values():
        lst.sort(key=lambda i: i.template.lower())

    active_pages = [p for p in pages if not p.get("skip")]
    skipped_pages = [p for p in pages if p.get("skip")]
    active_templates = {
        canonicalize_route(p["url"])
        for p in active_pages
        if canonicalize_route(p.get("url") or "")
    }
    all_reg_templates = {
        canonicalize_route(p["url"])
        for p in pages
        if canonicalize_route(p.get("url") or "")
    }

    lines: list[str] = []
    lines.append("ORK3 fuzzy-validator route template coverage")
    lines.append("=" * 72)
    lines.append("")
    lines.append("Canonical form: Controller[/action[/segment…]][?sortedQuery]")
    lines.append("  {id} = decimal resource id; {uuid}; {token}; {return}; {q}")
    lines.append("  Bare Controller ≡ Controller/index; empty Route ≡ (home)")
    lines.append("")
    lines.append("Summary")
    lines.append("-------")
    lines.append(f"  Registry pages (total)     : {len(pages)}")
    lines.append(f"  Registry pages (active)    : {len(active_pages)}")
    lines.append(f"  Registry pages (skip)      : {len(skipped_pages)}")
    lines.append(f"  Distinct registry templates: {len(all_reg_templates)}")
    lines.append(f"  Distinct active templates  : {len(active_templates)}")
    lines.append(f"  Discovered templates       : {len(items)}" + (" [ui-only]" if ui_only else ""))
    lines.append(f"    covered                  : {len(by_status['covered'])}")
    lines.append(f"    skipped (registry only)  : {len(by_status['skipped'])}")
    lines.append(f"    missing                  : {len(by_status['missing'])}")
    lines.append(f"    ambiguous                : {len(by_status['ambiguous'])}")
    lines.append("")

    lines.append("Active setpoint / registry pages (non-skip)")
    lines.append("------------------------------------------")
    for p in sorted(active_pages, key=lambda x: x["id"]):
        tmpl = canonicalize_route(p.get("url") or "") or "?"
        lines.append(f"  {p['id']:32} {tmpl:40} {p.get('url')}")
    lines.append("")

    lines.append("Skipped registry pages")
    lines.append("----------------------")
    for p in sorted(skipped_pages, key=lambda x: x["id"]):
        tmpl = canonicalize_route(p.get("url") or "") or "?"
        lines.append(f"  {p['id']:32} {tmpl:40} {p.get('url')}")
    lines.append("")

    def dump_section(title: str, status: str) -> None:
        lines.append(title)
        lines.append("-" * len(title))
        rows = by_status.get(status, [])
        if not rows:
            lines.append("  (none)")
            lines.append("")
            return
        for info in rows:
            src = ",".join(sorted({s.split(":")[0] for s in info.sources}))
            pages_s = ",".join(
                f"{p['id']}{'*' if p.get('skip') else ''}" for p in info.registry_pages
            ) or "-"
            lines.append(f"  {info.template:48} sources={src:20} pages={pages_s}")
        lines.append("")

    dump_section("MISSING — discovered templates not in page registry", "missing")
    dump_section("AMBIGUOUS — in registry but no controller public action", "ambiguous")
    dump_section("SKIPPED — in registry with skip:true only", "skipped")
    dump_section("COVERED — active registry pages", "covered")

    lines.append("All templates that need testing (union, sorted)")
    lines.append("----------------------------------------------")
    for info in sorted(items, key=lambda i: i.template.lower()):
        lines.append(f"  [{info.status:10}] {info.template}")
    lines.append("")
    lines.append("Note: '*' after a page id means skip:true in pages.json5.")
    lines.append("Exit gaps are informational; process exit code stays 0 on success.")
    return "\n".join(lines) + "\n"


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Discover orkui route templates and compare to fuzzy-validator pages.json5."
    )
    parser.add_argument(
        "--pages",
        type=Path,
        default=DEFAULT_PAGES,
        help=f"Page registry (default: {DEFAULT_PAGES})",
    )
    parser.add_argument(
        "--controllers",
        type=Path,
        default=DEFAULT_CONTROLLERS,
        help="orkui controller directory",
    )
    parser.add_argument(
        "--index",
        type=Path,
        default=DEFAULT_INDEX,
        help="orkui/index.php for Health + legacy redirects",
    )
    parser.add_argument(
        "--write",
        "-o",
        type=Path,
        default=None,
        help="Also write full report to this path",
    )
    parser.add_argument(
        "--ui-only",
        action="store_true",
        help="Omit *Ajax controllers from discovered set",
    )
    parser.add_argument(
        "--explain-delta",
        action="store_true",
        help="Print all-vs-ui-only count breakdown (Ajax filter) instead of full report",
    )
    parser.add_argument(
        "--dump-non-ui",
        type=Path,
        default=None,
        help="With --explain-delta, also write non-ui-only templates (one per line)",
    )
    parser.add_argument(
        "--no-literals",
        action="store_true",
        help="Skip UIR/Route= literal harvest (controllers + index + registry only)",
    )
    args = parser.parse_args(argv)

    if not args.pages.is_file():
        print(f"error: pages registry not found: {args.pages}", file=sys.stderr)
        return 2
    if not args.controllers.is_dir():
        print(f"error: controllers dir not found: {args.controllers}", file=sys.stderr)
        return 2

    controllers = parse_controller_methods(args.controllers)
    store = templates_from_controllers(controllers)

    for raw, src in harvest_index_specials(args.index):
        merge_template(store, canonicalize_route(raw), src)

    if not args.no_literals:
        literal_roots = [
            REPO_ROOT / "orkui",
            REPO_ROOT / "tests" / "e2e",
        ]
        for raw, src in harvest_literals(literal_roots):
            tmpl = canonicalize_route(raw)
            if not tmpl:
                continue
            merge_template(store, tmpl, src)
            key = controller_action_key(tmpl)
            if key:
                ctrl, _, method = key.partition(".")
                if ctrl in controllers and method in controllers[ctrl]:
                    store[tmpl].controller_actions.add(key)
                    store[tmpl].sources.add(
                        f"controller:{controllers[ctrl][method]['file']}"
                    )

    pages = load_registry(args.pages)
    attach_registry(store, pages, controllers=controllers)

    # Ensure home exists
    merge_template(store, "(home)", "special:home")

    if args.explain_delta:
        dump = args.dump_non_ui
        if dump is None and args.write:
            # Convenience: -o path can be the markdown; dump beside it.
            dump = args.write.parent / "non-ui-templates.txt"
        report = build_delta_explanation(store, dump_path=dump)
        sys.stdout.write(report)
        if args.write:
            args.write.parent.mkdir(parents=True, exist_ok=True)
            args.write.write_text(report, encoding="utf-8")
            print(f"\nWrote {args.write}", file=sys.stderr)
        return 0

    report = build_report(store, pages=pages, ui_only=args.ui_only)
    sys.stdout.write(report)

    if args.write:
        args.write.parent.mkdir(parents=True, exist_ok=True)
        args.write.write_text(report, encoding="utf-8")
        print(f"\nWrote {args.write}", file=sys.stderr)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
