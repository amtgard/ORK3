"""Pixel diff masks and bounding-box extraction for fuzz discovery and gating."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np
from PIL import Image


@dataclass(frozen=True)
class Rect:
    x: int
    y: int
    width: int
    height: int

    def as_dict(self, source: str = "auto", label: str | None = None) -> dict:
        payload = {
            "x": self.x,
            "y": self.y,
            "width": self.width,
            "height": self.height,
            "source": source,
        }
        if label:
            payload["label"] = label
        return payload

    def intersects(self, other: Rect) -> bool:
        return not (
            self.x + self.width <= other.x
            or other.x + other.width <= self.x
            or self.y + self.height <= other.y
            or other.y + other.height <= self.y
        )

    def union(self, other: Rect) -> Rect:
        left = min(self.x, other.x)
        top = min(self.y, other.y)
        right = max(self.x + self.width, other.x + other.width)
        bottom = max(self.y + self.height, other.y + other.height)
        return Rect(left, top, right - left, bottom - top)


def load_rgb_array(path: Path | str) -> np.ndarray:
    with Image.open(path) as image:
        return np.asarray(image.convert("RGB"))


def assert_same_shape(images: list[np.ndarray]) -> tuple[int, int]:
    if not images:
        raise ValueError("No images provided")
    height, width = images[0].shape[:2]
    for index, image in enumerate(images[1:], start=2):
        if image.shape[:2] != (height, width):
            raise ValueError(
                f"Image {index} shape {image.shape[:2]} != {(height, width)}"
            )
    return height, width


def pairwise_diff_mask(
    img_a: np.ndarray, img_b: np.ndarray, threshold: int
) -> np.ndarray:
    """Return bool mask where per-pixel channel-max diff exceeds threshold."""
    if img_a.shape != img_b.shape:
        raise ValueError("Image shapes must match for pairwise diff")
    diff = np.abs(img_a.astype(np.int16) - img_b.astype(np.int16))
    channel_max = diff.max(axis=2)
    return channel_max > threshold


def intersect_consecutive_masks(masks: list[np.ndarray]) -> np.ndarray:
    """Intersect diff masks from consecutive image pairs."""
    if not masks:
        raise ValueError("At least one mask is required")
    if len(masks) == 1:
        return masks[0].copy()
    result = masks[0].copy()
    for mask in masks[1:]:
        result &= mask
    return result


def consecutive_pair_masks(images: list[np.ndarray], threshold: int) -> list[np.ndarray]:
    """Build diff masks for each consecutive image pair."""
    if len(images) < 2:
        return []
    masks: list[np.ndarray] = []
    for left, right in zip(images, images[1:]):
        masks.append(pairwise_diff_mask(left, right, threshold))
    return masks


def merge_overlapping_rects(rects: list[Rect]) -> list[Rect]:
    if not rects:
        return []
    merged = list(rects)
    changed = True
    while changed:
        changed = False
        next_pass: list[Rect] = []
        while merged:
            current = merged.pop(0)
            absorbed = False
            for index, other in enumerate(next_pass):
                if current.intersects(other):
                    next_pass[index] = current.union(other)
                    absorbed = True
                    changed = True
                    break
            if not absorbed:
                next_pass.append(current)
        merged = next_pass
    return sorted(merged, key=lambda rect: (rect.y, rect.x))


def _pad_rect(rect: Rect, pad: int, width: int, height: int) -> Rect:
    x = max(0, rect.x - pad)
    y = max(0, rect.y - pad)
    right = min(width, rect.x + rect.width + pad)
    bottom = min(height, rect.y + rect.height + pad)
    return Rect(x, y, right - x, bottom - y)


def masks_to_boxes(
    mask: np.ndarray,
    min_area: int,
    pad: int,
    kernel_size: int = 5,
) -> list[Rect]:
    """Morphology + connected components → padded, merged bounding boxes."""
    if mask.dtype != bool:
        mask = mask.astype(bool)
    height, width = mask.shape
    if not mask.any():
        return []

    uint8 = (mask.astype(np.uint8)) * 255
    kernel = cv2.getStructuringElement(
        cv2.MORPH_RECT,
        (max(1, kernel_size), max(1, kernel_size)),
    )
    dilated = cv2.dilate(uint8, kernel, iterations=1)

    _, labels, stats, _ = cv2.connectedComponentsWithStats(dilated, connectivity=8)
    rects: list[Rect] = []
    for label in range(1, stats.shape[0]):
        area = int(stats[label, cv2.CC_STAT_AREA])
        if area < min_area:
            continue
        x = int(stats[label, cv2.CC_STAT_LEFT])
        y = int(stats[label, cv2.CC_STAT_TOP])
        w = int(stats[label, cv2.CC_STAT_WIDTH])
        h = int(stats[label, cv2.CC_STAT_HEIGHT])
        rects.append(_pad_rect(Rect(x, y, w, h), pad, width, height))

    return merge_overlapping_rects(rects)


def rect_mask(shape: tuple[int, int], rects: list[Rect]) -> np.ndarray:
    """True inside any rectangle."""
    height, width = shape
    mask = np.zeros((height, width), dtype=bool)
    for rect in rects:
        y0 = max(0, rect.y)
        x0 = max(0, rect.x)
        y1 = min(height, rect.y + rect.height)
        x1 = min(width, rect.x + rect.width)
        mask[y0:y1, x0:x1] = True
    return mask


def rects_from_manifest_zones(zones: list[dict]) -> list[Rect]:
    return [
        Rect(
            int(zone["x"]),
            int(zone["y"]),
            int(zone["width"]),
            int(zone["height"]),
        )
        for zone in zones
    ]
