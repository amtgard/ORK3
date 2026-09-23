#!/usr/bin/env python3
"""Lightweight HTTP reachability check for fuzzy-validator pages.json5.

Hits each **active** (non-skip) registry page with the auth mode the page
specifies (anonymous vs cookie login). Records status, final URL, and whether
the body looks like an ORK/PHP error page.

Does **not** run Playwright pixel/DOM fuzzy validation.

Usage (from repo root)::

    # Ensure sandbox is up, then:
    bin/ork-db use dev
    python3 tools/fuzzy-validator/scripts/validate_page_http.py \\
        --profile test \\
        -o tools/fuzzy-validator/analysis/http-reachability-test.md

Requires stdlib only. Auth defaults match manifests/profiles.json5.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
import http.cookiejar
from http.cookiejar import CookieJar
from pathlib import Path
from typing import Any

SCRIPT_PATH = Path(__file__).resolve()
TOOL_ROOT = SCRIPT_PATH.parents[1]
REPO_ROOT = TOOL_ROOT.parents[1]
DEFAULT_PAGES = TOOL_ROOT / "manifests" / "pages.json5"
DEFAULT_PROFILES = TOOL_ROOT / "manifests" / "profiles.json5"
DEFAULT_BASE_URL = "http://127.0.0.1:19080/orkui/"

ERROR_MARKERS = (
    "fatal error",
    "uncaught exception",
    "uncaught error",
    "php fatal",
    "allowed memory size of",
    "whoops!",
    "500 internal server error",
    "database error:",
)

# Soft signals: page rendered but is an ORK "sorry" / empty-handler shell.
SOFT_ERROR_MARKERS = (
    "an error has occurred",
    "something went wrong",
    "page not found",
)


@dataclass
class PageResult:
    id: str
    template: str
    url: str
    auth: str
    category: str
    status: int | None
    final_url: str
    elapsed_ms: int
    error_like: bool
    soft_error_like: bool
    snippet: str
    note: str = ""


@dataclass
class RunSummary:
    profile: str
    base_url: str
    started_at: str
    finished_at: str
    counts: dict[str, int] = field(default_factory=dict)
    results: list[PageResult] = field(default_factory=list)


def load_json5_compat(path: Path) -> Any:
    """pages.json5 / profiles.json5 in this repo are JSON-compatible."""
    return json.loads(path.read_text(encoding="utf-8"))


def resolve_page_url(base_url: str, page_url: str) -> str:
    base = base_url if base_url.endswith("/") else base_url + "/"
    rel = page_url.lstrip("./")
    return urllib.parse.urljoin(base, rel)


def canonicalize_route_simple(raw: str) -> str:
    """Minimal template label for reports (ids → {id})."""
    s = raw.strip()
    if "Route=" in s:
        s = s.split("Route=", 1)[1]
    s = s.split()[0] if s else s
    path, _, query = s.partition("&")
    if "?" in path:
        path, _, q2 = path.partition("?")
        query = "&".join(x for x in (q2, query) if x)
    path = path.strip("/")
    if path == "":
        return "(home)"
    parts = []
    for seg in path.split("/"):
        if re.fullmatch(r"\d+", seg):
            parts.append("{id}")
        elif re.fullmatch(
            r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}",
            seg,
        ):
            parts.append("{uuid}")
        else:
            parts.append(seg)
    out = "/".join(parts)
    if len(parts) >= 2 and parts[0] == "Login" and parts[1] == "login":
        out = "Login/login/{return}"
    return out


def profile_auth(profiles: dict[str, Any], profile_name: str) -> tuple[str, str]:
    profile = profiles.get("profiles", {}).get(profile_name)
    if not profile:
        raise SystemExit(f"unknown profile '{profile_name}' in {DEFAULT_PROFILES}")
    auth = profile.get("auth", {})
    user = auth.get("username") or os.environ.get(auth.get("usernameEnv") or "", "")
    user_env = auth.get("usernameEnv")
    if user_env and os.environ.get(user_env):
        user = os.environ[user_env]
    if not user:
        user = os.environ.get("ORK3_E2E_USERNAME", "")

    password = ""
    password_env = auth.get("passwordEnv")
    if password_env and os.environ.get(password_env):
        password = os.environ[password_env]
    elif auth.get("passwordDefault"):
        password = str(auth["passwordDefault"])
    else:
        password = os.environ.get("ORK3_E2E_PASSWORD", "")

    if not user:
        user = os.environ.get("ORK3_E2E_USERNAME", "")
    if not password:
        password = os.environ.get("ORK3_E2E_PASSWORD", "")
    return str(user), str(password)


def fixup_localhost_cookies(jar: CookieJar) -> None:
    """PHP often sets Domain=.localhost; urllib may not send those to host localhost.

    Rewrite to bare ``localhost`` so subsequent requests include PHPSESSID.
    Prefer base URL ``http://127.0.0.1:…`` (see e2e README) to avoid this entirely.
    """
    for cookie in jar:
        domain = (cookie.domain or "").lstrip(".").lower()
        if domain == "localhost":
            cookie.domain = "localhost"
            cookie.domain_initial_dot = False
            cookie.domain_specified = True


class _LocalhostFriendlyPolicy(http.cookiejar.DefaultCookiePolicy):
    """Accept Domain=.localhost / .127.0.0.1 cookies for matching hosts."""

    def return_ok_domain(self, cookie, request):  # noqa: ANN001
        host = urllib.parse.urlparse(request.get_full_url()).hostname or ""
        host = host.lower()
        cdom = (cookie.domain or "").lstrip(".").lower()
        if cdom in {"localhost", "127.0.0.1"} and host in {"localhost", "127.0.0.1"}:
            return True
        return super().return_ok_domain(cookie, request)

    def set_ok_domain(self, cookie, request):  # noqa: ANN001
        host = urllib.parse.urlparse(request.get_full_url()).hostname or ""
        host = host.lower()
        cdom = (cookie.domain or "").lstrip(".").lower()
        if cdom in {"localhost", "127.0.0.1"} and host in {"localhost", "127.0.0.1"}:
            return True
        return super().set_ok_domain(cookie, request)


def make_opener(jar: CookieJar | None = None) -> urllib.request.OpenerDirector:
    handlers: list[Any] = []
    if jar is not None:
        jar.set_policy(_LocalhostFriendlyPolicy())
        handlers.append(urllib.request.HTTPCookieProcessor(jar))
    # Follow redirects so we can classify final landing vs requested URL.
    handlers.append(urllib.request.HTTPRedirectHandler())
    return urllib.request.build_opener(*handlers)


def fetch(
    opener: urllib.request.OpenerDirector,
    url: str,
    *,
    method: str = "GET",
    data: bytes | None = None,
    timeout: float = 30.0,
    max_body: int = 256_000,
) -> tuple[int, str, str, int]:
    """Return (status, final_url, body_text, elapsed_ms)."""
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header(
        "User-Agent",
        "ORK3-fuzzy-validator-http-check/1.0 (+tools/fuzzy-validator/scripts)",
    )
    if data is not None:
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
    started = time.perf_counter()
    try:
        with opener.open(req, timeout=timeout) as resp:
            raw = resp.read(max_body)
            status = getattr(resp, "status", None) or resp.getcode() or 0
            final = resp.geturl()
            charset = resp.headers.get_content_charset() or "utf-8"
            body = raw.decode(charset, errors="replace")
            elapsed = int((time.perf_counter() - started) * 1000)
            return int(status), final, body, elapsed
    except urllib.error.HTTPError as exc:
        raw = exc.read(max_body) if exc.fp else b""
        body = raw.decode("utf-8", errors="replace")
        elapsed = int((time.perf_counter() - started) * 1000)
        return int(exc.code), exc.geturl() if hasattr(exc, "geturl") else url, body, elapsed
    except TimeoutError:
        elapsed = int((time.perf_counter() - started) * 1000)
        return None, url, "", elapsed  # type: ignore[return-value]
    except urllib.error.URLError as exc:
        elapsed = int((time.perf_counter() - started) * 1000)
        reason = str(getattr(exc, "reason", exc))
        if "timed out" in reason.lower() or "timeout" in reason.lower():
            return None, url, "", elapsed  # type: ignore[return-value]
        raise


def body_looks_error(body: str) -> tuple[bool, bool]:
    # Prefer title + early body (avoid matching strings inside large JS bundles).
    head = body[:8000]
    low_head = head.lower()
    hard = any(m in low_head for m in ERROR_MARKERS)
    if not hard and status_title_error(body):
        hard = True
    soft = (not hard) and any(m in low_head for m in SOFT_ERROR_MARKERS)
    return hard, soft


def status_title_error(body: str) -> bool:
    m = re.search(r"<title>([^<]+)</title>", body, re.I)
    if not m:
        return False
    t = m.group(1).lower()
    return any(x in t for x in ("500", "502", "503", "fatal", "exception", "error"))


def snippet_from_body(body: str, limit: int = 160) -> str:
    text = re.sub(r"\s+", " ", body)
    # Prefer title
    m = re.search(r"<title>([^<]+)</title>", body, re.I)
    if m:
        title = m.group(1).strip()
        return title[:limit]
    return text.strip()[:limit]


def login_session(
    base_url: str, username: str, password: str, timeout: float
) -> CookieJar:
    jar = CookieJar()
    opener = make_opener(jar)
    login_url = resolve_page_url(base_url, "./index.php?Route=Login/login")
    payload = urllib.parse.urlencode(
        {"username": username, "password": password}
    ).encode("utf-8")
    status, final, body, _ = fetch(
        opener, login_url, method="POST", data=payload, timeout=timeout
    )
    fixup_localhost_cookies(jar)
    if status is None:
        raise SystemExit("login timed out")
    low = body.lower()
    if status >= 400 or "login failed" in low:
        raise SystemExit(
            f"login failed (status={status} final={final}); "
            "check ORK3_E2E_* / profile passwordDefault"
        )
    # Sanity: expect session cookie
    if not any(c.name.upper() == "PHPSESSID" for c in jar):
        raise SystemExit(f"login did not set PHPSESSID (status={status} final={final})")
    return jar


def classify(
    status: int | None,
    *,
    requested: str,
    final: str,
    error_like: bool,
    soft_error_like: bool,
) -> str:
    if status is None:
        return "timeout"
    if error_like:
        return "error_body"
    if status >= 500:
        return "5xx"
    if status >= 400:
        return "4xx"
    # Redirect that landed elsewhere (urllib follows; compare path)
    req_path = urllib.parse.urlparse(requested).path + "?" + urllib.parse.urlparse(requested).query
    fin_path = urllib.parse.urlparse(final).path + "?" + urllib.parse.urlparse(final).query
    if soft_error_like:
        return "soft_error"
    if status in {200, 204} and normalize_for_compare(req_path) != normalize_for_compare(fin_path):
        return "redirect"
    if status in {200, 204}:
        return "ok"
    if 300 <= status < 400:
        return "redirect"
    return "other"


def normalize_for_compare(s: str) -> str:
    # Collapse trailing empties; ignore Session noise
    return s.rstrip("&").replace(" ", "")


def run_validation(
    *,
    pages: list[dict[str, Any]],
    defaults: dict[str, Any],
    base_url: str,
    username: str,
    password: str,
    timeout: float,
    active_only: bool,
) -> RunSummary:
    started = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    need_login = any(
        (p.get("auth") or defaults.get("auth") or "none") == "login"
        and (not active_only or not p.get("skip"))
        for p in pages
    )
    jar: CookieJar | None = None
    auth_skip_note = ""
    if need_login:
        if not username or not password:
            auth_skip_note = "missing credentials"
            jar = None
        else:
            jar = login_session(base_url, username, password, timeout)

    anon_opener = make_opener()
    auth_opener = make_opener(jar) if jar is not None else None

    results: list[PageResult] = []
    for page in pages:
        if active_only and page.get("skip"):
            continue
        page_id = str(page["id"])
        page_url = str(page.get("url") or "")
        auth = str(page.get("auth") or defaults.get("auth") or "none")
        template = canonicalize_route_simple(page_url)
        full = resolve_page_url(base_url, page_url)

        if auth == "login" and auth_opener is None:
            results.append(
                PageResult(
                    id=page_id,
                    template=template,
                    url=full,
                    auth=auth,
                    category="auth_skip",
                    status=None,
                    final_url="",
                    elapsed_ms=0,
                    error_like=False,
                    soft_error_like=False,
                    snippet="",
                    note=auth_skip_note or "no login session",
                )
            )
            continue

        opener = auth_opener if auth == "login" else anon_opener
        assert opener is not None
        try:
            status, final, body, elapsed = fetch(opener, full, timeout=timeout)
        except Exception as exc:  # noqa: BLE001 — report per-page
            results.append(
                PageResult(
                    id=page_id,
                    template=template,
                    url=full,
                    auth=auth,
                    category="other",
                    status=None,
                    final_url="",
                    elapsed_ms=0,
                    error_like=True,
                    soft_error_like=False,
                    snippet=str(exc)[:160],
                    note="request_error",
                )
            )
            continue

        # fetch() types status as int|None via timeout path
        hard, soft = body_looks_error(body) if body else (False, False)
        category = classify(
            status,
            requested=full,
            final=final,
            error_like=hard,
            soft_error_like=soft,
        )
        results.append(
            PageResult(
                id=page_id,
                template=template,
                url=full,
                auth=auth,
                category=category,
                status=status,
                final_url=final,
                elapsed_ms=elapsed,
                error_like=hard,
                soft_error_like=soft,
                snippet=snippet_from_body(body) if body else "",
            )
        )

    finished = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    counts = Counter(r.category for r in results)
    return RunSummary(
        profile="",
        base_url=base_url,
        started_at=started,
        finished_at=finished,
        counts=dict(counts),
        results=results,
    )


def render_markdown(summary: RunSummary) -> str:
    lines: list[str] = []
    lines.append("# HTTP reachability — fuzzy-validator pages")
    lines.append("")
    lines.append(f"- **Profile:** `{summary.profile}`")
    lines.append(f"- **Base URL:** `{summary.base_url}`")
    lines.append(f"- **Started:** {summary.started_at}")
    lines.append(f"- **Finished:** {summary.finished_at}")
    lines.append(f"- **Pages checked:** {len(summary.results)}")
    lines.append("")
    lines.append("## Counts")
    lines.append("")
    lines.append("| Category | Count |")
    lines.append("|----------|------:|")
    order = [
        "ok",
        "redirect",
        "soft_error",
        "4xx",
        "5xx",
        "error_body",
        "timeout",
        "auth_skip",
        "other",
    ]
    for key in order:
        if key in summary.counts:
            lines.append(f"| {key} | {summary.counts[key]} |")
    for key, val in sorted(summary.counts.items()):
        if key not in order:
            lines.append(f"| {key} | {val} |")
    lines.append("")

    failing = [
        r
        for r in summary.results
        if r.category in {"4xx", "5xx", "error_body", "timeout", "other", "auth_skip"}
    ]
    soft = [r for r in summary.results if r.category == "soft_error"]
    redirects = [r for r in summary.results if r.category == "redirect"]

    lines.append("## Failing routes")
    lines.append("")
    if not failing:
        lines.append("(none)")
        lines.append("")
    else:
        lines.append("| id | template | status | category | snippet |")
        lines.append("|----|----------|-------:|----------|---------|")
        for r in failing:
            snip = (r.snippet or r.note).replace("|", "\\|")[:120]
            lines.append(
                f"| `{r.id}` | `{r.template}` | {r.status if r.status is not None else '—'} "
                f"| {r.category} | {snip} |"
            )
        lines.append("")

    if soft:
        lines.append("## Soft errors (rendered warning copy)")
        lines.append("")
        lines.append("| id | template | status | snippet |")
        lines.append("|----|----------|-------:|---------|")
        for r in soft:
            snip = r.snippet.replace("|", "\\|")[:120]
            lines.append(f"| `{r.id}` | `{r.template}` | {r.status} | {snip} |")
        lines.append("")

    if redirects:
        lines.append("## Redirects (final URL differs)")
        lines.append("")
        lines.append("| id | template | status | final |")
        lines.append("|----|----------|-------:|-------|")
        for r in redirects:
            lines.append(
                f"| `{r.id}` | `{r.template}` | {r.status} | `{r.final_url}` |"
            )
        lines.append("")

    lines.append("## All results")
    lines.append("")
    lines.append("| id | auth | category | status | ms | template |")
    lines.append("|----|------|----------|-------:|---:|----------|")
    for r in summary.results:
        lines.append(
            f"| `{r.id}` | {r.auth} | {r.category} | "
            f"{r.status if r.status is not None else '—'} | {r.elapsed_ms} | `{r.template}` |"
        )
    lines.append("")
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="HTTP reachability check for fuzzy-validator active pages."
    )
    parser.add_argument("--pages", type=Path, default=DEFAULT_PAGES)
    parser.add_argument("--profiles", type=Path, default=DEFAULT_PROFILES)
    parser.add_argument(
        "--profile",
        default="test",
        help="Auth profile name in profiles.json5 (default: test)",
    )
    parser.add_argument(
        "--base-url",
        default=os.environ.get("ORK3_E2E_BASE_URL", DEFAULT_BASE_URL),
        help="ORK UI base URL (default: ORK3_E2E_BASE_URL or localhost:19080/orkui/)",
    )
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument(
        "--include-skip",
        action="store_true",
        help="Also check pages with skip:true",
    )
    parser.add_argument(
        "-o",
        "--write",
        type=Path,
        default=None,
        help="Write markdown report to this path",
    )
    parser.add_argument(
        "--json-out",
        type=Path,
        default=None,
        help="Also write machine-readable JSON summary",
    )
    args = parser.parse_args(argv)

    registry = load_json5_compat(args.pages)
    pages = registry.get("pages")
    if not isinstance(pages, list):
        print(f"error: {args.pages} missing pages array", file=sys.stderr)
        return 2
    defaults = registry.get("defaults") or {}
    profiles = load_json5_compat(args.profiles)
    username, password = profile_auth(profiles, args.profile)

    summary = run_validation(
        pages=pages,
        defaults=defaults if isinstance(defaults, dict) else {},
        base_url=args.base_url,
        username=username,
        password=password,
        timeout=args.timeout,
        active_only=not args.include_skip,
    )
    summary.profile = args.profile

    md = render_markdown(summary)
    sys.stdout.write(md)
    if args.write:
        args.write.parent.mkdir(parents=True, exist_ok=True)
        args.write.write_text(md, encoding="utf-8")
        print(f"\nWrote {args.write}", file=sys.stderr)
    if args.json_out:
        payload = {
            "profile": summary.profile,
            "baseUrl": summary.base_url,
            "startedAt": summary.started_at,
            "finishedAt": summary.finished_at,
            "counts": summary.counts,
            "results": [asdict(r) for r in summary.results],
        }
        args.json_out.parent.mkdir(parents=True, exist_ok=True)
        args.json_out.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
        print(f"Wrote {args.json_out}", file=sys.stderr)

    # Non-zero only on hard infrastructure failure (zero pages / total auth skip of login pages)
    hard_fails = sum(
        1
        for r in summary.results
        if r.category in {"5xx", "error_body", "timeout", "other"}
    )
    print(
        f"\nSummary: checked={len(summary.results)} "
        f"ok={summary.counts.get('ok', 0)} "
        f"redirect={summary.counts.get('redirect', 0)} "
        f"fail_hard={hard_fails} "
        f"4xx={summary.counts.get('4xx', 0)}",
        file=sys.stderr,
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
