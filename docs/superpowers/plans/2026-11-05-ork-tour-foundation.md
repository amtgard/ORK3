# ORK Tour foundation: progress storage, welcome modal, new-player tour

Branch `feature/driverjs-user-tour` (worktree `.worktrees/driverjs-user-tour`). Already in place, uncommitted:
driver.js 1.10.2 vendored in `orkui/template/revised-frontend/script/driverjs/` (via `npm run copy-driverjs`),
the `OrkTour` wrapper `script/ork-tour.js` (loaded on every page from `default.theme`), the theme
`style/ork-tour.css`, and the Resources-menu slot `#nav-tour-links` in `default.theme`.

## Decisions (from the user)

- Three kinds of guidance: long-lived tours (always in the menu), one-off feature intros (shown once while
  active, then gone), and checklists.
- Tours start automatically when needed (first time on a feature, first time after an account exists) and
  can also be started by hand.
- Progress lives in the database, so it follows the person across devices. localStorage is only a fallback
  for logged-out pages.
- Everyone, existing players included, gets the new-player offer once. No grandfathering.
- First surface: a site-wide "Take the ORK Tour" welcome modal with a dismiss option, leading into the
  new-player tour of the person's own profile.

## Contract shared by both tasks

### Table `ork_tour_progress`

| column | type |
|---|---|
| `tour_progress_id` | INT UNSIGNED AUTO_INCREMENT PK |
| `mundane_id` | INT NOT NULL |
| `tour_id` | VARCHAR(64) NOT NULL |
| `version` | INT NOT NULL DEFAULT 1 |
| `status` | ENUM('completed','dismissed') NOT NULL |
| `last_step` | INT NOT NULL DEFAULT 0 |
| `updated_at` | TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP |

UNIQUE (`mundane_id`, `tour_id`). Saving is an upsert, so one row per person per tour.

### Page state, emitted by `default.theme` before `ork-tour.js`

```js
window.OrkTourState = {
  loggedIn: true,
  userId: 123,                       // 0 when logged out
  profileUrl: '<UIR>Player/profile/123',   // the person's own revised profile; '' when logged out
  saveUrl: '<UIR>TourAjax/save',
  progress: { 'new-player': { v: 1, status: 'completed', at: '2026-11-05' } }   // {} when none / logged out
};
```

### Endpoint `TourAjax/save` (POST, form-encoded)

Fields `tour_id` (`^[a-z0-9][a-z0-9_-]{0,63}$`), `version` (int ≥ 1), `status` (`completed` | `dismissed`),
`last_step` (int ≥ 0). Response `{"Status":{"Status":0}}` on success, otherwise `{"Status":{"Status":1,"Error":"..."}}`.
Logged-out requests are refused the same way `WnAjax/dismiss` refuses them.

## Task A: backend (system/lib/ork3, orkui/model, orkui/controller, migration, tests)

1. Migration `db-migrations/2026-11-05-add-tour-progress.sql` (idempotent `CREATE TABLE IF NOT EXISTS`), classified in
   `tools/ork-db/manifests/migration-classification.json5`, applied to both `ork` and `ork_test`.
2. Domain methods next to the What's New ones in `system/lib/ork3/class.Player.php` (around line 5557):
   read all progress rows for a person, and upsert one. All validation (tour id regex, status whitelist, int casts)
   lives here. Read the memory notes on JSON-service exposure before naming them public.
3. Player merge (`class.Player.php` around line 2496, where `whats_new_seen` rows move): carry tour progress rows to
   the surviving player, de-duplicating on (mundane_id, tour_id).
4. Model pass-throughs in `orkui/model/model.Player.php` beside `dismiss_whats_new` / `get_whats_new_seen`.
5. `orkui/controller/controller.TourAjax.php` with `save()`, mirroring `controller.WnAjax.php`.
6. `system/lib/system/class.Controller.php` (around line 112, beside `ShowWhatsNew`): set `$this->data['TourProgress']`
   for logged-in users, in the map shape above.
7. `default.theme`: emit `window.OrkTourState` (shape above) immediately before the `ork-tour.js` script tag.
   Find the correct own-profile route for the revised profile.
8. Integration test in `tests/Integration/` covering upsert, re-save (version bump), invalid input rejected, read shape.

## Task B: frontend (ork-tour.js, ork-tour.css, new tour file)

1. `ork-tour.js` storage: when `OrkTourState.loggedIn`, `seen()` reads `OrkTourState.progress`, and `markSeen()` updates
   it in memory and POSTs to `saveUrl` (fetch, form-encoded, fire-and-forget, `keepalive: true`). Logged out → keep localStorage.
   Status is `completed` when the last step was reached, else `dismissed`; send `last_step`.
2. Tour definition fields: `kind` (`'tour'` default | `'feature'`), `activeFrom` / `activeUntil` (`YYYY-MM-DD`, a feature
   intro is hidden from the menu and never auto-starts outside the window), `audience()` (who; `match()` stays "which page").
3. `OrkTour.offer(id, opts)`: an ORK-styled modal (no native dialogs) with title, body, a primary "Take the ORK Tour", a
   "Maybe later" (suppresses for this browser session via sessionStorage), and "No thanks" (saves `dismissed`, and says the
   tour lives under Resources). Escape and the close button mean "Maybe later". Focus moves into the modal and returns
   after. Light + dark mode, phone width, respects reduced motion. Never shows while the What's New modal
   (`.wn-overlay.wn-open`) is open, or while another tour is active.
4. `script/tours/new-player.js`, loaded globally from `default.theme` right after `ork-tour.js`:
   - registers `new-player` (version 1). `match()`: on the revised player profile (`PnConfig` present) and
     `PnConfig.playerId === OrkTourState.userId`.
   - offers the welcome modal on any page to a logged-in person with no `new-player` progress. "Take the ORK Tour" starts
     the tour if already on their own profile, otherwise navigates to `profileUrl` with `ork_tour=new-player` added to the
     query string (the boot path in `ork-tour.js` already starts it from there).
   - steps (skip any whose element is absent): Welcome (centered) → profile hero → Design My Profile `#pn-design-btn`
     (the profile customizer) → each tab in `.pn-tab-nav` (`li[data-tab=...]`: myamtgard, about, awards, titles, attendance,
     recommendations, history = Notes, classes = Class Levels) → finish (centered: "find this again under Resources → Tour
     this page"). Click into My Amtgard and Class Levels so the panel is visible; point at the rest. Copy is short, plain,
     friendly, and accurate to what each tab actually shows (read the template).
5. Phone width: the tab labels collapse (`.pn-tab-label`); make sure steps still anchor sensibly.

## Verification (orchestrator)

`php -l` on touched PHP, `node --check` on JS, the integration test, a curl round trip of `TourAjax/save`, and Chrome in
light and dark at desktop and phone width: modal → tour → reload shows no modal → state in DB.
