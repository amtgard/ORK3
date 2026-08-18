"""Unit tests for overlay.py."""

from __future__ import annotations

from pathlib import Path

import numpy as np
from PIL import Image

from lib.diff_regions import Rect
from lib.overlay import draw_calibration_overlay, draw_gate_annotation
from tests.conftest import make_uniform_image


def test_draw_calibration_overlay_writes_png(tmp_path: Path):
    base = make_uniform_image(32, 32, (180, 180, 180))
    out = tmp_path / "overlay.png"
    draw_calibration_overlay(base, [Rect(4, 4, 8, 8)], out)
    assert out.exists()
    with Image.open(out) as image:
        assert image.size == (32, 32)


def test_draw_calibration_overlay_no_boxes(tmp_path: Path):
    base = make_uniform_image(16, 16, (200, 200, 200))
    out = tmp_path / "plain.png"
    draw_calibration_overlay(base, [], out)
    assert out.exists()


def test_draw_gate_annotation_writes_png(tmp_path: Path):
    base = make_uniform_image(32, 32, (180, 180, 180))
    out = tmp_path / "gate.png"
    draw_gate_annotation(
        base,
        fuzz_boxes=[Rect(2, 2, 6, 6)],
        failure_boxes=[Rect(20, 20, 4, 4)],
        out_path=out,
    )
    assert out.exists()
    pixels = np.asarray(Image.open(out))
    assert pixels.shape == (32, 32, 3)
