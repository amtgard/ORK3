# Drift overlays (fuzzy-validator v2)

Versioned allowances applied **at evaluate time** on top of a locked setpoint.
Overlays never rewrite zip baselines or committed `*.fuzz.json` / `*.dom-fuzz.json`.

```text
overlays/
  natural/        # reviewed natural refinements (optional)
  intentional/    # committed workstream intentional packs
  putative/       # drafts from putative-drift-overlay skill (source: putative)
```

Schema: `schemaVersion: 2` — see `docs/megiddo/fuzzy-validator/version-2/02-tool-extension.md`.

```bash
bin/fuzzy-validator overlay validate overlays/intentional/example.json5
bin/fuzzy-validator validate --page home-anonymous --overlay overlays/intentional/example.json5
```
