# PR #492 Rev4 — Json/SOAP auth inventory (C-31)

Analysis-only response to `@baltinerdist` Batch 5 ask: enumerate allowlisted / registered surfaces and require an actor before first read/write. This document inventories; it does **not** mass-gate historical master surfaces.

**Branch tip at inventory:** `fix-pr-492` (post C-19…C-30).  
**Json allowlist source:** [`orkservice/Json/index.php`](../../../orkservice/Json/index.php).

## Json allowlist classes

`Administration`, `Attendance`, `Authorization`, `AuthorizationGate`, `Banner`, `Award`, `Calendar`, `DataSet`, `EraPhoeniceService`, `Event`, `Game`, `Heraldry`, `Kingdom`, `LiveService`, `Map`, `Park`, `Player`, `Principality`, `Report`, `SearchService`, `Tournament`, `Treasury`, `Unit`, `WeatherService`.

**Not on Json allowlist (SOAP/internal):** `EventPlanning`, `EventEmbed`, `DangerAudit` (among others). Callers reach planning via orkui models / SOAP registration, not `call=EventPlanning/...`.

## Rev2 + Rev4 gates completed (branch-introduced holes closed)

| Item | Class::Method | Gate |
|------|---------------|------|
| C-02 | `Banner::CopyBanner` | Token + canEditBanner |
| C-03 | `DangerAudit::ListAuditLog` | MethodCall whitelist (not Json-exposed) |
| C-04 | `Administration::GetGlobalAdminGrants` | Token + AUTH_ADMIN |
| C-05 | `Administration::GetScopedAuths` / `GetKingdomParkAuths` / `GetEventInheritedPermissions` | Token + scoped AUTH_CREATE |
| C-06 | `Administration::GetServerHealthDbStatus` / `GetServerHealthProcesses` | Token + AUTH_ADMIN |
| C-07 | `AuthorizationGate::HasAuthority` | Actor from Token only |
| C-08 | `LiveService::GetStats` / `GetRecent` | Token |
| C-09 | `EventPlanning::GetOccurrencePageData` | Token for drafts; dietary gated |
| C-10 | `EventPlanning::SetCalendarDetailFeesAndLinks` / `SetCalendarDetailEventType` | Token + manage |
| C-11 | `EventPlanning::GetDietarySummaryForOccurrence` | Token + feast |
| C-12 | `Event::SetRsvp` / `WithdrawRsvp` | Token + actor |
| C-13 | `WeatherService` public methods | Token |
| C-19 | `Event::RemoveRsvp` | Token + actor (removed client trust flag) |
| C-20 | `Event::GetRsvpList` | Token + manage/attendance |
| C-21 | `Player::GetDisplayGrants` / `GetOfficerRoles` / `GetBeltlineForPlayer` / `GetRevokedAwardsForPlayer` | Token + profile viewer gate |
| C-22 | `Report::GetAdminDashboardStats` / `GetVotingEligibleForPlayer` / `GetAttendanceDates` / `GetKingdomOfficerDirectoryMerged` / `GetLadderAwardGrid` | Token + admin/scoped |
| C-23 | `Administration::GetServerHealthWeatherSummary` | Token + AUTH_ADMIN |
| C-24 | `Administration::GetServerHealthDbStatus` | Wanted[] whitelist |

## Branch-introduced RSVP helpers still ungated (follow-up candidates)

These moved onto `Event` with the refactor and remain reachable without Token. They are **lower sensitivity** than `GetRsvpList` (counts / own status / public upcoming lists), but they are still new Json-reachable surfaces:

| Method | Notes |
|--------|-------|
| `Event::GetRsvpStatus` | Single player's status for a detail |
| `Event::GetRsvpCounts` / `GetRsvpCountsBatch` / `GetRsvpSummaryBatch` | Aggregate counts (no persona roster) |
| `Event::GetUserRsvpStatusesBatch` | Statuses for a MundaneId across details |
| `Event::GetUpcomingRsvps` | Upcoming RSVPs for a MundaneId |
| `Event::GetKingdomUpcomingEventsWithoutRsvp` | Upcoming events missing RSVP |

**Recommendation:** Gate `GetUpcomingRsvps` / `GetUserRsvpStatusesBatch` to Token actor === MundaneId (or admin). Counts endpoints can stay public if product wants anonymous widgets; document that choice.

## Heuristic leftovers (mostly pre-existing / out of Rev4)

A crude scan of public methods on focus classes still finds many without an early `IsAuthorized`/`Token` check — especially large `Report::` and `Player::` surfaces that existed on master before this PR. Batch 5 explicitly scoped out pre-existing examples (e.g. `Report::GetVotingEligible`).

**Rev4 does not attempt** to Token-gate that historical surface. A mechanical follow-up should:

1. Enumerate every `public function` on each allowlisted class + every `Class.Method` in `orkservice/**/*.registration.php`.
2. Diff against `master` to classify **branch-introduced** vs **pre-existing**.
3. For branch-introduced only: require Token/actor before first DB read/write, with characterization tests.
4. Optionally add a fuzzy-validator / CI check that fails CI when a new allowlisted public method ships without a Token parameter or `_authorize*` call in the first N lines.

## SOAP registration note

Event RSVP list registration now uses `GetRsvpListRequest` (Token + EventCalendarDetailId) after C-20. Other Event RSVP count registrations still use request types without Token — consistent with keeping aggregate counts public until product decides otherwise.

## Conclusion for Batch 5

The Batch 5 **blocker** and the named ungated siblings (`GetRsvpList`, Player getters, Report getters, weather summary) are closed in C-19…C-23. Remaining work is an intentional second pass on RSVP helper reads and a CI inventory gate — tracked here, not blocking the named twelve.
