"""Draw calibration and gate annotation overlays on screenshots."""

from __future__ import annotations

import os
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw

from lib.diff_regions import Rect


def _save_image_atomic(image: Image.Image, out: Path) -> None:
    """Write via temp file + replace to reduce cloud-sync overwrite deadlocks."""
    tmp = out.with_name(f".{out.stem}.tmp-{os.getpid()}{out.suffix}")
    try:
        image.save(tmp)
        os.replace(tmp, out)
    finally:
        if tmp.exists():
            try:
                tmp.unlink()
            except OSError:
                pass


def _apply_colored_boxes(
    base: Image.Image,
    rects: list[Rect],
    color: tuple[int, int, int],
    alpha: float,
) -> Image.Image:
    if not rects:
        return base.copy()

    overlay = Image.new("RGBA", base.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    fill_alpha = int(255 * alpha)
    for rect in rects:
        draw.rectangle(
            [
                rect.x,
                rect.y,
                rect.x + rect.width - 1,
                rect.y + rect.height - 1,
            ],
            outline=(*color, 255),
            fill=(*color, fill_alpha),
            width=2,
        )

    base_rgba = base.convert("RGBA")
    return Image.alpha_composite(base_rgba, overlay).convert("RGB")


def draw_calibration_overlay(
    base_image: Image.Image | np.ndarray,
    boxes: list[Rect],
    out_path: Path | str,
    *,
    color: tuple[int, int, int] = (220, 40, 40),
    alpha: float = 0.35,
) -> Path:
    """Red semi-transparent boxes for human calibration review."""
    if isinstance(base_image, np.ndarray):
        base = Image.fromarray(base_image)
    else:
        base = base_image

    annotated = _apply_colored_boxes(base, boxes, color, alpha)
    out = Path(out_path)
    out.parent.mkdir(parents=True, exist_ok=True)
    _save_image_atomic(annotated, out)
    return out


def draw_gate_annotation(
    base_image: Image.Image | np.ndarray,
    fuzz_boxes: list[Rect],
    failure_boxes: list[Rect],
    out_path: Path | str,
) -> Path:
    """Green fuzz allowances and red failure regions."""
    if isinstance(base_image, np.ndarray):
        base = Image.fromarray(base_image)
    else:
        base = base_image

    annotated = _apply_colored_boxes(base, fuzz_boxes, (40, 180, 60), 0.25)
    annotated = _apply_colored_boxes(annotated, failure_boxes, (220, 40, 40), 0.45)
    out = Path(out_path)
    out.parent.mkdir(parents=True, exist_ok=True)
    _save_image_atomic(annotated, out)
    return out
