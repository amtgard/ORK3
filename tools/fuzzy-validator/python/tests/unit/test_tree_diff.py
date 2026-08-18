"""Unit tests for tree_diff.py."""

from __future__ import annotations

from lib.canonical_dom import html_to_canonical_tree
from lib.tree_diff import (
    collapse_ancestor_fuzz,
    compare_dom_trees,
    consecutive_volatile_paths,
    discover_fuzz_nodes,
    infer_fuzz_mode,
)


def _tree(html: str) -> dict:
    return html_to_canonical_tree(html)


def test_consecutive_volatile_paths_detects_changing_token():
    trees = [
        _tree('<html><body><input value="token-a"></body></html>'),
        _tree('<html><body><input value="token-b"></body></html>'),
        _tree('<html><body><input value="token-c"></body></html>'),
    ]
    volatile = consecutive_volatile_paths(trees)
    assert "/html[0]/body[0]/input[0]" in volatile


def test_infer_fuzz_mode_attributes_for_volatile_value():
    trees = [
        _tree('<html><body><input value="a" data-csrf="1"></body></html>'),
        _tree('<html><body><input value="b" data-csrf="2"></body></html>'),
    ]
    mode = infer_fuzz_mode(trees, "/html[0]/body[0]/input[0]")
    assert mode["mode"] == "attributes"
    assert "value" in mode["attrs"]


def test_discover_fuzz_nodes_collapses_subtree_ancestors():
    fuzz_nodes = [
        {"path": "/html[0]/body[0]/div[0]", "mode": "subtree", "source": "auto"},
        {"path": "/html[0]/body[0]/div[0]/span[0]", "mode": "text", "source": "auto"},
    ]
    collapsed = collapse_ancestor_fuzz(fuzz_nodes)
    assert len(collapsed) == 1
    assert collapsed[0]["mode"] == "subtree"


def test_compare_dom_trees_fails_on_tag_rename_outside_fuzz():
    baseline = _tree('<html><body><div>Stable</div></body></html>')
    candidate = _tree('<html><body><section>Stable</section></body></html>')
    manifest = {"fuzzNodes": [], "manualNodes": []}
    result = compare_dom_trees(baseline, candidate, manifest)
    assert result.passed is False
    assert any(failure.reason == "tag_mismatch" for failure in result.failures)


def test_compare_dom_trees_passes_with_attributes_fuzz():
    baseline = _tree('<html><body><input value="old"></body></html>')
    candidate = _tree('<html><body><input value="new"></body></html>')
    manifest = {
        "fuzzNodes": [
            {
                "path": "/html[0]/body[0]/input[0]",
                "mode": "attributes",
                "attrs": ["value"],
                "source": "manual",
            }
        ],
        "manualNodes": [],
    }
    result = compare_dom_trees(baseline, candidate, manifest)
    assert result.passed is True


def test_compare_dom_trees_fails_on_child_text_with_attributes_fuzz_on_parent():
    baseline = _tree('<html><body><div data-token="a"><h1>Stable</h1></div></body></html>')
    candidate = _tree('<html><body><div data-token="b"><h1>Changed</h1></div></body></html>')
    manifest = {
        "fuzzNodes": [
            {
                "path": "/html[0]/body[0]/div[0]",
                "mode": "attributes",
                "attrs": ["data-token"],
                "source": "manual",
            }
        ],
        "manualNodes": [],
    }
    result = compare_dom_trees(baseline, candidate, manifest)
    assert result.passed is False
    assert any(failure.reason == "text_mismatch" for failure in result.failures)


def test_discover_fuzz_nodes_from_calibration_runs():
    trees = [
        _tree('<html><body><span>Mon</span></body></html>'),
        _tree('<html><body><span>Tue</span></body></html>'),
        _tree('<html><body><span>Wed</span></body></html>'),
    ]
    fuzz_nodes = discover_fuzz_nodes(trees)
    assert fuzz_nodes
    assert fuzz_nodes[0]["mode"] in {"text", "subtree"}


def test_compare_dom_trees_ignores_heraldry_cache_bust_query_params():
    heraldry = "http://127.0.0.1:19080/assets/heraldry/player/000001.jpg"
    baseline = _tree(
        f'<html><body><img src="{heraldry}?v=111?222"></body></html>'
    )
    candidate = _tree(
        f'<html><body><img src="{heraldry}?v=999?888"></body></html>'
    )
    manifest = {"fuzzNodes": [], "manualNodes": []}
    result = compare_dom_trees(baseline, candidate, manifest)
    assert result.passed is True


def test_compare_dom_trees_ignores_stylesheet_cache_bust_query_params():
    css = "http://127.0.0.1:19080/orkui/template/default/style/tokens.css"
    baseline = _tree(
        f'<html><head><link rel="stylesheet" href="{css}?v=111"></head><body></body></html>'
    )
    candidate = _tree(
        f'<html><head><link rel="stylesheet" href="{css}?v=999"></head><body></body></html>'
    )
    result = compare_dom_trees(
        baseline, candidate, {"fuzzNodes": [], "manualNodes": []}
    )
    assert result.passed is True


def test_compare_dom_trees_ignores_heraldry_style_background_url_cache_bust():
    heraldry = "http://127.0.0.1:19080/assets/heraldry/player/000001.jpg"
    baseline = _tree(
        "<html><body><div style=\"background-image: "
        f"url('{heraldry}?v=111?222')\"></div></body></html>"
    )
    candidate = _tree(
        "<html><body><div style=\"background-image: "
        f"url('{heraldry}?v=999?888')\"></div></body></html>"
    )
    manifest = {"fuzzNodes": [], "manualNodes": []}
    result = compare_dom_trees(baseline, candidate, manifest)
    assert result.passed is True


def test_discover_fuzz_nodes_from_pair_masks_structural_drift():
    from lib.tree_diff import discover_fuzz_nodes_from_pair

    baseline = _tree(
        "<html><body><div id=\"w\"><a>Event A</a><a>Event B</a></div></body></html>"
    )
    candidate = _tree("<html><body><div id=\"w\"><a>Event C</a></div></body></html>")
    fuzz_nodes = discover_fuzz_nodes_from_pair(baseline, candidate)
    assert fuzz_nodes
    result = compare_dom_trees(baseline, candidate, {"fuzzNodes": fuzz_nodes, "manualNodes": []})
    assert result.passed is True
