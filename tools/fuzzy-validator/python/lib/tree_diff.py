"""DOM tree diffing, fuzz discovery, and gate comparison."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any


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


def effective_fuzz_nodes(manifest: dict) -> list[dict]:
    nodes = list(manifest.get("fuzzNodes", [])) + list(manifest.get("manualNodes", []))
    return collapse_ancestor_fuzz(nodes)


def _is_fuzzed(path: str, fuzz_nodes: list[dict]) -> bool:
    for node in fuzz_nodes:
        fuzz_path = node["path"]
        mode = node.get("mode")
        if mode == "subtree" and (path == fuzz_path or path.startswith(f"{fuzz_path}/")):
            return True
        if path == fuzz_path and mode in {"text", "attributes"}:
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
        if base_attrs.get(name) != cand_attrs.get(name):
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

    passed = failure_paths == 0 and dom_score >= dom_min_score
    return DomCompareResult(
        passed=passed,
        dom_score=dom_score,
        comparable_paths=comparable_paths,
        failure_paths=failure_paths,
        failures=failures,
    )
