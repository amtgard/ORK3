# Panel report — Medieval Scroll & Manuscript Design

## Summary
Scrollery's bones are more historically literate than they look: the arms on the page are the **issuer's**, not the recipient's (`model.Scrollery.php:744-760`), which is exactly right for a grant — a charter carries the granter's seal, not the grantee's. Where it falls short of a document of record is *diplomatics*: a real charter runs invocatio -> intitulatio -> **notificatio** -> narratio -> dispositio -> **corroboratio** (witness/seal) -> datum -> subscriptio, and Scrollery has the first, second, fourth, fifth, seventh and eighth. It is missing the two clauses that make a document read as LEGAL rather than CONGRATULATORY: the notification and the corroboration. The other gap is vocabulary, not layout: five of sixteen orders repeat one sentence across all eleven ranks, and the `custom` order — covering ~136 otherwise-unprintable awards — prints **no flavour at all**. Kisa's voice is right; there isn't enough of it yet, and the accepted constraint is on ARTWORK, not on words.

## Findings

- **Amtgard Year on the date line** — the one dating convention that is authentic, free, and needs no DB.
  - Now: `Scroll::FormatDate()` (`class.Scroll.php:417-432`) prints `13th May 2024` and nothing else. Grep for `amtgard year|regnal|reign of` repo-wide returns zero hits.
  - Proposal: append the game's own era: `13th May 2024, in the XLII year of Amtgard`. Founding 25 Feb 1983, so it is `floor()` arithmetic against one epoch constant — pure function beside `FormatDate()`, unit-testable, no query. Roman, to rhyme with the rank numeral art (verified: `common/rank_4.png` = **IV**). Opt-out per print, since bulk reprints of 1998 grants should stay honest.
  - Why: the single cheapest thing that turns the page from a form letter into a document of record, and it is REAL Amtgard terminology — the right side of the "context, not cosplay" line.
  - Effort: S — Risk: the epoch is a community convention, not a schema fact; pick it once with the maintainer and record the reasoning on the constant.

- **The notificatio — the one clause a charter cannot do without**
  - Now: `_text.tpl:29-45` goes straight from intitulatio ("Amtgard and the Freehold of / Mordengaard") to the dispositive verb ("presents"). No address to the reader at all.
  - Proposal: one line between the issuer block and `presents`, in the existing `.Body` italic at ~0.5em, letterspaced: *"Know all who read these words that"*. No new asset, no new data. Optionally make it the rubricated line.
  - Why: every real charter, patent and letter of award has it; its absence is why the text reads as a certificate template rather than a proclamation. It also gives the top of the page a third beat the composition lacks.
  - Effort: S — Risk: vertical space. `.Award` is a flex column with `padding:20% 17% 13%` (`scrollery.css:161-179`) and the per-order `SignAndDate` overrides (`scrollery.css:341-492`) are hand-tuned against 16 layouts. Prototype at rank 10 warrior and rank 0 zodiac, the two tightest.

- **The `custom` scroll prints no flavour whatsoever**
  - Now: `scroll-orders.php:522-535` — the `custom` order's flavour array is ELEVEN EMPTY STRINGS. Every Masterhood, Knighthood, Paragon, Defender, Weaponmaster and kingdom-invented award prints a blank sentiment band (`_text.tpl:83-85`).
  - Proposal: key a small flavour set to the classification the ORK ALREADY COMPUTES — `Award::OptionGroupLabelFor()` (`class.Award.php:408-447`) returns eight buckets. Eight to sixteen new lines in Kisa's register, credited beside hers in NOTICE. A Knighthood deserves different words from a Squire's oath; both deserve better than a blank.
  - Why: phase 2 made ~136 awards printable and then printed them mute. A Knighthood scroll with an empty sentiment block is the one certificate a person frames.
  - Effort: M (wiring is S; getting good words written is the work) — Risk: `BuildScroll()` already receives the award row so `OptionGroupLabelFor()` can be called there. Ask Kisa first; if she declines this becomes a community call for text, not a reason to ship placeholders.

- **Five orders have degenerate flavour ladders**
  - Now: `flame` (`scroll-orders.php:127-140`) and `hunter` (`277-289`) repeat one identical sentence at all eleven ranks. `hydra` (`304-317`) and `smith` (`427-440`) run in identical pairs — five distinct sentences across ten ranks. `dragon` (`101`,`106`) uses the same line at rank 0 and rank 5.
  - Proposal: fill them. ~30 sentences total. This is the *extend the vocabulary* move; the artwork constraint does not apply to text. Ship as additions to the same array so attribution and structure are untouched.
  - Why: flavour is the only part of the page that changes as a player climbs. A Flame recipient at X gets the same words as at I.
  - Effort: S mechanically / M with authorship — Risk: do not silently rewrite Kisa's existing lines; only fill the repeats, and note in the file header which entries are later additions and by whom.

- **No flavour choice at the point of issue**
  - Now: `Scrollery_create.tpl` exposes Reason (321), Date (327) and the recipe picker (349-400) but nothing for flavour; `BuildScroll()` (`class.Scroll.php:550-554`) takes the single string at `[order][rank]`.
  - Proposal: a select of the order's own eleven lines (labelled by rank), defaulting to the rank's own, plus an override textarea. Nothing persists — rides the existing URL seam.
  - Why: a Regent who knows WHY this Rose is being given can pick the line that fits, and a court that used rank-7's wording once this reign can vary it. Also makes the degenerate ladders survivable before they are filled.
  - Effort: S — Risk: an override is free text on a printed page — escape through `scroll_e()` and cap length; `_text.tpl:83-85` has no length-scaled font size the way `Award_Reason` does (line 74), so a long override will overflow.

- **The recipient's device as a second heraldic slot**
  - Now: `_heraldry.tpl:13-17` draws one device; `heraldry_url()` (`model.Scrollery.php:744-760`) resolves park-first, kingdom-fallback — the ISSUER's. Historically correct, should not change.
  - Proposal: an optional small second device at the foot beside/below `SignAndDate`, from `GetHeraldryUrl(['Type'=>'Player',...])`. **4,778 players have `has_heraldry=1`** — roughly one recipient in ten. Off by default; offered in create and on `mine` only when the row exists.
  - Why: the difference between a certificate about an ORDER and a document about a PERSON. Uses heraldry the ORK stores and displays nowhere else on paper.
  - Effort: M — Risk: blend modes. The issuer device is `mix-blend-mode:multiply` and the dark-ground path runs it through `#OWL_edge-detect` (`custom.tpl:101-113`); a second device needs the same treatment or it punches a white box through `cyanotype`. 624 of 626 heraldry files are opaque JPEGs.

- **Rubrication and a versal, from type alone**
  - Now: `.Award{color:#000000}` (`scrollery.css:165`). Every glyph on every certificate is black. `--primary-color`/`--secondary-color` are scoped to the border SVG (`_border.tpl:28`) and never reach the text container.
  - Proposal: (a) pipe the order's primary onto `.Award` as a variable and rubricate exactly two things — the notification line and `.Order` (`_text.tpl:71`), the name of the thing granted; (b) a versal — enlarge and colour the first letter of the notification with `::first-letter`, no image. `print-color-adjust:exact` is already set (`scrollery.css:151-152`) so both survive printing.
  - Why: red-for-the-first-line is THE recognisable manuscript signal, and it costs one CSS variable. It also finally makes the per-order colour pair visible on orders whose border is raster rather than knotwork.
  - Effort: S — Risk: seven orders have `primary => null` (`scroll-orders.php:66,122,150,206,237,299,362,391,417,454,483`) and need a default; a rubric must not apply on `cyanotype`. Gate on `Scroll::DARK_GROUNDS`, which the composer already consults.

- **Period-style presets for the composer**
  - Now: `Scrollery_create.tpl:349-400` offers Ground (12), Border (10), Border colours (16), Rank numerals (4) as four independent selects. Nothing prevents knotwork over the `map` ground with `starfield` numerals.
  - Proposal: six named presets above the four slots that set all of them at once, slots still free underneath — **Insular** (parchment/knot/Griffon tan/common), **Gothic** (parchment/crown/Crown gold/common), **White-vine** (linen/rose/Rose crimson/garber), **Architectural** (cyanotype/owl/Owl indigo/common), **Celestial** (starfield/knot/Zodiac night/zodiac), **Forge** (forge wash/smith/Smith steel/common). Name them honestly — the art is Valory's modern illustration, not period facsimile, so the label is an ORGANISING IDEA, not a claim of provenance.
  - Why: an officer picking four dropdowns at 11pm before court will produce something incoherent; a named preset produces something that looks designed. It is also how every scribal handbook organises this material.
  - Effort: S — Risk: presets must validate through `Scroll::NormaliseRecipe()` (`custom.tpl:41`), and each should be eyeballed once at rank 1 and rank 10 — the five known non-composing pieces (`custom.tpl:16-23`) are exactly the traps a preset would walk into.

- **Roman numerals are right; the doubled numbering is right too — one guard**
  - Now: the art carries Roman numerals in all four rank sets (verified `common/rank_4.png`=IV, `garber/rank_4.png`=IV with scissors and needle), while `_text.tpl:16,64` prints the ordinal WORD — "with their fourth". Non-ladder awards suppress both (`class.Scroll.php:535-537`, `custom.tpl:132`).
  - Proposal: keep it. Roman in the illumination and the ordinal word in the body is genuine manuscript practice, not redundancy. Add one guard: `$ordinals` stops at `tenth` (`_text.tpl:16`) and `$ordinals[$rank] ?? ''` fails SILENTLY to an empty string, so if the Zodiac branch lifts `ClampRank()` past 10 the phrase becomes "with their " and nobody will notice. Extend to twelfth, or fall back to the numeral.
  - Effort: S — Risk: none; do not couple to the Zodiac month work.

- **Diction verdict: the flavour text is on the right side of the line; the FRAME is where the risk sits — resist the Latin**
  - Now: Kisa's lines are modern, warm and plain — "May your blade ever sing", "Each stitch with your needle brings joy to others" (`scroll-orders.php:48,163`). The archaic register is confined to frame captions, "On this day…" and "By my hand…" (`_text.tpl:93,100`). That is the correct division: ceremony in the furniture, sincerity in the sentiment.
  - Proposal: hold the line explicitly. **No Latin, no "Hear ye", no pseudo-medieval orthography.** Take formality from Amtgard's own real vocabulary — Crown, Monarch, Regent, Champion, `ork_parktitle.title`. One concrete fix in this spirit: for a kingdom `issuerTitle` is deliberately empty (`class.Scroll.php:517-520`) so the line reads "Amtgard and / The Kingdom of the Wetlands" — correct, but "and" does very little. "Amtgard, and the Freehold of / Mordengaard" with a comma reads as an intitulatio rather than a list.
  - Effort: S — Risk: none beyond taste. Write it down so a future contributor does not "improve" it into cosplay.

- **A corroboration clause — witness line and seal — but do NOT auto-fill a reign**
  - Now: `_text.tpl:87-102` gives two ruled blanks: date, and one signatory pre-filled from `given_by` (`model.Scrollery.php:372,449`).
  - Proposal: (1) a third ruled blank labelled **"For the Crown"** — always blank, never pre-filled. (2) a seal impression at the foot: a small circular device (the issuer's arms again, or a plain roundel in `--primary-color`) with a ruled tag line beneath. Both are type and existing assets.
  - Why: the corroboratio is the clause that says THIS DOCUMENT IS AUTHENTIC, and a blank signature line invites the physical ritual — a monarch signing a scroll in court is the whole point of printing it.
  - Effort: M — **CRITICAL CAVEAT:** reign dating is NOT derivable. `ork_officer_history` has 2,514 rows and only **11 populated `start_date`s** (all Aug 2026 — a backfill artefact; all 705 monarch rows share the single date 2026-08-09). `KingdomProfile::GetRoyalOfficerIds()` (`class.KingdomProfile.php:17-38`) resolves only the CURRENT monarch and regent by `ORDER BY officer_id DESC LIMIT 1`. The ORK can tell you who reigns today and nothing about who reigned when an award was granted — and 391k rows are historical. Print the line blank; auto-filling from current officers would put the wrong monarch on every reprint. That is also the argument for Amtgard Year, which IS derivable from the award date alone.

**Deliberately NOT proposed:** deriving the Zodiac month from the award date (deferred permanently — though note `ork_awards.zodiac_month` now exists in the shared DB, so that branch is live), and per-kingdom theming (closed).
