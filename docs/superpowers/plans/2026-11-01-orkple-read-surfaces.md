# ORKPLE II: read-only ORK surfaces

Everything below is read-only, uses existing endpoints, and adds no backend code. All work is in
`orkui/template/revised-frontend/orkple/orkple-terminal.js` plus `tests/e2e/orkple*.spec.ts`.

## Rules

1. **Page parity.** A screen shows nothing the website hides from the same visitor. An endpoint working
   anonymously is not permission. If the page needs a login, the terminal leaves it out.
2. **Allow-list.** Every endpoint the terminal calls is named in this plan. Adding one means checking the
   matching page first and adding it here.
3. **Persona only.** Render only named public fields, never whole records. No real names, usernames, notes
   or suspension reasons.
4. **Always scope reports.** Many `Report/*` methods ignore `Type`/`Id` and silently return all data, which
   can mean megabytes and 40+ seconds. Pass `ParkId` or `KingdomId` explicitly, and never call a report
   the way this plan does not list it.
5. **Small payloads.** Anything over about 100 KB is out, except where listed. No page scraping.

## Phase 0: foundations (do first)

| Change | Why |
|---|---|
| `svc()` rejects a bare numeric `Status` above 0 | `Status:2` currently reads as success (`orkple-terminal.js:1180-1182`) |
| `svc()` unwraps `Result` consistently | Three response shapes exist |
| Pager `9` key keeps all program args, not just `id` and `page` | New pagers are keyed on more than an id (`:494`) |
| Generalize arms loading to Park, Unit and Event | `Heraldry/GetHeraldryUrl` supports them; the art path is Kingdom only |
| `htmlText()`: strip tags, then `clean` and `wrap` | Directions, descriptions and bios hold HTML |
| `barChart()` built from `ladderRow` | Attendance charts in the ladder-bar look |
| `allowed` list checked in `svc()`/`getJson()`, throwing in tests | Enforces rule 2 mechanically |

## Phase 1: parks

`PARKS` becomes selectable, and search can reach it.

**PARK PROFILE**: arms left, name, title, city and province, then today's field count, weekly and monthly
averages. Submenu:

| Item | Endpoint |
|---|---|
| 1. MEETING DAYS | `Park/GetParkDays`. The API gives no next date, so show the recurrence ("SATURDAYS 11:00 AM"). |
| 2. WHERE TO FIND US | `Park/GetParkDetails` (address, directions, description, URL) |
| 3. OFFICERS | `Park/GetOfficers`, role and persona only; a persona opens the player profile |
| 4. RECENT PLAYERS | `Report/RecentParkAttendees`; selecting opens the player profile |
| 5. EVENTS | `SearchService/Event&park_id=` |
| 6. COMPANIES | `Report/UnitSummary&request[ParkId]=&request[IncludeCompanies]=1` |
| 7. ATTENDANCE | `Report/GetMonthlyChartData&request[ParkId]=&request[Periods]=6&request[PerMonths]=1&request[PerWeeks]=0` as bars. (`Period` and `PeriodCount` are ignored and return all history.) |
| 8. SIGN-INS BY DAY | `Report/GetAttendanceDates&request[Type]=Park&request[Id]=` (the key is `Id`, not `TypeId`), kept to the past six months as the public attendance report is, then `Report/AttendanceForDate&request[ParkId]=&request[Date]=` for a date in that list. Never display its `Status.Detail` (it holds SQL). |

Header data: `Park/GetParkShortInfo`, `Park/GetParkDetails` (title, city, province), `Report/GetParkFieldCountToday`, `Kingdom/park_averages_json/{kid}`.

The kingdom PARKS list comes from `Report/GetKingdomParkAverages`, which is active parks only, exactly the website's kingdom Parks tab (`Kingdom/GetParks` includes retired parks and is not used). Retired parks are therefore left out, and each row is selectable and opens its park by `ParkId`. The player search now lists parks first (the `parks` array of `SearchAjax/universal`), tagged `PARK`.

## Allow-list

Every endpoint the terminal may call, and nothing else. `tests/e2e/orkple-parks.spec.ts` reads this table and fails if it
differs from the `ALLOWED` table in `orkple-terminal.js`; under test (`window.OrkpleStrict = true`) an unlisted call
throws. Later phases add their rows here first.

| Endpoint | Used for |
|---|---|
| `Kingdom/GetKingdoms` | kingdom list |
| `Kingdom/GetKingdomShortInfo` | kingdom profile |
| `Kingdom/GetKingdomDetails` | kingdom profile: principality roll-up flag |
| `Kingdom/GetOfficers` | kingdom profile |
| `Kingdom/GetPrincipalities` | kingdom profile |
| `Report/GetKingdomParkAverages` | kingdom PARKS (active only) |
| `KingdomAjax/calendar` | KINGDOM CALENDAR (one month: `start`, `end`) |
| `Kingdom/park_averages_json` | kingdom and park averages |
| `Heraldry/GetHeraldryUrl` | arms: Kingdom, Park |
| `SearchAjax/universal` | player, park, kingdom, company and household search (SEARCH THE ORK and player search) |
| `Player/GetPlayer` | player profile |
| `Player/AttendanceForPlayer` | player profile and ATTENDANCE |
| `Player/ComputeClassProgress` | player CLASSES |
| `Player/AwardsForPlayer` | player AWARDS and TITLES |
| `Player/GetLadderProgress` | player LADDERS |
| `Event/GetUpcomingRsvps` | MY AMTGARD NEXT UP (own profile); hidden when the service refuses it (amtgard/ORK3 #552 denies anonymous calls) |
| `Park/GetParkShortInfo` | park header, player home park |
| `Park/GetParkDetails` | park header, WHERE TO FIND US |
| `Park/GetParkDays` | park MEETING DAYS; header AT FIELD NOW, only on a park day |
| `Park/GetOfficers` | park OFFICERS |
| `Report/GetParkFieldCountToday` | park header |
| `Report/RecentParkAttendees` | park RECENT PLAYERS |
| `SearchService/Event` | park EVENTS (`park_id`); event name, host, arms flag and the publish check (`event_id`: drafts are left out, so an empty answer stops the event screen) |
| `SearchService/CalendarDetail` | EVENT DETAIL (`event_calendardetail_id`) |
| `Event/GetRsvpCounts` | EVENT RSVP COUNTS, numbers only (`EventCalendarDetailId`) |
| `EventEmbed/schedule` | EVENT SCHEDULE (`{eid}/{did}`) |
| `Report/AttendanceForEvent` | EVENT WHO CAME (`EventId` and `EventCalendarDetailId`, both) |
| `Report/EventAttendanceReport` | PAST EVENTS on park and kingdom (`ParkId` or `KingdomId`) |
| `Report/UnitSummary` | park COMPANIES (ParkId, IncludeCompanies); kingdom COMPANIES AND HOUSEHOLDS (KingdomId, IncludeCompanies, IncludeHouseHolds, TopBySize, Limit=100) |
| `Unit/GetUnit` | UNIT PROFILE (`UnitId`): type, description, history, web address, heraldry flag, retired state |
| `Search/unitactivity` | kingdom unit list: members signed in over 12 months, `ids` 25 a call (the website's INACTIVE rule) |
| `Search/unitsearch` | UNIT PROFILE leaders for a unit opened from search (`q` its exact name, matched on `UnitId`) |
| `Report/GetMonthlyChartData` | park ATTENDANCE (ParkId, Periods, PerMonths) |
| `Report/GetAttendanceDates` | park SIGN-INS BY DAY (Type Park, Id) |
| `Report/AttendanceForDate` | park SIGN-INS BY DAY (ParkId, a listed Date) |
| `Report/GetActiveKingdomsSummary` | THE ORK TODAY: KINGDOM STANDINGS (no parameters) |
| `Report/GetTopParksByAttendance` | THE ORK TODAY: TOP PARKS (`Limit` 25, `StartDate`, `EndDate`; without dates it returns all history) |
| `Recap/json` | THE ORK TODAY: THIS WEEK, all of Amtgard (UI route) |
| `Recap/json_kingdom` | THE ORK TODAY: THIS WEEK, one kingdom (`/{id}`, UI route) |
| `Live/stats` | THE ORK TODAY: ON THE FIELD NOW (UI route) |
| `Live/recent` | THE ORK TODAY: LATEST SIGN-INS (UI route) |
| `WeatherService/GetPlayForDate` | THE ORK TODAY: WEATHER FOR PLAY (`date`, empty `Token`, plain parameters) |
| `EraPhoenice/today` | main menu date line (UI route) |
| `Report/PlayerAwards` | kingdom KNIGHTS AND MASTERS (`KingdomId`, `ParkId` 0, `IncludeKnights`, `IncludeMasters`) |
| `Player/GetPlayerMilestones` | player MILESTONES (`MundaneId`) |

Phases 2 to 4 are built and their rows are above.

## Phase 2: events (built)

Verified parameters (earlier plan guesses were wrong in places): `KingdomAjax/calendar/{id}&start=&end=` (end exclusive; the
feed also carries `park-day` rows, which the page hides by default); `SearchService/CalendarDetail&event_calendardetail_id=`
(no event name, so the name comes from `SearchService/Event&event_id=`, which also works for past events); `Event/GetRsvpCounts&request[EventCalendarDetailId]=`;
`Report/AttendanceForEvent&request[EventId]=&request[EventCalendarDetailId]=` (answers status 4 when nobody signed in, read as empty);
`Report/EventAttendanceReport&request[KingdomId|ParkId]=` (also lists future and undated occurrences, which are filtered out).
Admission fees have no public read endpoint, so only a non-zero `Price` shows.

| Screen | Endpoint |
|---|---|
| KINGDOM CALENDAR: month view as a dated list, `9` next month, all row types | `KingdomAjax/calendar/{id}&start&end` (already in use) |
| EVENT DETAIL: name, dates, place, price, wrapped description, arms | `SearchService/CalendarDetail&event_calendardetail_id=` |
| RSVP COUNTS, numbers only | `Event/GetRsvpCounts` |
| SCHEDULE: day pages | `EventEmbed/schedule/{eid}/{did}` |
| WHO CAME: published page attendance | `Report/AttendanceForEvent` with both ids (omitting them is a 500) |
| PAST EVENTS on park and kingdom | `Report/EventAttendanceReport` with `ParkId` or `KingdomId` |

Never `Event/GetRsvpList`; attendee names are officers only.

## Phase 3: companies and global search (built)

Verified parameters: `Report/UnitSummary&request[KingdomId]=&request[IncludeCompanies]=1&request[IncludeHouseHolds]=1&request[TopBySize]=1&request[Limit]=100`
returns the website's default unit list for a kingdom (the 100 largest by roster size, retired units out, about 27 KB) and is
the only form that keeps the payload small: without `TopBySize` a large kingdom's companies and households run to 160 KB and
3.5 s. That path leaves `ActiveMemberCount` empty, so the page fills it from `Search/unitactivity&ids=` (25 ids at most; the
page's own 75-id batches lose all but the first 25) and flags a unit inactive when the count is 0. `Unit/GetUnit&request[UnitId]=`
carries no member list or leaders; leaders come from the list row, or for a unit opened from search from `Search/unitsearch&q=`
(exact name, matched by `UnitId`). `SearchAjax/universal` returns `units` (id, name, `unitType`) and `kingdoms`, including retired
kingdoms, which the terminal leaves out unless the KINGDOMS list carries them.

| Screen | Endpoint |
|---|---|
| Kingdom COMPANIES AND HOUSEHOLDS | `Report/UnitSummary&request[KingdomId]=&request[IncludeCompanies]=1` |
| UNIT PROFILE: arms, type, leaders, wrapped description and history | `Unit/GetUnit`, list row for leaders |
| SEARCH THE ORK (main menu): results grouped PLAYERS, PARKS, KINGDOMS, COMPANIES | `SearchAjax/universal`, which already returns all four |

Unit member lists are left out for now: the available call returns private fields, and rule 3 would need
a verified field filter. Revisit once the endpoint is trimmed.

## Phase 4: THE ORK TODAY and profile depth (built)

Verified parameters and page parity (checked against the anonymous website; earlier plan guesses were wrong in places):

- `Report/GetActiveKingdomsSummary` takes none. Standings show kingdoms only (principalities have a `ParentKingdomId`), ranked by
  `Attendance` divided by the weeks in the six months to today, the home page's own figure; parks and the figure match its cards.
- `Report/GetTopParksByAttendance` returns all history unless `StartDate` and `EndDate` are passed. The Top Parks page (public through
  `Admin/topparks`) uses `Limit` 25 and the six months to today; so does the terminal, and the figures match the page's table.
- Recap is read through the UI routes `Recap/json` and `Recap/json_kingdom/{id}` (the Recap page is public and linked from the home page).
  A week without a recap answers `Status: not_computed`. New players are cut to the first 30 (the page lists them all).
- `Live/stats` and `Live/recent` are UI routes, public with no player ids. The local data has no rows, so the screens were walked with rows
  mocked from `class.Live.php` (parks and events are objects keyed by id, or `[]` when empty; recent sign-ins are tuples
  `[iso UTC, park, event, calendar detail, first ever, self]`).
- Weather uses `WeatherService/GetPlayForDate` with plain `date` and an empty `Token` (the plan's `GetDailySummary` carries no park rows).
  The screen is the Weather page's default view: parks playing today that carry a warning, worst first.
- `EraPhoenice/today` is a UI route (the home page shows the same date). The menu's date row is filled in the background and never waited for.
- Kingdom KNIGHTS AND MASTERS: `Report/PlayerAwards` with `KingdomId`, `ParkId` 0, `IncludeKnights`, `IncludeMasters`, the Reports tab's list
  (public). The payload is up to 122 KB for the largest kingdom, the one listed exception to rule 5. Only persona, award, date and park are kept;
  who entered the award is not.
- Player ABOUT (`AboutPersona`, `AboutStory`), COMPANIES (`Company[]`, companies only) and the milestone switches come from the existing
  `Player/GetPlayer` read; MILESTONES is `Player/GetPlayerMilestones`, filtered by the player's own switches as the page does. A suspended or
  restricted player's page shows these as for anyone, so the terminal adds nothing.

Not built, because an anonymous visitor does not see them on the website: AWARDS OF THE REALM (`Kingdom/GetAwardList`) and PARK TITLES
(`Kingdom/GetKingdomParkTitles`). The award list reaches an anonymous page only as the options of the hidden award-entry form, and the title
rules appear only on the kingdom admin tab. A park's own title is already shown on PARKS.

| Screen | Endpoint |
|---|---|
| KINGDOM STANDINGS | `Report/GetActiveKingdomsSummary` |
| TOP PARKS | `Report/GetTopParksByAttendance` |
| THIS WEEK: events, parks, knights, masters, paragons, new players; all of Amtgard or one kingdom | `Recap/json`, `Recap/json_kingdom/{id}` |
| ON THE FIELD NOW, LATEST SIGN-INS | `Live/stats`, `Live/recent` |
| WEATHER FOR PLAY | `WeatherService/GetPlayForDate` |
| Era Phoenice date line on the menu | `EraPhoenice/today` |
| Kingdom KNIGHTS AND MASTERS | `Report/PlayerAwards` |
| Player ABOUT, COMPANIES, MILESTONES | `Player/GetPlayer` (existing), `Player/GetPlayerMilestones` |

Stretch (a 1-bit kingdom map from `Map/GetParkLocations`) was skipped.

## Left out on purpose

| Surface | Reason |
|---|---|
| Rosters, dues, waivers, voting eligibility, recommendations, notes, dietary | Private or gated, per rule 1 |
| Class masters, ladder grid, closest parks, reeve and corpora lists, guild reports, beltline | Page requires login |
| Suspensions | Public on the site, but not kind as an easter egg |
| Tournaments | Module hidden site-wide |
| What's New, docs | No read endpoint |

## Menu

The first four numbers stay put, so nothing learned or tested moves:
`1 KINGDOMS 2 PLAYERS 3 MY PROFILE 4 ORK GAMES 5 SEARCH THE ORK 6 THE ORK TODAY`.
Parks are reached through a kingdom or through search.

## Verification per phase

- Playwright spec walking each new screen on KCG (kingdom 17) and park 1049, read through the role=log text.
- Every string fits 39 columns and every screen 24 rows, across a sample of long real names.
- Each program has an empty state, a `?I/O ERROR` state and a working `0` back path.
- Parity check: for each new screen, compare fields to the page as an anonymous visitor.
- Parity with the live page for counts, as was done for kingdom events.
- Allow-list test passes: no endpoint outside this plan is called.

## Order

Phase 0 and 1 ship together, as parks were the request. Phases 2 to 4 each stand alone. Each phase is one
Sonnet builder working in `orkple-terminal.js`, so they run in sequence, not parallel. Consider splitting
programs into a lazily loaded file first, since the terminal is already over 70 KB.
