# HTTP reachability — fuzzy-validator pages

- **Profile:** `test`
- **Base URL:** `http://127.0.0.1:19080/orkui/`
- **Started:** 2026-07-23T21:30:52Z
- **Finished:** 2026-07-23T21:31:03Z
- **Pages checked:** 278

## Counts

| Category | Count |
|----------|------:|
| ok | 190 |
| redirect | 88 |

## Failing routes

(none)

## Redirects (final URL differs)

| id | template | status | final |
|----|----------|-------:|-------|
| `player-profile` | `Player/profile` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `kingdom-profile` | `Kingdom/profile/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `event-list` | `Event` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `event-detail` | `Event/detail/{id}/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `event-index-rsvp` | `Event/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Reports/event_attendance/Kingdom/100001&filter=Spring%20War` |
| `event-index-rsvp-gok` | `Event/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Reports/event_attendance/Kingdom/100001&filter=Gathering%20of%20Kingdoms` |
| `event-create` | `Event/create/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Login` |
| `admin-dashboard` | `Admin` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-permissions` | `Admin/permissions` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `sign-in-invalid` | `SignIn/index/abc` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Login/login` |
| `reports-voting-eligible` | `Reports/voting_eligible` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-attendance` | `Reports/attendance` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-auditlog` | `Admin/auditlog` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-editpark` | `Admin/editpark` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-event` | `Admin/event` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-inactivekingdoms` | `Admin/inactivekingdoms` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-inactiveparks` | `Admin/inactiveparks` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-kingdom` | `Admin/kingdom` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-park` | `Admin/park` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-permissions-2` | `Admin/permissions/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-player` | `Admin/player` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-resetwaivers` | `Admin/resetwaivers` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-resetwaivers-2` | `Admin/resetwaivers/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-serverhealth` | `Admin/serverhealth` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `admin-tournament` | `Admin/tournament` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Tournament` |
| `admin-unit` | `Admin/unit` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `attendance-event` | `Attendance/event/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `attendance-park` | `Attendance/park` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `authorization-add-auth` | `Authorization/add_auth/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Admin/authorization` |
| `authorization-del-auth` | `Authorization/del_auth/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Admin/authorization` |
| `event-create-2` | `Event/create` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Login` |
| `event-detail-3` | `Event/detail/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `event-list-2` | `Event/list` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `kingdom-index-2` | `Kingdom/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `kingdom-profile-2` | `Kingdom/profile` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `kingdom-recommendations-panel` | `Kingdom/recommendations_panel` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `login-logout` | `Login/logout` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `login-logout-2` | `Login/logout/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `park-index-2` | `Park/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `park-profile-2` | `Park/profile` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `player-index` | `Player` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `player-index-2` | `Player/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Player/profile/1` |
| `player-reconcile` | `Player/reconcile` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `player-reconcile-2` | `Player/reconcile/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Player/profile/1` |
| `recap-kingdom` | `Recap/kingdom` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Recap` |
| `reports-active` | `Reports/active` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-active-2` | `Reports/active/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-active-duespaid` | `Reports/active_duespaid` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-active-duespaid-2` | `Reports/active_duespaid/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-active-waivered-duespaid` | `Reports/active_waivered_duespaid` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-active-waivered-duespaid-2` | `Reports/active_waivered_duespaid/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-attendance-2` | `Reports/attendance` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-dues` | `Reports/dues` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-dues-2` | `Reports/dues/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-duespaid` | `Reports/duespaid` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-duespaid-2` | `Reports/duespaid/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-event-attendance` | `Reports/event_attendance` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-event-attendance-kingdom` | `Reports/event_attendance/Kingdom` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-event-attendance-2` | `Reports/event_attendance/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-eventheraldry` | `Reports/eventheraldry` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-guilds` | `Reports/guilds` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-guilds-2` | `Reports/guilds/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-inactive` | `Reports/inactive` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-inactive-2` | `Reports/inactive/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-index` | `Reports` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-index-2` | `Reports/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-ladder-grid-2` | `Reports/ladder_grid` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-ladder-grid-3` | `Reports/ladder_grid/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-masters` | `Reports/masters` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-masters-2` | `Reports/masters/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-player-status-reconciliation` | `Reports/player_status_reconciliation` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-player-status-reconciliation-2` | `Reports/player_status_reconciliation/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-playerheraldry` | `Reports/playerheraldry` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-release-utilization` | `Reports/release_utilization` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-release-utilization-2` | `Reports/release_utilization/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-roster` | `Reports/roster` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-roster-2` | `Reports/roster/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-unwaivered` | `Reports/unwaivered` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-unwaivered-2` | `Reports/unwaivered/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-voting-eligible-2` | `Reports/voting_eligible` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-voting-eligible-3` | `Reports/voting_eligible/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-waivered` | `Reports/waivered` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `reports-waivered-2` | `Reports/waivered/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `signin-index` | `SignIn` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Login/login` |
| `signin-index-2` | `SignIn/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Login/login` |
| `tournament-worksheet` | `Tournament/worksheet` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=` |
| `unit-index` | `Unit` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Unit/unitlist` |
| `unit-index-2` | `Unit/index/{id}` | 200 | `http://127.0.0.1:19080/orkui/index.php?Route=Unit/unitlist` |

## All results

| id | auth | category | status | ms | template |
|----|------|----------|-------:|---:|----------|
| `home-authenticated` | login | ok | 200 | 36 | `(home)` |
| `player-profile` | login | redirect | 200 | 100 | `Player/profile` |
| `player-profile-sandbox` | login | ok | 200 | 54 | `Player/profile/{id}` |
| `kingdom-profile` | login | redirect | 200 | 78 | `Kingdom/profile/{id}` |
| `kingdom-auth-sandbox` | login | ok | 200 | 76 | `Kingdom/profile/{id}` |
| `park-auth-sandbox` | login | ok | 200 | 121 | `Park/profile/{id}` |
| `event-list` | login | redirect | 200 | 125 | `Event` |
| `event-detail` | login | redirect | 200 | 167 | `Event/detail/{id}/{id}` |
| `event-index-rsvp` | login | redirect | 200 | 140 | `Event/index/{id}` |
| `event-index-rsvp-gok` | login | redirect | 200 | 93 | `Event/index/{id}` |
| `event-template` | login | ok | 200 | 83 | `Event/template/{id}` |
| `event-create` | login | redirect | 200 | 139 | `Event/create/{id}` |
| `admin-dashboard` | login | redirect | 200 | 107 | `Admin` |
| `admin-state-of-amtgard` | login | ok | 200 | 39 | `Admin/stateofamtgard` |
| `admin-permissions` | login | redirect | 200 | 115 | `Admin/permissions` |
| `search` | none | ok | 200 | 38 | `Search` |
| `search-unitsearch` | login | ok | 200 | 38 | `Search/unitsearch` |
| `attendance` | none | ok | 200 | 43 | `Attendance` |
| `sign-in-invalid` | none | redirect | 200 | 64 | `SignIn/index/abc` |
| `reports-officer-directory` | none | ok | 200 | 30 | `Reports/kingdom_officer_directory` |
| `reports-voting-eligible` | login | redirect | 200 | 56 | `Reports/voting_eligible` |
| `reports-ladder-grid` | login | ok | 200 | 31 | `Reports/ladder_grid` |
| `reports-attendance` | login | redirect | 200 | 65 | `Reports/attendance` |
| `live-stats` | login | ok | 200 | 35 | `Live/stats` |
| `weather` | login | ok | 200 | 36 | `Weather` |
| `tournament` | login | ok | 200 | 46 | `Tournament` |
| `admin-auditlog` | login | redirect | 200 | 82 | `Admin/auditlog` |
| `admin-authorization` | login | ok | 200 | 29 | `Admin/authorization` |
| `admin-banplayer` | login | ok | 200 | 34 | `Admin/banplayer` |
| `admin-claimplayer` | login | ok | 200 | 24 | `Admin/claimplayer` |
| `admin-claimplayer-2` | login | ok | 200 | 29 | `Admin/claimplayer/{id}` |
| `admin-createevent` | login | ok | 200 | 31 | `Admin/createevent` |
| `admin-createkingdom` | login | ok | 200 | 32 | `Admin/createkingdom` |
| `admin-createpark` | login | ok | 200 | 42 | `Admin/createpark` |
| `admin-createpark-2` | login | ok | 200 | 27 | `Admin/createpark/{id}` |
| `admin-createplayer` | login | ok | 200 | 31 | `Admin/createplayer` |
| `admin-createplayer-2` | login | ok | 200 | 26 | `Admin/createplayer/{id}` |
| `admin-editkingdom` | login | ok | 200 | 25 | `Admin/editkingdom/{id}` |
| `admin-editpark` | login | redirect | 200 | 71 | `Admin/editpark` |
| `admin-editpark-2` | login | ok | 200 | 40 | `Admin/editpark/{id}` |
| `admin-editparks` | login | ok | 200 | 31 | `Admin/editparks/{id}` |
| `admin-event` | login | redirect | 200 | 42 | `Admin/event` |
| `admin-event-2` | login | ok | 200 | 24 | `Admin/event/{id}` |
| `admin-inactivekingdoms` | login | redirect | 200 | 95 | `Admin/inactivekingdoms` |
| `admin-inactiveparks` | login | redirect | 200 | 80 | `Admin/inactiveparks` |
| `admin-kingdom` | login | redirect | 200 | 52 | `Admin/kingdom` |
| `admin-kingdom-2` | login | ok | 200 | 26 | `Admin/kingdom/{id}` |
| `admin-manageevent` | login | ok | 200 | 21 | `Admin/manageevent` |
| `admin-mergepark` | login | ok | 200 | 27 | `Admin/mergepark` |
| `admin-mergeplayer` | login | ok | 200 | 48 | `Admin/mergeplayer` |
| `admin-mergeplayer-2` | login | ok | 200 | 22 | `Admin/mergeplayer/{id}` |
| `admin-mergeunit` | login | ok | 200 | 26 | `Admin/mergeunit` |
| `admin-moveplayer` | login | ok | 200 | 27 | `Admin/moveplayer` |
| `admin-new-player-attendance` | login | ok | 200 | 27 | `Admin/new_player_attendance` |
| `admin-park` | login | redirect | 200 | 60 | `Admin/park` |
| `admin-park-2` | login | ok | 200 | 27 | `Admin/park/{id}` |
| `admin-permissions-2` | login | redirect | 200 | 60 | `Admin/permissions/{id}` |
| `admin-player` | login | redirect | 200 | 42 | `Admin/player` |
| `admin-player-2` | login | ok | 200 | 31 | `Admin/player/{id}` |
| `admin-player-bak` | login | ok | 200 | 22 | `Admin/player_bak/{id}` |
| `admin-resetwaivers` | login | redirect | 200 | 45 | `Admin/resetwaivers` |
| `admin-resetwaivers-2` | login | redirect | 200 | 54 | `Admin/resetwaivers/{id}` |
| `admin-serverhealth` | login | redirect | 200 | 68 | `Admin/serverhealth` |
| `admin-serverhealth-weather-stats` | login | ok | 200 | 34 | `Admin/serverhealth_weather_stats` |
| `admin-setkingdomofficers` | login | ok | 200 | 26 | `Admin/setkingdomofficers` |
| `admin-setparkofficers` | login | ok | 200 | 21 | `Admin/setparkofficers` |
| `admin-suspendplayer` | login | ok | 200 | 20 | `Admin/suspendplayer` |
| `admin-topparks` | login | ok | 200 | 24 | `Admin/topparks` |
| `admin-topparks-2` | login | ok | 200 | 27 | `Admin/topparks/{id}` |
| `admin-tournament` | login | redirect | 200 | 56 | `Admin/tournament` |
| `admin-transferpark` | login | ok | 200 | 25 | `Admin/transferpark` |
| `admin-transferpark-2` | login | ok | 200 | 20 | `Admin/transferpark/{id}` |
| `admin-unit` | login | redirect | 200 | 49 | `Admin/unit` |
| `admin-unit-2` | login | ok | 200 | 19 | `Admin/unit/{id}` |
| `admin-vacatekingdomofficer` | login | ok | 200 | 20 | `Admin/vacatekingdomofficer` |
| `admin-vacateparkofficer` | login | ok | 200 | 22 | `Admin/vacateparkofficer` |
| `atlas-index` | login | ok | 200 | 24 | `Atlas` |
| `atlas-index-2` | login | ok | 200 | 24 | `Atlas/index/{id}` |
| `atlas-map` | login | ok | 200 | 32 | `Atlas/map` |
| `atlas-map-2` | login | ok | 200 | 25 | `Atlas/map/{id}` |
| `attendance-behold` | none | ok | 200 | 20 | `Attendance/behold/{id}` |
| `attendance-event` | none | redirect | 200 | 73 | `Attendance/event/{id}` |
| `attendance-index` | none | ok | 200 | 20 | `Attendance/index/{id}` |
| `attendance-kingdom` | none | ok | 200 | 23 | `Attendance/kingdom/{id}` |
| `attendance-park` | none | redirect | 200 | 34 | `Attendance/park` |
| `attendance-park-2` | none | ok | 200 | 21 | `Attendance/park/{id}` |
| `authorization-add-auth` | login | redirect | 200 | 33 | `Authorization/add_auth/{id}` |
| `authorization-del-auth` | login | redirect | 200 | 42 | `Authorization/del_auth/{id}` |
| `authorization-index` | login | ok | 200 | 21 | `Authorization` |
| `authorization-index-2` | login | ok | 200 | 24 | `Authorization/index/{id}` |
| `award-index` | login | ok | 200 | 21 | `Award` |
| `award-index-2` | login | ok | 200 | 24 | `Award/index/{id}` |
| `award-kingdom` | login | ok | 200 | 23 | `Award/kingdom/{id}` |
| `award-park` | login | ok | 200 | 18 | `Award/park/{id}` |
| `event-create-2` | login | redirect | 200 | 38 | `Event/create` |
| `event-detail-2` | login | ok | 200 | 27 | `Event/detail` |
| `event-detail-3` | login | redirect | 200 | 54 | `Event/detail/{id}` |
| `event-list-2` | login | redirect | 200 | 63 | `Event/list` |
| `event-template-2` | login | ok | 200 | 76 | `Event/template` |
| `event-view` | login | ok | 200 | 19 | `Event/view` |
| `heraldry-index` | none | ok | 200 | 25 | `Heraldry` |
| `home-index-login` | none | ok | 200 | 20 | `Home/index/login` |
| `kingdom-events-more` | login | ok | 200 | 19 | `Kingdom/events_more` |
| `kingdom-events-more-2` | login | ok | 200 | 23 | `Kingdom/events_more/{id}` |
| `kingdom-ics-2` | login | ok | 200 | 27 | `Kingdom/ics` |
| `kingdom-index` | login | ok | 200 | 20 | `Kingdom` |
| `kingdom-index-2` | login | redirect | 200 | 53 | `Kingdom/index/{id}` |
| `kingdom-map` | login | ok | 200 | 24 | `Kingdom/map` |
| `kingdom-map-2` | login | ok | 200 | 21 | `Kingdom/map/{id}` |
| `kingdom-park-averages-json` | login | ok | 200 | 17 | `Kingdom/park_averages_json` |
| `kingdom-park-averages-json-2` | login | ok | 200 | 17 | `Kingdom/park_averages_json/{id}` |
| `kingdom-park-monthly-json` | login | ok | 200 | 16 | `Kingdom/park_monthly_json` |
| `kingdom-park-monthly-json-2` | login | ok | 200 | 17 | `Kingdom/park_monthly_json/{id}` |
| `kingdom-players-json` | login | ok | 200 | 24 | `Kingdom/players_json` |
| `kingdom-players-json-2` | login | ok | 200 | 18 | `Kingdom/players_json/{id}` |
| `kingdom-profile-2` | login | redirect | 200 | 28 | `Kingdom/profile` |
| `kingdom-recommendations-panel` | login | redirect | 200 | 33 | `Kingdom/recommendations_panel` |
| `kingdom-recommendations-panel-2` | login | ok | 200 | 22 | `Kingdom/recommendations_panel/{id}` |
| `live-index` | login | ok | 200 | 17 | `Live` |
| `live-index-2` | login | ok | 200 | 15 | `Live/index/{id}` |
| `live-recent` | login | ok | 200 | 15 | `Live/recent` |
| `login-forgotpassword` | none | ok | 200 | 17 | `Login/forgotpassword` |
| `login-forgotpassword-2` | none | ok | 200 | 13 | `Login/forgotpassword/{id}` |
| `login-index` | none | ok | 200 | 14 | `Login` |
| `login-index-2` | none | ok | 200 | 17 | `Login/index/{id}` |
| `login-login` | none | ok | 200 | 48 | `Login/login/{return}` |
| `login-logout` | none | redirect | 200 | 63 | `Login/logout` |
| `login-logout-2` | none | redirect | 200 | 37 | `Login/logout/{id}` |
| `login-oauth-callback` | none | ok | 200 | 22 | `Login/oauth_callback` |
| `park-index` | login | ok | 200 | 40 | `Park` |
| `park-index-2` | login | redirect | 200 | 108 | `Park/index/{id}` |
| `park-profile-2` | login | redirect | 200 | 100 | `Park/profile` |
| `player-index` | login | redirect | 200 | 83 | `Player` |
| `player-index-2` | login | redirect | 200 | 166 | `Player/index/{id}` |
| `player-reconcile` | login | redirect | 200 | 111 | `Player/reconcile` |
| `player-reconcile-2` | login | redirect | 200 | 65 | `Player/reconcile/{id}` |
| `playernew-index` | login | ok | 200 | 22 | `Playernew` |
| `principality-index` | login | ok | 200 | 28 | `Principality` |
| `principality-index-2` | login | ok | 200 | 21 | `Principality/index/{id}` |
| `qr-index` | none | ok | 200 | 22 | `QR` |
| `qualtest-export` | login | ok | 200 | 23 | `QualTest/export` |
| `qualtest-export-2` | login | ok | 200 | 22 | `QualTest/export/{id}` |
| `qualtest-index` | login | ok | 200 | 29 | `QualTest` |
| `qualtest-manage` | login | ok | 200 | 27 | `QualTest/manage` |
| `qualtest-manage-2` | login | ok | 200 | 32 | `QualTest/manage/{id}` |
| `qualtest-question` | login | ok | 200 | 25 | `QualTest/question` |
| `qualtest-question-create` | login | ok | 200 | 25 | `QualTest/question/create/{id}/{id}` |
| `qualtest-question-edit` | login | ok | 200 | 29 | `QualTest/question/edit/{id}` |
| `qualtest-question-2` | login | ok | 200 | 40 | `QualTest/question/{id}` |
| `qualtest-questions` | login | ok | 200 | 36 | `QualTest/questions` |
| `qualtest-questions-2` | login | ok | 200 | 26 | `QualTest/questions/{id}` |
| `qualtest-questions-3` | login | ok | 200 | 22 | `QualTest/questions/{id}/{id}` |
| `qualtest-take` | login | ok | 200 | 23 | `QualTest/take` |
| `qualtest-take-2` | login | ok | 200 | 21 | `QualTest/take/{id}` |
| `qualtest-take-3` | login | ok | 200 | 20 | `QualTest/take/{id}/{id}` |
| `recap-index` | login | ok | 200 | 26 | `Recap` |
| `recap-index-2` | login | ok | 200 | 23 | `Recap/index/{id}` |
| `recap-kingdom` | login | redirect | 200 | 43 | `Recap/kingdom` |
| `recap-kingdom-2` | login | ok | 200 | 20 | `Recap/kingdom/{id}` |
| `releasenotes-index` | none | ok | 200 | 20 | `ReleaseNotes` |
| `releasenotes-index-2` | none | ok | 200 | 20 | `ReleaseNotes/index/{id}` |
| `reports-active` | login | redirect | 200 | 46 | `Reports/active` |
| `reports-active-2` | login | redirect | 200 | 46 | `Reports/active/{id}` |
| `reports-active-duespaid` | login | redirect | 200 | 65 | `Reports/active_duespaid` |
| `reports-active-duespaid-2` | login | redirect | 200 | 68 | `Reports/active_duespaid/{id}` |
| `reports-active-waivered-duespaid` | login | redirect | 200 | 55 | `Reports/active_waivered_duespaid` |
| `reports-active-waivered-duespaid-2` | login | redirect | 200 | 63 | `Reports/active_waivered_duespaid/{id}` |
| `reports-attendance-2` | login | redirect | 200 | 42 | `Reports/attendance` |
| `reports-attendance-3` | login | ok | 200 | 27 | `Reports/attendance/{id}` |
| `reports-beltline-explorer` | login | ok | 200 | 22 | `Reports/beltline_explorer` |
| `reports-beltline-explorer-2` | login | ok | 200 | 20 | `Reports/beltline_explorer/{id}` |
| `reports-class-masters` | login | ok | 200 | 22 | `Reports/class_masters` |
| `reports-class-masters-2` | login | ok | 200 | 21 | `Reports/class_masters/{id}` |
| `reports-closest-parks` | login | ok | 200 | 30 | `Reports/closest_parks` |
| `reports-closest-parks-2` | login | ok | 200 | 20 | `Reports/closest_parks/{id}` |
| `reports-corpora` | login | ok | 200 | 21 | `Reports/corpora` |
| `reports-corpora-2` | login | ok | 200 | 20 | `Reports/corpora/{id}` |
| `reports-corpora-test-results` | login | ok | 200 | 20 | `Reports/corpora_test_results` |
| `reports-corpora-test-results-2` | login | ok | 200 | 21 | `Reports/corpora_test_results/{id}` |
| `reports-custom-awards` | login | ok | 200 | 22 | `Reports/custom_awards` |
| `reports-custom-awards-2` | login | ok | 200 | 21 | `Reports/custom_awards/{id}` |
| `reports-dues` | login | redirect | 200 | 46 | `Reports/dues` |
| `reports-dues-2` | login | redirect | 200 | 43 | `Reports/dues/{id}` |
| `reports-duespaid` | login | redirect | 200 | 39 | `Reports/duespaid` |
| `reports-duespaid-2` | login | redirect | 200 | 43 | `Reports/duespaid/{id}` |
| `reports-event-attendance` | login | redirect | 200 | 41 | `Reports/event_attendance` |
| `reports-event-attendance-kingdom` | login | redirect | 200 | 39 | `Reports/event_attendance/Kingdom` |
| `reports-event-attendance-2` | login | redirect | 200 | 38 | `Reports/event_attendance/{id}` |
| `reports-eventheraldry` | login | redirect | 200 | 78 | `Reports/eventheraldry` |
| `reports-eventheraldry-2` | login | ok | 200 | 23 | `Reports/eventheraldry/{id}` |
| `reports-guilds` | login | redirect | 200 | 41 | `Reports/guilds` |
| `reports-guilds-2` | login | redirect | 200 | 42 | `Reports/guilds/{id}` |
| `reports-inactive` | login | redirect | 200 | 50 | `Reports/inactive` |
| `reports-inactive-2` | login | redirect | 200 | 45 | `Reports/inactive/{id}` |
| `reports-index` | login | redirect | 200 | 42 | `Reports` |
| `reports-index-2` | login | redirect | 200 | 45 | `Reports/index/{id}` |
| `reports-kingdom-officer-directory` | none | ok | 200 | 24 | `Reports/kingdom_officer_directory/{id}` |
| `reports-kingdomheraldry` | login | ok | 200 | 25 | `Reports/kingdomheraldry` |
| `reports-kingdomheraldry-2` | login | ok | 200 | 23 | `Reports/kingdomheraldry/{id}` |
| `reports-knights` | login | ok | 200 | 55 | `Reports/knights` |
| `reports-knights-2` | login | ok | 200 | 32 | `Reports/knights/{id}` |
| `reports-knights-and-masters` | login | ok | 200 | 27 | `Reports/knights_and_masters` |
| `reports-knights-and-masters-2` | login | ok | 200 | 22 | `Reports/knights_and_masters/{id}` |
| `reports-knights-list` | login | ok | 200 | 22 | `Reports/knights_list` |
| `reports-knights-list-2` | login | ok | 200 | 21 | `Reports/knights_list/{id}` |
| `reports-ladder-grid-2` | login | redirect | 200 | 44 | `Reports/ladder_grid` |
| `reports-ladder-grid-3` | login | redirect | 200 | 44 | `Reports/ladder_grid/{id}` |
| `reports-masters` | login | redirect | 200 | 44 | `Reports/masters` |
| `reports-masters-2` | login | redirect | 200 | 49 | `Reports/masters/{id}` |
| `reports-masters-list` | login | ok | 200 | 22 | `Reports/masters_list` |
| `reports-masters-list-2` | login | ok | 200 | 20 | `Reports/masters_list/{id}` |
| `reports-new-player-attendance` | login | ok | 200 | 23 | `Reports/new_player_attendance` |
| `reports-new-player-attendance-2` | login | ok | 200 | 22 | `Reports/new_player_attendance/{id}` |
| `reports-orkremental` | login | ok | 200 | 24 | `Reports/orkremental` |
| `reports-orkremental-2` | login | ok | 200 | 25 | `Reports/orkremental/{id}` |
| `reports-park-attendance-explorer` | login | ok | 200 | 23 | `Reports/park_attendance_explorer` |
| `reports-park-attendance-explorer-2` | login | ok | 200 | 26 | `Reports/park_attendance_explorer/{id}` |
| `reports-park-distance-matrix` | login | ok | 200 | 20 | `Reports/park_distance_matrix` |
| `reports-park-distance-matrix-2` | login | ok | 200 | 20 | `Reports/park_distance_matrix/{id}` |
| `reports-parkheraldry` | login | ok | 200 | 37 | `Reports/parkheraldry` |
| `reports-parkheraldry-2` | login | ok | 200 | 23 | `Reports/parkheraldry/{id}` |
| `reports-player-award-recommendations` | login | ok | 200 | 25 | `Reports/player_award_recommendations` |
| `reports-player-award-recommendations-2` | login | ok | 200 | 22 | `Reports/player_award_recommendations/{id}` |
| `reports-player-awards` | login | ok | 200 | 25 | `Reports/player_awards` |
| `reports-player-awards-2` | login | ok | 200 | 21 | `Reports/player_awards/{id}` |
| `reports-player-status-reconciliation` | login | redirect | 200 | 41 | `Reports/player_status_reconciliation` |
| `reports-player-status-reconciliation-2` | login | redirect | 200 | 51 | `Reports/player_status_reconciliation/{id}` |
| `reports-playerheraldry` | login | redirect | 200 | 42 | `Reports/playerheraldry` |
| `reports-playerheraldry-2` | login | ok | 200 | 22 | `Reports/playerheraldry/{id}` |
| `reports-reeve` | login | ok | 200 | 23 | `Reports/reeve` |
| `reports-reeve-2` | login | ok | 200 | 20 | `Reports/reeve/{id}` |
| `reports-reeve-test-results` | login | ok | 200 | 26 | `Reports/reeve_test_results` |
| `reports-reeve-test-results-2` | login | ok | 200 | 21 | `Reports/reeve_test_results/{id}` |
| `reports-release-utilization` | login | redirect | 200 | 38 | `Reports/release_utilization` |
| `reports-release-utilization-2` | login | redirect | 200 | 42 | `Reports/release_utilization/{id}` |
| `reports-roster` | login | redirect | 200 | 39 | `Reports/roster` |
| `reports-roster-2` | login | redirect | 200 | 39 | `Reports/roster/{id}` |
| `reports-set-player-active-json` | login | ok | 200 | 19 | `Reports/set_player_active_json` |
| `reports-suspended` | login | ok | 200 | 21 | `Reports/suspended` |
| `reports-suspended-kingdom-q-id` | login | ok | 200 | 21 | `Reports/suspended/Kingdom` |
| `reports-suspended-2` | login | ok | 200 | 19 | `Reports/suspended/{id}` |
| `reports-unitheraldry` | login | ok | 200 | 20 | `Reports/unitheraldry` |
| `reports-unitheraldry-2` | login | ok | 200 | 19 | `Reports/unitheraldry/{id}` |
| `reports-unwaivered` | login | redirect | 200 | 42 | `Reports/unwaivered` |
| `reports-unwaivered-2` | login | redirect | 200 | 43 | `Reports/unwaivered/{id}` |
| `reports-voting-eligible-2` | login | redirect | 200 | 42 | `Reports/voting_eligible` |
| `reports-voting-eligible-3` | login | redirect | 200 | 39 | `Reports/voting_eligible/{id}` |
| `reports-waivered` | login | redirect | 200 | 42 | `Reports/waivered` |
| `reports-waivered-2` | login | redirect | 200 | 45 | `Reports/waivered/{id}` |
| `search-event` | none | ok | 200 | 20 | `Search/event` |
| `search-index` | none | ok | 200 | 20 | `Search/index/{id}` |
| `search-kingdom` | none | ok | 200 | 19 | `Search/kingdom` |
| `search-kingdom-2` | none | ok | 200 | 17 | `Search/kingdom/{id}` |
| `search-park` | none | ok | 200 | 19 | `Search/park` |
| `search-park-2` | none | ok | 200 | 20 | `Search/park/{id}` |
| `search-tournament` | none | ok | 200 | 18 | `Search/tournament` |
| `search-unit` | none | ok | 200 | 20 | `Search/unit` |
| `search-unitactivity` | none | ok | 200 | 18 | `Search/unitactivity` |
| `selfreg-check-username` | none | ok | 200 | 21 | `SelfReg/check_username` |
| `selfreg-check-username-2` | none | ok | 200 | 17 | `SelfReg/check_username/{id}` |
| `selfreg-form` | none | ok | 200 | 19 | `SelfReg/form` |
| `selfreg-form-2` | none | ok | 200 | 22 | `SelfReg/form/{id}` |
| `selfreg-index` | none | ok | 200 | 23 | `SelfReg` |
| `signin-index` | none | redirect | 200 | 41 | `SignIn` |
| `signin-index-2` | none | redirect | 200 | 37 | `SignIn/index/{id}` |
| `tournament-create` | login | ok | 200 | 21 | `Tournament/create` |
| `tournament-worksheet` | login | redirect | 200 | 45 | `Tournament/worksheet` |
| `tournament-worksheet-2` | login | ok | 200 | 35 | `Tournament/worksheet/{id}` |
| `unit-create` | login | ok | 200 | 29 | `Unit/create/{id}` |
| `unit-index` | login | redirect | 200 | 35 | `Unit` |
| `unit-index-2` | login | redirect | 200 | 31 | `Unit/index/{id}` |
| `unit-unitlist` | login | ok | 200 | 17 | `Unit/unitlist` |
| `unit-unitlist-2` | login | ok | 200 | 22 | `Unit/unitlist/{id}` |
| `unit-unitlist-q-kingdomid` | login | ok | 200 | 21 | `Unit/unitlist` |
| `unit-unitlist-q-parkid` | login | ok | 200 | 22 | `Unit/unitlist` |
| `weather-day` | login | ok | 200 | 17 | `Weather/day` |
| `weather-day-2` | login | ok | 200 | 18 | `Weather/day/{id}` |
| `weather-index` | login | ok | 200 | 23 | `Weather/index/{id}` |
