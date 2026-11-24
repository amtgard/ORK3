# Player Search Status Toggles — Design

Date: 2026-11-24 · Branch: `refactor/playersearch` · Builds on `2026-11-23-playersearch-scope-filter-design.md`

## Intent
Let viewers include/exclude Active, Inactive and Banned players in player search, inside the
existing filter modal/popover. Pill unchanged.

## Behavior
- Toggle row in the filter modal (and header anchored popover), copy exactly:
  `Active Players | Inactive Players | Banned Players`.
- Visibility: Active + Inactive shown to every signed-in viewer (inactive is visible to all today);
  Banned shown only when the viewer can see banned (viewerCaps banned_kids non-empty or 'all').
  Logged-out viewers: no toggles.
- Defaults = today: all shown toggles on. Off only narrows. Banned on never exceeds grants or a
  surface's own banned narrowing (`bannedScope`, park-surface family narrowing).
- At least one toggle on; Apply disabled otherwise.
- Funnel icon shows its active state when toggles differ from defaults. State is per field, resets on reload.
- No toggles where there is no filter icon (`filter:false`, park-locked).

## Approaches
1. Toggles in the shared filter modal (chosen) — one implementation, header inherits.
2. Toggles in the results dropdown — clutters every search; rejected.

## Server
- `RankedPlayers` opts `includeActive`, `includeInactive`, `includeBanned` (bool, default true);
  tier WHERE narrowed accordingly; banned still auth-gated. All false → [].
- `SearchAjax/players` params `includeActive|includeInactive|includeBanned` (0/1, absent = 1).
- `SearchAjax/universal` params `ia|ii|ib` → players section only.
- `SearchAjax/filteroptions` (no forKingdom) adds `canSeeBanned` (0/1) and `signedIn` (0/1) from
  the session token.
