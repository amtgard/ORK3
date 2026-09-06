# Panel report — ORK Platform Integration

## Summary
Scrollery is unusually well-built for a first landing: it wears the shared `.rp-*` shell, reuses `kn-ac-results` + `tnPositionAcFixed` for the recipient search, reuses `Award::OptionGroupLabelFor()`/`awInitPicker` for the award picker, and has 17 unit tests plus mobile and dark-mode blocks. Its weaknesses are almost all CONNECTIVE: the ORK already holds data and already has surfaces that Scrollery either ignores or half-wires. Two of those half-wirings are real bugs (a computed-and-discarded print link, and a bulk/reprint path with no `revoked` predicate). The single biggest structural gap is that **nothing anywhere records that a scroll was printed**, which blocks the scribe workflow, the Release Utilization report, and the Court Planner handshake all at once.

## Findings

- **[BUG] Revoked awards are printable, and print blank**
  - Now: `viewer_may_print()` (`model.Scrollery.php:694`) checks recipient/park/kingdom only; `bulkSqlForTest()` (`:331-357`) has no `revoked` clause. `Player::AwardsForPlayer()` (`class.Player.php:1126`) also carries no revoked filter, unlike its three siblings at `:160,188,229`.
  - Proposal: `and awards.revoked = 0` in `bulkSqlForTest()` and `eventsWithPrintableAwardsSqlForTest()`, plus a `(int)$row['Revoked']===1 -> false` guard in `viewer_may_print()`. Surface `Revoked` on the row (`AwardsForPlayer` already selects `awards.*`).
  - Why: verified live — `awards_id 428148`, a revoked rank-8 Order of the Rose at kingdom 6 dated 2026-07-18, is returned by the kingdom-6 bulk query TODAY, PRE-CHECKED, with a **NULL recipient** (revocation sets `mundane_id=0`, `class.Player.php:3462-3463`). 2,797 such rows; 538 sit on ladder base awards that have art. An officer prints a nameless certificate for an award that was taken away.
  - Effort: S — Risk: none; `revoked` is `NOT NULL DEFAULT 0`.

- **[BUG] Every single certificate materialises 3,076 award rows to find one**
  - Now: `award_row()` (`model.Scrollery.php:721`) calls `AwardsForPlayer(['MundaneId'=>0,'AwardsId'=>N])`, building `where awards.mundane_id='0' or awards.awards_id='N'` (`class.Player.php:1152`). `mundane_id=0` is EXACTLY the revoked set — 3,075 rows — over a 9-table `select distinct`. Confirmed by running it: 3,076 rows. `scroll_for_awards_id()` then calls `GetPlayer()` (`:52`), which itself runs two `GetDues()` passes, an attendance query and a heraldry lookup (`class.Player.php:898-940`).
  - Proposal: a single-row fetch (`where awards.awards_id=N`) in the domain layer; batch the whole render run into one `awards_id IN (...)`; replace `GetPlayer()` with a persona+pronoun lookup — the scroll needs two fields, not a profile.
  - Why: `MAX_PRINT_RUN=200`, so one bulk print is ~615,000 rows materialised into PHP arrays plus ~1,000 profile sub-queries, before any of the 62 MB of art loads.
  - Effort: M — Risk: must keep the alias/kingdomaward/event joins identical or `ResolveTitle()` drifts.

- **[BUG] `Controller_Award` computes a print link that no template reads**
  - Now: `controller.Award.php:126` sets `$this->data['PrintAwardsId']`. It is the ONLY occurrence of that key in the repo; `Award_addawards.tpl` renders `$Message` alone.
  - Proposal: either render it or delete it and rely on the CRM path. That template lives in `default/`, the pre-3.5 surface — deletion is arguably the right call.
  - Effort: S — Risk: none.

- **The Titles table is missing a print button whose flag is already computed — highest value per line changed in the module**
  - Now: `Model_Player` stamps `CanPrintScroll` on EVERY award row (`model.Player.php:77`). The awards actions cell renders the print `<a>` (`Playernew_index.tpl:2228-2233`); the structurally identical TITLES actions cell (`:2348`) does not, though it iterates the same array filtered by `IsTitle==1 || OfficerRole!=='none'` (`:2283-2290`).
  - Proposal: copy the six-line `<a>` block into the titles cell. Officer positions self-exclude — `Scroll::HasArt()` returns false for them via `IsOfficerPosition()` (`class.Scroll.php:341`).
  - Why: a Knighthood or a Masterhood is THE thing people frame. Phase 2's custom scroll made them printable; the button was never added.
  - Effort: S — Risk: none — no colspan or header change, the cell already exists.

- **Two of the ORK's three grant paths offer no print — including "Grant from recommendation"**
  - Now: `Controller_PlayerAjax` returns `printable` + `awardsId` (`controller.PlayerAjax.php:171-173`) and `revised.js:2308-2315` renders the link. But `Controller_Admin::…addaward` (`controller.Admin.php:1104`) **discards** the `AwardsId` from `add_player_award()`, and `knDoSave`/`pkDoSave` (`revised.js:3633`,`:7371`) never parse the response body — so the Kingdom and Park grant modals, and both `knGiveFromRec`/`pkGiveFromRec` recommendation grants (`revised.js:3520`,`:7258`), end with no certificate offer.
  - Proposal: return `AwardsId` + `award_is_printable()` from `controller.Admin.php:1104` (the model method exists, `model.Scrollery.php:897`), parse in both save handlers, reuse `pnSetPrintLink()`.
  - Why: granting from a recommendation in Court season is the exact moment a scroll is wanted.
  - Effort: M — Risk: `add_player_award` returns a FLAT `$r['Status']` int — misreading it is the known double-grant trap.

- **The event page is the natural home for bulk, and the backend is already done**
  - Now: the Event admin tab is a one-item list (`Eventnew_index.tpl:2080-2086`). Meanwhile `Scrollery/bulk` already accepts `?event=` (`controller.Scrollery.php:105-124`) and `events_with_printable_awards()` already builds a scoped picker (`model.Scrollery.php:506`).
  - Proposal: one `<li>` — `Scrollery/bulk/park/<parkId>?event=<eventId>` — gated on the tab's existing `$canManage`.
  - Effort: S — Risk: none; `ScrolleryBulkQueryTest` already pins the scoped predicate.

- **The certificate never says WHERE it was given, though the event name is already on the row**
  - Now: `AwardsForPlayer()` returns `EventName` (`class.Player.php:1194`, joined at `:1148`). `Scroll::BuildScroll()` (`class.Scroll.php:475`) never reads it, and `_text.tpl` has no slot for it.
  - Proposal: pass `EventName` into `BuildScroll()`, render under the date blank in `_text.tpl:87-94` — "On this day… *at Midreign*". Blank when absent, exactly as the date is.
  - Why: 20,930 awards carry a named event (253 distinct). "Given at Midreign" is the one piece of provenance a framed scroll wants and a hand-typed Arcanyst scroll never had. It also gives bulk's event filter a visible payoff.
  - Effort: S — Risk: typography — SignAndDate is already two columns tight.

- **Nothing records that a scroll was printed, and three separate features need that record**
  - Now: `Scrollery/render` is a stateless GET (`controller.Scrollery.php:196`). No log table, no audit call.
  - Proposal: an `ork_scroll_print` row (`awards_id`, `mundane_id`, `printed_at`, `variant`/`recipe`) written from `render()`/`compose()`, in `system/lib/ork3/class.Scroll.php`.
  - Why: unlocks (a) the scribe workflow — bulk "only awards not yet printed", the whole reason a Guildmaster of Scribes uses this; (b) a Release Feature Utilization entry (`class.Report.php:6301`), which today has NOTHING TO COUNT; (c) the Court Planner handshake — `feature/court-planner` already ships `ork_court_award.scroll_maker_id`/`scroll_status` and `ork_court.last_printed_at`, and those columns exist in the shared dev DB with **zero PHP readers on this branch**. Scrollery is the missing half of a data model somebody already built.
  - Effort: M (L with Court wiring) — Risk: needs a `db-migrations/` file classified in ork-db's `migration-classification.json5` or `drift-check --strict` blocks the test run.

- **Layering: the module's two raw queries sit in `orkui/model/`, and nothing on this branch catches it**
  - Now: `model.Scrollery.php` is the ONLY file under `orkui/model/` (1 of 33) that references `DB_PREFIX` or reaches `Ork3::$Lib->player->db` — `bulkSqlForTest()` (`:320`), `eventsWithPrintableAwardsSqlForTest()` (`:548`), `db_query()` (`:636`), `event_rows()` (`:589`), plus a hand-rolled `result_failed()` SQLSTATE reader (`:686`).
  - Proposal: move both statements and both fetchers into `system/lib/ork3/class.Scroll.php`; the model keeps the shaping loop and the `...ForTest` seams move with them.
  - Why: `bin/check-layering.sh` R1/R2 flags `$DB->` and `Ork3::$Lib` under `orkui/` — but it lives only on `feature/front-door`, so the gate silently no-ops here (there is also no `.github/workflows/`, and `.githooks/pre-commit` is formatting-only and `exit 0`s). **The 17 Scroll tests run in no CI at all.**
  - Effort: M — Risk: the `$DB->Clear()`-then-manual-`Next()` shape must survive the move verbatim.

- **Zero onboarding surface — the module ships invisible**
  - Now: no help-modal entry (the registry is literally one array line, `controller.QualTestAjax.php:43`); no What's New entry (`orkui/whats_new_content.php:11,16,22` — still `3.5.5 Hydra`); no Release Utilization feature; `controller.Scrollery.php` has no constructor so pages show a stale kingdom/park breadcrumb (contrast `controller.ReleaseNotes.php:8-10`); and `Scrollery/index` — "the three doors" — is linked from nowhere.
  - Proposal: a `docs/scrollery-guide.md` + one registry line + a `.kn-help-btn` in the Certificates groups (`Kingdomnew_index.tpl:930`, `Parknew_index.tpl:1309`); a release entry; and a "Print My Certificates" item in the avatar dropdown (`default.theme:549`) so every player has a path from any page.
  - Effort: S each — Risk: none.

- **A 400-character reason has six writing lines and a font-size floor**
  - Now: `_text.tpl:74` scales the reason with `max(0.55em, calc(0.8em - 0.15em*(len/150)))` over six `.Underline.Cull` lines.
  - Proposal: in create and `mine`, warn above ~150 chars and offer a per-print trimmed reason (compose already accepts `reason` — `controller.Scrollery.php:301`).
  - Why: 6,358 award notes exceed 120 chars, 1,658 exceed 200, longest is 410. Many are A&S judging records ("…tying for first in the weapon category with a score of 4.33") never written to be calligraphed.
  - Effort: S — Risk: none, display-only.

- **62 MB of print-resolution art, no renditions, and the live preview loads all of it**
  - Now: `assets/scrolls` = 62 MB / 253 files; `jovious/bg.jpg` 3.4 MB, `rose/border.png` 2.8 MB, `warrior/symbol_9.png` 2.5 MB. `Scrollery_render.tpl:54` fires `window.print()` on `load`, i.e. after every byte. The create tool's Ajax preview (`controller.ScrolleryAjax.php:107`) renders the same full-res layers into a thumbnail.
  - Proposal: run the art through the existing masters+WebP rendition pipeline (already built for heraldry) and serve a `?preview=1` rendition set to the composer; keep full-res for `print=1`. Add a "preparing N certificates…" state before the print dialog.
  - Why: a mixed-order 200-scroll run can pull the whole 62 MB with no progress indication; a scribe on venue wifi sees a blank page.
  - Effort: L — Risk: blend modes are luminance-sensitive — WebP quality must be validated against `GROUND_WASHES`/`BORDER_STYLES` (`class.Scroll.php:83-129`), not eyeballed.

**Two smaller notes:** the date fields are raw `<input type="date">` (`Scrollery_bulk.tpl:127,132`, `Scrollery_create.tpl:328`) against the project's Flatpickr `altInput` rule; and `Scrollery/mine` offers only per-row Print (`Scrollery_mine.tpl:102`) with no select-all, unlike bulk.
