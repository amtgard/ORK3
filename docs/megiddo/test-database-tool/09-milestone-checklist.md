# Test Database Tool — Milestone Checklist

---

## TD-0 — Design sign-off

- [x] Doc set complete
- [x] No database CLI args on extract/render/apply
- [x] Deployment tier guard documented
- [x] `manifests/wiring.json5` — hardcoded mirror + sandbox
- [ ] Maintainer fills `ken_walker` / `avery_krouse` mundane_id
- [ ] Maintainer review

---

## TD-1 — Docker sandbox container

- [x] `ork3testdb` on port 19307, database `ork_test`, volume `data-test-db`

---

## TD-2 — Safety + tier guard

- [x] `DeploymentTier.php` — local vs production
- [x] Data commands refused on production tier
- [x] `validate` — port 19307, database `ork_test`, canaries, fingerprints
- [x] No CLI overrides for wiring host/port/database

---

## TD-3 — Extract

- [ ] `bin/ork-db extract` — no args — always mirror 19306/ork
- [ ] Refused on production tier

---

## TD-4 — Render

- [ ] `bin/ork-db render` — no DB connection
- [ ] Refused on production tier

---

## TD-5 — Apply

- [ ] `bin/ork-db apply` — no args — always sandbox 19307/ork_test
- [ ] Refused on production tier
- [ ] Kingdom monikers + Grand Duchy of Litavia post-apply

---

## TD-6 — use prod|dev

- [ ] `bin/ork-db use prod` / `use dev`
- [ ] `use dev` refused on production tier

---

## TD-7 — PHPUnit

- [ ] `config.test.php` → 19307 / `ork_test`
- [ ] Full suite passes after `apply`

---

## TD-8 — Migration classifier

- [ ] `migration-classification.json5` complete
- [ ] `schema-diff`

---

## TD-9 — Tests

- [ ] PHPUnit golden render test
- [ ] Tier refusal test (production signals → extract/apply refuse)
- [ ] Integration round-trip on local tier

---

## Project sign-off

- [ ] `apply` impossible on production host
- [ ] `apply` impossible against mirror (no code path)
- [ ] Operator cannot mistype a database name on data commands
