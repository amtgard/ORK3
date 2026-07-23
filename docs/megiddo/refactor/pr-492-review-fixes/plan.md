# PR #492 Review Fix Plan

Per-comment plans for addressing review feedback on [PR #492](https://github.com/amtgard/ORK3/pull/492).
Progress is tracked in [checklist.md](./checklist.md).

**Branch:** `fix-pr-492`  
**Base tip:** `megiddo/fuzzy-validator-v2` @ `93a93be2`

---

## C-01 — Event model runtime-fatal delegation

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3615538285 · id `3615538285`
- **Summary:** `Model_Event` uses `APIModel('Event')`, which binds to plain `Event`. Planning/embed methods live on `EventPlanning` / `EventEmbed`, so ~12+ call sites (and more in `model.EventPlanning.php`) fatal at runtime with “undefined method”.
- **Code context:** Broken calls include `GetSchedule`, `GetPublishedScheduleEmbed`, `GetOccurrencePageData`, schedule CRUD, etc. Working pattern: `Model_EventPlanning::_planning()`. Tests today call `new EventPlanning()` directly and miss the UI path.
- **Proposed fix:** Add `_planning()` / `_embed()` helpers on `Model_Event` (and fix the same `APIModel('Event')` misuse in `model.EventPlanning.php`). Route planning/embed calls through those; keep `$this->Event` for true `Event` APIs.
- **Tests:** Integration via `Model_Event` for `get_schedule` / embed / occurrence page data — must not fatal. Optional guard: `method_exists(new Event(), 'GetSchedule') === false`.

---

## C-02 — Banner::CopyBanner missing authorization

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3615538291 · id `3615538291`
- **Summary:** `CopyBanner` mutates banner files/DB with no Token/`canEditBanner`, but is JSON/SOAP registered.
- **Code context:** Siblings `SetBanner` / `UpdateBannerConfig` / `RemoveBanner` all gate. Internal caller `EventPlanning::CreateEventWithCopy` already has a token.
- **Proposed fix:** Require Token; `canEditBanner` on target (and source). Pass Token from `CreateEventWithCopy`. Add Token to `CopyBannerRequest`.
- **Tests:** No/bad Token → BadToken; unauthorized → NoAuthorization; authorized copy succeeds; CreateEventWithCopy with banner still works.

---

## C-03 — DangerAudit MethodCall SQL injection

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3615538296 · id `3615538296`
- **Summary:** `mysql_real_escape_string` is a no-op shim; `MethodCall` is interpolated unescaped into WHERE.
- **Code context:** Dates regex-validated; EntityType whitelisted; MethodCall is not. Prefer whitelist against `ListAuditMethods()`.
- **Proposed fix:** If MethodCall not in `ListAuditMethods()`, treat as empty filter (or reject).
- **Tests:** Injection payload does not broaden results; legitimate MethodCall still filters.

---

## C-04 — GetGlobalAdminGrants unauthenticated

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616549274 · id `3616549274`
- **Summary:** Returns ORK-admin PII with no Token; JsonServer-exposed.
- **Proposed fix:** Add Token; gate like `PurgeLogs` (`IsAuthorized` + `HasAuthority(AUTH_ADMIN, 0, AUTH_CREATE)`).
- **Tests:** No/non-admin Token denied; admin Token returns grants. Update AdminDashboard/controller callers to pass session token.

---

## C-05 — Scoped/Kingdom/Event auth getters IDOR

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616549283 · id `3616549283`
- **Summary:** `GetScopedAuths` / `GetKingdomParkAuths` / `GetEventInheritedPermissions` ungated on JSON → IDOR.
- **Proposed fix:** Add Token; `HasAuthority(..., AUTH_CREATE)` for the scoped entity (allow global AUTH_ADMIN as needed).
- **Tests:** Cross-tenant denied; holder with AUTH_CREATE succeeds. Update AdminDashboard + Controller_Admin.

---

## C-06 — Server health getters unauthenticated

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616549292 · id `3616549292`
- **Summary:** `GetServerHealthProcesses` / `GetServerHealthDbStatus` expose process list / DB status without auth.
- **Proposed fix:** Token + AUTH_ADMIN like PurgeLogs. Do not blindly gate weather summary without updating Weather callers.
- **Tests:** Non-admin denied; admin returns expected shapes. Update AdminDashboard + controller.

---

## C-07 — AuthorizationGate privilege oracle

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616549297 · id `3616549297`
- **Summary:** `HasAuthority` trusts client `MundaneId` with no Token check.
- **Proposed fix:** Resolve actor from `IsAuthorized(Token)`; ignore client MundaneId (or allow override only for self/admin).
- **Tests:** Stranger Token + admin MundaneId must fail; valid Token reflects actor’s authority.

---

## C-08 — LiveService anti-scrape bypass

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638673 · id `3616638673`
- **Summary:** `GetStats`/`GetRecent` ungated; bypasses Controller_Live session gate.
- **Proposed fix:** Require Token on both methods; thread session token via Model_Live. Keep controller gate.
- **Tests:** No Token → auth failure; valid Token → same payload shape as existing Live tests.

---

## C-09 — GetOccurrencePageData draft + dietary PII

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638683 · id `3616638683`
- **Summary:** SOAP/JSON can read draft event details and dietary PII with only id pairing.
- **Proposed fix:** Token for drafts (manage/staff/creator); ignore `IncludeDietary` unless feast-capable; published public fields may remain anonymous.
- **Tests:** Anonymous+draft denied; anonymous+published+IncludeDietary → empty DietarySummary; feast Token → summary.

---

## C-10 — Ungated calendar detail writes

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638686 · id `3616638686`
- **Summary:** `SetCalendarDetailFeesAndLinks` / `SetCalendarDetailEventType` mutate without Token.
- **Proposed fix:** Match `SetEventStatus`: Token + detailBelongsToEvent + `CanManageEventDetail(..., 'manage')`. Add Token to WSDL request types; pass from controllers.
- **Tests:** No/stranger Token → deny + unchanged DB; manage Token → success (existing tests updated).

---

## C-11 — GetDietarySummaryForOccurrence PII leak

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638693 · id `3616638693`
- **Summary:** Returns full dietary profiles for any detail id with no auth.
- **Proposed fix:** Token + `CanManageEventDetail(..., 'feast')`. Add Token to `OccurrenceDetailRequest`.
- **Tests:** Anonymous/non-feast denied; feast Token returns Items.

---

## C-12 — SetRsvp / WithdrawRsvp trust MundaneId

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638697 · id `3616638697`
- **Summary:** Anyone can set/clear any player’s RSVP via SOAP/JSON.
- **Proposed fix:** Resolve actor from Token; own RSVP OK; other MundaneId only with RemoveRsvp-equivalent authority. Pass Token from model/controllers.
- **Tests:** No Token → BadToken; A cannot mutate B without auth; A can mutate self; staff can mutate other.

---

## C-13 — WeatherService unauthenticated + quota abuse

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638705 · id `3616638705`
- **Summary:** WeatherService bypasses Controller_Weather session gate; can force Open-Meteo fetches.
- **Proposed fix:** Self-gate every public method with Token; update registration + Model_Weather to pass session token.
- **Tests:** Invalid Token fails; valid Token succeeds; logged-in weather page still works.

---

## C-14 — GetArchiveForPark null → TypeError

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638708 · id `3616638708`
- **Summary:** Declared `: array` but delegate returns `null` on misses → fatal TypeError.
- **Proposed fix:** `return $result ?? [];`. Audit siblings (only this method broken).
- **Tests:** Invalid park / bad date / miss path returns `[]`, never TypeError.

---

## C-15 — Event permissions breadcrumbs undefined

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638712 · id `3616638712`
- **Summary:** `$evKingdomId`/`$evParkId` never assigned after refactor → breadcrumbs dropped.
- **Proposed fix:** Return `parkId`/`kingdomId` from `GetEventInheritedPermissions`; assign in controller.
- **Tests:** Assert ids in domain return; optional menu URL assertions.

---

## C-16 — UniversalSearch retired units leak

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616638723 · id `3616638723`
- **Summary:** Unit query lacks `active = 'Active'` (parks already filter).
- **Proposed fix:** Prefix unit WHERE with `active = 'Active' AND …`.
- **Tests:** Retired unit not in UniversalSearch units; active twin is.

---

## C-17 — Attendance reactivation broadening (policy)

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616693590 · id `3616693590`
- **Summary:** `reactivateInactiveMundane` now runs from `AddAttendance` and `UseAttendanceLink`; master only reactivated on AttendanceAjax.
- **Decision:** Keep on `AddAttendance` (intentional centralization for officer-authorized credits). **Remove from `UseAttendanceLink`** (public self-service must not undo officer deactivation).
- **Tests:** Keep AddAttendance reactivation test; change UseAttendanceLink test to assert still inactive; Ajax path still reports reactivated via AddAttendance.

---

## C-18 — Revoked awards alias title misclassification

- **PR reference:** https://github.com/amtgard/ORK3/pull/492#discussion_r3616693598 · id `3616693598`
- **Summary:** `GetRevokedAwardsForPlayer` ignores `alias_award_id`; alias-backed titles land in Awards tab.
- **Proposed fix:** `LEFT JOIN award alias`; classify with COALESCE/GREATEST mirroring active-grant / revoke_award.
- **Tests:** Alias-backed revoked title → RevokedTitles; ordinary ladder revoke → RevokedAwards.

---

## Behavior triage (out of scope for this series)

From top-level review https://github.com/amtgard/ORK3/pull/492#pullrequestreview-4736478341 — **not committed here** unless a follow-up is requested:

| Item | Classification |
|------|----------------|
| GetLadderAwardGrid kingdomaward + master map | Confirm intentional |
| GetVotingEligible VotingRules merge | Accept / document SOAP bare-call change |
| ScopedPlayerSearch KD: ordering | Clear bug — follow-up candidate |
| GetAwardOptionListHtml custom above Ladder | Clear UX regression — follow-up candidate |
| coords_for_calendar_detail both-zero | Align with siblings — follow-up candidate |

---

## Suggested commit order

1. C-01 (runtime blocker)  
2. C-14, C-15, C-16, C-18 (localized correctness)  
3. C-03 (SQLi)  
4. C-02, C-04, C-05, C-06, C-07 (auth surface)  
5. C-08, C-13 (session-bypass services)  
6. C-10, C-12 (writes)  
7. C-09, C-11 (dietary/draft reads)  
8. C-17 (policy)
