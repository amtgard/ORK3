---
name: Knight Belt Display Preference
description: Icons tab in Design My Profile letting knights choose White Belt / My Belt(s) / No Belt for the hero icon
type: spec
---

# Knight Belt Display Preference

**Date:** 2026-04-23
**Status:** Approved for build

## Summary

Add an "Icons" tab to the Design My Profile modal that is only rendered when the viewing owner is a belted knight. Three radio options control the belt icon displayed next to the persona in the hero:

- **White Belt** (default, current behavior) — the single generic `belt.svg`
- **My Belt(s)** — the knight's actual knighthood belts, one per knighthood, in award-date order (oldest first)
- **No Belt** — nothing

## Schema

Add one column to `ork_mundane_design`:

```sql
ALTER TABLE ork_mundane_design
  ADD COLUMN belt_display varchar(10) NOT NULL DEFAULT 'white'
  AFTER show_beltline;
```

Allowed values (validated server-side): `white`, `own`, `none`.

Migration: `db-migrations/2026-04-23-belt-display.sql`.

## Assets

Five PNGs copied into `assets/images/`:

| Award ID | Award | File |
|---|---|---|
| 18 | Knight of the Crown | `belt-crown.png` |
| 17 | Knight of the Flame | `belt-flame.png` |
| 19 | Knight of the Serpent | `belt-serpent.png` |
| 20 | Knight of the Sword | `belt-sword.png` |
| 245 | Knight of Battle | `belt-battle.png` |

The existing `belt.svg` stays as the White Belt option's source.

## Backend (`class.Player.php`)

**Read path (~line 338):** add `'BeltDisplay' => $design->belt_display` into the existing `$design->*` key block.

**Write path (~line 1043):** add a validated assignment inside the own-profile block:

```php
$validBeltDisplays = ['white','own','none'];
$design->belt_display = (isset($request['BeltDisplay']) && in_array($request['BeltDisplay'], $validBeltDisplays)) ? $request['BeltDisplay'] : $design->belt_display;
```

## Template (`Playernew_index.tpl`)

**Image map constant** near the top, next to the existing `$knightAwardIds`:

```php
$beltImageMap = [
    17 => 'assets/images/belt-flame.png',
    18 => 'assets/images/belt-crown.png',
    19 => 'assets/images/belt-serpent.png',
    20 => 'assets/images/belt-sword.png',
    245 => 'assets/images/belt-battle.png',
];
```

**Own-belts collection** built from `$Details['Awards']`: keep awards whose `AwardId` is in `$knightAwardIds`, sort ascending by `Date`, keep one image per award.

**Hero render (current single `<img class="pn-belt-icon">`):** replace with a switch on `$Player['BeltDisplay']`:

- `white` → existing `belt.svg` (unchanged)
- `own` → one `<img class="pn-belt-icon pn-belt-icon-own">` per owned knighthood belt, inline, same px height as the current icon, small gap
- `none` → render nothing

**Icons tab** — added inside the `<?php if ($isOwnProfile): ?>` Design modal block, wrapped in `<?php if ($isKnight): ?>`:

- Tab button: `<button class="pn-design-tab" data-panel="icons"><i class="fas fa-shield-alt"></i> Icons</button>`
- Panel with three radio inputs (name=`pn-design-belt-display`, values `white`/`own`/`none`), hint text, and a live preview strip that re-renders on change using the same image map
- Pre-selected radio reflects current `$Player['BeltDisplay']`
- Informational `.pn-dm-hint` infobox with the text: *"If one or more of your Knighthoods is recorded as a Note or unreconciled award, the icon will not be able to show. Reach out to your Monarch or Prime Minister to reconcile the record so things can display as expected."*

## Submit

The existing Design-modal submit payload gets one more key: `BeltDisplay: <selected radio value>` when the Icons tab exists. Reuses the existing `UpdatePlayer` SOAP call — no new endpoint.

## Scope

**In:**
- Schema column, migration, read/write wiring.
- Icons tab UI (Knights-only) + hero render branching.

**Out:**
- Reordering or individually hiding specific belts when "My Belt(s)" is chosen — always all owned knighthoods in ascending date order.
- Changes to the sidebar beltline card.
- Non-knight users — tab is hidden, not shown disabled.
- Any preview of belt display in the Welcome tab.

## Acceptance

- Non-knight owners: Design modal opens without an Icons tab.
- Knight owner with default value: hero shows the generic white `belt.svg` as before.
- Knight owner selects "My Belt(s)": hero shows their earned belts in date order, side by side, same icon size as current.
- Knight owner selects "No Belt": hero shows nothing after the persona name.
- Saving the Icons tab persists `belt_display`; reopening the modal pre-selects the saved value.
- Invalid `BeltDisplay` values in the request are ignored (kept at stored value).
