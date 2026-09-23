"""Unit tests for canonical_dom.py."""

from __future__ import annotations

from lib.canonical_dom import html_to_canonical_tree, load_canonical_tree, save_canonical_tree


def _body_node(tree: dict) -> dict:
    for child in tree.get("children", []):
        if child.get("tag") == "body":
            return child
    raise AssertionError("expected body node")


def test_html_to_canonical_tree_assigns_index_paths():
    html = """
    <!DOCTYPE html>
    <html lang="en">
      <body class="ork-home">
        <div id="a">Alpha</div>
        <div id="b">Beta</div>
      </body>
    </html>
    """
    tree = html_to_canonical_tree(html)
    assert tree["tag"] == "html"
    assert tree["path"] == "/html[0]"
    body = _body_node(tree)
    assert body["tag"] == "body"
    assert body["path"] == "/html[0]/body[0]"
    first_div = body["children"][0]
    second_div = body["children"][1]
    assert first_div["path"] == "/html[0]/body[0]/div[0]"
    assert second_div["path"] == "/html[0]/body[0]/div[1]"
    assert first_div["children"][0]["text"] == "Alpha"


def test_html_to_canonical_tree_skips_inline_script_bodies():
    html = """
    <html><body>
      <script>window.token = "abc";</script>
      <script src="/app.js"></script>
      <style>.x { color: red; }</style>
    </body></html>
    """
    tree = html_to_canonical_tree(html)
    body = _body_node(tree)
    tags = [child["tag"] for child in body["children"] if "tag" in child]
    assert tags == ["script"]
    assert body["children"][0]["attrs"]["src"] == "/app.js"


def test_save_and_load_canonical_tree(tmp_path):
    tree = html_to_canonical_tree("<html><body><p>Hi</p></body></html>")
    out = tmp_path / "tree.json"
    save_canonical_tree(out, tree)
    loaded = load_canonical_tree(out)
    assert loaded["path"] == tree["path"]
