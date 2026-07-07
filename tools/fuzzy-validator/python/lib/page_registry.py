"""Load and validate manifests/pages.json5."""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any

from lib.manifest import load_json5

TOOL_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_PAGES_PATH = TOOL_ROOT / "manifests" / "pages.json5"

PAGE_ID_PATTERN = re.compile(r"^[a-z0-9-]+$")
VALID_AUTH = frozenset({"none", "login"})


def load_pages_registry(path: Path | str | None = None) -> dict[str, Any]:
    return load_json5(path or DEFAULT_PAGES_PATH)


def validate_pages_registry(registry: dict[str, Any]) -> list[str]:
    """Return a list of validation errors (empty when valid)."""
    errors: list[str] = []

    defaults = registry.get("defaults")
    if not isinstance(defaults, dict):
        errors.append("defaults must be an object")
    else:
        viewport = defaults.get("viewport")
        if not isinstance(viewport, dict):
            errors.append("defaults.viewport must be an object")
        elif "width" not in viewport or "height" not in viewport:
            errors.append("defaults.viewport requires width and height")
        if "repeat" not in defaults:
            errors.append("defaults.repeat is required")

    pages = registry.get("pages")
    if not isinstance(pages, list):
        errors.append("pages must be an array")
        return errors

    seen_ids: set[str] = set()
    for index, page in enumerate(pages):
        prefix = f"pages[{index}]"
        if not isinstance(page, dict):
            errors.append(f"{prefix} must be an object")
            continue

        page_id = page.get("id")
        if not page_id:
            errors.append(f"{prefix} missing id")
            continue
        if not isinstance(page_id, str) or not PAGE_ID_PATTERN.match(page_id):
            errors.append(f'{prefix} id "{page_id}" must match [a-z0-9-]+')
        if page_id in seen_ids:
            errors.append(f'duplicate page id "{page_id}"')
        seen_ids.add(page_id)

        url = page.get("url")
        if not url or not isinstance(url, str):
            errors.append(f'page "{page_id}" missing url')

        auth = page.get("auth", defaults.get("auth") if isinstance(defaults, dict) else "none")
        if auth not in VALID_AUTH:
            errors.append(f'page "{page_id}" has invalid auth "{auth}"')

        if "viewport" in page:
            viewport = page["viewport"]
            if not isinstance(viewport, dict) or "width" not in viewport or "height" not in viewport:
                errors.append(f'page "{page_id}" viewport must include width and height')

        if "repeat" in page and not isinstance(page["repeat"], int):
            errors.append(f'page "{page_id}" repeat must be an integer')

        if "waitAfterMs" in page and not isinstance(page["waitAfterMs"], int):
            errors.append(f'page "{page_id}" waitAfterMs must be an integer')

    return errors


def assert_valid_pages_registry(registry: dict[str, Any]) -> None:
    errors = validate_pages_registry(registry)
    if errors:
        raise ValueError("invalid pages registry:\n" + "\n".join(f"  - {err}" for err in errors))


def active_page_ids(registry: dict[str, Any]) -> list[str]:
    return [page["id"] for page in registry["pages"] if not page.get("skip")]


def estimated_calibrate_seconds(registry: dict[str, Any], *, seconds_per_page: int = 55) -> int:
    """Rough runtime for --all calibration (capture ×N + discover per page)."""
    repeat = int(registry.get("defaults", {}).get("repeat", 5))
    capture_seconds = max(30, seconds_per_page * repeat // 5)
    return len(active_page_ids(registry)) * capture_seconds
