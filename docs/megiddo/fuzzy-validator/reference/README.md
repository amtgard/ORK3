# Fuzzy Validator — Reference

Live technical specs for operators and agents who need more depth than the top-level guides. These describe **as-built** behavior of `tools/fuzzy-validator/` (FU-0 … FU-16).

| Doc | Purpose |
|-----|---------|
| [01-architecture.md](./01-architecture.md) | Algorithms, stabilization, capture/calibrate/gate pipeline |
| [03-manifest-schema.md](./03-manifest-schema.md) | `pages.json5`, fuzz / DOM-fuzz JSON, thresholds |
| [04-operating-guide.md](./04-operating-guide.md) | Detailed operating procedures and troubleshooting |
| [06-gate-output-and-report.md](./06-gate-output-and-report.md) | Pass/fail scoring + HTML report contract |
| [10-cli-reference.md](./10-cli-reference.md) | Complete `bin/fuzzy-validator` flag reference |
| [11-dual-database-profiles.md](./11-dual-database-profiles.md) | Test (strict) vs mirror (lenient) profiles |
| [examples/profiles.json5.example](./examples/profiles.json5.example) | Example dual-profile config |

**Start here instead for most work:** [../USER-GUIDE.md](../USER-GUIDE.md) · [../DEVELOPER-GUIDE.md](../DEVELOPER-GUIDE.md) · [../12-design-and-implementation.md](../12-design-and-implementation.md)
