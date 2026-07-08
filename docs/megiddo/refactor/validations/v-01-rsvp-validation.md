# V-01: RSVP — Validation Artifacts

**Milestone:** V-01  
**Branch:** `megiddo/v-01-rsvp-validation`  
**Target IDs:** T-RSV-01 through T-RSV-09, T-INF-06  
**Depends on:** DS-01, T-01, V-00  
**Execution sprint:** R-01  
**Discovery source:** [ds-01-rsvp-discovery.md §1](../ds-01-rsvp-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Notes |
|--------|-------|------|------------|-------|
| `home-authenticated` | `./index.php?Route=` | login | T-INF-06 | Home widget RSVP counts |
| `event-detail-rsvp` | `./index.php?Route=Event/detail/{detailId}` | none | T-RSV-01–03 | RSVP buttons + counts on occurrence |
| `event-detail-auth-rsvp` | `./index.php?Route=Event/detail/{detailId}` | login | T-RSV-02 | my_status + set/withdraw |
| `player-upcoming-rsvp` | `./index.php?Route=Player/profile/{mundaneId}` | login | T-RSV-08 | Upcoming RSVPs list |

**Sandbox pins (draft — confirm after deploy-sandbox):** `{detailId}`, `{mundaneId}` from seeded major event + fake player in `ork_test`.

### 1.2 Canary matrix

| Surface | Variant A | Variant B | Variant C |
|---------|-----------|-----------|-----------|
| Event RSVP AJAX host | Published future event, anonymous | Same event, logged-in player | Past event (end-date gate — expect stable chrome, no button) |
| Home widget counts | Authenticated home | — | — |
| Player upcoming | Own profile | — | — |

### 1.3 Record baselines

```bash
bin/fuzzy-validator record --pages home-authenticated,event-detail-rsvp,event-detail-auth-rsvp,player-upcoming-rsvp --phase all --profile test
bin/fuzzy-validator record --pages home-authenticated,event-detail-rsvp,event-detail-auth-rsvp,player-upcoming-rsvp --phase all --profile mirror
```

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-01)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/EventRsvpTest.php` | Integration | Model_Event RSVP methods |
| `tests/Integration/EventRsvpAjaxTest.php` | Integration | EventRsvpAjax counts/set/withdraw |
| `tests/Integration/EventRsvpSearchTest.php` | Integration | Search + kingdom upcoming |
| `tests/Unit/EventRsvpValidationTest.php` | Unit | Status whitelist, date gate |
| `tests/e2e/rsvp.spec.ts` | e2e | Home + player profile smoke |

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `EventRsvpAjaxTest` | 404 or wrong JSON shape | AJAX handler thinned; logic in service |
| `EventRsvpTest` | Direct model method removed | Reads move to `EventService` |
| `EventRsvpSearchTest` | SQL assertion on frontend path | Query moved to domain |
| `rsvp.spec.ts` | Selector drift | Template changes if API-driven markup differs |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-01 | Not allowed |
|----------|---------------------|-------------|
| **Integration tests** | Call `EventService` / JSON endpoints instead of `Model_Event` | Change RSVP count semantics or status enum |
| **Fixtures** | Use sandbox seeded event detail id | Hardcode mirror-only event ids without doc |
| **e2e** | Update selectors for equivalent markup | Remove authenticated flow from sign-off |
| **Fuzzy** | Re-record only if intentional RSVP UI change | Pass validate by widening fuzz without review |
| **Infection** | `--filter=class.Event.php` + new service paths | MSI below T-01 documented floor |

### 2.4 Post-R-01 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Event.php \
  --test-framework-options="--filter=EventRsvp"
```

---

## 3. R-01 sign-off checklist

- [ ] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [ ] Test edits within §2.3
- [ ] Full unit suite green
- [ ] Infection per §2.4
