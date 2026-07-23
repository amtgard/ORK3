"""Shared pytest fixtures for fuzzy-validator."""

from __future__ import annotations

from pathlib import Path

import numpy as np
import pytest
from PIL import Image

FIXTURES_DIR = Path(__file__).resolve().parent / "fixtures"
PNG_FIXTURES = FIXTURES_DIR / "png"


def _write_rgb(path: Path, array: np.ndarray) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.fromarray(array.astype(np.uint8)).save(path)
    return path


def make_uniform_image(width: int, height: int, color: tuple[int, int, int]) -> np.ndarray:
    array = np.zeros((height, width, 3), dtype=np.uint8)
    array[:, :] = color
    return array


def make_image_with_patch(
    width: int,
    height: int,
    *,
    base_color: tuple[int, int, int] = (240, 240, 240),
    patch_color: tuple[int, int, int] = (20, 20, 20),
    patch_rect: tuple[int, int, int, int],
) -> np.ndarray:
    image = make_uniform_image(width, height, base_color)
    x, y, w, h = patch_rect
    image[y : y + h, x : x + w] = patch_color
    return image


@pytest.fixture
def tiny_calibration_dir(tmp_path: Path) -> Path:
    """Three 64×64 images with a volatile patch in the same region."""
    cal_dir = tmp_path / "calibrations" / "fixture-page"
    cal_dir.mkdir(parents=True)
    patch = (20, 20, 12, 12)
    for index, shift in enumerate((0, 2, 0), start=1):
        image = make_uniform_image(64, 64, (200, 200, 200))
        x, y, w, h = patch
        image[y : y + h, x + shift : x + shift + w] = (10, 10, 10)
        _write_rgb(cal_dir / f"run-{index:03d}.png", image)
    return cal_dir


@pytest.fixture
def gate_fixture_paths(tmp_path: Path) -> dict[str, Path]:
    """Baseline/candidate pair with one diff outside a fuzz zone."""
    width, height = 80, 80
    baseline = make_uniform_image(width, height, (220, 220, 220))
    candidate = baseline.copy()
    candidate[10:20, 10:20] = (0, 0, 0)

    baseline_path = _write_rgb(tmp_path / "baseline.png", baseline)
    candidate_path = _write_rgb(tmp_path / "candidate.png", candidate)
    manifest_path = tmp_path / "fixture.fuzz.json"
    manifest_path.write_text(
        """
{
  "schemaVersion": 1,
  "pageId": "fixture-page",
  "imageWidth": 80,
  "imageHeight": 80,
  "calibrationRuns": 3,
  "params": {"colorThreshold": 20, "minAreaPx": 4, "padPx": 0},
  "fuzzZones": [
    {"x": 50, "y": 50, "width": 10, "height": 10, "source": "auto"}
  ],
  "manualZones": []
}
""".strip()
        + "\n",
        encoding="utf-8",
    )
    return {
        "baseline": baseline_path,
        "candidate": candidate_path,
        "manifest": manifest_path,
    }
