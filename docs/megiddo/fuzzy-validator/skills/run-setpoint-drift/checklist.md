# run-setpoint-drift — Checklist

## Preflight

- [ ] Docker php8 stack up; `ORK3_E2E_BASE_URL` set if needed
- [ ] Playwright + `tools/fuzzy-validator` Python deps present
- [ ] Gold-master setpoint restored (`bin/fuzzy-validator setpoint restore`)
- [ ] Mirror freshness checked (`extracted_at` ≤ 7 days) **or** operator override recorded
- [ ] Sandbox available if using test profile (`deploy-sandbox` / `--ensure-sandbox`)

## Run

- [ ] `validate --profiles test,mirror --phase all` with overlay flags as directed
- [ ] Exit code recorded (0 / 1 / 2)
- [ ] `drifts.json` present; unexpected listed first in stdout / HTML
- [ ] Per-page `reproduce.md` present under report `pages/`

## Optional evaluator (non-masking)

- [ ] `annotations.json` written with assessments only
- [ ] Confirmed: exit code unchanged; unexpected drifts still in `drifts.json` and HTML Unexpected section

## Operator summary

- [ ] Unexpected / expected intentional / expected natural counts
- [ ] Report path + setpoint id + mirror age
