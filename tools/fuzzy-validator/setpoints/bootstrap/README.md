# Setpoint bootstrap bundles

Committed pilot baseline zips for local restore and CI. Heavy baselines are **not** stored in git as loose PNG/DOM/asset files — only this pointer zip and `setpoint.json`.

## Restore (developers)

From repo root:

```bash
bin/fuzzy-validator setpoint restore
```

Uses `latestBundle` from `setpoint.json` and extracts into `baselines/` (gitignored).

## Maintainer uploads

After `setpoint capture`, upload the zip from `setpoints/out/` to the public Google Drive folder **ORK3 Fuzzy Setpoints** (filename unchanged), then `setpoint publish --bundle …` and commit `setpoint.json` + `manifests/` only.

See [04-operating-guide.md](../../../../docs/megiddo/fuzzy-validator/reference/04-operating-guide.md) § Setpoint promotion.
