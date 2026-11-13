# ORKPLE II: read-only ORK surfaces

The terminal (`orkui/template/revised-frontend/orkple/orkple-terminal.js`) only reads, and only through the
endpoints listed below.

## Rules

1. **Page parity.** A screen shows nothing the website hides from the same visitor. If the page needs a
   login, the terminal leaves it out.
2. **Allow-list.** Every endpoint the terminal calls is named in this plan and in the `ALLOWED` table in
   `orkple-terminal.js`. A call to anything else is refused. Each `ALLOWED` entry also names the fields the
   terminal keeps from the answer; everything else is dropped before a screen sees it. Adding an endpoint
   means checking the matching page first and adding it here.

## Allow-list

`tests/e2e/orkple-parks.spec.ts` reads this table and fails if it differs from `ALLOWED`.

| Endpoint | Used for |
|---|---|
| `Kingdom/GetKingdoms` | kingdom list |
| `Kingdom/GetKingdomShortInfo` | kingdom profile |
| `Kingdom/GetKingdomDetails` | kingdom profile: principality roll-up flag |
| `Kingdom/GetOfficers` | kingdom profile |
| `Kingdom/GetPrincipalities` | kingdom profile |
| `Report/GetKingdomParkAverages` | kingdom PARKS (active only) |
| `KingdomAjax/calendar` | KINGDOM CALENDAR (one month) |
| `Kingdom/park_averages_json` | kingdom and park averages |
| `Heraldry/GetHeraldryUrl` | arms: Kingdom, Park, Unit, Event |
| `SearchAjax/universal` | player, park, kingdom, company and household search |
| `Player/GetPlayer` | player profile |
| `Player/AttendanceForPlayer` | player profile and ATTENDANCE |
| `Player/ComputeClassProgress` | player CLASSES |
| `Player/AwardsForPlayer` | player AWARDS and TITLES |
| `Player/GetLadderProgress` | player LADDERS |
| `EventRsvpAjax/mine` | MY AMTGARD NEXT UP: the signed-in player's own RSVPs (session only, no player id); left out when logged out |
| `Park/GetParkShortInfo` | park header, player home park |
| `Park/GetParkDetails` | park header, WHERE TO FIND US |
| `Park/GetParkDays` | park MEETING DAYS |
| `Park/GetOfficers` | park OFFICERS |
| `Report/GetParkFieldCountToday` | park header, only on a park day |
| `Report/RecentParkAttendees` | park RECENT PLAYERS |
| `SearchService/Event` | park EVENTS; event name, host, arms flag and publish check |
| `SearchService/CalendarDetail` | EVENT DETAIL |
| `Event/GetRsvpCounts` | EVENT RSVP COUNTS, numbers only |
| `EventEmbed/schedule` | EVENT SCHEDULE |
| `Report/AttendanceForEvent` | EVENT WHO CAME |
| `Report/EventAttendanceReport` | PAST EVENTS on park and kingdom |
| `Report/UnitSummary` | park COMPANIES; kingdom COMPANIES AND HOUSEHOLDS |
| `Unit/GetUnit` | UNIT PROFILE |
| `Search/unitactivity` | kingdom unit list: the website's INACTIVE rule |
| `Search/unitsearch` | UNIT PROFILE leaders for a unit opened from search |
| `Report/GetMonthlyChartData` | park ATTENDANCE |
| `Report/GetAttendanceDates` | park SIGN-INS BY DAY |
| `Report/AttendanceForDate` | park SIGN-INS BY DAY |
| `Report/GetActiveKingdomsSummary` | THE ORK TODAY: KINGDOM STANDINGS |
| `Report/GetTopParksByAttendance` | THE ORK TODAY: TOP PARKS |
| `Recap/json` | THE ORK TODAY: THIS WEEK, all of Amtgard |
| `Recap/json_kingdom` | THE ORK TODAY: THIS WEEK, one kingdom |
| `Live/stats` | THE ORK TODAY: ON THE FIELD NOW |
| `Live/recent` | THE ORK TODAY: LATEST SIGN-INS |
| `WeatherService/GetPlayForDate` | THE ORK TODAY: WEATHER FOR PLAY |
| `EraPhoenice/today` | main menu date line |
| `Report/PlayerAwards` | kingdom KNIGHTS AND MASTERS |
| `Player/GetPlayerMilestones` | player MILESTONES |

## Changing the list

Add the row here and to `ALLOWED` (with its field list) in the same change, after checking the matching page
as the same visitor would see it.
