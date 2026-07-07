#!/usr/bin/env python3
"""Apply controlled mutations for fuzzy-validator evidence suite."""

from __future__ import annotations

import argparse
import hashlib
import re
import shutil
import sys
from pathlib import Path

from PIL import Image

from lib.asset_manifest import load_asset_manifest, parse_assets, save_asset_manifest
from lib.asset_store import asset_file_name, resolve_baseline_asset_path

HERALDRY_BOX = (35, 140, 225, 280)
SESSION_TOKEN_ATTR = "data-session-token"
SESSION_TOKEN_STABLE = "evidence-session-stable"
SESSION_TOKEN_VOLATILE_A = "evidence-session-volatile-a"
SESSION_TOKEN_VOLATILE_B = "evidence-session-volatile-b"

ASSET_PAGE_ID = "home-authenticated"
ASSET_CSS_ID = "css-000"
ASSET_JS_ID = "js-000"


def _repo_root() -> Path:
    return Path(__file__).resolve().parents[4]


def evidence_root() -> Path:
    return Path(__file__).resolve().parents[1]


def heraldry_source() -> Path:
    return _repo_root() / "tools" / "ork-db" / "generated-assets" / "players" / "000000.png"


def reset_calibration_dir(cal_dir: Path, virgin_png: Path) -> None:
    cal_dir.mkdir(parents=True, exist_ok=True)
    for path in cal_dir.glob("run-*"):
        path.unlink()
    for run in range(1, 6):
        label = f"run-{run:03d}"
        shutil.copy2(virgin_png, cal_dir / f"{label}.png")


def reset_dom_calibration_dir(cal_dir: Path, virgin_png: Path, virgin_dom_html: Path) -> None:
    reset_calibration_dir(cal_dir, virgin_png)
    html = virgin_dom_html.read_text(encoding="utf-8")
    for run in range(1, 6):
        (cal_dir / f"run-{run:03d}.dom.html").write_text(html, encoding="utf-8")


def patch_heraldry(image_path: Path, heraldry_path: Path, box: tuple[int, int, int, int]) -> None:
    base = Image.open(image_path).convert("RGB")
    heraldry = Image.open(heraldry_path).convert("RGB")
    x0, y0, x1, y1 = box
    target = heraldry.resize((x1 - x0, y1 - y0), Image.Resampling.LANCZOS)
    base.paste(target, (x0, y0))
    base.save(image_path)


def add_top_padding_bar(image_path: Path, height_px: int = 20) -> None:
    base = Image.open(image_path).convert("RGB")
    draw = Image.new("RGB", base.size, (0, 0, 0))
    draw.paste((220, 40, 40), (0, 0, base.size[0], height_px))
    base.paste(draw.crop((0, 0, base.size[0], height_px)), (0, 0))
    base.save(image_path)


def prepare_pixel_discover_calibrations(
    *,
    cal_dir: Path,
    virgin_png: Path,
    heraldry_png: Path,
) -> None:
    reset_calibration_dir(cal_dir, virgin_png)
    for run in range(1, 6):
        if run % 2 == 0:
            patch_heraldry(cal_dir / f"run-{run:03d}.png", heraldry_png, HERALDRY_BOX)


def prepare_pixel_inzone_candidate(cal_dir: Path, virgin_png: Path, heraldry_png: Path) -> None:
    cal_dir.mkdir(parents=True, exist_ok=True)
    candidate = cal_dir / "candidate.png"
    shutil.copy2(virgin_png, candidate)
    patch_heraldry(candidate, heraldry_png, HERALDRY_BOX)


def prepare_pixel_outzone_candidate(cal_dir: Path, virgin_png: Path) -> None:
    cal_dir.mkdir(parents=True, exist_ok=True)
    candidate = cal_dir / "candidate.png"
    shutil.copy2(virgin_png, candidate)
    add_top_padding_bar(candidate)


def _inject_session_token(html: str, token: str) -> str:
    if 'id="main"' in html:
        return re.sub(
            r'(<[^>]*\bid=["\']main["\'][^>]*)>',
            lambda match: (
                f'{match.group(1)} {SESSION_TOKEN_ATTR}="{token}">'
                if SESSION_TOKEN_ATTR not in match.group(1)
                else re.sub(
                    rf'{SESSION_TOKEN_ATTR}="[^"]*"',
                    f'{SESSION_TOKEN_ATTR}="{token}"',
                    match.group(1),
                )
                + ">"
            ),
            html,
            count=1,
        )
    if 'id="theme_container"' in html:
        return re.sub(
            r'(<[^>]*\bid=["\']theme_container["\'][^>]*)>',
            lambda match: (
                f'{match.group(1)} {SESSION_TOKEN_ATTR}="{token}">'
                if SESSION_TOKEN_ATTR not in match.group(1)
                else re.sub(
                    rf'{SESSION_TOKEN_ATTR}="[^"]*"',
                    f'{SESSION_TOKEN_ATTR}="{token}"',
                    match.group(1),
                )
                + ">"
            ),
            html,
            count=1,
        )
    return html.replace("<body", f'<body {SESSION_TOKEN_ATTR}="{token}"', 1)


def prepare_dom_discover_calibrations(
    *,
    cal_dir: Path,
    virgin_dom_html: Path,
) -> None:
    virgin_png = virgin_dom_html.with_suffix(".png")
    if not virgin_png.is_file():
        virgin_png = cal_dir.parent.parent / "baselines" / "test" / "home-authenticated.png"
    reset_dom_calibration_dir(cal_dir, virgin_png, virgin_dom_html)
    html = virgin_dom_html.read_text(encoding="utf-8")
    for run in range(1, 6):
        token = SESSION_TOKEN_VOLATILE_A if run % 2 == 0 else SESSION_TOKEN_STABLE
        (cal_dir / f"run-{run:03d}.dom.html").write_text(
            _inject_session_token(html, token),
            encoding="utf-8",
        )


def prepare_dom_inzone_candidate(cal_dir: Path, virgin_dom_html: Path) -> None:
    cal_dir.mkdir(parents=True, exist_ok=True)
    html = virgin_dom_html.read_text(encoding="utf-8")
    (cal_dir / "candidate.dom.html").write_text(
        _inject_session_token(html, SESSION_TOKEN_VOLATILE_A),
        encoding="utf-8",
    )


def prepare_dom_outzone_candidate(cal_dir: Path, virgin_dom_html: Path) -> None:
    cal_dir.mkdir(parents=True, exist_ok=True)
    html = virgin_dom_html.read_text(encoding="utf-8")
    mutated = _inject_session_token(html, SESSION_TOKEN_STABLE)
    mutated = mutated.replace(
        "Welcome to the Amtgard Online Record Keeper",
        "EVIDENCE OUT-OF-ZONE DOM REGRESSION",
        1,
    )
    (cal_dir / "candidate.dom.html").write_text(mutated, encoding="utf-8")


def _sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _baseline_assets_manifest(root: Path, profile: str, page_id: str) -> Path:
    return root / "baselines" / profile / f"{page_id}.assets.json"


def _candidate_assets_dir(cal_dir: Path) -> Path:
    return cal_dir / "assets" / "candidate"


def _copy_asset_bytes(
    *,
    entry,
    root: Path,
    dest_dir: Path,
) -> None:
    source = resolve_baseline_asset_path(entry.baseline_path, root)
    dest_dir.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, dest_dir / asset_file_name(entry))


def prepare_assets_pass_candidate(root: Path, profile: str, page_id: str = ASSET_PAGE_ID) -> None:
    cal_dir = root / "calibrations" / page_id
    manifest_path = _baseline_assets_manifest(root, profile, page_id)
    manifest = load_asset_manifest(manifest_path)
    candidate_dir = _candidate_assets_dir(cal_dir)
    if candidate_dir.exists():
        shutil.rmtree(candidate_dir)
    for entry in parse_assets(manifest):
        _copy_asset_bytes(entry=entry, root=root, dest_dir=candidate_dir)
    save_asset_manifest(cal_dir / "candidate.assets.json", manifest)


def _mutate_asset_byte(
    *,
    root: Path,
    profile: str,
    page_id: str,
    asset_id: str,
) -> None:
    prepare_assets_pass_candidate(root, profile, page_id)
    cal_dir = root / "calibrations" / page_id
    manifest_path = cal_dir / "candidate.assets.json"
    manifest = load_asset_manifest(manifest_path)
    candidate_dir = _candidate_assets_dir(cal_dir)
    target = next(entry for entry in parse_assets(manifest) if entry.id == asset_id)
    asset_path = candidate_dir / asset_file_name(target)
    if not asset_path.is_file():
        matches = sorted(candidate_dir.glob(f"{asset_id}*"))
        asset_path = matches[0]
    mutated = asset_path.read_bytes() + b"X"
    asset_path.write_bytes(mutated)
    updated_assets = []
    for asset in manifest["assets"]:
        payload = dict(asset)
        if payload["id"] == asset_id:
            payload["sha256"] = _sha256_bytes(mutated)
            payload["byteLength"] = len(mutated)
        updated_assets.append(payload)
    manifest["assets"] = updated_assets
    save_asset_manifest(manifest_path, manifest)


def prepare_assets_css_fail_candidate(root: Path, profile: str) -> None:
    _mutate_asset_byte(root=root, profile=profile, page_id=ASSET_PAGE_ID, asset_id=ASSET_CSS_ID)


def prepare_assets_js_fail_candidate(root: Path, profile: str) -> None:
    _mutate_asset_byte(root=root, profile=profile, page_id=ASSET_PAGE_ID, asset_id=ASSET_JS_ID)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Evidence suite mutation helpers")
    parser.add_argument(
        "action",
        choices=[
            "pixel-discover",
            "pixel-inzone",
            "pixel-outzone",
            "dom-discover",
            "dom-inzone",
            "dom-outzone",
            "assets-pass",
            "assets-css-fail",
            "assets-js-fail",
        ],
    )
    parser.add_argument("--evidence-root", type=Path, default=None)
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    root = args.evidence_root or evidence_root()
    profile = "test"

    if args.action.startswith("assets"):
        manifest_path = _baseline_assets_manifest(root, profile, ASSET_PAGE_ID)
        if not manifest_path.is_file():
            print(f"evidence_mutations: missing asset baseline {manifest_path}", file=sys.stderr)
            return 1
        if args.action == "assets-pass":
            prepare_assets_pass_candidate(root, profile)
        elif args.action == "assets-css-fail":
            prepare_assets_css_fail_candidate(root, profile)
        elif args.action == "assets-js-fail":
            prepare_assets_js_fail_candidate(root, profile)
        return 0

    heraldry = heraldry_source()

    if args.action.startswith("pixel"):
        page_id = "player-profile"
        cal_dir = root / "calibrations" / page_id
        virgin_png = root / "baselines" / profile / f"{page_id}.png"
        if not virgin_png.is_file():
            print(f"evidence_mutations: missing virgin baseline {virgin_png}", file=sys.stderr)
            return 1
        if not heraldry.is_file():
            print(f"evidence_mutations: missing heraldry source {heraldry}", file=sys.stderr)
            return 1

        if args.action == "pixel-discover":
            prepare_pixel_discover_calibrations(
                cal_dir=cal_dir,
                virgin_png=virgin_png,
                heraldry_png=heraldry,
            )
        elif args.action == "pixel-inzone":
            prepare_pixel_inzone_candidate(cal_dir, virgin_png, heraldry)
        elif args.action == "pixel-outzone":
            prepare_pixel_outzone_candidate(cal_dir, virgin_png)
        return 0

    page_id = "home-authenticated"
    cal_dir = root / "calibrations" / page_id
    virgin_dom = root / "baselines" / profile / f"{page_id}.dom.html"
    if not virgin_dom.is_file():
        print(f"evidence_mutations: missing virgin DOM {virgin_dom}", file=sys.stderr)
        return 1

    if args.action == "dom-discover":
        prepare_dom_discover_calibrations(cal_dir=cal_dir, virgin_dom_html=virgin_dom)
    elif args.action == "dom-inzone":
        prepare_dom_inzone_candidate(cal_dir, virgin_dom)
    elif args.action == "dom-outzone":
        prepare_dom_outzone_candidate(cal_dir, virgin_dom)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
