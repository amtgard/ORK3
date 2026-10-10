<?php
/**
 * Record Court — the catch-up pass (spec §5). Reachable from the court hero
 * only when a plan is published; reads back the paper Court Record sheet in
 * screen form, in the same column order: # · check · x · Recipient · Award ·
 * Rank · Given by · PTL. This file carries the shell + top strip (Task 6);
 * Task 7 fills #cp-rec-rows with the per-row controls and walk-on row.
 *
 * Deliberately a separate file from Court_detail.tpl (already ~4,700 lines)
 * even though it reuses the same cp- visual language, so the two surfaces
 * read as one tool without becoming one unmanageable file.
 */
$court          = $Court          ?? [];
$courtAwards    = $CourtAwards    ?? [];
$giverOptions   = $GiverOptions   ?? ['default' => null, 'pills' => []];
$upcomingEvents = $UpcomingEvents ?? [];
$courtMode      = $CourtMode      ?? 'run';
$awardOptions   = $AwardOptions   ?? [];
$error          = $Error          ?? '';

$courtId = (int)($court['CourtId'] ?? 0);
$courtSt = $court['Status'] ?? 'draft';

// Drift warning (spec §5): true when the award COUNT differs from the count
// recorded the last time the packet was printed — the only thing that
// actually renumbers the paper. See Court::courtChangedSincePrint() for why
// this is a count, not a court_award.modified timestamp comparison (that
// column is ON UPDATE CURRENT_TIMESTAMP, so it would fire on every mark).
$courtChangedSincePrint = $CourtChangedSincePrint ?? false;
$lastPrintedHuman = !empty($court['LastPrintedAt']) ? date('F j, Y', strtotime($court['LastPrintedAt'])) : '';

$statusLabel      = ['draft' => 'Draft', 'published' => 'Published', 'complete' => 'Complete'];
$statusBadgeClass = ['draft' => 'cp-badge-draft', 'published' => 'cp-badge-published', 'complete' => 'cp-badge-complete'];

// Scope chip (rp-header shell) — park if the court is park-scoped, else kingdom.
$scopeIsPark = ($court['ParkId'] ?? 0) > 0 && !empty($court['ParkName']);
$scopeLabel  = $scopeIsPark ? $court['ParkName'] : ($court['KingdomName'] ?? '');
$scopeLink   = $scopeIsPark
    ? UIR . 'Park/profile/' . (int)$court['ParkId']
    : UIR . 'Kingdom/profile/' . (int)($court['KingdomId'] ?? 0);
$scopeIcon   = $scopeIsPark ? 'fa-map-marker-alt' : 'fa-chess-rook';

// Initial staged-safeguard count (spec §5.3), computed from the same CourtAwards
// payload the JS globals below carry — every mark is a real server write the
// moment it happens (spec §5), so this count is exact at render time. The
// server render defaults to "plan" phrasing (mirrors Court_detail.tpl's own
// comment on this); cpUpdateStagedIndicator() normalizes it to the true mode
// on load, same idiom as the planner.
$initialStagedCount = 0;
foreach ($courtAwards as $__a) {
    if (($__a['Status'] ?? '') === 'staged') {
        $initialStagedCount++;
    }
}
unset($__a);
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/court-planner.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/court-planner.css') ?>">
<script src="<?= HTTP_TEMPLATE ?>default/script/court-planner.js?v=<?= filemtime(DIR_TEMPLATE . 'default/script/court-planner.js') ?>"></script>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/rank-pill.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/rank-pill.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
/* Page-specific rules only — the shell, hero, buttons, badges, modals,
   autocomplete, tracking icons, toast, mode badge, staged-count indicator and
   Finalize/Complete modal all come from the shared
   default/style/court-planner.css (linked below), which Court_detail.tpl
   also links. Nothing in this block should duplicate a cp-* rule that already
   lives there. */

/* ---- Court Details sidebar card (spec §5: "top strip — court date, event, and
   default giver — edited through the new update_court endpoint"). The court's
   own date, event link, and recorder are the persisted, update_court-backed
   fields (0.1/0.7); this card surfaces them as inline edit-in-place controls
   instead of a modal, since correcting them IS the catch-up pass. Now a
   vertical stack inside the standard 220px .rp-sidebar (density pass 2b)
   rather than a horizontal full-width strip above the grid — same fields,
   same ids, same JS (cpRecSaveField/flatpickr/cpAcSearch), just relaid out. */
.cp-rec-topstrip { display: flex; flex-direction: column; gap: 12px; }
.cp-rec-strip-field label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #718096; margin-bottom: 4px; }
.cp-rec-strip-field input,
.cp-rec-strip-field select { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 14px; box-sizing: border-box; min-height: 38px; }
.cp-rec-strip-status { display: block; font-size: 12px; color: #718096; min-height: 16px; }
.cp-rec-strip-status.cp-rec-strip-saved { color: #276749; font-weight: 600; }
.cp-rec-strip-status.cp-rec-strip-error { color: #c53030; font-weight: 600; }
html[data-theme="dark"] .cp-rec-strip-field label { color: #97a3b4; }
/* #theme_container carries orkui.css's broad dark-mode input rule
   (html[data-theme="dark"] #theme_container input[type="text"], ...) — an ID
   selector, which outranks a same-scoped class selector on specificity
   regardless of source order (0,2,2 beats 0,2,1). It was silently winning here
   too (computed-style check, not by eye — same failure mode Task 9/10 already
   hit twice on this file): the flatpickr alt-input for Court Date is a plain
   input[type=text] inside #theme_container, so the broad rule's #374151 beat
   this rule's #1f2733 outright. Matching input[type="text"]/select with the
   class appended, same as .cp-rec-walkon-input above, guarantees this wins. */
html[data-theme="dark"] #theme_container input[type="text"].cp-rec-strip-input,
html[data-theme="dark"] #theme_container select.cp-rec-strip-input,
html[data-theme="dark"] .cp-rec-strip-field input,
html[data-theme="dark"] .cp-rec-strip-field select { background: #1f2733; border-color: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .cp-rec-strip-status { color: #97a3b4; }
html[data-theme="dark"] .cp-rec-strip-status.cp-rec-strip-saved { color: #68d391; }

/* ---- Drift warning banner (spec §5) — sits between .rp-context and
   .rp-body, in the rp-* shell's own idiom (compare Reports_eventattendance.tpl's
   .rp-stats-filter-notice: a warning-toned note above the table area). Uses the
   global --ork-alert-warning-* tokens from orkui.css directly rather than
   hardcoding light/dark pairs — those tokens already flip correctly under
   html[data-theme="dark"], so there is nothing left for this file to override. */
.cp-rec-drift-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--ork-alert-warning-bg);
    border: 1px solid var(--ork-alert-warning-border);
    color: var(--ork-alert-warning-text);
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 14px;
    font-size: 13px;
    line-height: 1.45;
}
.cp-rec-drift-banner.cp-rec-drift-dismissed { display: none; }
.cp-rec-drift-icon { font-size: 16px; flex-shrink: 0; }
.cp-rec-drift-text { flex: 1; min-width: 0; }
.cp-rec-drift-dismiss {
    background: none;
    border: none;
    color: inherit;
    opacity: .65;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    padding: 2px 4px;
    flex-shrink: 0;
    box-sizing: border-box;
}
.cp-rec-drift-dismiss:hover { opacity: 1; }
/* House convention: compact by default, >=44px hit area only under a coarse
   pointer (padding-driven, not a bigger glyph) — matches every other icon
   button on this page (.cp-rec-cite-preview, .cp-rec-giver-chip, etc). */
@media (pointer: coarse) {
    .cp-rec-drift-dismiss { min-width: 44px; min-height: 44px; }
}
html[data-theme="dark"] .cp-rec-strip-status.cp-rec-strip-error { color: #fc8181; }

/* ---- Row list (spec §5.1) — per-row marks, same column order as the printed
   Sheet 2: # · Recipient · Award · Rank · [mark] · Given by · PTL. The paper's
   separate check/x columns collapse into one three-state control here. Every
   mark is a real server write the moment it's made (CourtAjax/grant_award,
   skip_award, unstage_award) — nothing here batches client-side. ---- */
/* The three-sentence explanation that used to sit beside this button now lives
   in the sidebar's "About This Tool" card (density pass 2b) — a short data-tip
   on the button covers the zero-context case. */
.cp-rec-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
/* House rule: no interactive target under 44px, checked at 390 AND 768 — not just
   the <=600px stacked layout. .cp-btn-primary's own padding lands at ~31px, so
   this must not be gated behind the mobile media query below. */
#cp-rec-bulk-btn { min-height: 44px; }
.cp-rec-empty { padding: 30px 16px; text-align: center; color: #a0aec0; font-size: 13px; border: 1px dashed #cbd5e0; border-radius: 8px; }
html[data-theme="dark"] .cp-rec-empty { color: #718096; border-color: #2d3748; }

.cp-rec-list { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; }
/* flex-wrap: wrap so the citation (inline by default, see .cp-rec-cite below) can
   drop to its own line at narrow widths, or forces one when expanded for editing
   (its :has()-driven flex-basis:100% below). Density pass 2b: the citation is no
   longer an unconditional full-width second row — see .cp-rec-cite — so this row
   is now sized to its tallest control, not doubled by a citation banner. */
.cp-rec-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 5px; padding: 5px 12px; border-bottom: 1px solid #edf2f7; }
.cp-rec-row:last-child { border-bottom: none; }
.cp-rec-row-header { background: #f7fafc; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #718096; }
.cp-rec-c-label { display: none; }
.cp-rec-c-num { flex: 0 0 26px; color: #a0aec0; font-size: 12px; }
.cp-rec-row-header .cp-rec-c-num { color: #718096; }
/* Fix round 1 / Finding 1: mark control sits right after # — same position as
   the paper's ✓/✕ columns (cpSheetRecord in Court_detail.tpl: # · ✓ · ✕ ·
   Recipient · Award · Rank · Given by · PTL). DOM order carries the visual
   order here (no flex `order` trick), so the header row and each data row's
   markup were both moved, not just this rule.
   Column-alignment fix: pinned to a fixed basis (was flex: 0 0 auto) so the
   header cell — plain text "Mark" — renders at the SAME width as the data
   row's three-button .cp-rec-seg group, instead of hugging its own (much
   narrower) content. box-sizing: border-box makes that basis denote the
   same border-box width in both places: the data cell also carries
   .cp-rec-seg's 1px border, which a content-box basis would otherwise add
   on top of, reintroducing a 2px header/data mismatch. 131px is the
   measured min-content width of the three buttons; verified equal
   (getBoundingClientRect) in both rows after this change. */
.cp-rec-c-mark { flex: 0 0 131px; box-sizing: border-box; }
/* Density pass 2b: recipient is bounded (flex-grow:0), not one of the two columns
   fighting over slack — a ~200px cap is comfortably wider than any real persona,
   and the reclaimed width goes to the award name and the now-inline citation. */
.cp-rec-c-recip { flex: 0 1 170px; min-width: 90px; font-weight: 600; color: #1a202c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-rec-park { font-size: 11px; color: #718096; font-weight: 400; margin-left: 4px; }
.cp-rec-c-award { flex: 1 1 140px; min-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-rec-c-rank { flex: 0 0 62px; }
.cp-rec-c-giver { flex: 0 0 110px; font-size: 12px; color: #4a5568; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* Widened from 30px (icon-only) to fit the "To Local" header label — see the
   header-cell comment below for why the full "Pass to Local" doesn't fit
   without pushing the >=1150px single-line row-height breakpoint higher
   (measured regression to ~1300-1350px; 62px keeps the existing ~1300px
   breakpoint unchanged). Must stay identical between header and data cells
   (shared class) or the .cp-rec-c-cite column that follows it would drift
   between the two rows exactly the way this whole fix is undoing. */
.cp-rec-c-ptl { flex: 0 0 62px; text-align: center; color: #718096; }
/* Column-alignment fix: the header row (7 cells: #, Mark, Recipient, Award,
   Rank, Given by, PTL) was missing a counterpart for each data row's 8th
   cell, the inline citation (.cp-rec-cite, flex: 1 1 150px; min-width: 100px
   — Task 9 / density pass 2b). With no competing header item to share
   flex-grow with, the header's Award cell alone soaked up all the slack
   that Award+Citation split between them in the data rows, so every header
   cell from Rank rightward rendered to the right of its data-row column.
   This is that counterpart — same flex-basis/min-width as .cp-rec-cite —
   used only by the header's new "Citation" label. */
.cp-rec-c-cite { flex: 1 1 150px; min-width: 100px; }
.cp-rec-row.cp-rec-row-given { background: #f0fff4; }
.cp-rec-row.cp-rec-row-skipped { background: #fff5f5; opacity: .8; }

/* ---- Citation (Task 9) — the public, permanent ork_awards.note-to-be. A truncated
   preview by default (zero clicks in the common case: it was written during
   planning and is already correct) that expands to a textarea on one click, saves
   on blur via CourtAjax/update_award — a PARTIAL write (spec 0.6: CourtAwardId,
   PublicComment, RowVersion only) so this can never clobber Notes/PassToLocal/
   ScrollMakerId/RegaliaMakerId, none of which this view even shows. Deliberately
   NOT locked by row mark state the way the giver/rank chips are (Task 8's fix
   round): update_award's WHERE clause has no status condition (unlike
   grant_award/skip_award/unstage_award), so editing a citation after marking a
   row Given is a real, safe write, not a doomed one — locking it here would just
   block a legitimate correction. It IS locked when the court itself isn't
   'published' (same $canMark the mark buttons use), since a post-finalize edit
   here no longer reaches ork_awards.note at all.

   Density pass 2b: the citation used to be an unconditional flex-basis:100%
   item, forcing a second full-width row under every award even when the only
   content was the italic "No citation" placeholder — ~46px spent announcing an
   absence, on every one of ~25 rows. It's now an ordinary inline cell (bounded
   width, single truncated line, no border/background/min-height) that sits in
   the row alongside recipient/award/rank/giver/PTL. It only reclaims the full
   row width — and becomes the roomy textarea — while actually being edited:
   the :has() selector below tracks the textarea's own .cp-rec-hidden toggle
   (already flipped by cpRecCiteExpand/cpRecCiteCollapse in JS, unchanged), so
   no extra class bookkeeping was added anywhere. A walk-on's citation (Task 9's
   IsWalkOn hook) never carries .cp-rec-hidden on its textarea in the first
   place, so it's always matched by this same rule and stays full-width — the
   "must not be missable" requirement, now met by plain CSS instead of a
   special case. */
.cp-rec-cite { flex: 1 1 150px; min-width: 100px; display: flex; align-items: center; }
.cp-rec-cite:has(.cp-rec-cite-textarea:not(.cp-rec-hidden)) { flex: 1 1 100%; }
.cp-rec-cite-preview { display: flex; align-items: center; gap: 6px; width: 100%; background: none; border: none; border-radius: 4px; padding: 3px 6px; box-sizing: border-box; cursor: pointer; text-align: left; font-size: 12px; color: #718096; }
.cp-rec-cite-preview:hover { background: #f7fafc; }
/* House convention: a coarse pointer still gets a real ≥44px hit area, added
   via padding (not by inflating the compact mouse-facing glyph/line-height). */
@media (pointer: coarse) {
    .cp-rec-cite-preview { min-height: 44px; padding: 10px 6px; }
}
.cp-rec-cite-icon,
.cp-rec-cite-edit-icon { flex: 0 0 auto; color: #cbd5e0; font-size: 10px; }
.cp-rec-cite-edit-icon { display: none; }
.cp-rec-cite-preview-text { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-rec-cite-preview-text.cp-rec-cite-empty { color: #a0aec0; font-style: italic; }
/* Inherited (state 2, bugfix) — the recommendation's reason, shown because it IS
   what publishes if nobody edits it (spec 6.1 precedence). Deliberately NOT the
   same treatment as the empty placeholder above (that's lighter + italic, reads
   as "there's nothing here") — this needs to read as real, legible content
   awaiting review, so a darker/warmer tone plus the "from recommendation" tag
   carries the distinction instead of italics-and-fade. */
.cp-rec-cite-tag { flex: 0 0 auto; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: #975a16; background: #fffaf0; border: 1px solid #f6e05e; border-radius: 3px; padding: 1px 5px; white-space: nowrap; }
.cp-rec-cite-preview-text.cp-rec-cite-inherited { color: #744210; }
.cp-rec-cite-textarea { width: 100%; min-height: 64px; padding: 8px 10px; border: 1px solid #90cdf4; border-radius: 6px; font-size: 13px; font-family: inherit; line-height: 1.4; box-sizing: border-box; resize: vertical; }
.cp-rec-cite-textarea:disabled,
.cp-rec-cite-textarea[readonly] { background: #f7fafc; color: #718096; cursor: not-allowed; }
.cp-rec-cite-status { display: block; min-height: 14px; margin-top: 3px; font-size: 11px; color: #718096; }
.cp-rec-cite-status.cp-rec-cite-status-error { color: #c53030; font-weight: 600; }
.cp-rec-hidden { display: none !important; }

/* ---- Walk-on row (Task 10) — the permanent blank row at the foot of the list.
   A faster path to Court_detail.tpl's existing Add Award / Add Title modals,
   not a new capability: recipient search + one combined award/title search
   (fetch_award_option_groups already covers titles — no second entry point) +
   the SAME shared rank popover every other row uses (Task 8's #cp-rec-rank-pop,
   opened here with caid 'walkon') + a Pass-to-Local checkbox (the printed
   sheet's PTL box needs somewhere to land) + an always-expanded citation
   (Task 9's IsWalkOn hook — a walk-on has no planned citation anywhere else).
   Internal Notes are deliberately omitted; they stay editable on the planner.
   Unconditional >=44px hit areas throughout (not gated behind pointer:coarse
   or the 600px block), matching this file's own established convention for
   the giver/rank chips above. ---- */
.cp-rec-row-walkon { background: #f7fafc; border-top: 2px dashed #cbd5e0; }
html[data-theme="dark"] .cp-rec-row-walkon { background: #171e28; border-top-color: #2d3748; }
.cp-rec-walkon-input { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 5px; font-size: 13px; box-sizing: border-box; min-height: 44px; }
/* #theme_container wraps every page and already carries orkui.css's broad dark-mode
   input rule (html[data-theme="dark"] #theme_container input[type="text"]) — an
   ID selector PLUS a type+attribute selector, which outranks a same-scoped class
   selector on specificity, not just source order (0,2,2 beats 0,2,1 — adding
   #theme_container alone still lost). Found the same way Task 9 found the
   identical citation-textarea bug: a computed-style check, not by eye (the two
   shades of dark gray were close enough to pass a screenshot). Matching the
   input[type="text"] shape here, with the class appended on top, guarantees this
   wins regardless of source order. */
html[data-theme="dark"] #theme_container input[type="text"].cp-rec-walkon-input { background: #1f2733; border-color: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] #theme_container input[type="text"].cp-rec-walkon-input::placeholder { color: #718096; }
.cp-rec-walkon-add-btn { display: inline-flex; align-items: center; gap: 6px; background: #2c5282; border: 1px solid #2c5282; color: #fff; padding: 0 14px; min-height: 44px; box-sizing: border-box; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.cp-rec-walkon-add-btn:hover:not(:disabled) { background: #2b6cb0; }
.cp-rec-walkon-add-btn:disabled { opacity: .6; cursor: not-allowed; }
html[data-theme="dark"] .cp-rec-walkon-add-btn { background: #2b6cb0; border-color: #2b6cb0; }
.cp-rec-ptl-label { display: flex; align-items: center; gap: 6px; cursor: pointer; min-height: 44px; padding: 4px 2px; box-sizing: border-box; }
.cp-rec-ptl-check { width: 18px; height: 18px; flex: 0 0 auto; }

.cp-rec-seg { display: inline-flex; border: 1px solid #cbd5e0; border-radius: 6px; overflow: hidden; }
/* Density pass 2b: these fire on every one of ~25 rows, so their height sets the
   row's floor. Per the house convention, the hit area only needs to resolve to
   >=44px under a coarse pointer (added via padding below); a mouse gets a visually
   compact control instead of one double the height of its own label. */
.cp-rec-seg-btn { background: #fff; border: none; border-right: 1px solid #e2e8f0; padding: 4px 7px; min-height: 28px; font-size: 11px; font-weight: 600; color: #4a5568; cursor: pointer; box-sizing: border-box; }
.cp-rec-seg-btn:last-child { border-right: none; }
.cp-rec-seg-btn:hover:not(:disabled) { background: #f7fafc; }
.cp-rec-seg-btn:disabled { cursor: not-allowed; opacity: .5; }
.cp-rec-row[data-mark="given"] .cp-rec-seg-given { background: #276749; color: #fff; }
.cp-rec-row[data-mark="skipped"] .cp-rec-seg-skipped { background: #c53030; color: #fff; }
.cp-rec-row[data-mark="none"] .cp-rec-seg-none { background: #edf2f7; color: #2d3748; }

/* ---- Given-by chip + rank chip (Task 8) — the row-level controls that open the
   two shared floating popovers below. Same density-pass-2b treatment as the mark
   buttons above: visually compact by default, >=44px only under pointer:coarse
   (padding-driven, not a bigger glyph), checked at both 390 and 768. ---- */
.cp-rec-giver-chip { background: #edf2f7; border: 1px solid #cbd5e0; color: #2d3748; padding: 4px 8px; border-radius: 14px; font-size: 11px; font-weight: 600; cursor: pointer; max-width: 100%; min-height: 28px; box-sizing: border-box; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-rec-giver-chip:hover:not(:disabled) { background: #e2e8f0; }
.cp-rec-giver-chip:disabled { cursor: not-allowed; opacity: .6; }
.cp-rec-giver-chip.cp-rec-chip-custom { background: #ebf8ff; border-color: #90cdf4; color: #2b6cb0; }
.cp-rec-rank-chip { cursor: pointer; min-height: 28px; box-sizing: border-box; display: inline-flex; align-items: center; }
.cp-rec-rank-chip:disabled { cursor: not-allowed; opacity: .6; }
@media (pointer: coarse) {
    .cp-rec-seg-btn,
    .cp-rec-giver-chip,
    .cp-rec-rank-chip { min-height: 44px; }
}

/* Shared floating popover (giver + rank) — same fixed-position idiom as
   Court_detail.tpl's #cp-note-popup, positioned next to the chip that opened it. */
.cp-rec-pop { display: none; position: fixed; background: #fff; border: 1px solid #cbd5e0; border-radius: 8px; padding: 12px; width: 270px; max-width: calc(100vw - 20px); box-shadow: 0 6px 20px rgba(0,0,0,.18); z-index: 1150; }
.cp-rec-pop-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.cp-rec-pop-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #718096; }
.cp-rec-pop-close { background: none; border: none; color: #718096; cursor: pointer; font-size: 16px; line-height: 1; padding: 10px; margin: -10px; min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
.cp-rec-pop-close:hover { color: #2d3748; }
/* Popover pills get the same unconditional 44px — they're the giver/rank quick-picks
   called out by name in the house mobile rules. */
#cp-rec-giver-pop .cp-giver-pill,
#cp-rec-rank-pop .cp-rank-pill { min-height: 44px; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; }
/* The rank digits (1..12) are narrow single/double-character labels — height alone
   isn't enough of a hit area, so give them an explicit minimum width too. */
#cp-rec-rank-pop .cp-rank-pill { min-width: 44px; padding-left: 0; padding-right: 0; }
.cp-rec-pop-apply-btn { display: none; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 10px; background: #edf2f7; border: 1px solid #cbd5e0; color: #2c5282; padding: 8px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; min-height: 44px; box-sizing: border-box; }
.cp-rec-pop-apply-btn:hover { background: #e2e8f0; }
.cp-rec-pop-apply-btn.show { display: flex; }

html[data-theme="dark"] .cp-rec-list { background: #161b22; border-color: #2d3748; }
html[data-theme="dark"] .cp-rec-row { border-color: #22272e; }
html[data-theme="dark"] .cp-rec-row-header { background: #1a202c; color: #97a3b4; }
html[data-theme="dark"] .cp-rec-c-num { color: #718096; }
html[data-theme="dark"] .cp-rec-c-recip { color: #e2e8f0; }
html[data-theme="dark"] .cp-rec-park,
html[data-theme="dark"] .cp-rec-c-giver { color: #97a3b4; }
/* .cp-rec-c-ptl carries its own explicit color (like -giver above), so it
   doesn't inherit .cp-rec-row-header's dark-mode override — found via the
   same computed-style check this file's other dark-mode fixes used (not
   visible by eye: #718096 on the dark header background still reads, just
   the wrong, light-mode shade). Same target color as -giver/-park for
   consistency. */
html[data-theme="dark"] .cp-rec-c-ptl { color: #97a3b4; }
html[data-theme="dark"] .cp-rec-row.cp-rec-row-given { background: rgba(39,103,73,.18); }
html[data-theme="dark"] .cp-rec-row.cp-rec-row-skipped { background: rgba(197,48,48,.14); }
html[data-theme="dark"] .cp-rec-seg { border-color: #2d3748; }
html[data-theme="dark"] .cp-rec-seg-btn { background: #1f2733; border-color: #2d3748; color: #cbd5e0; }
html[data-theme="dark"] .cp-rec-seg-btn:hover:not(:disabled) { background: #2d3748; }
html[data-theme="dark"] .cp-rec-row[data-mark="given"] .cp-rec-seg-given { background: #276749; color: #fff; }
html[data-theme="dark"] .cp-rec-row[data-mark="skipped"] .cp-rec-seg-skipped { background: #9b2c2c; color: #fff; }
html[data-theme="dark"] .cp-rec-row[data-mark="none"] .cp-rec-seg-none { background: #2d3748; color: #e2e8f0; }

html[data-theme="dark"] .cp-rec-giver-chip { background: #1f2733; border-color: #2d3748; color: #e2e8f0; }
html[data-theme="dark"] .cp-rec-giver-chip:hover:not(:disabled) { background: #2d3748; }
html[data-theme="dark"] .cp-rec-giver-chip.cp-rec-chip-custom { background: rgba(43,108,176,.22); border-color: #2b6cb0; color: #90cdf4; }
html[data-theme="dark"] .cp-rec-pop { background: #161b22; border-color: #2d3748; box-shadow: 0 6px 20px rgba(0,0,0,.5); }
html[data-theme="dark"] .cp-rec-pop-title { color: #97a3b4; }
html[data-theme="dark"] .cp-rec-pop-close { color: #718096; }
html[data-theme="dark"] .cp-rec-pop-close:hover { color: #e2e8f0; }
html[data-theme="dark"] .cp-rec-pop-apply-btn { background: #1f2733; border-color: #2d3748; color: #90cdf4; }
html[data-theme="dark"] .cp-rec-pop-apply-btn:hover { background: #2d3748; }

html[data-theme="dark"] .cp-rec-cite-preview { color: #97a3b4; }
html[data-theme="dark"] .cp-rec-cite-preview:hover { background: #22272e; }
html[data-theme="dark"] .cp-rec-cite-preview-text.cp-rec-cite-empty { color: #718096; }
html[data-theme="dark"] .cp-rec-cite-tag { color: #f6d78e; background: #2d2411; border-color: #6b5416; }
html[data-theme="dark"] .cp-rec-cite-preview-text.cp-rec-cite-inherited { color: #ecc94b; }
/* #theme_container wraps every page and already carries a dark-mode rule for bare
   textareas (orkui.css: html[data-theme="dark"] #theme_container textarea) — an ID
   selector, so it beats a plain .cp-rec-cite-textarea override regardless of source
   order (found by computed-style check, not by eye: the "blue while editing" accent
   below was silently dead until #theme_container was added to match/exceed its
   specificity). Its background/text color are left alone here — same tokens as every
   other dark-mode input on the site — only the border is reasserted, for the same
   "you're editing this" accent the light-mode rule above gives it. */
html[data-theme="dark"] #theme_container .cp-rec-cite-textarea { border-color: #63b3ed; }
html[data-theme="dark"] #theme_container .cp-rec-cite-textarea:disabled,
html[data-theme="dark"] #theme_container .cp-rec-cite-textarea[readonly] { background-color: #161b22; color: #718096; border-color: #2d3748; }
html[data-theme="dark"] .cp-rec-cite-status { color: #97a3b4; }
html[data-theme="dark"] .cp-rec-cite-status.cp-rec-cite-status-error { color: #fc8181; }

@media (max-width: 600px) {
    /* House rule: >=44px hit area via padding, not larger glyphs. .cp-field's base
       8px vertical padding leaves these at ~38px on a 14px input; pad up, don't
       just embiggen the text. (.cp-rec-topstrip/.cp-rec-strip-field no longer need
       a flex-direction override here — the Court Details card is a vertical stack
       at every width now that it lives in the sidebar, not a horizontal strip.) */
    .cp-rec-strip-field input,
    .cp-rec-strip-field select { min-height: 44px; padding-top: 12px; padding-bottom: 12px; box-sizing: border-box; }

    /* Row list collapses to stacked cards — a table/flex-row layout at this width
       either overflows horizontally or forces sub-44px controls; neither is
       acceptable per the house mobile rules. */
    .cp-rec-toolbar { flex-direction: column; align-items: stretch; }
    #cp-rec-bulk-btn { justify-content: center; }
    .cp-rec-row-header { display: none; }
    .cp-rec-row { row-gap: 8px; } /* flex-wrap already set at base — see above */
    .cp-rec-c-num { flex: 0 0 auto; }
    .cp-rec-c-recip,
    .cp-rec-c-award,
    .cp-rec-c-rank,
    .cp-rec-c-giver { flex: 1 1 100%; }
    .cp-rec-c-label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #a0aec0; margin-bottom: 2px; }
    html[data-theme="dark"] .cp-rec-c-label { color: #718096; }
    .cp-rec-c-mark { flex: 1 1 100%; }
    .cp-rec-seg { width: 100%; }
    .cp-rec-seg-btn { flex: 1 1 33%; }
    .cp-rec-c-ptl { flex: 1 1 100%; text-align: left; display: flex; align-items: center; gap: 6px; }
    /* Inline citation cell (density pass 2b) also goes full-width once the row
       itself is a stack of full-width cards — same as every other column here. */
    .cp-rec-cite { flex: 1 1 100%; }
}
</style>

<?php if ($error): ?>
<div style="padding:24px">
    <div class="cp-error-box">
        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
    </div>
</div>
<?php else: ?>

<div class="rp-root cp-page" style="padding-top:0;padding-bottom:0;margin-bottom:0">

    <!-- Standard tool-page header plate (.rp-* shell, shared reports.css — see
         orkui/template/revised-frontend/Recommendations_manage.tpl for the
         worked example this follows). Replaces the old bespoke .cp-hero; the
         read-only date/event line it used to carry now lives in the Court
         Details sidebar card below, always visible, edit-in-place. -->
    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fas fa-gavel rp-header-icon"></i>
                <h1 class="rp-header-title"><?= htmlspecialchars($court['Name'] ?? '') ?></h1>
                <span class="cp-badge <?= $statusBadgeClass[$courtSt] ?? 'cp-badge-draft' ?>" id="cp-rec-status-badge">
                    <?= $statusLabel[$courtSt] ?? $courtSt ?>
                </span>
                <?php if (in_array($courtSt, ['published', 'complete'], true)): ?>
                <span class="cp-mode-badge <?= $courtMode === 'plan' ? 'cp-mode-plan' : 'cp-mode-run' ?>" id="cp-rec-mode-badge">
                    <i class="fas fa-<?= $courtMode === 'plan' ? 'clipboard-list' : 'bullhorn' ?>"></i>
                    <?= $courtMode === 'plan' ? 'Plan' : 'Run at Court' ?>
                </span>
                <?php endif; ?>
            </div>
            <?php if ($scopeLabel !== ''): ?>
            <div class="rp-header-scope">
                <a class="rp-scope-chip" href="<?= $scopeLink ?>">
                    <i class="fas <?= $scopeIcon ?>"></i> <?= htmlspecialchars($scopeLabel) ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <div class="rp-header-actions">
            <a class="rp-btn-ghost" href="<?= UIR ?>Court/detail/<?= $courtId ?>"><i class="fas fa-arrow-left"></i> Back to Planner</a>
            <?php if ($courtSt !== 'complete'): ?>
            <button type="button" class="rp-btn-ghost" onclick="cpOpenCompleteModal()" data-tip="Nothing reaches the permanent record until this runs">
                <i class="fas fa-stamp"></i> Finalize &amp; Complete
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="rp-context">
        <i class="fas fa-info-circle rp-context-icon"></i>
        <span>Record what actually happened at court, checked off against the printed sheet — mark each award Given or Skipped, correct the giver or rank if the ceremony diverged from plan, and add any walk-ons.</span>
    </div>

    <?php if ($courtChangedSincePrint): ?>
    <!-- Drift warning (spec §5) — the plan changed (a walk-on added, or a row
         removed) since the packet was last printed, so the numbering below no
         longer matches the paper in the recorder's hand. Dismissible per
         session, same idiom as Court_detail.tpl's cp-prev-banner. -->
    <div class="cp-rec-drift-banner" id="cp-rec-drift-banner">
        <i class="fas fa-triangle-exclamation cp-rec-drift-icon"></i>
        <span class="cp-rec-drift-text">The plan changed since this was printed<?= $lastPrintedHuman !== '' ? ' on ' . htmlspecialchars($lastPrintedHuman) : '' ?> — the paper you are holding may not match the numbering below.</span>
        <button type="button" class="cp-rec-drift-dismiss" onclick="cpRecDismissDriftBanner()" data-tip="Dismiss" aria-label="Dismiss">&times;</button>
    </div>
    <?php endif; ?>

    <div class="rp-body">
        <div class="rp-sidebar">

            <!-- Court Details (spec §5): court date, event, and recorder — the
                 same three fields 0.1/0.7 made editable via CourtAjax/update_court,
                 surfaced here as inline edit-in-place controls rather than the
                 planner's modal. Same ids/JS as before; just relocated from a
                 horizontal strip above the grid (density pass 2b — that strip
                 was a whole reclaimable band). -->
            <div class="rp-filter-card">
                <div class="rp-filter-card-header"><i class="fas fa-calendar-alt"></i> Court Details</div>
                <div class="rp-filter-card-body">
                    <div class="cp-rec-topstrip" id="cp-rec-topstrip">
                        <div class="cp-rec-strip-field">
                            <label for="cp-rec-date">Court Date</label>
                            <input type="text" id="cp-rec-date" class="cp-rec-strip-input" placeholder="Select a date…" autocomplete="off"
                                   <?= $courtSt === 'complete' ? 'disabled' : '' ?>>
                        </div>
                        <?php if (!empty($upcomingEvents)): ?>
                        <div class="cp-rec-strip-field">
                            <label for="cp-rec-event">Event</label>
                            <select id="cp-rec-event" class="cp-rec-strip-input" onchange="cpRecSaveField({EventCalendarDetailId: this.value})"
                                    <?= $courtSt === 'complete' ? 'disabled' : '' ?>>
                                <option value="0">&mdash; None &mdash;</option>
                                <?php foreach ($upcomingEvents as $ev): ?>
                                <option value="<?= (int)$ev['EventCalendarDetailId'] ?>"
                                    <?= ((int)$ev['EventCalendarDetailId'] === (int)($court['EventCalendarDetailId'] ?? 0)) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ev['Name']) ?><?= $ev['EventStart'] ? ' (' . date('M j', strtotime($ev['EventStart'])) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="cp-rec-strip-field">
                            <label for="cp-rec-recorder-text">Recorder <span style="font-weight:400;text-transform:none;letter-spacing:0"> — who records this court's grants</span></label>
                            <div class="cp-ac-wrap">
                                <input type="text" id="cp-rec-recorder-text" class="cp-rec-strip-input" placeholder="Search player name…" autocomplete="off"
                                       value="<?= htmlspecialchars($court['RecorderPersona'] ?? '') ?>"
                                       oninput="cpAcSearch(this,'cp-rec-recorder-ac','cp-rec-recorder-id',cpRecRecorderPick)"
                                       onblur="cpRecRecorderBlur()"
                                       <?= $courtSt === 'complete' ? 'disabled' : '' ?>>
                                <div class="cp-ac-dropdown" id="cp-rec-recorder-ac"></div>
                            </div>
                            <input type="hidden" id="cp-rec-recorder-id" value="<?= (int)($court['RecorderMundaneId'] ?? 0) ?>">
                        </div>
                        <span class="cp-rec-strip-status" id="cp-rec-strip-status" role="status" aria-live="polite"></span>
                    </div>
                </div>
            </div>

            <!-- About This Tool — the explanation that used to sit beside the
                 bulk button now lives here (kept, not deleted), split into its
                 three natural points; the button itself keeps a short data-tip. -->
            <div class="rp-filter-card">
                <div class="rp-filter-card-header"><i class="fas fa-info-circle"></i> About This Tool</div>
                <div class="rp-filter-card-body">
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Mark all remaining Given</span>
                        <span class="rp-col-guide-desc">Stages every still-planned award as Given, under the court's default giver.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Handle exceptions first</span>
                        <span class="rp-col-guide-desc">Mark Skipped rows — or anything unusual — before using the bulk button for the rest.</span>
                    </div>
                    <div class="rp-col-guide-item">
                        <span class="rp-col-guide-name">Safe to repeat</span>
                        <span class="rp-col-guide-desc">Safe to click again after adding walk-ons; it only ever touches rows still planned.</span>
                    </div>
                </div>
            </div>

        </div><!-- /rp-sidebar -->

        <div class="rp-table-area">

    <!-- Unfinalized-staged safeguard indicator (spec §5.3) — same idiom as the
         planner's #cp-staged-indicator, reused so the running total is visible
         while marking (Task 7 calls cpUpdateStagedIndicator() as rows are
         marked; every mark is a real server write the moment it happens). -->
    <div class="cp-staged-indicator<?= ($initialStagedCount > 0 && $courtSt !== 'complete') ? ' show' : '' ?>" id="cp-staged-indicator" role="status" aria-live="polite">
        <i class="fas fa-hourglass-half cp-si-icon"></i>
        <span class="cp-si-text"><strong><span id="cp-staged-count-n"><?= $initialStagedCount ?></span> grant<span id="cp-staged-count-s"><?= $initialStagedCount === 1 ? '' : 's' ?></span> staged</strong>, not yet finalized — Finalize to record them in the player registry.</span>
        <button class="cp-si-btn" onclick="cpOpenCompleteModal()"><i class="fas fa-stamp"></i> Finalize &amp; Complete</button>
    </div>

    <!-- Per-row marks (spec §5.1) — same column order as the printed Sheet 2
         (# · check · x · Recipient · Award · Rank · Given by · PTL), with the
         paper's separate check/x columns collapsed into one three-state
         control. Every mark is a real server write the moment it's made
         (grant_award/skip_award/unstage_award) — nothing here batches
         client-side, so a recorder who closes the laptop mid-pass loses
         nothing. Walk-ons (Task 10) land below this list. -->
    <div id="cp-rec-rows">
        <div class="cp-rec-toolbar">
            <?php
            // The label and tooltip are rebuilt by cpRecSyncBulkBtn() on load and after
            // every mark, so the count an officer reads is the count that will be staged.
            $plannedNow = 0;
            foreach ($courtAwards as $__a) {
                $__st = $__a['Status'] ?? '';
                if (!in_array($__st, ['given', 'staged', 'cancelled'], true)) {
                    $plannedNow++;
                }
            }
            ?>
            <button type="button" class="cp-btn-primary" id="cp-rec-bulk-btn" onclick="cpRecMarkAllGiven()"
                    data-tip="Stages every still-planned award as Given under the court's default giver — see About This Tool"
                    <?= ($courtSt !== 'published' || $plannedNow === 0) ? 'disabled' : '' ?>>
                <i class="fas fa-check-double"></i> <span class="cp-rec-bulk-label"><?= $plannedNow === 0 ? 'Nothing left to mark' : 'Mark ' . $plannedNow . ' remaining Given' ?></span>
            </button>
        </div>

        <?php
        $canMark = $courtSt === 'published';
        // The walk-on row (Task 10) only ever appears while a court is
        // published — CourtAjax/add_award has no status gate of its own, but a
        // row added after finalize could never be marked (every mark button
        // is already gated on the same $canMark) and would sit invisible,
        // unrecordable, forever. Empty-and-not-publishable is the one case
        // that still gets the plain empty message instead of the list shell.
        ?>
        <?php if (empty($courtAwards) && !$canMark): ?>
        <div class="cp-rec-empty">No awards on this court's plan.</div>
        <?php else: ?>
        <div class="cp-rec-list" id="cp-rec-list">
            <div class="cp-rec-row cp-rec-row-header" aria-hidden="true">
                <span class="cp-rec-c cp-rec-c-num">#</span>
                <span class="cp-rec-c cp-rec-c-mark">Mark</span>
                <span class="cp-rec-c cp-rec-c-recip">Recipient</span>
                <span class="cp-rec-c cp-rec-c-award">Award</span>
                <span class="cp-rec-c cp-rec-c-rank">Rank</span>
                <span class="cp-rec-c cp-rec-c-giver">Given by</span>
                <span class="cp-rec-c cp-rec-c-ptl" data-tip="Pass to Local — this award will be handed down to the recipient's home park to grant at their court.">To Local</span>
                <span class="cp-rec-c cp-rec-c-cite">Citation</span>
            </div>
            <?php if (empty($courtAwards)): ?>
            <div class="cp-rec-empty" style="border:none;padding:18px 16px">No awards on this court's plan yet — add the first one below.</div>
            <?php endif; ?>
            <?php
            foreach ($courtAwards as $__i => $aw):
                $caid = (int)($aw['CourtAwardId'] ?? 0);
                $mark = in_array($aw['Status'] ?? '', ['given', 'staged'], true) ? 'given'
                      : (($aw['Status'] ?? '') === 'cancelled' ? 'skipped' : 'none');
                $rowClass = $mark === 'given' ? ' cp-rec-row-given' : ($mark === 'skipped' ? ' cp-rec-row-skipped' : '');
                ?>
            <div class="cp-rec-row<?= $rowClass ?>" data-caid="<?= $caid ?>" data-rowversion="<?= (int)($aw['RowVersion'] ?? 0) ?>" data-mark="<?= $mark ?>">
                <span class="cp-rec-c cp-rec-c-num"><?= $__i + 1 ?></span>
                <span class="cp-rec-c cp-rec-c-mark cp-rec-seg" role="group" aria-label="Mark <?= htmlspecialchars($aw['Persona'] ?? 'this award') ?> — <?= htmlspecialchars($aw['AwardName'] ?? '') ?>">
                    <button type="button" class="cp-rec-seg-btn cp-rec-seg-given" aria-pressed="<?= $mark === 'given' ? 'true' : 'false' ?>" onclick="cpRecMark(<?= $caid ?>,'given')" <?= $canMark ? '' : 'disabled' ?>>Given</button>
                    <button type="button" class="cp-rec-seg-btn cp-rec-seg-skipped" aria-pressed="<?= $mark === 'skipped' ? 'true' : 'false' ?>" onclick="cpRecMark(<?= $caid ?>,'skipped')" <?= $canMark ? '' : 'disabled' ?>>Skipped</button>
                    <button type="button" class="cp-rec-seg-btn cp-rec-seg-none" aria-pressed="<?= $mark === 'none' ? 'true' : 'false' ?>" onclick="cpRecMark(<?= $caid ?>,'none')" data-tip="Clear this mark" <?= $canMark ? '' : 'disabled' ?>>&mdash;</button>
                </span>
                <span class="cp-rec-c cp-rec-c-recip" data-tip="<?= htmlspecialchars($aw['Persona'] ?? '') ?>">
                    <span class="cp-rec-c-label">Recipient</span>
                    <?= htmlspecialchars($aw['Persona'] ?? '') ?><?php if (!empty($aw['ParkAbbrev'])): ?> <span class="cp-rec-park"><?= htmlspecialchars($aw['ParkAbbrev']) ?></span><?php endif; ?>
                </span>
                <span class="cp-rec-c cp-rec-c-award" data-tip="<?= htmlspecialchars($aw['AwardName'] ?? '') ?>">
                    <span class="cp-rec-c-label">Award</span>
                    <?= htmlspecialchars($aw['AwardName'] ?? '') ?>
                </span>
                <span class="cp-rec-c cp-rec-c-rank">
                    <span class="cp-rec-c-label">Rank</span>
                    <?php if (!empty($aw['IsLadder'])):
                        $initRank = (int)($aw['Rank'] ?? 0) > 0 ? (int)$aw['Rank'] : 1;
                    ?>
                    <?php $rankLocked = !$canMark || $mark !== 'none'; ?>
                    <button type="button" class="ladder-rank cp-rec-rank-chip" id="cp-rec-rank-chip-<?= $caid ?>"
                            data-lvl="<?= min($initRank, 10) ?>" data-rank="<?= $initRank ?>"
                            data-award="<?= htmlspecialchars($aw['AwardName'] ?? '') ?>"
                            onclick="cpRecOpenRankPop(<?= $caid ?>, this)"
                            data-tip="<?= $mark !== 'none' ? 'Clear this row&#39;s mark (&mdash;) to change the rank' : 'Change the rank granted' ?>"
                            <?= $rankLocked ? 'disabled' : '' ?>>Rank <?= $initRank ?></button>
                    <?php else: ?>&mdash;<?php endif; ?>
                </span>
                <span class="cp-rec-c cp-rec-c-giver">
                    <span class="cp-rec-c-label">Given by</span>
                    <?php
                    $rowGiverId = (int)($aw['GivenByMundaneId'] ?? 0);
                    $rowGiverPersona = $rowGiverId > 0 ? $aw['GivenByPersona'] : ($giverOptions['default']['persona'] ?? '');
                    $rowGiverId = $rowGiverId > 0 ? $rowGiverId : (int)($giverOptions['default']['mundane_id'] ?? 0);
                    ?>
                    <?php $giverLocked = !$canMark || $mark !== 'none'; ?>
                    <button type="button" class="cp-rec-giver-chip" id="cp-rec-giver-chip-<?= $caid ?>"
                            data-mundane-id="<?= $rowGiverId ?>" data-persona="<?= htmlspecialchars($rowGiverPersona) ?>"
                            onclick="cpRecOpenGiverPop(<?= $caid ?>, this)"
                            data-tip="<?= $mark !== 'none' ? 'Clear this row&#39;s mark (&mdash;) to change the giver' : 'Change who gave this award' ?>"
                            <?= $giverLocked ? 'disabled' : '' ?>><?= htmlspecialchars($rowGiverPersona !== '' ? $rowGiverPersona : '—') ?></button>
                </span>
                <span class="cp-rec-c cp-rec-c-ptl">
                    <span class="cp-rec-c-label">To Local</span>
                    <?php if (!empty($aw['PassToLocal'])): ?><i class="fas fa-arrow-down" data-tip="Pass to Local" aria-label="Pass to Local"></i><?php else: ?>&mdash;<?php endif; ?>
                </span>
                <?php
                // Citation (Task 9) — becomes ork_awards.note on commit (spec 6.1
                // precedence: PublicComment, else the recommendation's reason). Truncate
                // BEFORE escaping so htmlspecialchars() never splits an entity mid-code.
                // Walk-on rows (Task 10) pass IsWalkOn=true to start expanded — a walk-on
                // has no recommendation and no planned citation, so it exists nowhere
                // else in the system and must not be missable as a truncated preview.
                //
                // Three states, not two (bugfix: a rec-derived award with no public
                // comment yet was reading as "No citation" even though the commit-time
                // precedence above means the recommendation's reason IS what publishes).
                // 1. Own    — PublicComment is set; render as-is (unchanged from before).
                // 2. Inherited — PublicComment empty, RecReason non-empty: show the
                //    rec's text, muted + tagged "from recommendation" so it reads as
                //    borrowed rather than authored, with a data-tip spelling out that
                //    it publishes as-is unless edited.
                // 3. Genuinely empty — both blank. Only this state says "No citation".
                // The textarea is pre-filled with the same effective text (own citation,
                // else the rec reason) so opening an inherited row edits FROM the rec
                // text (mirrors Court_detail.tpl's "(Start from Rec)" convention) rather
                // than retyping it. This does NOT write public_comment anywhere — the
                // save path (cpRecCiteBlur) and the grant-citation reader
                // (cpRecCitationFor) both compare the live value against data-prefill
                // (the effective text shown, not the raw saved value) and treat an
                // unedited textarea as nothing-to-save, so an officer's silence is never
                // recorded as authorship — see the JS comments below.
                $citation = (string)($aw['PublicComment'] ?? '');
                $recReason = (string)($aw['RecReason'] ?? '');
                $citationOwn = $citation !== '';
                $citationInherited = !$citationOwn && $recReason !== '';
                $citationEmpty = !$citationOwn && !$citationInherited;
                $citationDisplay = $citationOwn ? $citation : $recReason;
                $citationTrunc = mb_strlen($citationDisplay) > 90 ? mb_substr($citationDisplay, 0, 90) . '…' : $citationDisplay;
                $citeExpanded = !empty($aw['IsWalkOn']);
                $citeTip = $citationOwn
                    ? 'Edit this citation'
                    : ($citationInherited
                        ? 'Inherited from the recommendation — this text will be published as the citation unless you edit it. Click to edit.'
                        : 'Add a citation for the public record');
                ?>
                <div class="cp-rec-cite" data-caid="<?= $caid ?>">
                    <button type="button" class="cp-rec-cite-preview<?= $citeExpanded ? ' cp-rec-hidden' : '' ?>"
                            id="cp-rec-cite-preview-<?= $caid ?>"
                            onclick="cpRecCiteExpand(<?= $caid ?>)"
                            data-tip="<?= htmlspecialchars($citeTip) ?>">
                        <i class="fas fa-quote-left cp-rec-cite-icon" aria-hidden="true"></i>
                        <span class="cp-rec-c-label">Citation</span>
                        <span class="cp-rec-cite-tag<?= $citationInherited ? '' : ' cp-rec-hidden' ?>" id="cp-rec-cite-tag-<?= $caid ?>">from recommendation</span>
                        <span class="cp-rec-cite-preview-text<?= $citationEmpty ? ' cp-rec-cite-empty' : '' ?><?= $citationInherited ? ' cp-rec-cite-inherited' : '' ?>"><?= $citationEmpty ? 'No citation — click to add one' : htmlspecialchars($citationTrunc) ?></span>
                        <i class="fas fa-pen cp-rec-cite-edit-icon" aria-hidden="true"></i>
                    </button>
                    <textarea class="cp-rec-cite-textarea<?= $citeExpanded ? '' : ' cp-rec-hidden' ?>"
                              id="cp-rec-cite-textarea-<?= $caid ?>"
                              data-saved="<?= htmlspecialchars($citation) ?>"
                              data-prefill="<?= htmlspecialchars($citationDisplay) ?>"
                              placeholder="Citation for the public record — becomes this award's permanent public note when the court is finalized"
                              maxlength="1000"
                              onblur="cpRecCiteBlur(<?= $caid ?>)"
                              <?= $canMark ? '' : 'readonly' ?>><?= htmlspecialchars($citationDisplay) ?></textarea>
                    <span class="cp-rec-cite-status" id="cp-rec-cite-status-<?= $caid ?>"></span>
                </div>
            </div>
            <?php endforeach;
unset($__i, $aw, $caid, $mark, $rowClass); ?>
            <?php if ($canMark): ?>
            <!-- Walk-on row (Task 10) — the permanent blank row at the foot of the
                 list. A faster path to Court_detail.tpl's Add Award / Add Title
                 modals, not a new capability: those two buttons stay on the planner
                 page, and this replaces them here. Recipient + one combined
                 award/title search (fetch_award_option_groups already covers
                 titles), a rank chip that opens the SAME shared #cp-rec-rank-pop
                 popover every other row uses (caid 'walkon'), a Pass-to-Local
                 checkbox, and an always-expanded citation (Task 9's IsWalkOn
                 hook — a walk-on has no planned citation anywhere else in the
                 system). Internal Notes are deliberately omitted; they stay
                 editable on the planner page. Enter, from any field, commits via
                 CourtAjax/add_award and re-focuses the recipient search on a fresh
                 blank row (cpRecWalkOnKeydown/cpRecWalkOnCommit), so three
                 walk-ons in a row never need the mouse. -->
            <div class="cp-rec-row cp-rec-row-walkon" id="cp-rec-walkon-row" data-caid="0">
                <span class="cp-rec-c cp-rec-c-num" aria-hidden="true"><i class="fas fa-plus"></i></span>
                <span class="cp-rec-c cp-rec-c-mark">
                    <button type="button" class="cp-rec-walkon-add-btn" id="cp-rec-walkon-add-btn" onclick="cpRecWalkOnCommit()" data-tip="Add this walk-on to the court record">
                        <i class="fas fa-plus"></i> Add
                    </button>
                </span>
                <span class="cp-rec-c cp-rec-c-recip">
                    <span class="cp-rec-c-label">Recipient</span>
                    <div class="cp-ac-wrap">
                        <input type="text" id="cp-rec-walkon-persona" class="cp-rec-walkon-input" placeholder="Search player name…" autocomplete="off"
                               oninput="cpAcSearch(this,'cp-rec-walkon-ac','cp-rec-walkon-mundane-id')"
                               onkeydown="cpRecWalkOnKeydown(event,'recipient')">
                        <div class="cp-ac-dropdown" id="cp-rec-walkon-ac"></div>
                    </div>
                    <input type="hidden" id="cp-rec-walkon-mundane-id" value="">
                </span>
                <span class="cp-rec-c cp-rec-c-award">
                    <span class="cp-rec-c-label">Award</span>
                    <div class="cp-ac-wrap">
                        <input type="text" id="cp-rec-walkon-award" class="cp-rec-walkon-input" placeholder="Search awards &amp; titles…" autocomplete="off" data-ladder="0"
                               oninput="cpRecWalkOnAwardSearch()" onfocus="cpRecWalkOnAwardSearch()"
                               onkeydown="cpRecWalkOnKeydown(event,'award')">
                        <div class="cp-ac-dropdown" id="cp-rec-walkon-award-ac"></div>
                    </div>
                    <input type="hidden" id="cp-rec-walkon-award-id" value="">
                </span>
                <span class="cp-rec-c cp-rec-c-rank">
                    <span class="cp-rec-c-label">Rank</span>
                    <button type="button" class="ladder-rank cp-rec-rank-chip cp-rec-hidden" id="cp-rec-rank-chip-walkon"
                            data-lvl="1" data-rank="1" data-award=""
                            onclick="cpRecOpenRankPop('walkon', this)"
                            onkeydown="cpRecWalkOnKeydown(event,'rank')"
                            data-tip="Change the rank granted">Rank 1</button>
                </span>
                <span class="cp-rec-c cp-rec-c-giver">
                    <span class="cp-rec-c-label">Given by</span>
                    &mdash;
                </span>
                <span class="cp-rec-c cp-rec-c-ptl">
                    <label class="cp-rec-ptl-label" for="cp-rec-walkon-ptl" data-tip="Pass to Local">
                        <input type="checkbox" id="cp-rec-walkon-ptl" class="cp-rec-ptl-check" onkeydown="cpRecWalkOnKeydown(event,'ptl')">
                        <span class="cp-rec-c-label" style="margin:0">To Local</span>
                    </label>
                </span>
                <div class="cp-rec-cite" data-caid="walkon">
                    <textarea class="cp-rec-cite-textarea" id="cp-rec-walkon-cite"
                              placeholder="Citation for the public record — becomes this award's permanent public note when the court is finalized"
                              maxlength="1000"
                              onkeydown="cpRecWalkOnKeydown(event,'cite')"></textarea>
                    <span class="cp-rec-cite-status" id="cp-rec-walkon-status"></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

        </div><!-- /rp-table-area -->
    </div><!-- /rp-body -->
</div><!-- /rp-root -->

<!-- Given-by popover (Task 8) — a single shared floating panel, not one per row (22+
     rows would mean 22+ copies of the giver pills + search). Reuses cpGiverOptions'
     pills/search idiom from the grant modal's .cp-giver-pill / .cp-ac-* chrome (shared
     court-planner.css), positioned fixed next to the chip that opened it (same idiom as
     Court_detail.tpl's #cp-note-popup). "Apply to the rest below" lives here too — it
     acts on whichever row is currently open, and only updates rows below it in the
     list; it never marks a row. -->
<div class="cp-rec-pop" id="cp-rec-giver-pop" role="dialog" aria-modal="false" aria-labelledby="cp-rec-giver-pop-title">
    <div class="cp-rec-pop-header">
        <span class="cp-rec-pop-title" id="cp-rec-giver-pop-title">Given by</span>
        <button type="button" class="cp-rec-pop-close" onclick="cpRecGiverPopClose()" aria-label="Close">&times;</button>
    </div>
    <div class="cp-giver-pills" id="cp-rec-giver-pop-pills"></div>
    <div class="cp-ac-wrap">
        <input type="text" id="cp-rec-giver-pop-text" placeholder="Search for another giver…" autocomplete="off"
               oninput="cpRecGiverPopInput(this)">
        <div class="cp-ac-dropdown" id="cp-rec-giver-pop-ac"></div>
    </div>
    <input type="hidden" id="cp-rec-giver-pop-caid" value="0">
    <input type="hidden" id="cp-rec-giver-pop-hidden" value="0">
    <button type="button" class="cp-rec-pop-apply-btn" id="cp-rec-apply-rest-btn" onclick="cpRecApplyRest()">
        <i class="fas fa-arrow-down"></i> Apply to the rest below
    </button>
</div>

<!-- Rank popover (Task 8) — same shared-floating-panel idiom, reusing the ad-hoc
     modal's .cp-rank-pill / .ladder-rank chrome (shared court-planner.css). Non-ladder
     rows never get a rank control (getCourtAwards' IsLadder gate on the PHP side). -->
<div class="cp-rec-pop" id="cp-rec-rank-pop" role="dialog" aria-modal="false" aria-labelledby="cp-rec-rank-pop-title">
    <div class="cp-rec-pop-header">
        <span class="cp-rec-pop-title" id="cp-rec-rank-pop-title">Rank</span>
        <button type="button" class="cp-rec-pop-close" onclick="cpRecRankPopClose()" aria-label="Close">&times;</button>
    </div>
    <div class="cp-rank-pills" id="cp-rec-rank-pop-pills"></div>
    <input type="hidden" id="cp-rec-rank-pop-caid" value="0">
</div>

<!-- Finalize & Complete modal — identical flow to the planner's (spec §6.6). -->
<div class="cp-overlay" id="cp-complete-modal">
    <div class="cp-modal cp-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cp-complete-modal-title">
        <div class="cp-modal-header">
            <h3 id="cp-complete-modal-title"><i class="fas fa-stamp" style="margin-right:8px;color:#276749"></i>Complete Court</h3>
            <button class="cp-modal-close" onclick="cpCloseCompleteModal()" aria-label="Close">&times;</button>
        </div>
        <div class="cp-modal-body">
            <p class="cp-modal-lead" id="cp-complete-lead"></p>
            <div class="cp-complete-opts" id="cp-complete-opts"></div>
            <div class="cp-complete-fail" id="cp-complete-fail"></div>
        </div>
        <div class="cp-modal-footer">
            <button class="cp-btn-outline" onclick="cpCloseCompleteModal()">Go Back</button>
        </div>
    </div>
</div>

<?php endif; ?>
<?php if (!$error): ?>
<script>
(function() {
    var uir      = '<?= UIR ?>';
    var courtId  = <?= $courtId ?>;
    var kidId    = <?= (int)($court['KingdomId'] ?? 0) ?>;

    // Config for the shared scoped player-search autocomplete (court-planner.js).
    // Read at call time, so this can be set anywhere before the first keystroke.
    window.cpAcConfig = { uir: uir, kingdomId: kidId };
    var courtStatus = <?= json_encode($courtSt) ?>;

    // Same JS globals Court_detail.tpl emits, so Tasks 7-10 reuse its idioms
    // rather than inventing new ones. courtId is exported explicitly — a
    // sibling file once left this off and every later call site that reached
    // for window.courtId silently posted CourtId=undefined.
    window.courtId     = courtId;
    var courtAwards     = window.courtAwards = <?= json_encode($courtAwards) ?>;
    var courtMeta        = window.courtMeta   = {
        name: <?= json_encode($court['Name'] ?? '') ?>,
        date: <?= json_encode($court['CourtDate'] ?? '') ?>,
        eventId: <?= (int)($court['EventCalendarDetailId'] ?? 0) ?>,
        eventName: <?= json_encode($court['EventName'] ?? '') ?>,
        recorderId: <?= (int)($court['RecorderMundaneId'] ?? 0) ?>,
        recorderPersona: <?= json_encode($court['RecorderPersona'] ?? '') ?>
    };
    var cpGiverOptions = window.cpGiverOptions = <?= json_encode($giverOptions) ?>;
    var cpMode          = window.cpMode        = <?= json_encode($courtMode) ?>;
    var cpStagedCount    = window.cpStagedCount = <?= $initialStagedCount ?>;

    // Ad-hoc award/title picker options for the walk-on row (Task 10) — flattened
    // from Model_Award::fetch_award_option_groups(), the SAME source
    // Court_detail.tpl's Add Award/Add Title modals use, so the two can never
    // drift apart. There is no live AwardAjax search endpoint in this codebase;
    // Court_detail.tpl's own ad-hoc picker already filters this same shape of
    // list client-side, so this mirrors that rather than inventing a new pattern.
    // Deliberately ONE flat list covering both awards and titles together (the
    // brief is explicit: no second title-only entry point) — group order is
    // whatever order the groups first appear in this list, so it never needs to
    // be duplicated as a separate hardcoded constant on the client.
    var cpRecAwardOptions = <?= json_encode((function ($groups) {
        $flat = [];
        foreach (($groups ?? []) as $g) {
            foreach (($g['options'] ?? []) as $o) {
                $flat[] = [
                    'id'     => (int)$o['KingdomAwardId'],
                    'name'   => $o['Name'],
                    'ladder' => (bool)$o['IsLadder'],
                    'title'  => (bool)$o['IsTitle'],
                    'group'  => $g['label'],
                ];
            }
        }
        return $flat;
    })($awardOptions)) ?>;

    // ---- Utilities (mirrors Court_detail.tpl's own copies) ----
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    window.esc = esc;
    function gid(id) { return document.getElementById(id); }

    function cpGlobalError(msg) {
        var t = document.createElement('div');
        t.className = 'cp-toast';
        t.setAttribute('role', 'alert');
        t.setAttribute('aria-live', 'assertive');
        t.textContent = msg || 'Something went wrong. Please try again.';
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 5000);
    }
    window.cpGlobalError = cpGlobalError;

    // Informational toast (calm navy, .cp-toast-info) — distinct from cpGlobalError's
    // alarming red — for the S5 optimistic-lock "this row changed" notice (spec §0.4):
    // nothing failed, another recorder just got there first. Same idiom as
    // Court_detail.tpl's own cpNotice(), which is local to ITS IIFE and not exported —
    // this view needs its own copy.
    function cpNotice(msg) {
        var t = document.createElement('div');
        t.className = 'cp-toast cp-toast-info';
        t.setAttribute('role', 'status');
        t.setAttribute('aria-live', 'polite');
        t.textContent = msg || '';
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 4000);
    }
    window.cpNotice = cpNotice;

    // ---- Non-blocking dialogs (no native alert()/confirm()/prompt() anywhere) ----
    // cpAlert/cpConfirm are now thin aliases over the app-wide orkAlert/orkConfirm in
    // orkui.js — one implementation (Promise-returning, focus-trapping, focus-restoring)
    // instead of the three hand-rolled copies this module and the Recs Manager grew, and
    // it makes the house rule greppable: `confirm(` with no ork/cp prefix is a violation.
    // The tnConfirm and cpFallbackConfirm branches below stay as a defensive ladder —
    // this page must never lose its confirm dialog because orkui.js is stale in a cache.
    function cpAlert(msg, title) {
        if (typeof window.orkAlert === 'function') { window.orkAlert({ title: title || 'Court Planner', body: msg, confirmLabel: 'OK' }); return; }
        if (typeof tnConfirm === 'function') tnConfirm({ title: title || 'Court Planner', body: msg, confirmLabel: 'OK' });
        else cpGlobalError(msg);
    }
    window.cpAlert = cpAlert;

    function cpFallbackConfirm(opts) {
        opts = opts || {};
        var ov = document.createElement('div');
        ov.className = 'cp-overlay';
        ov.style.display = 'flex';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-modal', 'true');
        ov.innerHTML =
            '<div class="cp-modal cp-modal-sm">' +
              '<div class="cp-modal-header"><h3>' + esc(opts.title || 'Confirm') + '</h3>' +
                '<button class="cp-modal-close" type="button" aria-label="Close" data-cp-cancel>&times;</button></div>' +
              '<div class="cp-modal-body"><p class="cp-modal-lead">' + esc(opts.body || '') + '</p></div>' +
              '<div class="cp-modal-footer">' +
                '<button class="cp-btn-outline" type="button" data-cp-cancel>' + esc(opts.cancelLabel || 'Cancel') + '</button>' +
                '<button class="cp-btn-primary" type="button" data-cp-ok' + (opts.danger ? ' style="background:#c53030"' : '') + '>' + esc(opts.confirmLabel || 'OK') + '</button>' +
              '</div>' +
            '</div>';
        function close() { ov.remove(); }
        ov.addEventListener('click', function(e) {
            if (e.target === ov || e.target.closest('[data-cp-cancel]')) { close(); return; }
            if (e.target.closest('[data-cp-ok]')) { close(); if (typeof opts.onConfirm === 'function') opts.onConfirm(); }
        });
        document.body.appendChild(ov);
        var okBtn = ov.querySelector('[data-cp-ok]');
        if (okBtn) setTimeout(function() { okBtn.focus(); }, 30);
    }
    function cpConfirm(opts) {
        opts = opts || {};
        if (typeof window.orkConfirm === 'function') {
            // orkConfirm resolves true/false; every call site here passes an onConfirm
            // callback, so the two shapes are bridged once, here, instead of at ~20 sites.
            // onConfirm is withheld from the options handed over so a shared helper that
            // also honours it cannot fire the callback twice.
            var passed = {};
            Object.keys(opts).forEach(function(k) { if (k !== 'onConfirm') passed[k] = opts[k]; });
            var p = window.orkConfirm(passed);
            if (p && typeof p.then === 'function') {
                p.then(function(ok) { if (ok && typeof opts.onConfirm === 'function') opts.onConfirm(); });
            }
            return p;
        }
        if (typeof tnConfirm === 'function') tnConfirm(opts);
        else cpFallbackConfirm(opts);
    }
    window.cpConfirm = cpConfirm;

    function post(url, fd, silent) {
        return fetch(uir + url, {
            method: 'POST', body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).catch(function(err) {
            if (!silent) cpGlobalError('Could not reach the server. Please check your connection and try again.');
            return { status: -1, error: 'Request failed. Please try again.', _postFailed: true };
        });
    }
    window.cpPost = post;

    // ---- Scroll lock (identical idiom to Court_detail.tpl) ----
    var cpPrevRootOverflow = null;
    function cpSyncScrollLock() {
        var open = false;
        document.querySelectorAll('.cp-overlay[id]').forEach(function(o) {
            if (o.style.display === 'flex') open = true;
        });
        var root = document.documentElement;
        if (open) {
            if (cpPrevRootOverflow === null) {
                cpPrevRootOverflow = root.style.overflowY;
                root.style.overflowY = 'hidden';
            }
        } else if (cpPrevRootOverflow !== null) {
            root.style.overflowY = cpPrevRootOverflow;
            cpPrevRootOverflow = null;
        }
    }

    // ---- Autocomplete ----
    // cpPositionAc / cpAcBind / cpAcUnbind / cpAcDismiss / cpHideAcDropdowns / cpAcSearch
    // now live in the shared default/script/court-planner.js (linked at the top of this
    // template), which Court_detail.tpl carried a near-verbatim second copy of. The
    // shared cpAcSearch keeps this page's optional 4th argument, onPick — the strip
    // fields auto-save on pick because the hidden id is cleared on every keystroke, so
    // saving on blur alone would post a stale/empty id before the click that fills it
    // back in ever lands. It reads window.cpAcConfig (set below) for the UIR and the
    // COURT's kingdom id, and still publishes cpAcOpenDrop on window so this file's
    // `if (cpAcOpenDrop === drop) cpAcUnbind();` checks keep working.

    // ---- Top strip: date / event / recorder, each posting to CourtAjax/update_court
    // (spec §5, §3 0.1). Partial by design — only the changed field is sent, and a
    // save where nothing actually differs still reports success (Court::updateCourt
    // reads the row back rather than trusting changed-row count), so this never
    // raises an error toast for a no-op save. ----
    window.cpRecSaveField = function(fields) {
        var status = gid('cp-rec-strip-status');
        var fd = new FormData();
        fd.append('CourtId', courtId);
        Object.keys(fields).forEach(function(k) { fd.append(k, fields[k]); });
        if (status) { status.textContent = 'Saving…'; status.className = 'cp-rec-strip-status'; }
        post('CourtAjax/update_court', fd, true).then(function(d) {
            if (d.status === 0) {
                if ('CourtDate' in fields) courtMeta.date = fields.CourtDate;
                if ('EventCalendarDetailId' in fields) courtMeta.eventId = parseInt(fields.EventCalendarDetailId, 10) || 0;
                if ('RecorderMundaneId' in fields) courtMeta.recorderId = parseInt(fields.RecorderMundaneId, 10) || 0;
                if (status) {
                    status.textContent = 'Saved';
                    status.className = 'cp-rec-strip-status cp-rec-strip-saved';
                    setTimeout(function() { if (status.textContent === 'Saved') status.textContent = ''; }, 2500);
                }
            } else if (status) {
                status.textContent = d.error || 'Could not save.';
                status.className = 'cp-rec-strip-status cp-rec-strip-error';
            }
        });
    };

    window.cpRecRecorderPick = function(p) {
        cpRecSaveField({ RecorderMundaneId: p.MundaneId });
    };
    // cpAcSearch clears the hidden id on every keystroke (see comment above), so a
    // blur that merely follows a touch-then-leave of the prefilled box must not post
    // 0 and clear the recorder. Only save-to-clear when the box was deliberately
    // emptied; a typed-but-unpicked value is left untouched, same rule the planner's
    // Edit Details modal uses for this exact field.
    window.cpRecRecorderBlur = function() {
        var id  = gid('cp-rec-recorder-id').value;
        var txt = gid('cp-rec-recorder-text').value.trim();
        if (!id && !txt) {
            cpRecSaveField({ RecorderMundaneId: 0 });
        }
    };

    var cpRecFp = flatpickr('#cp-rec-date', {
        dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', allowInput: true,
        altInputClass: 'cp-rec-strip-input',
        onClose: function(selectedDates, dateStr) {
            if (dateStr !== (courtMeta.date || '')) {
                cpRecSaveField({ CourtDate: dateStr });
            }
        }
    });
    cpRecFp.setDate(courtMeta.date || null, false);

    // ---- Complete-court modal (identical flow to Court_detail.tpl's, spec §6.6) ----
    // Completing is one-way in this release (no amend/void path yet, and a complete
    // court closes every guarded write) — say so before the officer commits. Same
    // note, same wording, as the planner's Complete modal.
    var CP_ONE_WAY_NOTE = '<span class="cp-one-way-note"><i class="fas fa-exclamation-triangle"></i> ' +
        'Completing is final — a complete court cannot be reopened, edited or re-recorded, ' +
        'and grants written to the registry can only be corrected by revoking the award on ' +
        'the player\u2019s record. Resolve anything questionable first.</span>';

    window.cpOpenCompleteModal = function() {
        var unresolved = 0, staged = 0;
        courtAwards.forEach(function(a) {
            if (a.Status === 'planned' || a.Status === 'announced') unresolved++;
            else if (a.Status === 'staged') staged++;
        });
        var lead = gid('cp-complete-lead');
        var opts = gid('cp-complete-opts');
        var fail = gid('cp-complete-fail');
        fail.style.display = 'none'; fail.innerHTML = '';
        opts.innerHTML = '';
        if (unresolved > 0) {
            lead.innerHTML = '<strong>' + unresolved + '</strong> award' + (unresolved === 1 ? ' is' : 's are') +
                ' still unresolved (not granted or skipped)' +
                (staged > 0 ? ', and <strong>' + staged + '</strong> grant' + (staged === 1 ? ' is' : 's are') + ' staged to finalize' : '') +
                '. How would you like to complete this court?' + CP_ONE_WAY_NOTE;
            opts.innerHTML =
                '<div class="cp-complete-opt cp-co-danger" onclick="cpDoFinalize(1)"><i class="fas fa-forward"></i><div>' +
                    '<div class="cp-co-title">Skip Remaining Awards</div>' +
                    '<div class="cp-co-desc">Mark the ' + unresolved + ' unresolved award' + (unresolved === 1 ? '' : 's') + ' as skipped, then finalize the staged grants and complete.</div></div></div>' +
                '<div class="cp-complete-opt cp-co-neutral" onclick="cpDoFinalize(0)"><i class="fas fa-check"></i><div>' +
                    '<div class="cp-co-title">Leave As-Is and Close</div>' +
                    '<div class="cp-co-desc">Finalize the staged grants and complete. Unresolved awards are left untouched and resurface on the next court.</div></div></div>';
        } else if (staged > 0) {
            lead.innerHTML = 'Finalize <strong>' + staged + '</strong> staged grant' + (staged === 1 ? '' : 's') +
                ' and complete this court? This records ' + (staged === 1 ? 'it' : 'them') + ' in the player registry.';
            lead.innerHTML += CP_ONE_WAY_NOTE;
            opts.innerHTML =
                '<div class="cp-complete-opt cp-co-primary" onclick="cpDoFinalize(0)"><i class="fas fa-stamp"></i><div>' +
                    '<div class="cp-co-title">Finalize &amp; Complete</div>' +
                    '<div class="cp-co-desc">Commit ' + staged + ' staged grant' + (staged === 1 ? '' : 's') + ' to the permanent record and mark the court complete.</div></div></div>';
        } else {
            lead.innerHTML = 'There are no staged grants or unresolved awards. Mark this court complete?';
            lead.innerHTML += CP_ONE_WAY_NOTE;
            opts.innerHTML =
                '<div class="cp-complete-opt cp-co-primary" onclick="cpDoFinalize(0)"><i class="fas fa-check"></i><div>' +
                    '<div class="cp-co-title">Complete Court</div>' +
                    '<div class="cp-co-desc">Close out this court.</div></div></div>';
        }
        gid('cp-complete-modal').style.display = 'flex';
        cpSyncScrollLock();
    };
    window.cpCloseCompleteModal = function() {
        var m = gid('cp-complete-modal'); if (m) m.style.display = 'none';
        cpSyncScrollLock();
    };
    window.cpDoFinalize = function(skipRemaining) {
        var opts = gid('cp-complete-opts');
        function lockOpts(on) {
            opts.querySelectorAll('.cp-complete-opt').forEach(function(o) {
                o.style.pointerEvents = on ? 'none' : '';
                o.style.opacity = on ? '.6' : '';
            });
        }
        lockOpts(true);
        var fd = new FormData();
        fd.append('CourtId', courtId);
        fd.append('SkipRemaining', skipRemaining ? 1 : 0);
        post('CourtAjax/finalize_court', fd).then(function(d) {
            if (d._postFailed) { lockOpts(false); return; }
            if (d.status !== 0) {
                var f = gid('cp-complete-fail');
                f.textContent = d.error || 'Could not finalize.';
                f.style.display = 'block';
                lockOpts(false);
                return;
            }
            var dupes = (d.duplicates || []).length;
            if (d.completed) {
                if (dupes) {
                    cpAlert(dupes + ' award line' + (dupes === 1 ? ' was' : 's were') +
                        ' skipped because the same recipient, award and rank had already been ' +
                        'granted by another line in this court — the honor is recorded once.',
                        'Duplicate lines skipped');
                }
                location.reload();
                return;
            }
            var f = gid('cp-complete-fail');
            var msg = '<strong>' + (d.committed || 0) + ' grant' + (d.committed === 1 ? '' : 's') + ' recorded</strong>' +
                (dupes ? ', ' + dupes + ' duplicate line' + (dupes === 1 ? '' : 's') + ' skipped' : '') + ', but ' +
                (d.failed ? d.failed.length : 0) + ' could not be committed and remain staged:';
            msg += '<ul style="margin:6px 0 0;padding-left:18px">';
            (d.failed || []).forEach(function(fl) {
                var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(fl.court_award_id); });
                var who = a ? (a.Persona + ' — ' + a.AwardName) : ('Award #' + fl.court_award_id);
                msg += '<li>' + esc(who) + ': ' + esc(fl.error || 'error') + '</li>';
            });
            msg += '</ul>';
            f.innerHTML = msg;
            f.style.display = 'block';
            lockOpts(false);
        });
    };

    // Same idiom as Court_detail.tpl: normalizes the staged-safeguard banner's
    // wording to the current mode on load, and gives Task 7 a ready call site
    // to update the running total as rows are marked Given/Skipped.
    window.cpUpdateStagedIndicator = function(count) {
        cpStagedCount = window.cpStagedCount = count;
        var ind = gid('cp-staged-indicator');
        if (!ind) return;
        var txt = ind.querySelector('.cp-si-text');
        var btn = ind.querySelector('.cp-si-btn');
        var plural = count === 1 ? '' : 's';
        if (txt) {
            if (cpMode === 'plan') {
                txt.innerHTML = '<strong>' + count + ' grant' + plural + ' staged</strong>, not yet finalized — ' +
                    'Finalize to record them in the player registry.';
            } else {
                txt.innerHTML = '<strong>' + count + ' to record on Complete</strong> — ' +
                    'these grants are written to the player registry when you complete this court.';
            }
        }
        if (btn) {
            btn.innerHTML = cpMode === 'plan'
                ? '<i class="fas fa-stamp"></i> Finalize &amp; Complete'
                : '<i class="fas fa-check"></i> Complete Court';
        }
        ind.classList.toggle('show', count > 0 && courtStatus !== 'complete');
    };
    cpUpdateStagedIndicator(cpStagedCount);

    // ---- Per-row marks (spec §5.1) ----
    // cpRecGiverFor/cpRecRankFor/cpRecCitationFor: cpRecMark reads whatever the
    // officer left for each, so a value the officer changed sticks even if they
    // never touch it again. Citation is the odd one out — its live value lives in
    // the textarea itself (always present in the DOM, just hidden behind the
    // preview when collapsed), not in a separate JS map, so cpRecCitationFor
    // always returns whatever's actually typed right now, saved or not — the same
    // guarantee grant_award needs when Given is clicked mid-edit (see cpRecMark).
    //
    // Bugfix: an inherited (state 2) row's textarea is pre-filled with the
    // recommendation's reason (see cpRecCiteExpand/the PHP render) purely for
    // display/editing — that text was never saved as PublicComment. If the value
    // still matches data-prefill (what's currently shown, whether authored or
    // just borrowed from the rec), nothing was actually typed, so this returns
    // the true saved value (data-saved — '' for a never-touched inherited row)
    // instead of the borrowed rec text. Otherwise the officer clicked Given right
    // after opening an inherited citation without editing it would silently
    // promote the rec's wording into an authored public_comment via grant_award's
    // stage write — exactly the "officer's silence recorded as authorship" the
    // fix must not do.
    window.cpRecCitationFor = function(caid) {
        var ta = gid('cp-rec-cite-textarea-' + caid);
        if (ta) {
            var prefill = ta.dataset.prefill !== undefined ? ta.dataset.prefill : ta.dataset.saved;
            return (ta.value === prefill) ? (ta.dataset.saved || '') : ta.value;
        }
        var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
        return a ? (a.PublicComment || '') : '';
    };

    // Looks up the recommendation's reason for a row from the same courtAwards
    // data the initial render used (RecReason — Court::getCourtAwards() has
    // always returned this; the bug was the view never reading it). Used to
    // determine/redisplay the inherited (state 2) citation state after an edit.
    function cpRecCiteRecReason(caid) {
        var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
        return a ? (a.RecReason || '') : '';
    }

    // caid -> in-flight Promise for a citation save. Exists so cpRecMark (below) can
    // wait out a save that's already in flight instead of racing it: clicking Given
    // right after typing a citation blurs the textarea (starting a cpRecCiteBlur
    // save) BEFORE the click handler runs, so without this a grant_award request
    // could reach the server with a RowVersion the citation save is about to bump
    // out from under it — the same misleading "this row changed" conflict class the
    // giver/rank chip lock (fix round 1) exists to avoid, just on the write side.
    var cpRecCiteInFlight = {};

    function cpRecCiteTruncate(s, n) {
        return s.length > n ? s.slice(0, n) + '…' : s;
    }

    // Collapses back to the preview line, refreshing its truncated text/empty-state
    // from whatever's currently in the textarea (used after a successful save AND
    // when a blur finds nothing changed — no need to round-trip the server either
    // way). Uses textContent, never innerHTML, so a citation containing & < > can
    // never mangle the markup or get double-escaped on the next edit.
    //
    // Three states (bugfix): an own (SAVED, non-empty) value wins outright; a
    // blank saved value falls back to the row's RecReason (still "inherited" —
    // the commit-time precedence would fall back too), and only truly shows "No
    // citation" when both are blank. Deliberately keys off data-saved (the true
    // persisted PublicComment), NOT the live ta.value: cpRecCiteExpand pre-fills
    // ta.value with the borrowed rec text for an inherited row, so if this ran
    // off ta.value instead, collapsing right after an unedited expand+blur (a
    // real no-write path — see cpRecCiteBlur) would misread that untouched
    // borrowed text as an authored citation. Runs after every blur, including
    // one that saved a just-cleared own citation back to '' — which correctly
    // redisplays as inherited if a rec reason still exists, matching what
    // commitStagedAward will actually publish. Resyncs ta.value/data-prefill to
    // the resolved display text too, so a later reopen (or cpRecCitationFor)
    // never reads stale borrowed text left over from a prior expand.
    function cpRecCiteCollapse(caid) {
        var preview = gid('cp-rec-cite-preview-' + caid);
        var ta = gid('cp-rec-cite-textarea-' + caid);
        if (!ta) return;
        ta.classList.add('cp-rec-hidden');
        if (!preview) return;
        preview.classList.remove('cp-rec-hidden');
        var saved = (ta.dataset.saved || '').trim();
        var recReason = cpRecCiteRecReason(caid);
        var isOwn = saved !== '';
        var isInherited = !isOwn && recReason !== '';
        var display = isOwn ? saved : recReason; // '' when genuinely empty (state 3)
        ta.value = display;
        ta.dataset.prefill = display;
        var textEl = preview.querySelector('.cp-rec-cite-preview-text');
        var tagEl = gid('cp-rec-cite-tag-' + caid);
        if (textEl) {
            textEl.textContent = display === '' ? 'No citation — click to add one' : cpRecCiteTruncate(display, 90);
            textEl.classList.toggle('cp-rec-cite-empty', display === '');
            textEl.classList.toggle('cp-rec-cite-inherited', isInherited);
        }
        if (tagEl) tagEl.classList.toggle('cp-rec-hidden', !isInherited);
        preview.setAttribute('data-tip', isOwn
            ? 'Edit this citation'
            : (isInherited
                ? 'Inherited from the recommendation — this text will be published as the citation unless you edit it. Click to edit.'
                : 'Add a citation for the public record'));
    }

    // One click, per the spec: expand to the textarea and focus it, caret at the end
    // (not the start — the common case is appending/correcting a citation that's
    // already mostly right, not retyping it from scratch).
    //
    // Bugfix: if the textarea is blank but the row has a recommendation reason
    // (inherited, state 2 — or a just-cleared own citation that fell back to
    // inherited on the last collapse), re-prime it with that text before
    // showing/focusing, same as the initial server render already does for a
    // never-touched inherited row — so opening it always edits FROM the rec
    // text rather than from a blank box, per spec. data-prefill tracks it too,
    // so cpRecCiteBlur/cpRecCitationFor still treat it as unedited until the
    // officer actually changes it.
    window.cpRecCiteExpand = function(caid) {
        var preview = gid('cp-rec-cite-preview-' + caid);
        var ta = gid('cp-rec-cite-textarea-' + caid);
        if (!ta) return;
        if (ta.value.trim() === '') {
            var recReason = cpRecCiteRecReason(caid);
            if (recReason !== '') {
                ta.value = recReason;
                ta.dataset.prefill = recReason;
            }
        }
        if (preview) preview.classList.add('cp-rec-hidden');
        ta.classList.remove('cp-rec-hidden');
        ta.focus();
        try { ta.setSelectionRange(ta.value.length, ta.value.length); } catch (e) { /* unsupported — harmless */ }
    };

    // Saves on blur — PARTIAL write, exactly the three keys spec 0.6 allows
    // (CourtAwardId, PublicComment, RowVersion). Omitting Notes/PassToLocal/
    // ScrollMakerId/RegaliaMakerId is what makes this safe: update_award (unlike
    // grant_award/skip_award/unstage_award) carries no status condition in its
    // WHERE clause, so this write lands regardless of whether the row is still
    // planned or already given/skipped — see the CSS comment above the .cp-rec-cite
    // rules for why the control itself is deliberately NOT locked by row mark
    // state. It IS skipped entirely once the court isn't 'published' (courtStatus),
    // matching every other write on this page — a post-finalize edit here would
    // never reach ork_awards.note, so there's nothing honest to save.
    window.cpRecCiteBlur = function(caid) {
        var row = document.querySelector('.cp-rec-row[data-caid="' + caid + '"]');
        var ta  = gid('cp-rec-cite-textarea-' + caid);
        if (!row || !ta) return;
        if (courtStatus !== 'published') { cpRecCiteCollapse(caid); return; }
        var val = ta.value;
        // Compare against data-prefill (what's currently shown — the saved value
        // for an own/empty row, or the borrowed rec text for an untouched
        // inherited row), not data-saved: an inherited row's prefilled value
        // never equals its saved '' by design, so comparing against data-saved
        // here would fire a write on every blur of an unedited inherited
        // citation — recording the officer's silence as authorship, which the
        // fix must not do.
        var prefill = ta.dataset.prefill !== undefined ? ta.dataset.prefill : ta.dataset.saved;
        if (val === prefill) { cpRecCiteCollapse(caid); return; } // nothing changed — no write
        var statusEl = gid('cp-rec-cite-status-' + caid);
        if (statusEl) { statusEl.textContent = 'Saving…'; statusEl.classList.remove('cp-rec-cite-status-error'); }
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        fd.append('PublicComment', val);
        fd.append('RowVersion', row.getAttribute('data-rowversion') || '');
        var p = post('CourtAjax/update_award', fd).then(function(d) {
            delete cpRecCiteInFlight[caid];
            if (d && d.status === 0) {
                var newVersion = (parseInt(row.getAttribute('data-rowversion'), 10) || 0) + 1;
                row.setAttribute('data-rowversion', newVersion);
                ta.dataset.saved = val;
                ta.dataset.prefill = val; // now an own (or genuinely empty) row either way
                var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
                if (a) { a.RowVersion = newVersion; a.PublicComment = val; }
                if (statusEl) statusEl.textContent = '';
                cpRecCiteCollapse(caid);
                return;
            }
            // Non-destructive on failure — the typed text is untouched in the
            // textarea (never cleared), and the control stays expanded so nothing
            // looks silently lost.
            if (statusEl) { statusEl.textContent = 'Not saved — try again'; statusEl.classList.add('cp-rec-cite-status-error'); }
            if (d && d.status === 9) {
                cpNotice('This row changed — reload to see the latest.');
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not save this citation.');
            }
        });
        cpRecCiteInFlight[caid] = p;
    };

    // ---- Given-by chip + "apply to the rest below" (spec §5, Task 8) ----
    // caid (string, matches data-caid) -> {id, persona}. Seeded from the row's own
    // persisted GivenByMundaneId/GivenByPersona (Task 8 added these to getCourtAwards())
    // when the row was already staged/given by someone other than the default — e.g. a
    // page reload after a partial pass must not silently repaint every row back to the
    // default giver. Everything else seeds from the court's default giver, exactly like
    // the printed sheet pre-prints it.
    var cpRecGivers = {};
    courtAwards.forEach(function(a) {
        var hasOwn = a.GivenByMundaneId && a.GivenByMundaneId > 0;
        cpRecGivers[a.CourtAwardId] = {
            id: hasOwn ? a.GivenByMundaneId : ((cpGiverOptions && cpGiverOptions.default) ? cpGiverOptions.default.mundane_id : 0),
            persona: hasOwn ? a.GivenByPersona : ((cpGiverOptions && cpGiverOptions.default) ? cpGiverOptions.default.persona : '')
        };
    });
    window.cpRecGiverFor = function(caid) {
        var g = cpRecGivers[caid];
        return g ? (g.id || 0) : 0;
    };

    // caid (string) -> rank. Ladder rows only (non-ladder rows have no chip and are
    // never marked with a rank — grant_award still gets Rank=0 for them via cpRecMark's
    // FormData, same as before).
    var cpRecRanks = {};
    courtAwards.forEach(function(a) {
        if (a.IsLadder) cpRecRanks[a.CourtAwardId] = a.Rank > 0 ? a.Rank : 1;
    });
    window.cpRecRankFor = function(caid) {
        return cpRecRanks[caid] || 0;
    };

    function cpRecIsDefaultGiver(mundaneId) {
        return !!(cpGiverOptions && cpGiverOptions.default && String(cpGiverOptions.default.mundane_id) === String(mundaneId));
    }

    // Updates the map AND the visible chip together — every write path (pill pick,
    // search pick, apply-to-rest) funnels through this so the two can't drift.
    function cpRecSetGiverChip(caid, mundaneId, persona) {
        cpRecGivers[caid] = { id: mundaneId, persona: persona };
        var chip = gid('cp-rec-giver-chip-' + caid);
        if (!chip) return;
        chip.textContent = persona || '—';
        chip.dataset.mundaneId = mundaneId;
        chip.dataset.persona = persona;
        // Faint-default vs write-in, same idea as the printed sheet: a chip that still
        // matches the court default looks like the rest of the row; one that deviates
        // is visually called out so the recorder can see at a glance which rows they
        // touched.
        chip.classList.toggle('cp-rec-chip-custom', !cpRecIsDefaultGiver(mundaneId));
    }

    function cpRecSetRankChip(caid, rank) {
        cpRecRanks[caid] = rank;
        var chip = gid('cp-rec-rank-chip-' + caid);
        if (!chip) return;
        chip.textContent = 'Rank ' + rank;
        chip.dataset.rank = rank;
        chip.dataset.lvl = Math.min(rank, 10);
    }

    // Every .cp-rec-row that comes AFTER the given row in DOM order (the row list's
    // markup order IS its display order — same convention the mark-column comment
    // above relies on). "Apply to the rest below" only ever touches these; it never
    // looks upward and never touches the row itself twice.
    function cpRecRowsBelow(row) {
        var out = [];
        var el = row && row.nextElementSibling;
        while (el) {
            if (el.classList && el.classList.contains('cp-rec-row')) out.push(el);
            el = el.nextElementSibling;
        }
        return out;
    }

    // Shared positioning for both popovers — identical idiom to Court_detail.tpl's
    // cpShowNote(): fixed, flips above the anchor if it would run off the bottom of
    // the viewport, clamped to stay on-screen horizontally. Works the same whether the
    // anchor sits in a normally-flowing row or (at <=600px) a stacked card.
    function cpRecPositionPop(pop, anchor) {
        pop.style.display = 'block';
        var r  = anchor.getBoundingClientRect();
        var pw = pop.offsetWidth;
        var ph = pop.offsetHeight;
        // clientWidth/clientHeight (not window.innerWidth/innerHeight) — those include
        // the scrollbar gutter, which let the popover's right edge land a few px past
        // the actual visible content area on a page tall enough to scroll (caught at
        // 768px width during Task 8's own mobile check).
        var vh = document.documentElement.clientHeight;
        var vw = document.documentElement.clientWidth;
        var top = r.bottom + 6;
        if (top + ph > vh - 10) top = r.top - ph - 6;
        var left = r.left;
        if (left + pw > vw - 10) left = vw - pw - 10;
        pop.style.top  = Math.max(10, top)  + 'px';
        pop.style.left = Math.max(10, left) + 'px';
    }

    // ---- Given-by popover ----
    var cpRecGiverPopCaid = 0;
    function cpRecBuildGiverPopPills(activeId) {
        var wrap = gid('cp-rec-giver-pop-pills');
        if (!wrap) return;
        wrap.innerHTML = '';
        var list = [];
        if (cpGiverOptions && cpGiverOptions.default) list.push(cpGiverOptions.default);
        if (cpGiverOptions && cpGiverOptions.pills) cpGiverOptions.pills.forEach(function(p) { list.push(p); });
        if (!list.length) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'flex';
        list.forEach(function(g) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cp-giver-pill' + (String(g.mundane_id) === String(activeId) ? ' active' : '');
            btn.innerHTML = esc(g.persona) + ' <span class="cp-giver-role">' + esc(g.role || '') + '</span>';
            btn.onclick = function() { cpRecGiverPopPick(g.mundane_id, g.persona); };
            wrap.appendChild(btn);
        });
    }
    function cpRecGiverPopPick(mundaneId, persona) {
        if (!cpRecGiverPopCaid) return;
        cpRecSetGiverChip(cpRecGiverPopCaid, mundaneId, persona);
        cpRecBuildGiverPopPills(mundaneId);
        var acDrop = gid('cp-rec-giver-pop-ac');
        if (acDrop) { acDrop.style.display = 'none'; acDrop.innerHTML = ''; }
        gid('cp-rec-giver-pop-text').value = '';
    }
    window.cpRecOpenGiverPop = function(caid, chip) {
        cpRecGiverPopCaid = caid;
        gid('cp-rec-giver-pop-caid').value = caid;
        var cur = cpRecGivers[caid] || {};
        cpRecBuildGiverPopPills(cur.id);
        var txt = gid('cp-rec-giver-pop-text');
        if (txt) txt.value = '';
        var acDrop = gid('cp-rec-giver-pop-ac');
        if (acDrop) { acDrop.style.display = 'none'; acDrop.innerHTML = ''; }
        var row = chip.closest('.cp-rec-row');
        var below = cpRecRowsBelow(row);
        var applyBtn = gid('cp-rec-apply-rest-btn');
        if (applyBtn) {
            applyBtn.classList.toggle('show', below.length > 0);
            applyBtn.innerHTML = '<i class="fas fa-arrow-down"></i> Apply to the ' + below.length +
                ' row' + (below.length === 1 ? '' : 's') + ' below';
        }
        cpRecPositionPop(gid('cp-rec-giver-pop'), chip);
    };
    window.cpRecGiverPopClose = function() {
        var p = gid('cp-rec-giver-pop'); if (p) p.style.display = 'none';
        var acDrop = gid('cp-rec-giver-pop-ac'); if (acDrop) { acDrop.style.display = 'none'; acDrop.innerHTML = ''; }
        cpRecGiverPopCaid = 0;
    };
    // Wraps the page's shared cpAcSearch (scoped to this court's kingdom, &q=, custom
    // dropdown — house rules) with a pick callback that routes through the same
    // cpRecGiverPopPick() the quick-pick pills use.
    window.cpRecGiverPopInput = function(input) {
        cpAcSearch(input, 'cp-rec-giver-pop-ac', 'cp-rec-giver-pop-hidden', function(p) {
            cpRecGiverPopPick(p.MundaneId, p.Persona);
        });
    };
    // "Apply to the rest below" — updates every row below the currently-open one to
    // the giver just picked for THIS row. Deliberately calls cpRecSetGiverChip() only
    // (never cpRecMark/cpRecPost): the giver reaches the database the same way it
    // always does, when that row is individually marked Given.
    window.cpRecApplyRest = function() {
        if (!cpRecGiverPopCaid) return;
        var cur = cpRecGivers[cpRecGiverPopCaid];
        if (!cur) return;
        var row = document.querySelector('.cp-rec-row[data-caid="' + cpRecGiverPopCaid + '"]');
        if (!row) return;
        var below = cpRecRowsBelow(row);
        below.forEach(function(r) {
            cpRecSetGiverChip(r.getAttribute('data-caid'), cur.id, cur.persona);
        });
        cpNotice('Applied "' + cur.persona + '" as the giver for ' + below.length +
            ' row' + (below.length === 1 ? '' : 's') + ' below. Nothing was marked.');
        cpRecGiverPopClose();
    };

    // ---- Rank popover (ladder rows only) ----
    var cpRecRankPopCaid = 0;
    // Same zodiac-award heuristic as the ad-hoc modal's cpBuildAdhocRankPills().
    function cpRecMaxRank(awardName) {
        return /zodiac/i.test(awardName || '') ? 12 : 10;
    }
    function cpRecBuildRankPopPills(awardName, activeRank) {
        var wrap = gid('cp-rec-rank-pop-pills');
        if (!wrap) return;
        var maxRank = cpRecMaxRank(awardName);
        var html = '';
        for (var i = 1; i <= maxRank; i++) {
            html += '<button type="button" class="ladder-rank cp-rank-pill' + (i === activeRank ? ' cp-rank-pill-selected' : '') +
                '" data-lvl="' + Math.min(i, 10) + '" data-rank="' + i + '" onclick="cpRecRankPopPick(' + i + ')">' + i + '</button>';
        }
        wrap.innerHTML = html;
    }
    window.cpRecOpenRankPop = function(caid, chip) {
        cpRecRankPopCaid = caid;
        gid('cp-rec-rank-pop-caid').value = caid;
        var rank = cpRecRanks[caid] || 1;
        cpRecBuildRankPopPills(chip.dataset.award, rank);
        cpRecPositionPop(gid('cp-rec-rank-pop'), chip);
        // Task 10 fix: the popover is a floating panel that sits AFTER the whole
        // row list in DOM order, so a plain Tab from the chip that opened it never
        // reaches its pills — found while building the walk-on row's keyboard-only
        // flow (Tab landed on the PTL checkbox instead). Focusing the already-
        // selected pill here makes every rank chip's popover keyboard-reachable,
        // not just the walk-on row's — a real row's rank chip had the exact same
        // gap. Clicking a pill with the mouse is unaffected either way.
        var wrap = gid('cp-rec-rank-pop-pills');
        var selPill = wrap && wrap.querySelector('.cp-rank-pill-selected');
        if (selPill) setTimeout(function() { selPill.focus(); }, 0);
    };
    window.cpRecRankPopPick = function(rank) {
        if (!cpRecRankPopCaid) return;
        cpRecSetRankChip(cpRecRankPopCaid, rank);
        var wrap = gid('cp-rec-rank-pop-pills');
        if (wrap) {
            wrap.querySelectorAll('.cp-rank-pill').forEach(function(p) {
                p.classList.toggle('cp-rank-pill-selected', String(p.dataset.rank) === String(rank));
            });
        }
        // Walk-on keyboard flow (Task 10): picking a rank is this row's next step
        // toward PTL/citation, so auto-advance the same way selecting a recipient
        // or award does. Real per-row rank EDITS (caid is a numeric CourtAwardId,
        // never the 'walkon' sentinel) keep the popover open, unchanged.
        if (cpRecRankPopCaid === 'walkon') {
            cpRecRankPopClose();
            var ptl = gid('cp-rec-walkon-ptl');
            if (ptl) ptl.focus();
        }
    };
    window.cpRecRankPopClose = function() {
        var p = gid('cp-rec-rank-pop'); if (p) p.style.display = 'none';
        cpRecRankPopCaid = 0;
    };
    // Dismiss either popover on an outside click — mousedown so it fires before the
    // click that might be opening a DIFFERENT chip's popover.
    document.addEventListener('mousedown', function(e) {
        var giverPop = gid('cp-rec-giver-pop');
        if (giverPop && giverPop.style.display !== 'none' &&
            !e.target.closest('#cp-rec-giver-pop') && !e.target.closest('.cp-rec-giver-chip')) {
            cpRecGiverPopClose();
        }
        var rankPop = gid('cp-rec-rank-pop');
        if (rankPop && rankPop.style.display !== 'none' &&
            !e.target.closest('#cp-rec-rank-pop') && !e.target.closest('.cp-rec-rank-chip')) {
            cpRecRankPopClose();
        }
    });

    // caid -> in-flight XHR guard, so a fast double-click (or a stuck network
    // request) can't fire two writes for the same row.
    var cpRecInFlight = {};

    function cpRecSetRowUi(row, state) {
        row.dataset.mark = state;
        row.classList.remove('cp-rec-row-given', 'cp-rec-row-skipped');
        if (state === 'given') row.classList.add('cp-rec-row-given');
        else if (state === 'skipped') row.classList.add('cp-rec-row-skipped');
        row.querySelectorAll('.cp-rec-seg-btn').forEach(function(btn) {
            var btnState = btn.classList.contains('cp-rec-seg-given') ? 'given'
                         : btn.classList.contains('cp-rec-seg-skipped') ? 'skipped' : 'none';
            btn.setAttribute('aria-pressed', btnState === state ? 'true' : 'false');
        });
        // Fix round 1: the giver/rank chips are meaningless on a resolved row (Given
        // or Skipped) — editing one there can never reach the database (stageAward()'s
        // WHERE clause only accepts a row still 'planned') and the client then reports
        // a false "this row changed" conflict instead of the real reason. Mirrors
        // Court_detail.tpl's convention of hiding/disabling edit affordances on a
        // resolved row (:1438-1440) rather than inventing a new one. The clear-to-"—"
        // path already re-plans the row, so re-locking here on 'none' is what makes
        // that the officer's way back into editing, live, without a reload.
        var caid = row.getAttribute('data-caid');
        var locked = courtStatus !== 'published' || state !== 'none';
        var giverChip = gid('cp-rec-giver-chip-' + caid);
        var rankChip = gid('cp-rec-rank-chip-' + caid);
        if (giverChip) {
            giverChip.disabled = locked;
            giverChip.setAttribute('data-tip', state !== 'none'
                ? 'Clear this row\u2019s mark (\u2014) to change the giver'
                : 'Change who gave this award');
        }
        if (rankChip) {
            rankChip.disabled = locked;
            rankChip.setAttribute('data-tip', state !== 'none'
                ? 'Clear this row\u2019s mark (\u2014) to change the rank'
                : 'Change the rank granted');
        }
        // A chip that just got locked might have its popover open right now (e.g. the
        // officer opened the rank picker, then clicked Given before picking anything) —
        // close it rather than leave an editable popover pointed at a now-disabled chip.
        if (locked) {
            if (typeof cpRecGiverPopCaid !== 'undefined' && String(cpRecGiverPopCaid) === String(caid) && typeof cpRecGiverPopClose === 'function') {
                cpRecGiverPopClose();
            }
            if (typeof cpRecRankPopCaid !== 'undefined' && String(cpRecRankPopCaid) === String(caid) && typeof cpRecRankPopClose === 'function') {
                cpRecRankPopClose();
            }
        }
        // Every mark changes how many rows are still planned, which is what the bulk
        // button now names — keep its count, label and tooltip honest as they change.
        if (typeof window.cpRecSyncBulkBtn === 'function') window.cpRecSyncBulkBtn();
    }

    // cpRecPost handles the shared response contract for grant_award/skip_award/
    // unstage_award: status 9 = another recorder changed this row first (S5
    // optimistic lock, spec §0.4) — show the non-destructive notice and leave the
    // row exactly as it was, never clobber it. status 0 = the write landed; patch
    // the row's visual state and bump its row_version so the NEXT mark on this row
    // (Given -> Skipped -> — are all just re-marks) threads the fresh token.
    window.cpRecPost = function(url, fd, row, state) {
        var caid = row.getAttribute('data-caid');
        if (cpRecInFlight[caid]) return;
        cpRecInFlight[caid] = true;
        row.querySelectorAll('.cp-rec-seg-btn').forEach(function(b) { b.disabled = true; });
        post(url, fd).then(function(d) {
            delete cpRecInFlight[caid];
            var canMark = courtStatus === 'published';
            row.querySelectorAll('.cp-rec-seg-btn').forEach(function(b) { b.disabled = !canMark; });
            if (d && d.status === 9) {
                cpNotice('This row changed — reload to see the latest.');
                return;
            }
            if (d && d.status === 0) {
                var newVersion = (parseInt(row.getAttribute('data-rowversion'), 10) || 0) + 1;
                row.setAttribute('data-rowversion', newVersion);
                cpRecSetRowUi(row, state);
                var a = courtAwards.find(function(x) { return String(x.CourtAwardId) === String(caid); });
                if (a) {
                    a.RowVersion = newVersion;
                    a.Status = state === 'given' ? 'staged' : (state === 'skipped' ? 'cancelled' : 'planned');
                }
                if (typeof d.staged_count !== 'undefined') cpUpdateStagedIndicator(d.staged_count);
            } else if (!d._postFailed) {
                cpAlert(d.error || 'Could not save this mark.');
            }
        });
    };

    // Given -> stage, Skipped -> skip, — -> unstage. All three already exist and all
    // three are pre-finalize, so any of them can be undone by pressing another.
    window.cpRecMark = function(caid, state) {
        // A citation blur-save started by this very click (see cpRecCiteInFlight)
        // may still be in flight — wait for it rather than racing it on RowVersion.
        // Re-entrant: cpRecCitationFor below still reads the textarea live either way.
        if (cpRecCiteInFlight[caid]) {
            cpRecCiteInFlight[caid].then(function() { cpRecMark(caid, state); });
            return;
        }
        var row = document.querySelector('.cp-rec-row[data-caid="' + caid + '"]');
        if (!row) return;
        var current = row.getAttribute('data-mark') || 'none';
        // Clearing to "-" is NOT a single endpoint: unstage_award only clears a
        // STAGED row (its WHERE requires status='staged' — it refuses a cancelled
        // one, by design, same as grant_award refuses staging a cancelled row
        // directly). A currently-Skipped row is cleared the same way Court_detail.tpl's
        // "Un-skip" does it: set_award_status(Status=planned), which (like skip_award)
        // guards only against 'given'. Discovered via fix-round Finding 2's own
        // repair: before unstage_award honestly reported failure, a stale "-" click
        // on a Skipped row silently no-op'd server-side while the client showed it
        // as cleared anyway — the same class of desync, just single-recorder instead
        // of cross-recorder.
        if (state === 'none' && current === 'none') return; // already clear — nothing to do
        var url;
        var fd = new FormData();
        fd.append('CourtAwardId', caid);
        fd.append('RowVersion', row.getAttribute('data-rowversion') || '');
        if (state === 'given') {
            url = 'CourtAjax/grant_award';
            fd.append('GivenById', cpRecGiverFor(caid));
            fd.append('PublicComment', cpRecCitationFor(caid));
            fd.append('Rank', cpRecRankFor(caid));
        } else if (state === 'skipped') {
            url = 'CourtAjax/skip_award';
        } else if (current === 'skipped') {
            url = 'CourtAjax/set_award_status';
            fd.append('Status', 'planned');
        } else {
            url = 'CourtAjax/unstage_award';
        }
        cpRecPost(url, fd, row, state);
    };

    // ---- Walk-on row (spec §5, Task 10) — the permanent blank row at the foot
    // of the list. A faster path to Court_detail.tpl's Add Award/Add Title
    // modals (add_award already permits adds while published), not a new
    // capability. Deliberately no giver field here (a walk-on's giver is set
    // the same way every other row's is — the giver chip, once it's a real
    // row) and no Internal Notes (stay editable on the planner page). ----
    // Running display-number counter, seeded from however many rows the server
    // already rendered — the printed sheet numbers walk-ons AFTER the plan, and
    // this keeps that true across any number of adds in one sitting.
    var cpRecWalkOnNum = courtAwards.length;

    function cpRecCiteTruncatePreview(s, n) {
        return s.length > n ? s.slice(0, n) + '…' : s;
    }

    // ---- Award/title search (client-side filter over cpRecAwardOptions — the
    // same list Court_detail.tpl's ad-hoc picker filters, and there is no live
    // AwardAjax search endpoint in this codebase). One combined field for both
    // awards and titles, per the brief: group headers render in whatever order
    // groups first appear in cpRecAwardOptions, so there is nothing to keep in
    // sync with a separate hardcoded group-order constant. ----
    window.cpRecWalkOnAwardSearch = function() {
        var input = gid('cp-rec-walkon-award');
        var drop  = gid('cp-rec-walkon-award-ac');
        var q = (input.value || '').trim().toLowerCase();
        // Drop a stale selection as soon as the text stops matching it — same
        // rule Court_detail.tpl's cpAwardSearch uses, for the same reason: the
        // visible text and the posted award id must never disagree.
        if ((input.value || '').trim() !== (input.dataset.selectedName || '')) {
            gid('cp-rec-walkon-award-id').value = '';
            input.dataset.selectedName = '';
            input.dataset.ladder = '0';
            cpRecWalkOnAwardChange();
        }
        drop.innerHTML = '';
        var order = [];
        var byGroup = {};
        cpRecAwardOptions.forEach(function(o) {
            if (q && String(o.name).toLowerCase().indexOf(q) === -1) return;
            if (!byGroup[o.group]) { byGroup[o.group] = []; order.push(o.group); }
            byGroup[o.group].push(o);
        });
        var any = false;
        order.forEach(function(label) {
            any = true;
            var hdr = document.createElement('div');
            hdr.className = 'cp-ac-group';
            hdr.textContent = label;
            drop.appendChild(hdr);
            byGroup[label].forEach(function(o) {
                var div = document.createElement('div');
                div.className = 'cp-ac-item';
                div.textContent = o.name;
                div.addEventListener('mousedown', function(e) { e.preventDefault(); });
                div.addEventListener('click', function(e) { e.stopPropagation(); cpRecWalkOnSelectAward(o); });
                drop.appendChild(div);
            });
        });
        if (!any) {
            drop.innerHTML = '<div class="cp-ac-item" style="color:#a0aec0;cursor:default">No awards or titles found</div>';
        }
        cpPositionAc(input, drop);
        drop.style.display = 'block';
    };

    window.cpRecWalkOnSelectAward = function(o) {
        var input = gid('cp-rec-walkon-award');
        input.value = o.name;
        input.dataset.selectedName = o.name;
        input.dataset.ladder = o.ladder ? '1' : '0';
        gid('cp-rec-walkon-award-id').value = o.id;
        var drop = gid('cp-rec-walkon-award-ac');
        drop.style.display = 'none';
        drop.innerHTML = '';
        if (cpAcOpenDrop === drop) cpAcUnbind();
        cpRecWalkOnAwardChange();
    };

    // Toggle the rank chip based on the currently selected award — reuses the
    // SAME shared #cp-rec-rank-pop popover every real row's rank chip opens
    // (cpRecOpenRankPop / cpRecRankPopPick / cpRecSetRankChip above), keyed
    // under the 'walkon' sentinel caid instead of a real CourtAwardId.
    function cpRecWalkOnAwardChange() {
        var input = gid('cp-rec-walkon-award');
        var chip  = gid('cp-rec-rank-chip-walkon');
        if (!input || !chip) return;
        if (gid('cp-rec-walkon-award-id').value && input.dataset.ladder === '1') {
            chip.classList.remove('cp-rec-hidden');
            chip.dataset.award = input.value;
            cpRecSetRankChip('walkon', 1);
        } else {
            chip.classList.add('cp-rec-hidden');
            delete cpRecRanks.walkon;
        }
    }

    // Enter-from-any-field keyboard flow: resolves whatever the currently open
    // autocomplete dropdown's top match is (if any), then advances focus to the
    // row's next field — recipient -> award -> rank (if ladder) -> PTL -> citation
    // -> commit. Never touches the mouse. Shift+Enter in the citation field still
    // inserts a newline, same as any other multi-line field.
    window.cpRecWalkOnKeydown = function(e, field) {
        if (e.key !== 'Enter') return;
        if (field === 'cite' && e.shiftKey) return;
        e.preventDefault();
        if (field === 'recipient') {
            var drop = gid('cp-rec-walkon-ac');
            var first = drop && drop.style.display !== 'none' ? drop.querySelector('.cp-ac-item') : null;
            if (first) first.click();
            gid('cp-rec-walkon-award').focus();
            return;
        }
        if (field === 'award') {
            var adrop = gid('cp-rec-walkon-award-ac');
            var afirst = adrop && adrop.style.display !== 'none' ? adrop.querySelector('.cp-ac-item') : null;
            if (afirst) afirst.click();
            var chip = gid('cp-rec-rank-chip-walkon');
            if (chip && !chip.classList.contains('cp-rec-hidden')) chip.focus();
            else { var ptl1 = gid('cp-rec-walkon-ptl'); if (ptl1) ptl1.focus(); }
            return;
        }
        if (field === 'rank') {
            // Browser default Enter-triggers-click on a focused <button> is exactly
            // what's needed here (opens the rank popover) — preventDefault() above
            // suppresses it, so fire it explicitly instead of duplicating the logic.
            e.target.click();
            return;
        }
        if (field === 'ptl') {
            // Space toggles a checkbox; Enter never should (most browsers already
            // agree), so this is just the advance-to-citation step.
            var cite = gid('cp-rec-walkon-cite');
            if (cite) cite.focus();
            return;
        }
        if (field === 'cite') {
            cpRecWalkOnCommit();
            return;
        }
    };

    // Builds one row's markup exactly as the PHP loop above does (same classes,
    // same ids), so every existing per-row control — cpRecMark, the giver/rank
    // popovers, citation expand/collapse — works on a walk-on-added row without
    // any special-casing. mark is always 'none' / row-version 0 / unlocked: a
    // row can only be added here while $canMark was true server-side.
    function cpRecWalkOnRowHtml(aw, num) {
        var caid = aw.CourtAwardId;
        var html = '<div class="cp-rec-row" data-caid="' + caid + '" data-rowversion="0" data-mark="none">';
        html += '<span class="cp-rec-c cp-rec-c-num">' + num + '</span>';
        html += '<span class="cp-rec-c cp-rec-c-mark cp-rec-seg" role="group" aria-label="Mark ' +
            esc(aw.Persona || 'this award') + ' — ' + esc(aw.AwardName || '') + '">' +
            '<button type="button" class="cp-rec-seg-btn cp-rec-seg-given" aria-pressed="false" onclick="cpRecMark(' + caid + ',\'given\')">Given</button>' +
            '<button type="button" class="cp-rec-seg-btn cp-rec-seg-skipped" aria-pressed="false" onclick="cpRecMark(' + caid + ',\'skipped\')">Skipped</button>' +
            '<button type="button" class="cp-rec-seg-btn cp-rec-seg-none" aria-pressed="true" onclick="cpRecMark(' + caid + ',\'none\')" data-tip="Clear this mark">&mdash;</button>' +
            '</span>';
        html += '<span class="cp-rec-c cp-rec-c-recip" data-tip="' + esc(aw.Persona || '') + '"><span class="cp-rec-c-label">Recipient</span>' + esc(aw.Persona || '') +
            (aw.ParkAbbrev ? ' <span class="cp-rec-park">' + esc(aw.ParkAbbrev) + '</span>' : '') + '</span>';
        html += '<span class="cp-rec-c cp-rec-c-award" data-tip="' + esc(aw.AwardName || '') + '"><span class="cp-rec-c-label">Award</span>' + esc(aw.AwardName || '') + '</span>';

        html += '<span class="cp-rec-c cp-rec-c-rank"><span class="cp-rec-c-label">Rank</span>';
        if (aw.IsLadder) {
            var initRank = aw.Rank > 0 ? aw.Rank : 1;
            html += '<button type="button" class="ladder-rank cp-rec-rank-chip" id="cp-rec-rank-chip-' + caid + '" ' +
                'data-lvl="' + Math.min(initRank, 10) + '" data-rank="' + initRank + '" data-award="' + esc(aw.AwardName || '') + '" ' +
                'onclick="cpRecOpenRankPop(' + caid + ', this)" data-tip="Change the rank granted">Rank ' + initRank + '</button>';
        } else {
            html += '&mdash;';
        }
        html += '</span>';

        var giverId = (cpGiverOptions && cpGiverOptions.default) ? cpGiverOptions.default.mundane_id : 0;
        var giverPersona = (cpGiverOptions && cpGiverOptions.default) ? cpGiverOptions.default.persona : '';
        html += '<span class="cp-rec-c cp-rec-c-giver"><span class="cp-rec-c-label">Given by</span>' +
            '<button type="button" class="cp-rec-giver-chip" id="cp-rec-giver-chip-' + caid + '" ' +
            'data-mundane-id="' + giverId + '" data-persona="' + esc(giverPersona) + '" ' +
            'onclick="cpRecOpenGiverPop(' + caid + ', this)" data-tip="Change who gave this award">' +
            esc(giverPersona !== '' ? giverPersona : '—') + '</button></span>';

        html += '<span class="cp-rec-c cp-rec-c-ptl"><span class="cp-rec-c-label">To Local</span>' +
            (aw.PassToLocal ? '<i class="fas fa-arrow-down" data-tip="Pass to Local" aria-label="Pass to Local"></i>' : '&mdash;') + '</span>';

        // Citation — always rendered EXPANDED (Task 9's IsWalkOn hook), never the
        // truncated preview: a walk-on has no recommendation and no planned
        // citation, so it must not be missable behind a click.
        var citation = aw.PublicComment || '';
        html += '<div class="cp-rec-cite" data-caid="' + caid + '">' +
            '<button type="button" class="cp-rec-cite-preview cp-rec-hidden" id="cp-rec-cite-preview-' + caid + '" ' +
            'onclick="cpRecCiteExpand(' + caid + ')" data-tip="' + (citation === '' ? 'Add a citation for the public record' : 'Edit this citation') + '">' +
            '<i class="fas fa-quote-left cp-rec-cite-icon" aria-hidden="true"></i>' +
            '<span class="cp-rec-c-label">Citation</span>' +
            '<span class="cp-rec-cite-preview-text' + (citation === '' ? ' cp-rec-cite-empty' : '') + '">' +
            esc(citation === '' ? 'No citation — click to add one' : cpRecCiteTruncatePreview(citation, 90)) + '</span>' +
            '<i class="fas fa-pen cp-rec-cite-edit-icon" aria-hidden="true"></i></button>' +
            '<textarea class="cp-rec-cite-textarea" id="cp-rec-cite-textarea-' + caid + '" data-saved="' + esc(citation) + '" ' +
            'placeholder="Citation for the public record — becomes this award\'s permanent public note when the court is finalized" maxlength="1000" ' +
            'onblur="cpRecCiteBlur(' + caid + ')">' + esc(citation) + '</textarea>' +
            '<span class="cp-rec-cite-status" id="cp-rec-cite-status-' + caid + '"></span></div>';

        html += '</div>';
        return html;
    }

    // Inserts the just-added award as a real row (same shape courtAwards' other
    // entries carry, so cpRecMark/cpRecCitationFor's courtAwards.find() sees it
    // too), directly before the walk-on row so it lands at the end of the list —
    // published courts can't be reordered, which is exactly why the printed
    // sheet numbers walk-ons after the plan.
    function cpRecWalkOnInsertRow(aw) {
        if (!aw || !aw.CourtAwardId) return;
        var full = {
            CourtAwardId: aw.CourtAwardId,
            MundaneId: aw.MundaneId,
            Persona: aw.Persona,
            ParkAbbrev: aw.ParkAbbrev || '',
            KingdomAwardId: aw.KingdomAwardId,
            AwardName: aw.AwardName,
            IsLadder: !!aw.IsLadder,
            IsTitle: !!aw.IsTitle,
            Rank: aw.Rank || 0,
            RecommendationsId: aw.RecommendationsId || null,
            SortOrder: aw.SortOrder,
            RowVersion: 0,
            GivenByMundaneId: 0,
            GivenByPersona: '',
            PassToLocal: !!aw.PassToLocal,
            Notes: aw.Notes || '',
            PublicComment: aw.PublicComment || '',
            Status: aw.Status || 'planned',
            ScrollStatus: aw.ScrollStatus || 0,
            RegaliaStatus: aw.RegaliaStatus || 0,
            ScrollMakerId: null,
            ScrollMakerPersona: '',
            RegaliaMakerId: null,
            RegaliaMakerPersona: '',
            RecReason: aw.RecReason || '',
            RecByPersona: null,
            Artisans: aw.Artisans || []
        };
        courtAwards.push(full);
        cpRecWalkOnNum++;

        var walkonRow = gid('cp-rec-walkon-row');
        if (walkonRow && walkonRow.parentNode) {
            var wrap = document.createElement('div');
            wrap.innerHTML = cpRecWalkOnRowHtml(full, cpRecWalkOnNum);
            walkonRow.parentNode.insertBefore(wrap.firstElementChild, walkonRow);
        }

        // Seed the giver/rank maps exactly like the initial page-load loops do
        // (above), so cpRecGiverFor/cpRecRankFor and the popovers behave
        // identically for a walk-on-added row as for a planned one.
        cpRecGivers[full.CourtAwardId] = {
            id: (cpGiverOptions && cpGiverOptions.default) ? cpGiverOptions.default.mundane_id : 0,
            persona: (cpGiverOptions && cpGiverOptions.default) ? cpGiverOptions.default.persona : ''
        };
        if (full.IsLadder) cpRecRanks[full.CourtAwardId] = full.Rank > 0 ? full.Rank : 1;
    }

    function cpRecWalkOnReset() {
        var persona = gid('cp-rec-walkon-persona');
        var award = gid('cp-rec-walkon-award');
        var chip = gid('cp-rec-rank-chip-walkon');
        var statusEl = gid('cp-rec-walkon-status');
        if (persona) persona.value = '';
        gid('cp-rec-walkon-mundane-id').value = '';
        if (award) { award.value = ''; award.dataset.selectedName = ''; award.dataset.ladder = '0'; }
        gid('cp-rec-walkon-award-id').value = '';
        if (chip) chip.classList.add('cp-rec-hidden');
        delete cpRecRanks.walkon;
        gid('cp-rec-walkon-ptl').checked = false;
        gid('cp-rec-walkon-cite').value = '';
        if (statusEl) { statusEl.textContent = ''; statusEl.classList.remove('cp-rec-cite-status-error'); }
        cpHideAcDropdowns();
    }

    // Commits the walk-on row via CourtAjax/add_award — the exact same endpoint
    // and fields Court_detail.tpl's Add Award/Add Title modals post (minus
    // GivenById, which no add-time control here carries; minus Notes, which the
    // brief deliberately omits from this row). Recipient and award are the only
    // required fields; PTL, rank and citation are all optional.
    window.cpRecWalkOnCommit = function() {
        var btn = gid('cp-rec-walkon-add-btn');
        if (btn && btn.disabled) return;
        var mundaneId = gid('cp-rec-walkon-mundane-id').value;
        var awardId   = gid('cp-rec-walkon-award-id').value;
        var statusEl  = gid('cp-rec-walkon-status');
        if (!mundaneId) {
            if (statusEl) { statusEl.textContent = 'Pick a recipient first.'; statusEl.classList.add('cp-rec-cite-status-error'); }
            var p = gid('cp-rec-walkon-persona'); if (p) p.focus();
            return;
        }
        if (!awardId) {
            if (statusEl) { statusEl.textContent = 'Pick an award or title first.'; statusEl.classList.add('cp-rec-cite-status-error'); }
            var a = gid('cp-rec-walkon-award'); if (a) a.focus();
            return;
        }
        var rankChip = gid('cp-rec-rank-chip-walkon');
        var rank = (rankChip && !rankChip.classList.contains('cp-rec-hidden')) ? (cpRecRanks.walkon || 1) : 0;
        var ptl  = gid('cp-rec-walkon-ptl').checked ? 1 : 0;
        var cite = gid('cp-rec-walkon-cite').value;

        btn.disabled = true;
        if (statusEl) { statusEl.textContent = 'Adding…'; statusEl.classList.remove('cp-rec-cite-status-error'); }

        var fd = new FormData();
        fd.append('CourtId', courtId);
        fd.append('MundaneId', mundaneId);
        fd.append('KingdomAwardId', awardId);
        fd.append('Rank', rank);
        fd.append('PassToLocal', ptl);
        fd.append('Notes', ''); // Internal Notes deliberately omitted from this row
        fd.append('PublicComment', cite);
        post('CourtAjax/add_award', fd).then(function(d) {
            if (btn) btn.disabled = false;
            if (!d || d.status !== 0) {
                if (statusEl) {
                    statusEl.textContent = (d && d.error) ? d.error : 'Could not add this award.';
                    statusEl.classList.add('cp-rec-cite-status-error');
                }
                return;
            }
            cpRecWalkOnInsertRow(d.award);
            cpRecWalkOnReset();
            var recip = gid('cp-rec-walkon-persona');
            if (recip) recip.focus();
        });
    };

    // ---- "Mark all remaining Given" (spec §5, the old Record All Grants,
    // relabelled) — the only bulk action. There is no "stage all marked" button:
    // bulk_record_grants is a set-based UPDATE ... WHERE status = 'planned' that
    // already writes staged server-side, over whatever is still planned right
    // now — so it stays live and clicking it again after walk-ons land catches
    // them too. Rows are never silently defaulted to Given; this button is the
    // one explicit, visible way that happens. Reloads on success so row states
    // come back from the server rather than being guessed client-side. ----
    // Rows still to be marked = every .cp-rec-row carrying data-mark="none" (the
    // walk-on entry row and the header row carry no data-mark, so they are excluded).
    function cpRecPlannedCount() {
        return document.querySelectorAll('#cp-rec-rows .cp-rec-row[data-mark="none"]').length;
    }
    function cpRecDefaultGiverName() {
        return (cpGiverOptions && cpGiverOptions.default && cpGiverOptions.default.persona) || '';
    }
    // The bulk button names what it is about to do: how many rows, under whom — and
    // says "Nothing left to mark" (disabled) instead of confirming a no-op.
    window.cpRecSyncBulkBtn = function() {
        var btn = gid('cp-rec-bulk-btn');
        if (!btn || courtStatus !== 'published') return;
        var n     = cpRecPlannedCount();
        var giver = cpRecDefaultGiverName();
        var lbl   = btn.querySelector('.cp-rec-bulk-label');
        btn.disabled = (n === 0);
        if (lbl) lbl.textContent = n === 0 ? 'Nothing left to mark' : ('Mark ' + n + ' remaining Given');
        btn.setAttribute('data-tip', n === 0
            ? 'Every award on this court is already marked Given or Skipped.'
            : 'Stages the ' + n + ' still-planned award' + (n === 1 ? '' : 's') + ' as Given under ' +
              (giver || 'the court\u2019s default giver') + ' \u2014 see About This Tool');
    };

    window.cpRecMarkAllGiven = function() {
        var n     = cpRecPlannedCount();
        var giver = cpRecDefaultGiverName();
        if (n === 0) { cpAlert('Every award on this court is already marked Given or Skipped.', 'Nothing left to mark'); return; }
        cpConfirm({
            title: 'Mark all remaining Given',
            // bulk_record_grants is set-based over whatever is still 'planned' server-side,
            // so the real total can differ from this page's view if another officer has
            // been marking or adding rows — say so rather than implying an exact promise.
            body: 'Stage ' + n + ' still-planned award' + (n === 1 ? '' : 's') + ' as Given, under ' +
                  (giver || 'the court\u2019s default giver') + '? Rows you already marked Given or Skipped are ' +
                  'left alone, and anything another officer has added since this page loaded is included too. ' +
                  'You can still undo individual grants before finalizing.',
            confirmLabel: n === 1 ? 'Mark 1 Given' : ('Mark ' + n + ' Given'),
            onConfirm: function() {
                var btn = gid('cp-rec-bulk-btn');
                if (btn) btn.disabled = true;
                var fd = new FormData();
                fd.append('CourtId', courtId);
                post('CourtAjax/bulk_record_grants', fd).then(function(d) {
                    if (d.status === 0) { location.reload(); return; }
                    cpRecSyncBulkBtn();
                    if (!d._postFailed) cpAlert(d.error || 'Could not record grants.');
                });
            }
        });
    };
    cpRecSyncBulkBtn();

    // ---- Drift banner session-dismiss (mirrors Court_detail.tpl's cp-prev-banner) ----
    window.cpRecDismissDriftBanner = function() {
        var b = gid('cp-rec-drift-banner');
        if (b) b.classList.add('cp-rec-drift-dismissed');
        try { sessionStorage.setItem('cp.recDriftDismissed.' + courtId, '1'); } catch (e) {}
    };
    try {
        if (sessionStorage.getItem('cp.recDriftDismissed.' + courtId) === '1') {
            var _db = gid('cp-rec-drift-banner');
            if (_db) _db.classList.add('cp-rec-drift-dismissed');
        }
    } catch (e) {}

    // Close dropdowns on outside click; close the complete modal on backdrop
    // click / Escape (same idiom as Court_detail.tpl).
    document.addEventListener('click', function(e) { cpHideAcDropdowns(e.target); });
    var cpModal = gid('cp-complete-modal');
    if (cpModal) {
        cpModal.addEventListener('click', function(e) {
            if (e.target !== this) return;
            this.style.display = 'none';
            cpHideAcDropdowns();
            cpSyncScrollLock();
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var m = gid('cp-complete-modal'); if (m) m.style.display = 'none';
            cpHideAcDropdowns();
            cpSyncScrollLock();
            cpRecGiverPopClose();
            cpRecRankPopClose();
        }
    });
})();
</script>
<?php endif; ?>
