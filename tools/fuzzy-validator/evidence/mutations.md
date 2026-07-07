# Evidence mutation recipes

Controlled mutations for integration proof. Scripts in `scripts/evidence_mutations.py` apply these to calibration captures.

## Pixel — `player-profile`

| Step | Mutation | Expected |
|------|----------|----------|
| Virgin | 5× identical stabilized capture | Baseline PNG committed |
| 2a Discover | Runs 1–3 virgin; runs 4–5 heraldry patch (bbox ~35,140–225,280) with alternate heraldry PNG | Non-empty `player-profile.fuzz.json` + overlay |
| 2b In-zone | Candidate with heraldry patch inside learned bbox | `validate --phase visual` → exit 0 |
| 2c Out-of-zone | Candidate with 20px top padding bar (full width, outside heraldry bbox) | `validate --phase visual` → exit 1, red boxes in report |

**Heraldry source:** `tools/ork-db/generated-assets/players/000000.png` swapped into profile heraldry region.

## DOM — `home-authenticated`

| Step | Mutation | Expected |
|------|----------|----------|
| Virgin | 5× capture with DOM HTML | Baseline `home-authenticated.dom.json` |
| 2a Discover | Runs 1–3 stable; runs 4–5 change `data-session-token` on `#main` (or equivalent volatile attr) | Non-empty `home-authenticated.dom-fuzz.json` + `dom-fuzz.txt` |
| 2b In-zone | Candidate with same token drift as calibration runs 4–5 | `validate --phase dom` → exit 0 |
| 2c Out-of-zone | Candidate renames stable heading text outside fuzz nodes | `validate --phase dom` → exit 1 |

## Assets — *(FU-14, deferred here)*

| Step | Mutation | Expected |
|------|----------|----------|
| Pass | Same commit as baseline | `validate --phase assets` → exit 0 |
| Fail | Append one byte to a captured `.css` under `orkui/` | exit 1, diff in `assets-proof` report |

## Unified — *(FU-15, deferred here)*

Composite in-zone pass and out-of-zone fail with `validate --phase all`.
