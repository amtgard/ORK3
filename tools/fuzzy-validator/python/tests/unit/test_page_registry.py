"""Unit tests for page_registry.py."""

from __future__ import annotations

import pytest

from lib.page_registry import (
    active_page_ids,
    assert_valid_pages_registry,
    estimated_calibrate_seconds,
    load_pages_registry,
    validate_pages_registry,
)


def _minimal_registry(**overrides):
    registry = {
        "defaults": {
            "viewport": {"width": 1280, "height": 720},
            "repeat": 5,
            "waitAfterMs": 500,
            "auth": "none",
        },
        "pages": [
            {"id": "home-anonymous", "url": "./index.php?Route=", "auth": "none"},
            {"id": "player-profile", "url": "./index.php?Route=Player/profile", "auth": "login"},
        ],
    }
    registry.update(overrides)
    return registry


def test_load_committed_registry_has_at_least_twenty_entries():
    registry = load_pages_registry()
    assert len(registry["pages"]) >= 20


def test_committed_registry_validates_clean():
    registry = load_pages_registry()
    assert validate_pages_registry(registry) == []


def test_active_page_ids_excludes_skipped():
    registry = _minimal_registry(
        pages=[
            {"id": "visible", "url": "./index.php?Route=", "auth": "none"},
            {"id": "hidden", "url": "./index.php?Route=Health", "auth": "none", "skip": True},
        ]
    )
    assert active_page_ids(registry) == ["visible"]


def test_validate_rejects_duplicate_ids():
    registry = _minimal_registry(
        pages=[
            {"id": "dup", "url": "./index.php?Route=", "auth": "none"},
            {"id": "dup", "url": "./index.php?Route=Search", "auth": "none"},
        ]
    )
    errors = validate_pages_registry(registry)
    assert any("duplicate" in err for err in errors)


def test_validate_rejects_invalid_auth():
    registry = _minimal_registry(
        pages=[{"id": "bad-auth", "url": "./index.php?Route=", "auth": "oauth"}]
    )
    errors = validate_pages_registry(registry)
    assert any("invalid auth" in err for err in errors)


def test_assert_valid_raises_with_details():
    registry = _minimal_registry(pages=[{"id": "no-url"}])
    with pytest.raises(ValueError, match="invalid pages registry"):
        assert_valid_pages_registry(registry)


def test_estimated_calibrate_seconds_scales_with_active_pages():
    registry = _minimal_registry()
    assert estimated_calibrate_seconds(registry) == 110


def test_validate_rejects_missing_defaults_object():
    errors = validate_pages_registry({"pages": []})
    assert any("defaults must be an object" in err for err in errors)


def test_validate_rejects_non_dict_viewport():
    registry = {
        "defaults": {"viewport": "wide", "repeat": 1, "auth": "none"},
        "pages": [{"id": "ok", "url": "./index.php", "auth": "none"}],
    }
    errors = validate_pages_registry(registry)
    assert any("defaults.viewport must be an object" in err for err in errors)


def test_validate_rejects_bad_viewport_and_repeat():
    registry = {
        "defaults": {"viewport": {"width": 1280}, "auth": "none"},
        "pages": [{"id": "ok", "url": "./index.php", "auth": "none"}],
    }
    errors = validate_pages_registry(registry)
    assert any("viewport" in err for err in errors)
    assert any("repeat" in err for err in errors)


def test_validate_rejects_non_array_pages():
    errors = validate_pages_registry({"defaults": {"viewport": {"width": 1, "height": 1}, "repeat": 1}, "pages": {}})
    assert any("pages must be an array" in err for err in errors)


def test_validate_rejects_non_object_page_and_bad_id():
    registry = _minimal_registry(
        pages=[
            "not-an-object",
            {"id": "Bad_ID", "url": "./x", "auth": "none"},
            {"url": "./x", "auth": "none"},
        ]
    )
    errors = validate_pages_registry(registry)
    assert any("must be an object" in err for err in errors)
    assert any("must match" in err for err in errors)
    assert any("missing id" in err for err in errors)


def test_validate_rejects_bad_page_viewport_repeat_wait_and_drift():
    registry = _minimal_registry(
        pages=[
            {
                "id": "bad-meta",
                "url": "./index.php",
                "auth": "none",
                "viewport": {"width": 100},
                "repeat": "five",
                "waitAfterMs": "soon",
                "driftClass": "volatile",
            }
        ]
    )
    errors = validate_pages_registry(registry)
    assert any("viewport must include" in err for err in errors)
    assert any("repeat must be an integer" in err for err in errors)
    assert any("waitAfterMs must be an integer" in err for err in errors)
    assert any("driftClass must be one of" in err for err in errors)


def test_page_ids_by_drift_class():
    from lib.page_registry import page_ids_by_drift_class

    registry = _minimal_registry(
        pages=[
            {"id": "stable-page", "url": "./a", "auth": "none", "driftClass": "stable"},
            {"id": "natural-page", "url": "./b", "auth": "none", "driftClass": "natural"},
            {"id": "skipped", "url": "./c", "auth": "none", "driftClass": "natural", "skip": True},
        ]
    )
    assert page_ids_by_drift_class(registry, "natural") == ["natural-page"]
