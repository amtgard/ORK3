"""Unit tests for diff_regions.py."""

from __future__ import annotations

import numpy as np
import pytest

from lib.diff_regions import (
    Rect,
    assert_same_shape,
    consecutive_pair_masks,
    intersect_consecutive_masks,
    masks_to_boxes,
    merge_overlapping_rects,
    pairwise_diff_mask,
    rect_mask,
)
from tests.conftest import make_image_with_patch, make_uniform_image


def test_pairwise_diff_mask_identical_images():
    image = make_uniform_image(16, 16, (100, 100, 100))
    mask = pairwise_diff_mask(image, image, threshold=5)
    assert not mask.any()


def test_pairwise_diff_mask_detects_changed_pixels():
    left = make_uniform_image(16, 16, (100, 100, 100))
    right = left.copy()
    right[4:8, 4:8] = (0, 0, 0)
    mask = pairwise_diff_mask(left, right, threshold=5)
    assert mask[4:8, 4:8].all()
    assert mask.sum() == 16


def test_intersect_consecutive_masks_keeps_stable_volatile_region():
    stable = np.zeros((8, 8), dtype=bool)
    stable[2:5, 2:5] = True
    fading = stable.copy()
    fading[6, 6] = True
    result = intersect_consecutive_masks([stable, fading])
    assert result[2:5, 2:5].all()
    assert not result[6, 6]


def test_consecutive_pair_masks_from_three_images():
    images = [
        make_image_with_patch(32, 32, patch_rect=(4, 4, 6, 6)),
        make_image_with_patch(32, 32, patch_rect=(5, 4, 6, 6)),
        make_image_with_patch(32, 32, patch_rect=(4, 4, 6, 6)),
    ]
    masks = consecutive_pair_masks(images, threshold=10)
    assert len(masks) == 2
    assert masks[0].any()
    assert masks[1].any()


def test_masks_to_boxes_returns_padded_rect():
    mask = np.zeros((40, 40), dtype=bool)
    mask[10:20, 10:20] = True
    boxes = masks_to_boxes(mask, min_area=4, pad=2, kernel_size=3)
    assert len(boxes) == 1
    box = boxes[0]
    assert box.x <= 10
    assert box.y <= 10
    assert box.width >= 10
    assert box.height >= 10


def test_merge_overlapping_rects_unions_intersecting_boxes():
    first = Rect(0, 0, 10, 10)
    second = Rect(8, 8, 10, 10)
    merged = merge_overlapping_rects([first, second])
    assert len(merged) == 1
    assert merged[0] == Rect(0, 0, 18, 18)


def test_rect_mask_marks_interior_pixels():
    mask = rect_mask((20, 20), [Rect(2, 2, 5, 5)])
    assert mask[2:7, 2:7].all()
    assert not mask[0, 0]


def test_rect_as_dict_includes_label():
    rect = Rect(1, 2, 3, 4)
    payload = rect.as_dict(label="clock")
    assert payload["label"] == "clock"


def test_masks_to_boxes_empty_mask():
    mask = np.zeros((10, 10), dtype=bool)
    assert masks_to_boxes(mask, min_area=1, pad=0) == []


def test_assert_same_shape_rejects_mismatch():
    a = make_uniform_image(10, 10, (1, 1, 1))
    b = make_uniform_image(12, 10, (1, 1, 1))
    with pytest.raises(ValueError, match="shape"):
        assert_same_shape([a, b])
