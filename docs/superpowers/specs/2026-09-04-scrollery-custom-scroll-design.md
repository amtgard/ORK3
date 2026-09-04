# Scrollery phase 2 — the Custom Scroll, archery, and pronouns

**Date:** 2026-09-04
**Surface:** `Scrollery` module — `scroll-orders.php`, `class.Scroll.php`, a new `custom.tpl`,
the create flow and its Ajax preview
**Type:** Extension of the phase 1 port
**Predecessor:** `docs/superpowers/specs/2026-09-03-scrollery-design.md`
**Decisions:** `docs/scrollery-deferred.md` §0

## Problem

Phase 1 covers 14 of the ORK's 15 ladder awards plus the Flame, and shows a Print
control only where certificate artwork exists. That leaves **135** catalogue
entries with no way to print at all, including every Knighthood, every Master, every
Paragon, all 27 titles, and — discovered during triage — **award 31, "Order of the
Walker in the Middle", a genuine ranked ladder with 379 grants that Arcanyst simply
never drew**. The spec's claim that ladder coverage is complete is therefore 14 of 15,
not 15 of 15.

Three further gaps block real use:

- **Archery cannot be printed.** `hunter.tpl` renders and is asset-tested, but Arcanyst
  keyed it to a fabricated `ork_id` of 6969. Real archery grants hang off three
  different base ids (0, 94 "Custom Award", and 131) under a dozen kingdomaward names,
  so there is no id to look up.
- **Pronouns are inferred with no way to correct them.** The certificate reads "with
  *her* third"; when the profile carries nothing usable it says "their", and an officer
  who knows better cannot fix it.

## Goal

Make every award the ORK can grant printable, without commissioning new artwork, and
without pretending an award has a design it does not have.

## Constraints

- **No new artwork.** Everything composes from the ~62 MB already committed.
- **No new storage.** Decided during triage: a recipe is chosen per print and travels in
  the URL. No table, no migration, no admin screen.
- **The layer discipline from phase 1 is not negotiable.** Document order is z-order,
  repeated layers are deliberate, per-order CSS is `[data-order]`-scoped. Anything new
  obeys the same rules so it inherits the existing test coverage.
- Artwork remains Valory Fox's, used with permission to continue the work. NOTICE already
  records this; recombining her vocabulary is within that permission (decided 2026-09-04).

## Design

### 1. The Custom Scroll

Rather than one fixed generic certificate, a **composer** over the artwork already
present. The three slots are the pieces that are *not* order-identifying:

| Slot | Pieces available |
|---|---|
| Ground | **12** — `common/parchment.jpg`, `garber/linen.jpg`, `jovious/bg.jpg`, `flame/bg.jpg`, `zodiac/bg_generic.jpg`, `zodiac/bg_rank.jpg`, `owl/cyanotype.jpg`, `warrior/bg_10.jpg`, `smith/bg.jpg`, `smith/burn.jpg`, `rose/burn.jpg`, `battle/arcane/wash.jpg` |
| Border | the shared parametric knotwork SVG (`_border.tpl`), plus 9 raster borders — crown, hunter, hydra, lion, mask, owl, rose, smith, battle symbols |
| Rank motif | **4 numeral sets** — `common/`, `zodiac/`, `garber/`, `flame/` |

Every ground is exactly 2550x3300, the foreignObject's own size, so any ground is a
drop-in swap for any other. That is what makes the composer work without per-combination
tuning.

**Order symbols are deliberately excluded.** The lion, the griffon, the phoenix are
order identity; a griffon on a Knighthood reads as a mistake, not a choice.

**Border colour comes from a curated palette** — the 16 colour pairs the artist actually
chose (griffon's tan/gold, dragon's green/gold, battle's red/sand, and so on). The
knotwork SVG already recolours through `--primary-color` / `--secondary-color`, so this
needs no new art. Free colour entry was considered and rejected: every curated pair was
drawn deliberately, and two arbitrary colours can look wrong on artwork not designed for
them.

**Rank behaves as it does elsewhere.** A ranked award (Walker in the Middle, ranks 1-10)
draws its numeral from the chosen set; an unranked one (a Knighthood) draws none. This is
the same conditional every ranked order partial already contains.

#### Implementation shape

`scroll-orders.php` gains one entry, `custom`, whose art is not fixed. `Scroll::BuildScroll()`
carries four extra keys when the order is `custom`: `ground`, `border`, `borderColours`,
`rankSet`. `custom.tpl` composes them through the existing shared partials, so it inherits
the asset-path matrix, the duplicate-attribute guard and the `[data-order]` CSS scoping
without any new test machinery.

The recipe travels in the query string exactly as the create flow's other parameters
already do — `ScrolleryAjax/preview` for the live preview, `Scrollery/compose` to print.

#### The default recipe

A recipe chosen per print exists only for that print. Without a fallback, an uncovered
award would be printable **only** by an officer in the create tool — never from
`Scrollery/mine` and never in a bulk run, because neither has a recipe to work from.

So `Scroll::DEFAULT_RECIPE` is a named constant in code: the shared parchment, the
knotwork border in griffon's tan/gold, and the `common/` numerals. Every path that prints
an uncovered award without an explicit recipe uses it. Reprints are therefore
deterministic and identical, and the composer becomes an override for one-offs rather
than the only door.

#### What becomes printable

`Scroll::HasArt()` currently answers "is there an order for this award id". It becomes
"is there a specific order, **or** is this award eligible for the custom scroll". That one
change lights up Print across `Scrollery/mine`, `Scrollery/bulk` and the Awards tab
simultaneously, because all three already ask that question.

Eligibility excludes **officer positions** (`ork_award.officer_role <> 'none'`) — 54
entries, 7,143 grants. An officer position is a job someone held, not an honour someone
was given. The remaining **81** entries become printable — 27 titles, 17 Paragons, 9 Masters,
5 Knights, Squire, Man-at-Arms, Page, Lords-Page, 18 ordinary awards, and Walker in the
Middle — carrying 37,519 grants between them.

### 2. Archery

A curated name list resolves archery kingdomaward rows to the `hunter` order, since no
base award id can:

```
Order of the Hunter · Order of the Archer · Order of the Marksman
Order of the Sharpshooter · Hunter
```

Matching is on `ork_kingdomaward.name`, case-insensitive and trimmed. The printed title
still comes from that same field, so all four spellings render correctly on their own
certificates — which was the point of making the word after "Order of the" data in the
first place.

`Master Hunter` and `Master Archer` are deliberately **not** in the list. They fall
through to the custom scroll along with every other Master, because no other ladder gives
its Master tier the ladder's own artwork — a Master Rose does not print Rose art either.

A kingdom inventing a spelling outside the list gets no specific art and falls to the
custom scroll, which is a better failure than today's nothing.

### 3. Pronouns

The certificate needs the **possessive** — "with *her* third". Resolution order:

1. `ork_mundane.pronoun_id` → `ork_pronoun.possessive`
2. the custom-pronoun JSON's `"p"` set
3. `ork_mundane.pronoun_freetext`, when a branch that populates it has merged
4. `their`

Step 3 carries the trap that motivated this item: free text is *subject/object*
("he/him", "she/her", "they/them"), and reading it naively yields "him" — the certificate
would say "with **him** third". The leading token is matched against
`ork_pronoun.subject`, **longest match first** so that "e/ey" and "ze/zie" resolve before
"e" and "ze" would.

The create tool gains an override select listing the `ork_pronoun` possessives, defaulting
to whatever was inferred. **Per-print only** — Scrollery is a printing tool, and an officer
editing another player's pronouns from a print screen is a consent question, not a UX one.

## Testing

- The asset-path matrix expands over the recipe space. It walks `Scroll::Orders()` and
  renders each order across its ranks and variants; `custom` contributes its grounds,
  borders and numeral sets the same way, so every combination's `src` is stat-ed.
- Archery name resolution: each of the five names resolves to `hunter`; `Master Hunter`
  and `Master Archer` do **not**; an unknown spelling falls through.
- Custom-scroll eligibility: the 54 officer positions are excluded; a Knighthood, a
  Master, a title and Walker in the Middle are included; `HasArt()` still answers true for
  all 16 specific orders.
- Possessive resolution: `pronoun_id` wins; the custom JSON is honoured; **`he/him`
  resolves to `his`, never `him`**; `e/ey/em` resolves via the longest-subject match; an
  empty profile yields `their`; the override wins over all of it.
- The existing duplicate-attribute and no-raw-placeholder guards cover `custom.tpl`
  automatically, since both walk whatever orders exist.

## Out of scope

- **Zodiac's month and ranks 11-12** — permanently deferred to a dedicated branch that
  will own the whole question. Explicitly *not* to be solved here by deriving the month
  from the award date.
- **Per-kingdom theming** — closed: 0 of 36 `ork_kingdom_design` rows carry a colour.
- **Per-kingdom certificate name variants** — closed: `ork_kingdomaward.name` already is
  per-kingdom and already drives the title.
- **Saved recipes** — decided against. If reprint fidelity for composed one-offs later
  matters more than the simplicity, this is the thing to revisit, and the default recipe
  is the seam it would attach to.
- **Server-side PDF** — parked; `Scrollery_render.tpl` remains its seam.

## Open items

None blocking. The eight small deferred items decided alongside this (navigation,
own-profile link, print-from-grant, bulk event picker, WnAjax exits, `PrintUrl` out of
the model, woff2 MIME, the hunter visual check) are ordinary bounded work and are not
part of this spec.
