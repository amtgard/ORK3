# PR #492 Review Fixes — Work Checklist

Branch: `fix-pr-492` · PR: https://github.com/amtgard/ORK3/pull/492

Mark each item `[x]` only after: fix + unit tests + full PHPUnit green + checklist update + commit + PR reply comment.

| # | ID | Status | Commit | PR reply |
|---|----|--------|--------|----------|
| 1 | C-01 Event APIModel delegation fatal | [x] | | |
| 2 | C-14 WeatherService GetArchiveForPark null TypeError | [x] | | |
| 3 | C-15 Event permissions breadcrumbs | [x] | | |
| 4 | C-16 UniversalSearch active units | [x] | | |
| 5 | C-18 Revoked awards alias titles | [x] | | |
| 6 | C-03 DangerAudit MethodCall SQLi | [x] | | |
| 7 | C-02 Banner CopyBanner auth | [x] | | |
| 8 | C-04 GetGlobalAdminGrants auth | [x] | | |
| 9 | C-05 Scoped/Kingdom/Event auth getters | [x] | | |
| 10 | C-06 Server health getters auth | [x] | | |
| 11 | C-07 AuthorizationGate privilege oracle | [x] | | |
| 12 | C-08 LiveService Token gate | [x] | | |
| 13 | C-13 WeatherService Token gate | [x] | | |
| 14 | C-10 Calendar detail write auth | [x] | | |
| 15 | C-12 SetRsvp/WithdrawRsvp Token | [x] | | |
| 16 | C-09 GetOccurrencePageData auth | [x] | | |
| 17 | C-11 GetDietarySummary auth | [x] | | |
| 18 | C-17 Attendance reactivation policy | [x] | | |
| 19 | C-19 RemoveRsvp AuthorizedByController trust | [x] | 71ddcd29 | pending-replies/C-19.md |
| 20 | C-20 GetRsvpList ungated | [x] | 53fffd48 | pending-replies/C-20.md |
| 21 | C-21 Player getters ungated | [x] | 8574690a | pending-replies/C-21.md |
| 22 | C-22 Report methods ungated | [x] | 96445e11 | pending-replies/C-22.md |
| 23 | C-23 GetServerHealthWeatherSummary ungated | [x] | a3aec596 | pending-replies/C-23.md |
| 24 | C-24 GetServerHealthDbStatus Wanted SQLi | [x] | 9a6b9b78 | pending-replies/C-24.md |
| 25 | C-25 AddAttendance reactivation narrow | [x] | 2654ca7b | pending-replies/C-25.md |
| 26 | C-26 QualTest getreports reporters | [x] | 56a3220a | pending-replies/C-26.md |
| 27 | C-27 Park ladder-grid master parity | [ ] | | |
| 28 | C-28 ScopedPlayerSearch KD sort | [ ] | | |
| 29 | C-29 Weather calendar coords sentinel | [ ] | | |
| 30 | C-30 Award option HTML order | [ ] | | |
| 31 | C-31 Json/SOAP auth inventory | [ ] | | |

## Simple checklist (copy-friendly)

1. [x] C-01 Event APIModel delegation fatal
2. [x] C-14 WeatherService GetArchiveForPark null TypeError
3. [x] C-15 Event permissions breadcrumbs
4. [x] C-16 UniversalSearch active units
5. [x] C-18 Revoked awards alias titles
6. [x] C-03 DangerAudit MethodCall SQLi
7. [x] C-02 Banner CopyBanner auth
8. [x] C-04 GetGlobalAdminGrants auth
9. [x] C-05 Scoped/Kingdom/Event auth getters
10. [x] C-06 Server health getters auth
11. [x] C-07 AuthorizationGate privilege oracle
12. [x] C-08 LiveService Token gate
13. [x] C-13 WeatherService Token gate
14. [x] C-10 Calendar detail write auth
15. [x] C-12 SetRsvp/WithdrawRsvp Token
16. [x] C-09 GetOccurrencePageData auth
17. [x] C-11 GetDietarySummary auth
18. [x] C-17 Attendance reactivation policy
19. [x] C-19 RemoveRsvp AuthorizedByController trust
20. [x] C-20 GetRsvpList ungated
21. [x] C-21 Player getters ungated
22. [x] C-22 Report methods ungated
23. [x] C-23 GetServerHealthWeatherSummary ungated
24. [x] C-24 GetServerHealthDbStatus Wanted SQLi
25. [x] C-25 AddAttendance reactivation narrow
26. [x] C-26 QualTest getreports reporters
27. [ ] C-27 Park ladder-grid master parity
28. [ ] C-28 ScopedPlayerSearch KD sort
29. [ ] C-29 Weather calendar coords sentinel
30. [ ] C-30 Award option HTML order
31. [ ] C-31 Json/SOAP auth inventory
