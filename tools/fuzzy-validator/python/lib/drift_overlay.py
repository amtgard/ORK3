"""Drift overlay schema: load, merge, conflict detection (FV2).

Overlays are additive allowances applied at evaluate time. They never rewrite
setpoint baselines or committed fuzz manifests.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from lib.diff_regions import Rect
from lib.manifest import load_json5

VALID_CLASSES = frozenset({"natural", "intentional"})
VALID_LAYERS = frozenset({"dom", "visual", "assets"})
VALID_PROFILES = frozenset({"test", "mirror"})
VALID_SOURCES = frozenset({"calibrated", "manual", "putative", "promoted"})
VALID_DOM_MATCH = frozenset({"exact", "subtree", "text", "attributes"})


class OverlayError(ValueError):
    """Schema or conflict error — maps to CLI exit 2."""


@dataclass(frozen=True)
class OverlayEntry:
    id: str
    class_: str
    layer: str
    profiles: tuple[str, ...]
    pages: tuple[str, ...]
    rationale: str
    source: str
    requirement_ref: str | None = None
    visual: dict[str, int] | None = None
    dom: dict[str, Any] | None = None
    assets: dict[str, Any] | None = None
    overlay_id: str = ""

    @property
    def class_name(self) -> str:
        return self.class_

    def applies_to(self, *, page_id: str, profile: str, layer: str) -> bool:
        if self.layer != layer:
            return False
        if page_id not in self.pages:
            return False
        return profile in self.profiles

    def visual_rect(self) -> Rect | None:
        if not self.visual:
            return None
        return Rect(
            int(self.visual["x"]),
            int(self.visual["y"]),
            int(self.visual["width"]),
            int(self.visual["height"]),
        )

    def as_dict(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "id": self.id,
            "class": self.class_,
            "layer": self.layer,
            "profiles": list(self.profiles),
            "pages": list(self.pages),
            "rationale": self.rationale,
            "source": self.source,
            "overlayId": self.overlay_id,
        }
        if self.requirement_ref:
            payload["requirementRef"] = self.requirement_ref
        if self.visual:
            payload["visual"] = dict(self.visual)
        if self.dom:
            payload["dom"] = dict(self.dom)
        if self.assets:
            payload["assets"] = dict(self.assets)
        return payload


@dataclass
class DriftOverlay:
    schema_version: int
    id: str
    path: Path | None = None
    workstream: str | None = None
    created_at: str | None = None
    based_on_setpoint: str | None = None
    entries: list[OverlayEntry] = field(default_factory=list)

    def as_dict(self) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "schemaVersion": self.schema_version,
            "id": self.id,
            "entries": [entry.as_dict() for entry in self.entries],
        }
        if self.workstream:
            payload["workstream"] = self.workstream
        if self.created_at:
            payload["createdAt"] = self.created_at
        if self.based_on_setpoint:
            payload["basedOnSetpoint"] = self.based_on_setpoint
        return payload


@dataclass
class MergedOverlays:
    overlays: list[DriftOverlay]
    entries: list[OverlayEntry]
    paths: list[Path]

    def for_page(
        self, *, page_id: str, profile: str, layer: str
    ) -> list[OverlayEntry]:
        return [
            entry
            for entry in self.entries
            if entry.applies_to(page_id=page_id, profile=profile, layer=layer)
        ]

    def summarize(self) -> dict[str, Any]:
        by_class: dict[str, int] = {"natural": 0, "intentional": 0}
        by_layer: dict[str, int] = {"dom": 0, "visual": 0, "assets": 0}
        by_page: dict[str, int] = {}
        for entry in self.entries:
            by_class[entry.class_] = by_class.get(entry.class_, 0) + 1
            by_layer[entry.layer] = by_layer.get(entry.layer, 0) + 1
            for page_id in entry.pages:
                by_page[page_id] = by_page.get(page_id, 0) + 1
        return {
            "overlayCount": len(self.overlays),
            "entryCount": len(self.entries),
            "byClass": by_class,
            "byLayer": by_layer,
            "byPage": by_page,
            "paths": [str(path) for path in self.paths],
        }


def overlays_root(tool_root: Path) -> Path:
    return tool_root / "overlays"


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise OverlayError(message)


def _parse_entry(raw: dict[str, Any], *, overlay_id: str, index: int) -> OverlayEntry:
    prefix = f"entries[{index}]"
    _require(isinstance(raw, dict), f"{prefix} must be an object")
    entry_id = raw.get("id")
    _require(isinstance(entry_id, str) and entry_id, f"{prefix} missing id")

    class_ = raw.get("class")
    _require(class_ in VALID_CLASSES, f"{prefix} class must be natural|intentional")

    layer = raw.get("layer")
    _require(layer in VALID_LAYERS, f"{prefix} layer must be dom|visual|assets")

    profiles_raw = raw.get("profiles")
    _require(isinstance(profiles_raw, list) and profiles_raw, f"{prefix} profiles required")
    profiles = tuple(str(item) for item in profiles_raw)
    for profile in profiles:
        _require(profile in VALID_PROFILES, f"{prefix} invalid profile {profile!r}")

    pages_raw = raw.get("pages")
    _require(isinstance(pages_raw, list) and pages_raw, f"{prefix} pages required")
    pages = tuple(str(item) for item in pages_raw)

    rationale = raw.get("rationale")
    _require(isinstance(rationale, str) and rationale.strip(), f"{prefix} rationale required")

    source = raw.get("source", "manual")
    _require(source in VALID_SOURCES, f"{prefix} invalid source {source!r}")

    requirement_ref = raw.get("requirementRef")
    if class_ == "intentional":
        _require(
            isinstance(requirement_ref, str) and requirement_ref.strip(),
            f"{prefix} intentional entries require requirementRef",
        )

    visual = raw.get("visual")
    dom = raw.get("dom")
    assets = raw.get("assets")

    if layer == "visual":
        _require(isinstance(visual, dict), f"{prefix} visual bbox required")
        for key in ("x", "y", "width", "height"):
            _require(key in visual, f"{prefix} visual.{key} required")
            _require(isinstance(visual[key], int), f"{prefix} visual.{key} must be int")
            if key in {"width", "height"}:
                _require(visual[key] > 0, f"{prefix} visual.{key} must be > 0")
    elif layer == "dom":
        _require(isinstance(dom, dict), f"{prefix} dom addressing required")
        path = dom.get("path") or dom.get("pathPrefix")
        _require(isinstance(path, str) and path, f"{prefix} dom.path or pathPrefix required")
        match = dom.get("match", "subtree" if "pathPrefix" in dom else "exact")
        _require(match in VALID_DOM_MATCH, f"{prefix} dom.match invalid")
        # Normalize to path + mode used by gate_dom / tree_diff.
        dom = {
            "path": path,
            "mode": "subtree" if match == "subtree" else match,
            **({"attrs": dom["attrs"]} if "attrs" in dom else {}),
        }
    elif layer == "assets":
        _require(isinstance(assets, dict), f"{prefix} assets selector required")
        asset_ids = assets.get("ids") or assets.get("id")
        if isinstance(asset_ids, str):
            asset_ids = [asset_ids]
        _require(
            isinstance(asset_ids, list) and asset_ids,
            f"{prefix} assets.ids (or id) required",
        )
        assets = {"ids": [str(item) for item in asset_ids]}

    return OverlayEntry(
        id=entry_id,
        class_=class_,
        layer=layer,
        profiles=profiles,
        pages=pages,
        rationale=rationale,
        source=source,
        requirement_ref=requirement_ref if isinstance(requirement_ref, str) else None,
        visual=dict(visual) if isinstance(visual, dict) and layer == "visual" else None,
        dom=dom if layer == "dom" else None,
        assets=assets if layer == "assets" else None,
        overlay_id=overlay_id,
    )


def validate_overlay_payload(payload: dict[str, Any], *, path: Path | None = None) -> DriftOverlay:
    where = str(path) if path else "<overlay>"
    _require(isinstance(payload, dict), f"{where}: overlay must be an object")
    schema_version = payload.get("schemaVersion")
    _require(schema_version == 2, f"{where}: schemaVersion must be 2")
    overlay_id = payload.get("id")
    _require(isinstance(overlay_id, str) and overlay_id, f"{where}: id required")

    entries_raw = payload.get("entries")
    _require(isinstance(entries_raw, list), f"{where}: entries must be an array")

    entries = [
        _parse_entry(raw, overlay_id=overlay_id, index=index)
        for index, raw in enumerate(entries_raw)
    ]
    seen_ids: set[str] = set()
    for entry in entries:
        key = f"{overlay_id}:{entry.id}"
        _require(entry.id not in seen_ids, f"{where}: duplicate entry id {entry.id!r}")
        seen_ids.add(entry.id)
        del key

    return DriftOverlay(
        schema_version=2,
        id=overlay_id,
        path=path,
        workstream=payload.get("workstream") if isinstance(payload.get("workstream"), str) else None,
        created_at=payload.get("createdAt") if isinstance(payload.get("createdAt"), str) else None,
        based_on_setpoint=(
            payload.get("basedOnSetpoint")
            if isinstance(payload.get("basedOnSetpoint"), str)
            else None
        ),
        entries=entries,
    )


def load_overlay(path: Path | str) -> DriftOverlay:
    overlay_path = Path(path)
    if not overlay_path.is_file():
        raise OverlayError(f"overlay not found: {overlay_path}")
    try:
        payload = load_json5(overlay_path)
    except (OSError, ValueError) as exc:
        raise OverlayError(f"failed to load overlay {overlay_path}: {exc}") from exc
    return validate_overlay_payload(payload, path=overlay_path)


def _rects_overlap(a: Rect, b: Rect) -> bool:
    return a.intersects(b)


def _dom_paths_conflict(a: dict[str, Any], b: dict[str, Any]) -> bool:
    path_a = a["path"]
    path_b = b["path"]
    mode_a = a.get("mode", "exact")
    mode_b = b.get("mode", "exact")

    def covers(outer: str, outer_mode: str, inner: str) -> bool:
        if outer_mode == "subtree":
            return inner == outer or inner.startswith(f"{outer}/")
        return inner == outer

    return covers(path_a, mode_a, path_b) or covers(path_b, mode_b, path_a)


def detect_conflicts(entries: list[OverlayEntry]) -> list[str]:
    """Fail closed when overlapping regions disagree on class."""
    conflicts: list[str] = []
    for index, left in enumerate(entries):
        for right in entries[index + 1 :]:
            shared_pages = set(left.pages) & set(right.pages)
            shared_profiles = set(left.profiles) & set(right.profiles)
            if not shared_pages or not shared_profiles:
                continue
            if left.layer != right.layer:
                continue
            if left.class_ == right.class_:
                continue

            overlap = False
            if left.layer == "visual" and left.visual and right.visual:
                overlap = _rects_overlap(left.visual_rect(), right.visual_rect())  # type: ignore[arg-type]
            elif left.layer == "dom" and left.dom and right.dom:
                overlap = _dom_paths_conflict(left.dom, right.dom)
            elif left.layer == "assets" and left.assets and right.assets:
                overlap = bool(set(left.assets["ids"]) & set(right.assets["ids"]))

            if overlap:
                conflicts.append(
                    f"conflict {left.id} ({left.class_}) vs {right.id} ({right.class_}) "
                    f"on {left.layer} pages={sorted(shared_pages)} "
                    f"profiles={sorted(shared_profiles)}"
                )
    return conflicts


def merge_overlays(overlays: list[DriftOverlay]) -> MergedOverlays:
    entries: list[OverlayEntry] = []
    paths: list[Path] = []
    for overlay in overlays:
        entries.extend(overlay.entries)
        if overlay.path is not None:
            paths.append(overlay.path)
    conflicts = detect_conflicts(entries)
    if conflicts:
        raise OverlayError("; ".join(conflicts))
    return MergedOverlays(overlays=overlays, entries=entries, paths=paths)


def resolve_overlay_paths(
    tool_root: Path,
    *,
    overlay: str | None = None,
    overlay_dir: str | None = None,
    putative: bool = False,
) -> list[Path]:
    """Resolve --overlay / --overlay-dir / --putative into concrete files."""
    paths: list[Path] = []
    seen: set[Path] = set()

    def add(path: Path) -> None:
        resolved = path.resolve()
        if resolved in seen:
            return
        seen.add(resolved)
        paths.append(path)

    if overlay:
        for part in overlay.split(","):
            part = part.strip()
            if not part:
                continue
            candidate = Path(part)
            if not candidate.is_absolute():
                candidate = (Path.cwd() / candidate).resolve()
                if not candidate.is_file():
                    alt = tool_root / part
                    if alt.is_file():
                        candidate = alt
            add(candidate)

    root = overlays_root(tool_root)
    if overlay_dir:
        directory = Path(overlay_dir)
        if not directory.is_absolute():
            directory = (Path.cwd() / directory).resolve()
            if not directory.is_dir():
                alt = tool_root / overlay_dir
                if alt.is_dir():
                    directory = alt
        if not directory.is_dir():
            raise OverlayError(f"overlay dir not found: {overlay_dir}")
        for path in sorted(directory.glob("*.json5")) + sorted(directory.glob("*.json")):
            add(path)
    elif overlay is None and (root / "intentional").is_dir():
        # Explicit --overlay-dir only when flag set; bare validate without flags
        # loads nothing. Callers that want default intentional packs pass --overlay-dir.
        pass

    if putative:
        putative_dir = root / "putative"
        if putative_dir.is_dir():
            for path in sorted(putative_dir.glob("*.json5")) + sorted(
                putative_dir.glob("*.json")
            ):
                add(path)

    return paths


def load_overlays_from_flags(
    tool_root: Path,
    *,
    overlay: str | None = None,
    overlay_dir: str | None = None,
    putative: bool = False,
) -> MergedOverlays | None:
    if not overlay and not overlay_dir and not putative:
        return None
    paths = resolve_overlay_paths(
        tool_root, overlay=overlay, overlay_dir=overlay_dir, putative=putative
    )
    if not paths:
        if putative and not overlay and not overlay_dir:
            return MergedOverlays(overlays=[], entries=[], paths=[])
        raise OverlayError("no overlay files resolved")
    overlays = [load_overlay(path) for path in paths]
    return merge_overlays(overlays)


def visual_zones_from_overlay(entries: list[OverlayEntry]) -> list[dict[str, Any]]:
    zones: list[dict[str, Any]] = []
    for entry in entries:
        rect = entry.visual_rect()
        if rect is None:
            continue
        zones.append(
            rect.as_dict(source="overlay", label=f"overlay:{entry.id}:{entry.class_}")
        )
    return zones


def dom_nodes_from_overlay(entries: list[OverlayEntry]) -> list[dict[str, Any]]:
    nodes: list[dict[str, Any]] = []
    for entry in entries:
        if not entry.dom:
            continue
        node: dict[str, Any] = {
            "path": entry.dom["path"],
            "mode": entry.dom.get("mode", "subtree"),
            "source": "overlay",
            "label": f"overlay:{entry.id}:{entry.class_}",
        }
        if "attrs" in entry.dom:
            node["attrs"] = entry.dom["attrs"]
        nodes.append(node)
    return nodes


def asset_ids_from_overlay(entries: list[OverlayEntry]) -> set[str]:
    allowed: set[str] = set()
    for entry in entries:
        if entry.assets:
            allowed.update(entry.assets.get("ids", []))
    return allowed
