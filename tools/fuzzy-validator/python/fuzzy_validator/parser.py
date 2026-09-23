"""Argparse definitions for the fuzzy-validator CLI."""

from __future__ import annotations

import argparse


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="fuzzy-validator",
        description="ORK3 refactor-stability harness (pixels, DOM, assets).",
    )
    subparsers = parser.add_subparsers(dest="command", metavar="COMMAND")

    shared = argparse.ArgumentParser(add_help=False)
    shared.add_argument("--profile", metavar="NAME", help="Single profile: test or mirror")
    shared.add_argument(
        "--profiles",
        metavar="LIST",
        default="test,mirror",
        help="Comma-separated profiles (default: test,mirror)",
    )
    shared.add_argument(
        "--ensure-sandbox",
        action="store_true",
        help="Run bin/ork-db deploy-sandbox before test profile",
    )
    shared.add_argument("--base-url", metavar="URL", help="Override ORK3_E2E_BASE_URL")
    shared.add_argument("--dry-run", action="store_true", help="Print targets only")
    shared.add_argument("--run-id", metavar="ID", help="Report directory id")
    shared.add_argument("--visual-min-score", type=float, metavar="SCORE")
    shared.add_argument("--dom-min-score", type=float, metavar="SCORE")
    shared.add_argument("--assets-min-score", type=float, metavar="SCORE")
    shared.add_argument(
        "--tool-root",
        metavar="PATH",
        help="Alternate tool root (e.g. tools/fuzzy-validator/evidence)",
    )
    shared.add_argument(
        "--skip-capture",
        action="store_true",
        help="Validate using existing candidate files in calibrations/ (evidence suite)",
    )

    record = subparsers.add_parser(
        "record",
        parents=[shared],
        help="Capture stabilized renders, learn fuzz zones, write baselines",
    )
    record.add_argument("urls", nargs="*", help="Full page URLs (positional)")
    record.add_argument("--urls", metavar="FILE", help="URL list file (one per line)")
    record.add_argument("--page", metavar="ID", help="Single registry page id")
    record.add_argument("--pages", metavar="IDS", help="Comma-separated registry page ids")
    record.add_argument("--all", action="store_true", help="All non-skipped registry pages")
    record.add_argument("--phase", default="visual", choices=["visual", "assets", "dom", "all"])
    record.add_argument("--repeat", type=int, metavar="N", help="Calibration capture count")

    validate = subparsers.add_parser(
        "validate",
        parents=[shared],
        help="Capture once, compare to baselines, pass/fail + HTML report",
    )
    validate.add_argument("urls", nargs="*", help="Full page URLs (positional)")
    validate.add_argument("--urls", metavar="FILE", help="URL list file (one per line)")
    validate.add_argument("--page", metavar="ID", help="Single registry page id")
    validate.add_argument("--pages", metavar="IDS", help="Comma-separated registry page ids")
    validate.add_argument("--all", action="store_true", help="All non-skipped registry pages")
    validate.add_argument("--phase", default="all", choices=["visual", "assets", "dom", "all"])
    validate.add_argument(
        "--overlay",
        metavar="PATHS",
        help="Comma-separated drift overlay files (schemaVersion 2)",
    )
    validate.add_argument(
        "--overlay-dir",
        metavar="DIR",
        help="Load all *.json5 / *.json overlays from a directory",
    )
    validate.add_argument(
        "--putative",
        action="store_true",
        help="Also load overlays/putative/ (off by default)",
    )
    validate.add_argument(
        "--require-fresh-mirror",
        action="store_true",
        help="Fail (exit 2) if prod mirror extracted_at is older than 7 days",
    )
    validate.add_argument(
        "--mirror-stale-ok",
        metavar="REASON",
        help="Override stale mirror check; reason is recorded in drifts.json",
    )
    validate.add_argument(
        "--annotations",
        metavar="PATH",
        help="Optional annotations.json path (display-only; never affects exit)",
    )
    validate.add_argument(
        "--annotations-out",
        metavar="PATH",
        help="Write empty annotations shell for agent evaluator (display-only)",
    )

    refuzz = subparsers.add_parser(
        "refuzz",
        parents=[shared],
        help="Capture candidate vs baseline; merge cross-session fuzz; re-baseline",
    )
    refuzz.add_argument("--page", metavar="ID", help="Single registry page id")
    refuzz.add_argument("--pages", metavar="IDS", help="Comma-separated registry page ids")
    refuzz.add_argument("--all", action="store_true", help="All non-skipped registry pages")
    refuzz.add_argument(
        "--natural",
        action="store_true",
        help="All active pages with driftClass=natural",
    )
    refuzz.add_argument("--phase", default="all", choices=["visual", "dom", "all"])

    setpoint = subparsers.add_parser(
        "setpoint",
        help="Capture, publish, and restore baseline setpoint bundles",
    )
    setpoint_sub = setpoint.add_subparsers(dest="setpoint_command", metavar="ACTION")

    sp_capture = setpoint_sub.add_parser(
        "capture",
        help="record --all --phase all + zip baselines to setpoints/out/",
    )
    sp_capture.add_argument("--profile", metavar="NAME", help="Single profile: test or mirror")
    sp_capture.add_argument(
        "--profiles",
        metavar="LIST",
        default="test,mirror",
        help="Comma-separated profiles (default: test,mirror)",
    )
    sp_capture.add_argument("--ensure-sandbox", action="store_true")
    sp_capture.add_argument("--base-url", metavar="URL")
    sp_capture.add_argument("--dry-run", action="store_true")
    sp_capture.add_argument(
        "--out-dir",
        metavar="PATH",
        help="Bundle output directory (default: setpoints/out)",
    )

    sp_publish = setpoint_sub.add_parser(
        "publish",
        help="Update setpoint.json with bundle filename + metadata",
    )
    sp_publish.add_argument(
        "--bundle",
        metavar="PATH",
        help="Path to zip (default: newest file in setpoints/out/)",
    )
    sp_publish.add_argument(
        "--drive-folder",
        metavar="NAME",
        default="ORK3 Fuzzy Setpoints",
        help="Human hint for Google Drive folder name",
    )

    sp_restore = setpoint_sub.add_parser(
        "restore",
        help="Extract baselines from a setpoint zip bundle",
    )
    sp_restore.add_argument(
        "--bundle",
        metavar="PATH",
        help="Local zip path (default: bootstrap or cached latest from setpoint.json)",
    )
    sp_restore.add_argument(
        "--base-url",
        metavar="URL",
        help="Public folder base URL; downloads latestBundle filename",
    )
    sp_restore.add_argument(
        "--no-verify",
        action="store_true",
        help="Skip content sha256 check against setpoint.json",
    )
    sp_restore.add_argument(
        "--tool-root",
        metavar="PATH",
        help="Alternate tool root (default: tools/fuzzy-validator)",
    )

    overlay = subparsers.add_parser(
        "overlay",
        help="Validate or summarize drift overlay files (schemaVersion 2)",
    )
    overlay_sub = overlay.add_subparsers(dest="overlay_command", metavar="ACTION")

    ov_validate = overlay_sub.add_parser(
        "validate",
        help="Schema + conflict check without page capture",
    )
    ov_validate.add_argument("paths", nargs="+", help="Overlay file path(s)")
    ov_validate.add_argument(
        "--tool-root",
        metavar="PATH",
        help="Alternate tool root",
    )

    ov_summarize = overlay_sub.add_parser(
        "summarize",
        help="Print entry counts by class/page",
    )
    ov_summarize.add_argument("paths", nargs="+", help="Overlay file path(s)")
    ov_summarize.add_argument(
        "--tool-root",
        metavar="PATH",
        help="Alternate tool root",
    )

    return parser
