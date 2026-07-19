"""DOM tree diffing, fuzz discovery, and gate comparison."""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from typing import Any

_HERALDRY_PATH = "/assets/heraldry/"
_URL_IN_STYLE_RE = re.compile(r"url\((['\"]?)([^)'\"]+)\1\)")


def normalize_dom_attr_value(name: str, value: Any) -> Any:
    """Strip deploy-time cache-bust query params from static asset URLs in DOM attrs."""
    if not isinstance(value, str):
        return value
    if name in {"href", "src"} and "?v=" in value:
        return re.sub(r"\?.*$", "", value)
    if name == "src" and _HERALDRY_PATH in value:
        return re.sub(r"\?.*$", "", value)
    if name == "style" and _HERALDRY_PATH in value:

        def _strip_url(match: re.Match[str]) -> str:
            quote, url = match.group(1), match.group(2)
            if _HERALDRY_PATH not in url:
                return match.group(0)
            stripped = re.sub(r"\?.*$", "", url)
            return f"url({quote}{stripped}{quote})"

        return _URL_IN_STYLE_RE.sub(_strip_url, value)
    return value


@dataclass(frozen=True)
class DomDiffFailure:
    path: str
    reason: str
    baseline: Any = None
    candidate: Any = None


@dataclass
class DomCompareResult:
    passed: bool
    dom_score: float
    comparable_paths: int
    failure_paths: int
    failures: list[DomDiffFailure] = field(default_factory=list)

    def as_dict(self) -> dict:
        return {
            "passed": self.passed,
            "domScore": round(self.dom_score, 6),
            "comparablePaths": self.comparable_paths,
            "failurePaths": self.failure_paths,
            "failures": [
                {
                    "path": failure.path,
                    "reason": failure.reason,
                    "baseline": failure.baseline,
                    "candidate": failure.candidate,
                }
                for failure in self.failures
            ],
        }


def is_text_node(node: dict) -> bool:
    return "text" in node


def is_element_node(node: dict) -> bool:
    return "tag" in node


def index_nodes(tree: dict) -> dict[str, dict]:
    """Map path → node for element and text nodes."""
    nodes: dict[str, dict] = {}

    def walk(node: dict) -> None:
        path = node.get("path")
        if path:
            nodes[path] = node
        if is_element_node(node):
            for child in node.get("children", []):
                walk(child)

    walk(tree)
    return nodes


def subtree_blob(node: dict) -> str:
    """Hashable JSON blob for a subtree at node."""
    if is_text_node(node):
        payload = {"text": node.get("text", "")}
    else:
        payload = {
            "tag": node.get("tag"),
            "attrs": node.get("attrs", {}),
            "children": [_child_signature(child) for child in node.get("children", [])],
        }
    return json.dumps(payload, sort_keys=True, separators=(",", ":"))


def _child_signature(node: dict) -> dict:
    if is_text_node(node):
        return {"text": node.get("text", "")}
    return {
        "tag": node.get("tag"),
        "childCount": len(node.get("children", [])),
    }


def consecutive_volatile_paths(trees: list[dict]) -> set[str]:
    """Paths whose subtree blob differs in every consecutive calibration pair."""
    if len(trees) < 2:
        return set()

    indexed = [index_nodes(tree) for tree in trees]
    common_paths = set.intersection(*(set(mapping.keys()) for mapping in indexed))
    volatile: set[str] = set()

    for path in common_paths:
        differs_every_pair = True
        for left, right in zip(indexed, indexed[1:], strict=False):
            left_blob = subtree_blob(left[path])
            right_blob = subtree_blob(right[path])
            if left_blob == right_blob:
                differs_every_pair = False
                break
        if differs_every_pair:
            volatile.add(path)

    return volatile


def _volatile_attribute_names(trees: list[dict], path: str) -> set[str]:
    attr_names: set[str] = set()
    nodes = [index_nodes(tree).get(path) for tree in trees]
    if any(node is None or not is_element_node(node) for node in nodes):
        return set()

    for node in nodes:
        attr_names.update(node.get("attrs", {}).keys())

    volatile: set[str] = set()
    for name in attr_names:
        values = {node.get("attrs", {}).get(name) for node in nodes}
        if len(values) > 1:
            volatile.add(name)
    return volatile


def infer_fuzz_mode(trees: list[dict], path: str) -> dict:
    """Infer fuzz node mode for a volatile calibration path."""
    nodes = [index_nodes(tree).get(path) for tree in trees]
    nodes = [node for node in nodes if node is not None]
    if not nodes:
        return {"path": path, "mode": "subtree", "source": "auto"}

    if all(is_text_node(node) for node in nodes):
        return {"path": path, "mode": "text", "source": "auto"}

    if all(is_element_node(node) for node in nodes):
        tags = {node.get("tag") for node in nodes}
        child_counts = {len(node.get("children", [])) for node in nodes}
        volatile_attrs = _volatile_attribute_names(trees, path)

        structure_differs = len(tags) > 1 or len(child_counts) > 1
        if structure_differs:
            return {"path": path, "mode": "subtree", "source": "auto"}

        if volatile_attrs and all(
            set(node.get("attrs", {}).keys()) == set(nodes[0].get("attrs", {}).keys())
            for node in nodes
        ):
            return {
                "path": path,
                "mode": "attributes",
                "attrs": sorted(volatile_attrs),
                "source": "auto",
            }

        text_values = []
        for node in nodes:
            for child in node.get("children", []):
                if is_text_node(child):
                    text_values.append(child.get("text", ""))
        if text_values and len(set(text_values)) > 1:
            return {"path": path, "mode": "text", "source": "auto"}

    return {"path": path, "mode": "subtree", "source": "auto"}


def collapse_ancestor_fuzz(fuzz_nodes: list[dict]) -> list[dict]:
    """Drop child fuzz rules covered by an ancestor subtree rule."""
    subtree_paths = sorted(
        {node["path"] for node in fuzz_nodes if node.get("mode") == "subtree"},
        key=len,
    )
    kept: list[dict] = []
    for node in sorted(fuzz_nodes, key=lambda item: len(item["path"])):
        path = node["path"]
        if any(
            ancestor != path and path.startswith(f"{ancestor}/")
            for ancestor in subtree_paths
        ):
            continue
        kept.append(node)
    return kept


def discover_fuzz_nodes(trees: list[dict]) -> list[dict]:
    """Discover auto fuzz nodes from calibration trees."""
    volatile_paths = consecutive_volatile_paths(trees)
    fuzz_nodes = [infer_fuzz_mode(trees, path) for path in sorted(volatile_paths)]
    return collapse_ancestor_fuzz(fuzz_nodes)


def discover_fuzz_nodes_from_pair(baseline: dict, candidate: dict) -> list[dict]:
    """Discover fuzz nodes from baseline vs candidate (cross-session refuzz)."""
    probe = compare_dom_trees(
        baseline,
        candidate,
        {"fuzzNodes": [], "manualNodes": []},
        dom_min_score=0.0,
    )
    # Score floors must not hide failures here — discovery keys off diffs.
    if not probe.failures:
        return []

    failure_paths = sorted({failure.path for failure in probe.failures})
    trees = [baseline, candidate]
    fuzz_nodes = [infer_fuzz_mode(trees, path) for path in failure_paths]
    return collapse_ancestor_fuzz(fuzz_nodes)


def merge_fuzz_nodes(existing: list[dict], new_nodes: list[dict]) -> list[dict]:
    """Merge auto fuzz nodes; subtree rules subsume children and duplicate paths."""
    by_path: dict[str, dict] = {}
    for node in existing + new_nodes:
        path = node["path"]
        prior = by_path.get(path)
        if prior is None:
            by_path[path] = node
            continue
        if node.get("mode") == "subtree" or prior.get("mode") != "subtree":
            by_path[path] = node
    return collapse_ancestor_fuzz(list(by_path.values()))


def effective_fuzz_nodes(manifest: dict) -> list[dict]:
    nodes = list(manifest.get("fuzzNodes", [])) + list(manifest.get("manualNodes", []))
    return collapse_ancestor_fuzz(nodes)


def _is_fuzzed(path: str, fuzz_nodes: list[dict]) -> bool:
    for node in fuzz_nodes:
        fuzz_path = node["path"]
        mode = node.get("mode")
        if mode == "subtree" and (path == fuzz_path or path.startswith(f"{fuzz_path}/")):
            return True
        if path == fuzz_path and mode == "text":
            return True
    return False


def _compare_element_nodes(
    baseline: dict,
    candidate: dict,
    path: str,
    fuzz_nodes: list[dict],
    failures: list[DomDiffFailure],
) -> None:
    fuzz_for_path = next((node for node in fuzz_nodes if node["path"] == path), None)

    if baseline.get("tag") != candidate.get("tag"):
        if not _is_fuzzed(path, fuzz_nodes):
            failures.append(
                DomDiffFailure(
                    path=path,
                    reason="tag_mismatch",
                    baseline=baseline.get("tag"),
                    candidate=candidate.get("tag"),
                )
            )
        return

    base_attrs = baseline.get("attrs", {})
    cand_attrs = candidate.get("attrs", {})
    ignored_attrs: set[str] = set()
    if fuzz_for_path and fuzz_for_path.get("mode") == "attributes":
        ignored_attrs = set(fuzz_for_path.get("attrs", []))

    for name in sorted(set(base_attrs) | set(cand_attrs)):
        if name in ignored_attrs:
            continue
        base_val = normalize_dom_attr_value(name, base_attrs.get(name))
        cand_val = normalize_dom_attr_value(name, cand_attrs.get(name))
        if base_val != cand_val:
            failures.append(
                DomDiffFailure(
                    path=path,
                    reason="attribute_mismatch",
                    baseline={name: base_attrs.get(name)},
                    candidate={name: cand_attrs.get(name)},
                )
            )

    base_children = baseline.get("children", [])
    cand_children = candidate.get("children", [])
    if len(base_children) != len(cand_children):
        if not _is_fuzzed(path, fuzz_nodes):
            failures.append(
                DomDiffFailure(
                    path=path,
                    reason="child_count_mismatch",
                    baseline=len(base_children),
                    candidate=len(cand_children),
                )
            )
        return

    for base_child, cand_child in zip(base_children, cand_children, strict=False):
        compare_nodes(base_child, cand_child, fuzz_nodes, failures)


def compare_nodes(
    baseline: dict,
    candidate: dict,
    fuzz_nodes: list[dict],
    failures: list[DomDiffFailure],
) -> None:
    path = baseline.get("path") or candidate.get("path") or "/"
    if _is_fuzzed(path, fuzz_nodes):
        return

    if is_text_node(baseline) or is_text_node(candidate):
        if is_text_node(baseline) and is_text_node(candidate):
            if baseline.get("text") != candidate.get("text"):
                failures.append(
                    DomDiffFailure(
                        path=path,
                        reason="text_mismatch",
                        baseline=baseline.get("text"),
                        candidate=candidate.get("text"),
                    )
                )
        else:
            failures.append(
                DomDiffFailure(
                    path=path,
                    reason="node_kind_mismatch",
                    baseline="text" if is_text_node(baseline) else "element",
                    candidate="text" if is_text_node(candidate) else "element",
                )
            )
        return

    _compare_element_nodes(baseline, candidate, path, fuzz_nodes, failures)


def compare_dom_trees(
    baseline: dict,
    candidate: dict,
    fuzz_manifest: dict,
    *,
    dom_min_score: float = 1.0,
) -> DomCompareResult:
    """Compare candidate tree to baseline honoring fuzz nodes."""
    fuzz_nodes = effective_fuzz_nodes(fuzz_manifest)
    baseline_index = index_nodes(baseline)
    candidate_index = index_nodes(candidate)

    failures: list[DomDiffFailure] = []
    compare_nodes(baseline, candidate, fuzz_nodes, failures)

    all_paths = set(baseline_index) | set(candidate_index)
    comparable_paths = sum(1 for path in all_paths if not _is_fuzzed(path, fuzz_nodes))
    failure_paths = len({failure.path for failure in failures})

    if comparable_paths <= 0:
        dom_score = 1.0 if failure_paths == 0 else 0.0
    else:
        dom_score = 1.0 - (failure_paths / comparable_paths)

    # Mirror profiles intentionally allow a small DOM score floor (< 1.0) for
    # live-data markup drift; pass/fail is score-based, not zero-failure.
    passed = dom_score >= dom_min_score
    return DomCompareResult(
        passed=passed,
        dom_score=dom_score,
        comparable_paths=comparable_paths,
        failure_paths=failure_paths,
        failures=failures,
    )
