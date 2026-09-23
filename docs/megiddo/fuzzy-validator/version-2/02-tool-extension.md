# Version 2 — Tool Extension Plan

**Status:** Implemented  
**Code target:** `tools/fuzzy-validator/`  
**Parent:** [README.md](./README.md) · Requirements: [01-requirements.md](./01-requirements.md)

This document proposes concrete extensions to the shipped v1 tool. Prefer the smallest change that preserves exit-code honesty and setpoint immutability.

---

## 1. Design principles

1. **Setpoint immutability** — overlays live outside the zip / baseline trees.
2. **Fail closed** — unknown overlay schema, conflict, or missing requirement refs for intentional entries → error (`exit 2`) or unexpected (never silent pass).
3. **Additive classification** — gate math still subtracts allowed surface; reporting adds class labels.
4. **Evidence reuse** — reproduction packs point at existing report PNGs / DOM dumps; do not invent a second capture pipeline unless necessary.
5. **Asset layer stays hard** — intentional asset drift requires an explicit overlay entry with `layer: assets` and human review; default remains byte-identical.

---

## 2. New artifact: drift overlay

### 2.1 Location (proposal)

```text
tools/fuzzy-validator/overlays/
  natural/
    shared.json5                 # optional shared natural refinements
  intentional/
    {workstream-id}.json5        # e.g. megiddo-r20-calendar.json5
  putative/
    {workstream-id}.json5        # drafts from skill 5.2; not for CI by default
```

Committed overlays under `intentional/` and reviewed `natural/` are git-tracked. `putative/` may be committed as draft or gitignored per team preference (default: commit drafts next to requirements for review).

### 2.2 Schema sketch (`schemaVersion: 2`)

```json5
{
  schemaVersion: 2,
  id: "megiddo-feature-calendar-v1",
  workstream: "megiddo/feature-calendar",
  createdAt: "2026-07-19T12:00:00Z",
  basedOnSetpoint: "20260719T124448Z-671c108b-149b83ec1220a285.zip",
  entries: [
    {
      id: "cal-header-title",
      class: "intentional",
      layer: "dom",
      profiles: ["test", "mirror"],
      pages: ["home-authenticated"],
      // DOM: path or stable selector contract matching gate_dom
      dom: { pathPrefix: "/html/body/.../h1", match: "subtree" },
      rationale: "Home header title changes per REQ-12",
      requirementRef: "docs/megiddo/.../requirements.md#REQ-12",
      source: "putative"
    },
    {
      id: "weather-widget-pixels",
      class: "natural",
      layer: "visual",
      profiles: ["mirror"],
      pages: ["weather"],
      visual: { x: 40, y: 120, width: 400, height: 280 },
      rationale: "Third-party weather tiles",
      source: "manual"
    }
  ]
}
```

Exact DOM path / selector fields must align with existing `gate_dom` / `*.dom-fuzz.json` node identity (see [../reference/03-manifest-schema.md](../reference/03-manifest-schema.md)). Prefer reusing fuzz-node identity rather than inventing a parallel addressing scheme.

### 2.3 Merge order

1. Setpoint-calibrated fuzz (`*.fuzz.json`, `*.dom-fuzz.json`) → class `natural` for reporting.
2. Overlay `natural` entries.
3. Overlay `intentional` entries.
4. Detect conflicts (overlapping region, different classes or contradictory scopes) → fail closed.

---

## 3. CLI extensions

| Flag / command | Behavior |
|----------------|----------|
| `validate --overlay path[,path…]` | Load overlay files; classify drifts; apply allowances |
| `validate --overlay-dir dir` | Load all `*.json5` from intentional (+ optional natural) dirs |
| `validate --putative` | Also load `overlays/putative/` (off by default in CI) |
| `overlay validate path` | Schema + conflict check without full page capture |
| `overlay summarize path` | Print entry counts by class/page for humans/agents |

Stdout summary (extend v1 `FUZZ_GATE` line):

```text
FUZZ_GATE run=… pages=N pass=P fail=F unexpected=U expectedNatural=E1 expectedIntentional=E2 exit=0|1|2
```

Human block lists unexpected first, then expected by class.

---

## 4. Gate / report changes

### 4.1 Classification pipeline

For each layer diff unit (pixel component outside calibrated fuzz, DOM path, asset id):

1. If covered by calibrated fuzz or overlay → `expected` + class.
2. Else → `unexpected`.
3. Score computation: same as v1, but “allowed surface” includes overlay matches.

### 4.2 Drift inventory JSON

Write `reports/run-{id}/drifts.json`:

```json
{
  "runId": "…",
  "setpoint": "…",
  "overlays": ["…"],
  "drifts": [
    {
      "driftId": "mirror/home-authenticated/visual/d-014",
      "status": "unexpected",
      "class": null,
      "pageId": "home-authenticated",
      "profile": "mirror",
      "layer": "visual",
      "bbox": { "x": 0, "y": 0, "width": 0, "height": 0 },
      "evidence": {
        "baselinePng": "pages/home-authenticated/baseline.png",
        "candidatePng": "pages/home-authenticated/candidate.png",
        "diffPng": "pages/home-authenticated/diff.png"
      },
      "reproduce": {
        "stepsPath": "pages/home-authenticated/reproduce.md"
      }
    }
  ]
}
```

### 4.3 Reproduction markdown (mechanical)

Per page (or per drift), emit `reproduce.md` from templates filled with:

- `bin/fuzzy-validator setpoint restore` (pin bundle id)
- `bin/ork-db use prod|dev` for profile
- Base URL, page URL from `pages.json5`, viewport, auth
- `bin/fuzzy-validator validate --pages … --profiles … --overlay …`
- Relative links to baseline / candidate / diff images in the same report bundle

### 4.4 HTML report

Add index sections:

- Unexpected drifts (red) — primary
- Expected intentional (amber)
- Expected natural (green / muted)

Agent annotations (R5), if present, render as a **separate** “Assessment” column or sidebar that cannot collapse or hide the Unexpected section.

---

## 5. Agent evaluator integration (tool-adjacent)

Not a gate stage. Optional post-step:

```text
reports/run-{id}/annotations.json
```

```json
{
  "runId": "…",
  "annotator": "agent",
  "items": [
    {
      "driftId": "…",
      "assessment": "reasonable",
      "requirementRefs": ["…"],
      "notes": "Matches REQ-12 header copy change"
    }
  ]
}
```

CLI may support `validate … --annotations-out path` as a **placeholder path** only; the agent skill writes the file. The gate must ignore annotations for scoring.

---

## 6. Mirror freshness probe (shared with skill 5.1)

Tool or thin helper script (callable by the skill):

| Probe | Initial proposal |
|-------|------------------|
| Primary | `tools/ork-db/extracted/manifest.json` → `extracted_at` age |
| Fallback | Documented ork-db status if/when a first-class “mirror import age” exists |
| Threshold | 7 days |
| Behavior | Print clear prompt; exit non-zero with code meaning “stale mirror — refresh required” when `--require-fresh-mirror` is set |

Skill 5.1 uses this before dual-profile validate. Operators refresh the local prod mirror dump, re-extract if needed, then re-run.

---

## 7. Testing plan

| Tier | Coverage |
|------|----------|
| Unit | Overlay parse, merge, conflict detect, classification of synthetic diffs |
| Unit | Reproduction template rendering |
| Evidence | Extend evidence suite: intentional overlay turns a controlled mutation from FAIL→PASS **only** for that region; residual mutation still FAIL |
| Negative | Annotation file present must not change pytest/gate exit |

---

## 8. Suggested implementation milestones (tool only)

| ID | Deliverable |
|----|-------------|
| **FV2-0** | Overlay schema + `overlay validate` + unit tests |
| **FV2-1** | `validate --overlay` classification + `drifts.json` |
| **FV2-2** | HTML expected/unexpected sections + reproduce.md |
| **FV2-3** | `--require-fresh-mirror` helper + docs |
| **FV2-4** | Evidence suite cases for intentional vs unexpected |
| **FV2-5** | Annotation render path (display-only) |

Skill milestones are in [03-skills-and-milestones.md](./03-skills-and-milestones.md).
