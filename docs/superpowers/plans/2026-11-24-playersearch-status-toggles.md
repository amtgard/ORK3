# Player Search Status Toggles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development.

**Goal:** Active/Inactive/Banned include toggles in the shared player-search filter modal.
**Architecture:** Server tier flags ANDed into RankedPlayers; filteroptions reports viewer caps; UI only in ork-player-search.js/.css; header passes ia/ii/ib.
**Spec:** `docs/superpowers/specs/2026-11-24-playersearch-status-toggles-design.md`

## Global Constraints
- Copy exactly: `Active Players`, `Inactive Players`, `Banned Players`.
- Defaults all on; toggles only narrow; banned never exceeds viewerCaps / surface narrowing.
- SQL only in system/lib/ork3; (int)/bool-cast params; db->clear(); ->Next(); new public SearchService methods need '_'.
- UI only in ork-player-search.js/.css (header default.theme = wiring only). data-tip not title; dark mode; 375px; no native dialogs.
- Never stage class.Authorization.php.

## Review Focus
1. includeBanned=1 for a viewer without banned caps → still no banned rows (test).
2. All three false → [] (test).
3. Modal Apply with all toggles off is disabled.
4. Toggle state survives reattach()/setScope and is sent on Load more pages.
5. Header: logged-out viewer gets no toggles; universal endpoint ignores ib without caps.

### Task 1: Server tier flags + caps in filteroptions
Files: class.SearchService.php (RankedPlayers, UniversalSearch), controller.SearchAjax.php (players, universal, filteroptions), model.Search.php if needed, tests/Integration/SearchServiceTest.php.
Tests: testTierFlagsExcludeInactive, testTierFlagsExcludeActive, testIncludeBannedCannotExceedCaps, testAllTierFlagsOffReturnsEmpty, testUniversalSearchHonorsTierFlags. Run `vendor/bin/phpunit --filter SearchServiceTest`. Commit `Enhancement: player search active/inactive/banned include flags`.

### Task 2: Component toggles + header wiring
Files: ork-player-search.js/.css, default.theme.
filterField state gains `tiers {active,inactive,banned}`; handle `getTiers()`; onChange(filter, info) fires on tier change; attach buildUrl sends includeActive/includeInactive/includeBanned only when non-default; header nsSearch sends ia/ii/ib. Toggle row rendered from filteroptions `signedIn`/`canSeeBanned`. Icon active class when non-default. Verify in Chrome (header + Kingdom award field, light/dark, 375px). Commit `Enhancement: player search status toggles in filter modal`.
