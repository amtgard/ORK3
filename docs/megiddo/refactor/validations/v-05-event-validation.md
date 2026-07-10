# V-05: Event Controller Detail — Validation Artifacts

**Milestone:** V-05  
**Branch:** `megiddo/v-05-event-validation`  
**Target IDs:** T-EVT-01 through T-EVT-08  
**Depends on:** DS-05, T-05, V-00  
**Execution sprint:** R-05  
**Discovery source:** [ds-05-event-discovery.md §1](../ds-05-event-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Event **page-render** hosts (sandbox index RSVP + create). Occurrence detail and template stay `skip: true` (Playwright `page.goto` hang). Mirror `event-index` (park `1`) is too volatile for the R-05 gate (event detail href churn) — keep as V-00 class coverage only.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `event-index-rsvp` | `./index.php?Route=Event/index/80000` | login | T-EVT-01 | V-01 — re-validate |
| `event-index-rsvp-gok` | `./index.php?Route=Event/index/80003` | login | T-EVT-01 | V-01 — re-validate |
| `event-create` | `./index.php?Route=Event/create/1` | login | T-EVT-07 | V-00 / V-04 — re-validate |
| `event-index` | `./index.php?Route=Event/index/1` | login | T-EVT-01 host | V-00 only — **not** R-05 gate (mirror DOM href churn) |
| `event-template` | `./index.php?Route=Event/template/80000` | login | T-EVT-02 | skip (hang); behavior via `EventRsvpBatchTest` |
| `event-detail-rsvp` | `./index.php?Route=Event/detail/80000/1` | none | T-EVT-03–06 | skip (hang) |
| `event-detail-auth-rsvp` | `./index.php?Route=Event/detail/80000/1` | login | T-EVT-03–06 | skip (hang) |

**Domain capture set:** none new (template skipped). Re-validate existing hosts.  
**R-05 fuzzy gate:** `event-index-rsvp,event-index-rsvp-gok,event-create`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Event index (RSVP batch) | Spring War `80000` | GOK `80003` |
| Create occurrence | `event-create` (park `1`) | — |
| Template / detail | e2e + integration only | visual skip |

**Sandbox pins:** events `80000` / `80003`, park `1`, login `megiddo`.  
**Mirror:** sandbox-namespace event ids render home chrome; `event-create` uses mirror park `1`.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages event-index-rsvp,event-index-rsvp-gok,event-create --phase all
```

**V-05 capture result:** validate exit **0** (6/6) on gate ids. `event-template` registered with `skip: true`.

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-05)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/EventOccurrenceTest.php` | Integration | Occurrence DTO, fees/links, reconcile, dietary, draft |
| `tests/Integration/EventRsvpBatchTest.php` | Integration | Batch RSVP counts / ownership (T-EVT-01/02) |
| `tests/e2e/event-detail.spec.ts` | e2e | Index / create / detail smoke |

**Infection:** `infection.t05-event.json5` — MSI 37% on `class.Event.php` + `EventOccurrenceTest|EventRsvpBatchTest`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `EventOccurrenceTest` | Method moved to `Event` / `EventPlanning` | Page DTO + fees/links leave controller |
| `EventRsvpBatchTest` | Depends on R-01 batch API | Coordinate with V-01 / R-01 |
| `event-detail.spec.ts` | Selector / tab visibility | Detail markup after DTO refactor |
| Template / detail visual | Still skipped | Hang — do not un-skip without tool fix |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-05 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | `GetOccurrencePageData`, fees/links, reconcile APIs | Change draft gate or attendance move semantics |
| **Cross-sprint** | Call R-01 RSVP + R-04 planning APIs | Re-implement RSVP/staff SQL in `Controller_Event` |
| **Fixtures** | Sandbox events `80000+`; fixture-created details | Mirror-only event ids without doc |
| **Fuzzy** | Re-record on intentional Event UI change | Widen thresholds to force pass; un-skip hang pages |
| **Infection** | Add `--filter=class.EventPlanning.php` if methods land there | MSI below T-05 floor without justification |

### 2.4 Post-R-05 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Event.php \
  --filter=class.EventPlanning.php \
  --test-framework-options="--filter=EventOccurrenceTest|EventRsvpBatchTest"
```

---

## 3. R-05 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4 (`--only-covered`)
- [x] No new `$DB` in `Controller_Event` for migrated T-EVT-* targets
