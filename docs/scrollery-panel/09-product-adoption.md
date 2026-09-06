# Panel report — Product Strategy & Adoption

## Summary
Coverage is right: 87.5% of the 107,841 awards granted since 2023 (94,342) resolve to one of the 16 specific-art orders, 11.3% (12,133) fall to the phase-2 composer, 1.3% (1,366) are officer positions correctly excluded. Head served, tail served, effort split defensible. The problem is not coverage — it is that **volume is not print demand**, that the module leaves no trace of itself, and that every path except the composer fires `window.print()` on an unseen page. Navigation was built and is adequate for officers; the player-side entry has a hole. Nothing in the ORK tells a kingdom this exists, and nothing will ever tell us whether they used it.

## Findings

- **[BUG] Every entry point prints sight-unseen**
  - Now: `Scrollery_mine.tpl:110`, `Playernew_index.tpl:2230`, `Scrollery_bulk.tpl:290` and `revised.js:2311` all append `&print=1`; `render()` (`controller.Scrollery.php:243`) honours it via `AutoPrint`. Without the flag `render` is already a perfectly good preview surface — it is simply never linked that way.
  - Proposal: drop `print=1` from all four call sites. Add a no-print sticky bar to `Scrollery_render.tpl` carrying "N page(s)", **Print**, and **Back**. Keep `print=1` supported for the future PDF service and a "print again" deep link.
  - Why: this is the whole reversibility story. Paper and ink are spent before the officer sees the heraldry is a placeholder, the pronoun read "their", or the reason line is blank (14.9% of recent awards carry no `note`).
  - Effort: S — Risk: one extra click on the single-scroll path; worth it.

- **Bulk pre-checks all 200 rows and one click sends them to the printer**
  - Now: `Scrollery_bulk.tpl:210` and `:222` both emit `checked`; `sc-print-bulk` opens `render&print=1` with up to `MAX_PRINT_RUN`=200 ids. Only feedback is "N selected".
  - Proposal: default select-all **off**; button reads `Print 37 certificates`; explicit confirm above ~20 pages (project rule: no native `confirm()` — use the CRM helper).
  - Why: a date-range search over a busy weekend legitimately returns 50-100 rows (48 event-days since 2023 produced 51-100 awards; 27 produced 100+). The default action is currently "print all of them".
  - Effort: S — Risk: none.

- **Nothing records that a scroll was printed** — the module is invisible to the ORK, the officer, and us
  - Now: `render()`/`compose()` render and exit. No table, no column, no log. `Report::ReleaseFeatureUtilization()` (`class.Report.php:5067`) computes every KPI from row counts, so Scrollery is structurally unmeasurable there.
  - Proposal: one insert-only `ork_scroll_print` row per certificate rendered (`awards_id`, `mundane_id`, `order`, `recipe`, `printed_at`). Then a "printed 2026-06-14" marker in `mine` and bulk, a bulk "not yet printed" filter, and a real utilization KPI.
  - Why: answers three questions with one table — did anyone adopt it, has this scroll already been printed, and (per the upstreaming criterion) has the module run a real event.
  - Effort: M — Risk: needs a `migration-classification.json5` entry or `drift-check --strict` blocks the test run.

- **Volume is not print demand, and bulk has no opinion about which awards a kingdom actually scribes**
  - Now: `awards_for_bulk()` filters on scope, event and date only. Of the 94,342 art-backed grants since 2023, 29,379 are rank 1 and 61,141 are ranks 1-3; the ceremonial population is the 12,133 custom-eligible rows, ~167 per kingdom per year.
  - Proposal: two client-side filters over the returned set — **minimum rank** and **ceremonial only** (non-ladder + rank >= 6) — with the last choice remembered per user. No new config table.
  - Why: an officer's real job is "print the six scrolls for tonight's court", not "print the 240 ladder bumps from the last quarter". Today they uncheck 234 boxes.
  - Effort: S — Risk: none; pure UI over data already fetched.

- **[BUG] Officers never see the player-side door on their own profile**
  - Now: `Playernew_index.tpl` gates the Awards toolbar `if ($canManageAwards) … elseif ($isOwnProfile)` (`:2101`), and "Print My Certificates" lives only in the second branch (`:2109`).
  - Proposal: move the link out of the branch so it renders whenever `$isOwnProfile`, in both toolbars.
  - Why: week-one adoption is monarchs, PMs and GMRs poking at their own profile. The one person most likely to try the module is the one person who cannot find it.
  - Effort: S — Risk: none.

- **The landing page is orphaned, and its copy is a phase behind**
  - Now: a repo-wide grep finds no link to bare `Scrollery`. `Scrollery_index.tpl:56` and `Scrollery_mine.tpl:65` both still read "Artwork exists for the ladder orders and the Order of the Flame" — untrue since phase 2 lit up 81 more entries.
  - Proposal: fix both strings ("every award your kingdom grants can be printed; 16 orders have their own artwork, the rest use a composed certificate"). Point the Park/Kingdom "Certificates" group heading at `Scrollery` itself, and the profile link's sibling at it too.
  - Why: the Valory Fox credit is a LICENCE OBLIGATION and a prerequisite for upstreaming; it currently sits on a page nobody reaches. The stale copy actively tells players their Knighthood cannot be printed.
  - Effort: S — Risk: none.

- **No What's New entry, no in-app docs entry** — the ORK's only broadcast channel to 27 kingdoms is unused
  - Now: `orkui/whats_new_content.php` has no Scrollery item; `WHATS_NEW_VERSION` is still `2026-08-22`. No `docs_modal.tpl` ORKDOC_DATA section exists.
  - Proposal: one release item (a `fa-scroll` headline item, not `notes_only`) plus a docs section covering the three doors, what "no artwork" means, and the paper stock people should use. Ship in the same release as the preview fix.
  - Effort: S — Risk: should land WITH, not before, the preview and bulk-default fixes.

- **You cannot print a certificate for anything not in the kingdom's award catalogue — the single biggest switching cost against Photoshop**
  - Now: `compose()` re-resolves the submitted award against `award_order_for_compose()` and redirects on no match (`controller.Scrollery.php:270`). Correct as a security gate, but a tournament champion scroll, an event-participation certificate, a thank-you scroll or a household charter cannot be produced at all.
  - Proposal: a **Free title** mode: an officer with AUTH_EDIT on the scope may type an arbitrary title and recipient name, backed by the custom recipe only (never order art, never an officer position). It prints, it is logged, it grants nothing. Obvious first customer: the Tournament module — a "Print champion scrolls" action off `Tournament/export`.
  - Why: "it's integrated" does not move a herald who already has a Canva file. "It does the three certificates Canva can't do from your data, and the tournament ones too" does. Free title is what makes Scrollery the ONLY tool needed rather than one of two.
  - Effort: M (free title) / L (tournament wiring) — Risk: keeps the officer-position exclusion and the `NormaliseRecipe()` allowlist; adds an abuse surface, which is why the print log should land first.

- **No proof / low-ink draft mode**
  - Now: print CSS forces `print-color-adjust:exact` on every layer (`scrollery.css:610`). No lighter path.
  - Proposal: `&draft=1` on render that hides ground and border layers (keeping text, heraldry outline and rank numeral) — one CSS class on `.award-template`, no new markup. Offer as "Proof (low ink)" beside Print in the new render toolbar.
  - Why: parks print on a home inkjet. A full-bleed parchment ground at letter size is roughly a full colour cartridge per dozen sheets; a proof pass is what makes people willing to try it at all.
  - Effort: S — Risk: must be visibly labelled so a draft is never mistaken for the real thing.

- **Court is the natural home for scroll printing, and Scrollery does not know courts exist**
  - Now: `ork_court`, `ork_court_award`, `ork_court_award_artisan` exist and `ork_awards.court_award_id` is a real column; no code on this branch references any of them, and all 107k+ rows have `court_award_id` NULL. Bulk's event path meanwhile reaches only ~9.2% of grants — just 9,868 of 107,846 awards since 2023 carry an `at_event_id` at all.
  - Proposal: when Court Planner merges, add a fourth bulk source: "this court". A finalised court is an exact, ordered, already-proofread list of what is about to be handed out — infinitely better than a date range, and it is the moment an officer thinks about scrolls.
  - Effort: M — Risk: blocked on Court Planner; design the bulk source as a strategy now so it slots in.

- **Upstreaming has no defined gate, and the fork will drift**
  - Now: the phase-1 spec defers to "once the module has run a real event"; nothing defines what "run" means or who checks.
  - Proposal: write the gate down as three measurable conditions the print log supplies: >=3 kingdoms have printed, >=1 run of >=10 certificates in a single job, zero unresolved rendering defects for 30 days. Separately, a CI check that every `src` referenced by an order partial exists (the asset-path matrix already does this) so a rebase cannot silently orphan art.
  - Why: the drift risk is not the PHP — it is that upstream refactors `class.Award.php` or `AwardsForPlayer()` and 19 partials break in a fork nobody runs CI on. A dated, falsifiable gate also stops the decision being deferred indefinitely.
  - Effort: S (gate) / M (CI) — Risk: depends on the print log for two of three conditions.

- **6.3% of recent awards are printable by nobody but the recipient, and the denial is silent**
  - Now: `viewer_may_print()` has no AUTH_ADMIN branch. Since 2023, **6,831 of 107,846** rows carry neither `at_park_id` nor `at_kingdom_id`. `render()` redirects with no explanation (`controller.Scrollery.php:236`).
  - Proposal: add AUTH_ADMIN; replace the silent redirect with the denied template carrying the real reason.
  - Why: the doc treats this as a curiosity about four 1990s rows; it is one award in sixteen. An officer who hits it three times concludes the tool is broken and goes back to Canva.
  - Effort: S — Risk: do not widen beyond AUTH_ADMIN.

## Sequencing call
1. **The trust bundle (all S, one release).** Preview instead of auto-print; bulk select-all off + counted confirm; the officer's own-profile link; the two stale copy strings; the AUTH_ADMIN branch and a real denial message. Under a day of work, and the difference between a tool people trust with paper and one they try once.
2. **The print log + a What's New / docs entry.** The log is the prerequisite for every adoption question and for the upstreaming gate; the release note is what puts 27 kingdoms in front of the module. Ship together so the announcement's audience is measurable.
3. **Free title in the composer, then Court Planner as a bulk source.** Free title is the switching-cost feature. Court is the workflow hook that makes it habitual.

**Kill or defer explicitly:** stop adding to the composer — ground x border x palette x numeral set already serves 11% of volume and a twelfth ground buys nothing; that is the gold-plating. Defer server-side PDF (correctly parked) until the print log shows someone running bulk. Do not build per-kingdom recipe storage until free title exists, because free title is the use case that would justify it.
