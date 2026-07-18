# V-04: EventAjax Core — Validation Artifacts

**Milestone:** V-04  
**Branch:** `megiddo/v-04-eventajax-validation`  
**Target IDs:** T-EVA-01 through T-EVA-13 (excl. addauth → V-02; banner → V-03)  
**Depends on:** DS-04, T-04, V-00  
**Execution sprint:** R-04  
**Discovery source:** [ds-04-eventajax-discovery.md §1](../ds-04-eventajax-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Event **planning host pages** (list, create, kingdom/park calendars, index). EventAjax JSON (preview, staff, schedule, status) is covered by T-04 integration + e2e.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `event-list` | `./index.php?Route=Event` | login | T-EVA-01–02 host | V-00 — re-validate / refresh if drifted |
| `event-create` | `./index.php?Route=Event/create/1` | login | T-EVA-01 | V-00 |
| `event-kingdom` | `./index.php?Route=Event/kingdom/1` | login | T-EVA-03 preview host | V-00 |
| `event-park` | `./index.php?Route=Event/park/1` | login | Calendar / preview | V-00 |
| `event-index-rsvp` | `./index.php?Route=Event/index/80000` | login | Index + RSVP overlap | V-01 |
| `event-index-rsvp-gok` | `./index.php?Route=Event/index/80003` | login | Index variant | V-01 |

No new `pages.json5` rows for V-04. Event/detail remains `skip: true` (fullPage hang) — preview modal behavior stays in `EventPlanningTest` / e2e.

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Event list / create | `event-list` | `event-create` |
| Calendar hosts | `event-kingdom` | `event-park` |
| Event index (sandbox) | Spring War `80000` | GOK `80003` |

### 1.3 Record / validate

V-01 index canaries already baselined. V-00 event hosts re-recorded on V-04 (DOM/visual drift). Confirm:

```bash
bin/fuzzy-validator validate --pages event-index-rsvp,event-index-rsvp-gok,event-list,event-create,event-kingdom,event-park --phase all
```

**V-04 capture result:** validate exit **0** (12/12). Setpoint updated with refreshed event-list/create/kingdom/park baselines.
---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-04)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/EventPlanningTest.php` | Integration | Status, staff, schedule, copy, heraldry |
| `tests/Integration/EventAttendanceAjaxTest.php` | Integration | Staff attendance / delete RSVP |
| `tests/Support/EventPlanningFixture.php` | Fixture | Seeded events/staff/schedule |
| `tests/e2e/event-planning.spec.ts` | e2e | Kingdom/park calendar hosts |

**Infection:** `tools/infection/infection.t04-eventajax.json5` — MSI 48% on `class.Event.php` + `EventPlanningTest`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `EventPlanningTest` | Method moved to `EventPlanning` / service | Controllers thinned |
| `EventAttendanceAjaxTest` | Enrichment query path | Attendance display row API |
| `event-planning.spec.ts` | Selector / draft visibility | Status API changes markup |
| RSVP overlap (T-EVA-05) | Depends on R-01 API | Coordinate with V-01 / R-01 |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-04 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | New `class.EventPlanning.php` + EventService handlers | Change draft/publish semantics or staff capability bits |
| **Cross-sprint** | Call R-01 RSVP + R-03 CopyBanner APIs | Re-implement RSVP/banner SQL in EventAjax |
| **Fixtures** | Sandbox events `80000+`; fixture-created planning rows | Mirror-only event ids without doc |
| **Fuzzy** | Re-record on intentional planning UI change | Widen thresholds to force pass |
| **Infection** | Add `--filter=class.EventPlanning.php` | MSI below T-04 floor without justification |

### 2.4 Post-R-04 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Event.php \
  --filter=class.EventPlanning.php \
  --test-framework-options="--filter=EventPlanningTest"
```

---

## 3. R-04 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4
- [x] No new `$DB` in `Controller_EventAjax` for migrated targets
