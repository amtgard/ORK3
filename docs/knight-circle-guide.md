# Circle of Knights Manager — How to Use

The Circle of Knights Manager gives a kingdom's knights one place to see who the knights are, whether
they are eligible to take part in the Circle's business, and who is on the road to knighthood. It has
two halves: a **Roster** of current knights, and a **Candidates** pipeline that follows a player from
first mention to knighting.

Find it under **Reports > Awards > Circle of Knights** on your kingdom page. The link only appears if
you are allowed in.

---

## Who can do what

| Role | What they can do |
|---|---|
| **ORK admin** | Everything, in every kingdom, including naming a Guildmaster without that person holding a knighthood |
| **Guildmaster of Knights (GMoK)** | View everything, manage the pipeline, change settings, and add or remove deputies |
| **Deputy** | Same day-to-day powers as the GMoK on the roster, pipeline and settings, as appointed by the GMoK or the kingdom |
| **Kingdom officer with CREATE authority** (usually the Monarch and Prime Minister) | View the module and appoint the GMoK and deputies. Appointment is under **Admin > Knights > Circle of Knights: Guildmaster** |
| **Kingdom officer with EDIT authority** | View the roster, and the pipeline too when the kingdom setting "officers see the pipeline" is on (the default) |
| **Resident knights** | View-only. A knight never sees their own candidacy in the pipeline |
| **Everyone else** | No access |

The GMoK must be a knight who is an active, non-Legend player. Naming a new GMoK replaces the old one.
Players marked as Legends are never listed as candidates or appointed.

---

## The five orders

The Circle tracks the five knightly orders in the ORK: **Knight of the Flame, Crown, Serpent and Sword**,
and **Knight of Battle**. Knight of Battle is the fifth order. A player may hold more than one, and each
order is a separate candidacy.

---

## The Roster

The Roster lists every active knight whose home is in the kingdom (principalities included, by default),
plus knights from elsewhere who have signed in within the kingdom during the window. Non-residents are
marked as such.

* Filter by order with the pills across the top, and toggle **Eligible only** or **Resident only**.
* Each row shows sign-ins in the window, dues status, waiver, suspension, months as a knight, and an
  Eligible flag. If someone is not eligible, the reasons are listed.
* Export to CSV from the table toolbar.
* The sentence above the table spells out the current rules, plus any caveat the GMoK wrote.

---

## Configuring eligibility (Settings tab)

The GMoK and deputies set the rules. Everyone else sees them read-only. Until a kingdom saves anything,
sensible defaults apply.

| Setting | Meaning | Default |
|---|---|---|
| Minimum sign-ins | How many sign-ins a knight needs | 6 |
| Window (months) | How far back to look (1-24) | 6 |
| Sign-in counting | Count sign-ins, distinct days, or distinct weeks | Count |
| Sign-in scope | Only this kingdom, or anywhere | Kingdom |
| Online attendance | Follow the kingdom's setting, always include, or always exclude | Kingdom setting |
| Require dues | Dues paid; in this kingdom or anywhere | On |
| Require waiver | A waiver on file | Off |
| Require residence | Home kingdom is this one | On |
| Include principalities | Treat principalities as part of the kingdom | On |
| Exclude suspended | Suspended players are not eligible | On |
| Minimum months as knight | Time since knighting (0-600) | 0 |
| Use kingdom voting rules | Also require whatever the kingdom's voting-eligibility rules require | Off |
| Watchlist minimum rank | Rank at which the suggested watchlist starts (1-12) | 9 |
| Officers see pipeline | Let EDIT officers view the Candidates tab | On |
| Caveat | Free text (up to 1000 characters) shown with the roster rules | blank |

The same tab holds the **Circle Officers** card, where the GMoK and deputies are listed and (for people
with appointment rights) changed. Search for a player in your kingdom to appoint them.

---

## The Candidates pipeline

A **candidacy** is one player for one order. The same player can be a candidate for two orders at once.

### Stages

1. **Watchlist** — someone the Circle is keeping an eye on.
2. **Masterhood** — the player holds the master title of the matching ladder that leads to that order.
3. **Under Discussion** — the Circle is talking about them.
4. **Voting** — a vote is under way.
5. **Knight-Elect** — approved, awaiting the ceremony.
6. **Knighted** — the knighthood has been granted. Reached automatically.

Two more stages sit outside the path:

* **Holding** — parked for now. From Discussion, Voting or Knight-Elect a candidate can be put on Hold
  and later brought back to Discussion or Voting (Holding can also be dismissed).
* **Dismissed** — removed from active consideration. Dismissal stops automatic re-adding. A dismissed
  candidate can be restored to the Watchlist. Use **Show dismissed** to see them.

### Allowed moves

| From | To |
|---|---|
| Watchlist | Masterhood, Discussion, Dismissed |
| Masterhood | Watchlist, Discussion, Dismissed |
| Discussion | Voting, Holding, Watchlist, Dismissed |
| Voting | Knight-Elect, Discussion, Holding |
| Holding | Discussion, Voting, Dismissed |
| Knight-Elect | Voting, Discussion, Holding |
| Dismissed | Watchlist |
| Knighted | none |

Drag a card to a new column, or use the card's **Move to...** menu. Leaving **Voting** opens a short form
asking for the outcome, the votes for, against and abstaining, and a comment. Every move is written to the
candidate's history. Each candidate also has private notes (author or GMoK can delete them) and a facts panel.

### Automatic transitions

The system keeps the pipeline honest and never moves anyone backwards:

* When a player is **granted the knighthood** for the order, that candidacy becomes **Knighted**, from any stage.
* When a **Watchlist** player is granted the matching **master** title, they move to **Masterhood**.
* Residents who already hold a master title but not the matching knighthood and have no candidacy are
  added to **Masterhood** automatically.
* Automatic moves are recorded in history as "Automatic: ..." with no human actor. The **Refresh** button
  re-runs the check on demand.

Ladder awards map to orders automatically where the ORK knows the mapping.
If a ladder does not map to an order, the GMoK chooses the order when promoting.

### Suggested watchlist

Above the Watchlist is a **Suggested** drawer. It lists resident players at or above the **watchlist
minimum rank** in a knight-track ladder who do not hold the knighthood and are not already candidates.
The suggested rule is **rank 9 or 10**: set the minimum to 9 (the default) to start suggesting at rank 9, or
to 10 for a shorter list. Suggestions are computed each time; nothing is stored until you act.

* **Promote** adds them to the Watchlist (you pick the order if it cannot be worked out).
* **Dismiss** removes the suggestion so it does not return.

---

## Tips

* Appoint the GMoK first; they then control rules and deputies.
* Apply the migration `db-migrations/2026-11-28-knight-circle.sql` and restart the app container
  (the schema cache is held in APCu).
* The roster is cached for five minutes. Saving settings clears the cache.
