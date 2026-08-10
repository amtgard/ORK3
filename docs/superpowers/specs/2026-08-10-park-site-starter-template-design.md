# Park Site Starter Template — "Seal and Field"

**Date:** 2026-08-10 · **Branch:** `feature/front-door` · **Status:** design, awaiting implementation plan

## Problem

A park's OGRE site at `/p/{slug}` is currently seeded from a lightly-adapted kingdom
template. The 2026-08-10 remediation made it *scope-correct* — no more kingdom-only
blocks rendering blank — but scope-correct is not the same as *good*. A park is not a
small kingdom. It is a physical place that a nervous stranger might drive to on a
Saturday, and its site has one job the kingdom's does not: convert that stranger into
someone who shows up.

This spec defines the starter template a park gets on day one, before an officer edits
anything.

## Audiences, in priority order

1. **New players** (primary). Found the park via search or Facebook, never played,
   possibly anxious. Needs: is this for me, what do I do, what do I bring, when and
   where exactly.
2. **Local players.** Already members. Need a time and an address, fast, then links into
   their own ORK records.
3. **Non-local players.** Amtgard players from elsewhere. Need events, an officer to
   contact, and enough to judge whether to visit.

The whole design rests on one observation: **all three want the same thing first** — when
and where. They diverge only on the second thing. So the site hoists that shared fact
above the split and lets the split happen below it.

## What the data forced

Every number below was measured against a production-shaped restore (342 active parks).

| Signal | Coverage | Consequence for the design |
|---|---|---|
| Park-day records | **308 / 342 (90%)** | The recurring meeting is the spine of the site |
| Street address | 335 (98%) | Near-universal fallback when park days are missing |
| Lat / lng | 328 (96%) | Directions almost always possible |
| Officer rows | 342 (100%), 78% of seats filled | Roster always renders |
| Heraldry device | 316 (92%) | The visual anchor |
| Description | 246 (72%) | Enough to pre-fill, not to depend on |
| External URL | 204 (60%) | Secondary CTA only |
| Written directions | 180 (53%) | Supplementary |
| **Upcoming event** | **26 (7.6%)** | **No Events page.** It would be empty on 92% of parks |
| **Banner photo** | **5 (1.5%)** | The hero cannot assume photography |

Two ratios decide most of the structure: **90% park-days vs 7.6% events** (the meeting is
the spine, not the calendar), and **92% heraldry vs 1.5% photos** (the hero is a crest,
not a photograph).

### Data explicitly rejected

- **A newcomer-count block.** 274 parks (80%) had a first-ever attendee in the last 180
  days, and it would have answered the strongest newcomer objection directly. Cut by
  product decision: it publishes an activity signal a struggling park has no way to
  opt out of, and the parks that most need recruiting help are the ones it embarrasses.
- **A headcount / "N players last month" signal.** Same objection, weaker payoff.
- **A founding date.** No such column. Deriving it from `MIN(ork_attendance.date)` is
  expensive and wrong for merged parks.
- **`ork_parkday.location` / `ork_park.location` as venue names.** These are geocode
  caches in practice — 522 of 806 parkday rows and 781 of 1048 park rows hold JSON.
  Already guarded in `park_meeting.tpl`; the guard must not be removed.

## Page set — three pages

Deliberately smaller than the kingdom's five. Every seeded page is a page a volunteer
must keep alive or it rots.

| Page | Slug | Purpose | Primary audience |
|---|---|---|---|
| **Home** | `home` | Prove this is real and active; answer when-and-where before anything else | All three |
| **New Players** | `new-players` | Remove every reason a first-timer might not show up | New players |
| **Contact & Officers** | `contact` | Give a named human to ask, plus the park's ORK record | Non-local, then local |

### Nav: `Home · New Players · Contact`

Three items, one phone line, no overflow. Nav labels are the highest-traffic copy on the
site.

- **"New Players"** over "Get Started" (SaaS), "Join Us" (implies an application and dues
  — false, and it contradicts the entire message), "First Time?" (doesn't scan). It names
  *the reader*, so it does audience routing in the nav itself: a local reads it and
  correctly skips it.
- **"Contact"** over "Officers" (a newcomer doesn't know a Prime Minister is who you
  email), "Leadership" (corporate), "Staff" (implies paid). The page *title* stays
  "Contact & Officers" so the in-group term is still on the page and indexed.

### Removed from the current park seed

- **About Us.** Its seeded body is author instructions published to the open web
  ("Share your park's story… Replace this placeholder with your own history"). History is
  also the one fact none of the three audiences asked for. The 72% of parks with a
  description get that paragraph on Home instead, where it sits next to something useful.
- **Documents & Resources.** The sharpest kingdom/park divergence: a kingdom has a
  corpora, bylaws, award criteria and election rules — a real library. A park has, at
  most, a waiver. An empty `file_download` behind a nav item is a dead end on ~every park.
- **The "Board of Directors" `staff_roster`.** Parks have no board. The current seed
  publishes a fabricated person named "Add a board member" to the public web.
- **No Events page.** 26 of 342 parks have an upcoming event. A nav item leading to an
  empty page tells a prospective newcomer *this club is dead* — and delivers that message
  before anyone clicks. As a *block* inside Home, the same empty state is one honest line
  in a live page.

## Home page — block order

| # | Block | Answers |
|---|---|---|
| 1 | `park_hero` (new) | What is this? Am I in the right place? |
| 2 | `park_meeting` | When and where, exactly? |
| 3 | `steps` — "Your first day, start to finish" | What do I actually *do*? I'll feel stupid |
| 4 | `rich_text` — who we are, pre-filled from `ork_park.description` | Who are these people? |
| 5 | `park_events` | Anything coming up? Is this park alive? |
| 6 | `park_officers` | Who's in charge? Are they normal? |
| 7 | `cta_band` | I want to ask before I come |

The 4-step first-day script stays on **Home**, not behind a click: it is the
highest-anxiety content for the primary audience, and it is short. The full 8-question
FAQ moves to **New Players**, because on Home it would bury the meeting time for the
other two audiences.

**New Players** carries: `steps` (expanded), the 8-item `accordion`, a link out to the
Atlas for wrong-geography visitors, and a `cta_band` back to the meeting time.

**Contact & Officers** carries: `park_officers`, then a line linking the park's ORK
record. 100% dynamic, so it cannot rot.

## The persistent quick-facts strip

A sticky one-line strip under the site header, on **every** page:

> **Saturdays 1:00 PM · Ramsey Park, Austin TX · Directions**

Never authored by hand; sourced from park-day data. This is the mechanism that resolves
the three-audience tension. The local reads it and leaves in four seconds; the newcomer
scrolls past it into reassurance; the visitor reads it and taps Contact.

Three degradation tiers — this is what makes it safe to pin:

1. Park-day rows exist (308 parks) → time, place, directions.
2. No park days but an address (98% overall) → **place and directions only. Never invent
   or approximate a time.**
3. Neither → **the strip does not render at all.** A permanently sticky "Meeting times
   coming soon" follows the visitor down every page announcing that the site is unfinished.

It is site chrome (a `Site_shell` element in park scope), not a block, so it cannot be
deleted by accident.

## Calls to action

Two tiers, because no second channel can be assumed to exist.

**Tier 1 — "come to a park day."** The only CTA needing no data, true for all 342 parks,
and the honest ask: showing up is free and requires nothing. There is also **no
self-service signup to point at** — `Controller_SelfReg::form()` requires an
officer-issued hex token validated by `validate_selfreg_link()`, so a "Sign Up" button
would be a guaranteed dead end *and* would reframe a free Saturday as registering with an
organization. Appears as the hero's primary button ("Plan your first visit", anchoring to
the meeting block) and again in the closing band.

**Tier 2 — the park's one link, auto-labeled by what it actually is.** Of the 204 parks
with a URL: 148 Facebook, 37 Amtgard-related, 36 other, 5 Discord. Domain detection at
seed time picks the label — Facebook → "Ask us on Facebook", Discord → "Join our
Discord", else "Visit our page". A generic "Visit our website" wastes the reassurance a
*social* link carries: a newcomer can see the group is active and lurk before committing.
For the 138 parks with no URL, **no second button renders**.

**Tier 2 must never outweigh Tier 1.** A social link is a lower-commitment action and
will out-click the real goal given equal weight. It stays a ghost button beside a solid
one, never the reverse.

**Discord without a schema change.** `ork_park` has exactly one `url` column, which is
why Discord shows up only 5 times — the most public thing wins the slot. The closing
`cta_band` therefore seeds **two** slots: slot 1 auto-filled from `ork_park.url`, slot 2
empty with an editor-only hint, "Add your Discord or group chat." Since empty CTAs now
render nothing publicly and prompt loudly in the editor, officers get an obvious home for
Discord at zero data-model cost. High adoption in that slot is the evidence for a real
`park_links` table later.

## Visual design

### The problem

The global front door leans on full-bleed event photography. A park cannot: 5 of 342 have
a banner. The page must feel warm and alive with a crest and type.

### Hero — `park_hero` (new block)

Two-column grid (`minmax(0,1fr) auto`), max-width 1120, vertically centred, **no fixed
height** (`.fd-carousel`'s fixed 480px produces dead space with no photo and crops on
phones). Bottom edge is a 3px accent rule.

**Copy column:** eyebrow (`SHIRE · KINGDOM OF THE BURNING LANDS` — real rank and
allegiance from `ork_parktitle` + parent kingdom), `<h1>` park name, `City, Province`,
then a live line — **next game day** via the existing `Park::CalculateNextParkDay()`,
plus **weather** from the existing `ork_park_weather` table (295 rows, cron-refreshed by
`bin/refresh-weather.php`). "Saturday, Aug 16 · 2:00 PM · 78°F" is local, current, and
answers a real question. Degrade silently when the next game day is more than 7 days out.

**The seal.** The park's heraldry on a plate, with a double tressure (hard 2px accent
ring + soft halo + drop shadow) — reads as a seal rather than an avatar. Two modes,
discriminated for free because `Heraldry::resolve_heraldry_url()` already probes `.png`
then `.jpg` and the upload pipeline writes PNG only when the image has alpha:

| File | Treatment |
|---|---|
| `.png` (alpha, edge-trimmed) | `object-fit: contain` at 78% — device floats on the plate |
| `.jpg` (opaque) | `object-fit: cover` at 100%, clipped to the disc — the white background *becomes* the plate |

That second rule is what makes mediocre heraldry look deliberate. **The frame is the
design decision; the image is cargo.** You cannot fix 342 images of varying quality, but
you can frame them identically.

**Photo layer (product decision).** A generic Amtgard action photo is seeded behind the
field as a **clearly-labeled placeholder**, with an officer-facing prompt to replace it
with a photo of their own park. It gives day-one warmth without pretending to be this
park; removing it degrades to the crest-and-colour hero, never to an empty frame.

**Fallback for the 8% with no device:** a **monogram seal** — same plate, same tressure,
park initials in the display face. Not the generic placeholder crest, which would make 27
parks identical and unloved. Precedent exists: `.fd-roster-photo-empty` already does
monogram-on-navy. Gate the monogram on `has_heraldry`, never on a truthy URL —
`resolve_heraldry_url()` returns a guaranteed-404 path when no file exists.

### Colour — extract once, at seed time

The park's own device drives its palette, so no two of the 342 look alike. The algorithm
already exists client-side (`revised.js` `pkApplyHeroColor()` — 60×60 canvas, 16-step RGB
bucketing, skips near-white/near-black/transparent). It is in the wrong place: it
recomputes on every page load and theme toggle, caches nothing, flashes a default green
before the image loads, breaks under CORS once park sites get custom domains, and cannot
be overridden by the officer.

**Port it to PHP** (GD, reading the local heraldry file), clamp, and write the result into
the theme row the seeder creates:

```
hue    → keep
sat    → clamp(s, 0.30, 0.62)   // floor kills mud, ceiling kills neon
light  → 0.22                    // a deep field; white always clears 7:1
```

`CmsThemeTokens` already ships `HexToHsl` / `HslToHex` / `Luminance` / `Contrast` /
`EnsureContrast`, and `Derive()` then generates the whole dark palette for free, WCAG-
corrected. It lands in the theme editor as a **starting point the officer can override**.

Colour cascade when there's no device: park device → **parent kingdom's device** (a park
without arms belongs to a kingdom that almost certainly has some, and inheriting is
meaningful) → deterministic hash of the park name. Neighbouring parks never collide.

Keep the ORK gold as the accent — it's the brand tie-back. Add one derived token so a
gold-hued park doesn't collide with it:

```php
$d['--fd-accent-on-primary'] = (self::Contrast($accent, $primary) >= 3.0)
    ? $accent : $d['--fd-primary-contrast'];
```

**Do not use the device as a full-bleed watermark.** Masking a solid-background JPEG by
its alpha channel yields a giant opaque rectangle; with 92% coverage and unpredictable
backgrounds the pixels cannot be trusted at large scale.

### Texture, banding, type

- **Diapering.** Medieval heralds incised a faint lattice into large flat tincture fields
  precisely because unbroken flat colour reads as dead paint — literally this brief's
  problem. A CSS-only lozenge lattice at ~4% white, masked strongest behind the seal and
  faded behind the headline. No image, no alpha dependency.
- **Tint the white.** Base paper carries the park's hue at ~2% chroma. A page whose white
  is faintly wine or faintly forest reads as considered; `#ffffff` reads as unstyled.
- **Three bands, not two.** The current `#fff` / `#f7f8fb` alternation is a 4% step —
  invisible, which is why long pages read as sameness. Use paper / vellum (~7% step, with
  hue) / deep field, and let the field appear exactly twice, bookending the page.
- **Eyebrows are the visitor's question**, not decoration: *"When can I show up?"* ·
  *"What's coming up?"* · *"Who do I talk to?"* Costs nothing and turns a stack of blocks
  into a conversation.
- **One card component.** `pe-card-accent`, `ke-card-accent`, `bf-card-accent` and
  `blog-card-accent` are four near-identical gold slabs that stack into a row of stripes.
  Replace with a single card: background one step lighter than its band, 1px border, and a
  3px left edge in the park colour. No `translateY` hover lift — that's the templated tell.
- **Type:** body at 17px (public pages should read like pages, not like the 14–15px admin
  tables), major-third scale, all expressed through `--fd-font-scale` so the org's density
  control still works.
- FontAwesome **5** names only (`fa-map-marker-alt`, `fa-external-link-alt`,
  `fa-cloud-sun`). FA6 names render blank.

## Bugs folded into this work

| # | Bug | Blast radius |
|---|---|---|
| B1 | **Phantom officer cards.** `_shared/officers.tpl` skips a seat only when `persona === '' && role === ''`. A vacant seat has a role but no persona, so it renders as a card with an office title and nobody in it. | **187 / 342 parks (55%)** |
| B2 | **`MedievalSharp` is the default heading font** (`frontdoor.css:17`) and the seeder never creates a theme row — verified: 2 sites, 0 theme rows. Every org site ships in a faux-medieval face, the exact cosplay the project brand rule forbids. | Every org site |
| B3 | **`member_bar` is `display:none !important` below the phone breakpoint** ("hide on mobile to reclaim above-the-fold"). Inverted for park sites, where phone-in-a-parking-lot is the highest-frequency visit. | All park sites |
| B4 | **Theming doesn't reach below the hero.** `.fd-section-light{background:#fff}` / `.fd-section-muted{background:#f7f8fb}` are literals, and ~30 colours are hard-coded (`.fd-body-text#3a4356`, `.fd-link#1d4ed8` and its five clones, `.pm-addr#778`, …). A wine-red park still gets Bootstrap-blue links. | All org sites |

B4 is a precondition, not a nicety: the three-band tinted-paper scheme is dead on arrival
until the section bands read tokens.

Two related fixes worth doing at the same time: `.fd-kicker` uses the `font` shorthand,
which **resets font-family**, so every kicker silently ignores `--fd-font-body`; and
`#theme_container a` (specificity 1,0,1) hijacks link colour in dark mode, so any new
`.pk-*` anchor must be declared at that specificity from the first commit.

## Decay behaviour

**Never rots — safe to seed.** The quick-facts strip and `park_meeting` (sourced from
`ork_parkday`, maintained for attendance-credit reasons independent of the website —
self-healing: a park that moves fixes it in the ORK and the site corrects itself);
`park_officers` (sourced from `ork_officer`, maintained because it drives *authority* in
the app — an officer who lets it rot loses their own access); and the New Players page,
which is evergreen because "wear closed-toe shoes" is as true in 2036.

**Degrades gracefully.** Home's who-we-are paragraph when seeded from
`ork_park.description`; stale prose is mildly stale, never dangerous.

**Must never be seeded.** Any hand-typed time or place. This is the most dangerous rot
vector on the site because it fails *actively*: a `rich_text` saying "we meet Saturdays at
1" contradicts `ork_parkday` the day the park moves, and a newcomer drives to an empty
field. Every time and place claim comes from the dynamic block. Likewise hand-typed
officer emails — officers turn over on fixed terms.

## Cross-links out

| Target | Placement | Label |
|---|---|---|
| Kingdom site | Footer, every page | "Part of the Kingdom of {Name}" |
| Amtgard global | Footer | "About Amtgard" |
| **Atlas** | New Players **and** footer | "Not near {City}? Find another Amtgard group" |
| ORK park record | Contact page | "Park records, attendance, and awards on the ORK" |
| ORK personal | `member_bar`, logged-in only | "My attendance & awards" |

The Atlas link is the highest-leverage on the site: a large share of newcomer traffic is
geographically wrong — someone searched "amtgard near me" and landed on the wrong chapter.
Recovering that visitor costs the park nothing. It must point at the **global** Atlas, not
the kingdom's park list, because the wrong-geography visitor is frequently in a different
kingdom.

## Default copy

All seeded copy must be true for essentially any Amtgard park with no editing — no
weekday, no price, no city, no kingdom, no claim about attendance volume. Every
park-specific fact comes from a dynamic block. Copy that reads as instructions to the
officer ("Describe your park here") must never reach the public page.

The full copy deck — hero, the four first-day steps, the eight FAQ answers, and the CTA
band — is carried in the implementation plan.

The eight FAQ questions, in order, chosen against the real objections that stop someone
attending: what should I wear · do I need equipment · does it cost anything · what
actually happens · is it safe · **will I be the only new person** · how old do you have to
be · do I have to role-play. The sixth is the one no park site currently answers and the
one that most often decides it.

## Success criteria

1. A park provisioned with zero officer input has a home page that looks finished, states
   a real meeting time, and shows no empty band, no placeholder prose, and no phantom
   officer card.
2. No seeded page contains a hand-typed time, place, or officer contact.
3. The three-page nav fits one phone line at 390px with no wrap.
4. The hero renders correctly for all four cases: PNG device, JPEG device, no device
   (monogram), and retired park.
5. Light and dark both pass WCAG AA across the seeded page.
6. Zero horizontal overflow at 390px on all three pages.
7. `tests/cms-site` pins the park page set, nav labels, and the absence of `kingdom_*`
   blocks in park scope.

## Out of scope

A `park_links` table for multiple social channels; an officer contact relay (there is no
public contact route today, which is why no contact block is proposed); photo galleries;
per-park custom domains; the newcomer-count and headcount blocks.
