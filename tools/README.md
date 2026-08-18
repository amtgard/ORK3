# Dev tooling

Local development tools live here (not at the repo root). Entry points are usually under `bin/`.

| Path | Purpose | CLI |
|------|---------|-----|
| [ork-db/](./ork-db/) | Sandbox DB build, assets, app DB switch | `bin/ork-db` |
| [fuzzy-validator/](./fuzzy-validator/) | Fuzzy UI / DOM / asset regression gates | `bin/fuzzy-validator` |
| [infection/](./infection/) | Infection mutation-testing configs | `bin/run-infection.sh` |
| [php-cs-fixer/](./php-cs-fixer/) | Pinned PHP CS Fixer PHAR (PSR-12 pre-commit) | `php tools/php-cs-fixer/php-cs-fixer.phar` |
