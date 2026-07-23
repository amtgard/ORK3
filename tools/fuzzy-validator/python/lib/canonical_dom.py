"""HTML → canonical indexed-path DOM tree JSON."""

from __future__ import annotations

import html5lib
from html5lib.constants import namespaces
from html5lib.treebuilders import getTreeBuilder

VOID_ELEMENTS = frozenset(
    {
        "area",
        "base",
        "br",
        "col",
        "embed",
        "hr",
        "img",
        "input",
        "link",
        "meta",
        "param",
        "source",
        "track",
        "wbr",
    }
)


def _local_name(node) -> str | None:
    if not hasattr(node, "tagName"):
        return None
    tag = node.tagName
    if not tag:
        return None
    if tag.startswith("{"):
        return tag.rsplit("}", 1)[-1].lower()
    return tag.lower()


def _element_attrs(node) -> dict[str, str]:
    attrs: dict[str, str] = {}
    if not hasattr(node, "attributes") or node.attributes is None:
        return attrs
    for index in range(node.attributes.length):
        attr = node.attributes.item(index)
        if attr is None:
            continue
        name = attr.name.lower()
        value = attr.value
        if value is None:
            value = ""
        attrs[name] = value
    return dict(sorted(attrs.items()))


def _should_skip_element(tag: str, attrs: dict[str, str], compare_script_bodies: bool) -> bool:
    if tag == "script":
        if attrs.get("src"):
            return False
        return not compare_script_bodies
    if tag == "style":
        return not compare_script_bodies
    return False


def _child_elements(node) -> list:
    elements = []
    for child in node.childNodes:
        if getattr(child, "nodeType", None) == child.ELEMENT_NODE:
            elements.append(child)
    return elements


def _text_segments(node) -> list[str]:
    segments: list[str] = []
    for child in node.childNodes:
        if getattr(child, "nodeType", None) == child.TEXT_NODE:
            text = child.data or ""
            if text.strip():
                segments.append(text)
    return segments


def _build_element_node(
    node,
    parent_path: str,
    *,
    compare_script_bodies: bool,
) -> dict | None:
    tag = _local_name(node)
    if tag is None:
        return None

    attrs = _element_attrs(node)
    if _should_skip_element(tag, attrs, compare_script_bodies):
        return None

    sibling_index = 0
    parent = node.parentNode
    if parent is not None:
        for sibling in _child_elements(parent):
            if sibling is node:
                break
            if _local_name(sibling) == tag:
                sibling_index += 1

    path = f"{parent_path}/{tag}[{sibling_index}]"
    children: list[dict] = []

    if tag not in VOID_ELEMENTS and not (tag == "script" and attrs.get("src")):
        element_children = _child_elements(node)
        for child in element_children:
            child_node = _build_element_node(
                child,
                path,
                compare_script_bodies=compare_script_bodies,
            )
            if child_node is not None:
                children.append(child_node)

        for text_index, text in enumerate(_text_segments(node)):
            children.append({"text": text, "path": f"{path}/text[{text_index}]"})

    return {"tag": tag, "path": path, "attrs": attrs, "children": children}


def html_to_canonical_tree(
    html: str,
    *,
    compare_script_bodies: bool = False,
) -> dict:
    """Parse HTML and emit a canonical tree rooted at html[0]."""
    parser = html5lib.HTMLParser(tree=getTreeBuilder("dom"), namespaceHTMLElements=False)
    document = parser.parse(html)

    html_nodes = [
        node
        for node in document.childNodes
        if getattr(node, "nodeType", None) == node.ELEMENT_NODE
        and _local_name(node) == "html"
    ]
    if not html_nodes:
        raise ValueError("HTML document has no <html> root element")

    root = _build_element_node(
        html_nodes[0],
        "",
        compare_script_bodies=compare_script_bodies,
    )
    if root is None:
        raise ValueError("Failed to canonicalize HTML root")
    return root


def load_canonical_tree(path) -> dict:
    """Load a previously serialized canonical tree JSON file."""
    from lib.manifest import load_json5

    return load_json5(path)


def save_canonical_tree(path, tree: dict) -> None:
    from lib.manifest import save_json

    save_json(path, tree)
