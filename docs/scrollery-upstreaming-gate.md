# Scrollery — the upstreaming gate

**Date:** 2026-09-06 · batch 2 item **45**, third part.

## Why this file exists

`docs/superpowers/specs/2026-09-03-scrollery-design.md:333` makes attribution "a
prerequisite for any later upstreaming to `amtgard/ork3`", and the phase plan defers the
decision itself to *"once the module has run a real event."*

Nothing defined what a real event was, what "run" meant, who decided, or what evidence
they were supposed to look at. A deferral with no exit condition is not a deferral — it
is an open question that stays open because nobody can tell when it closed.

This file closes it. **Three conditions. Two of them are a SQL query.**

---

## The gate

Scrollery is proposed for upstreaming to `amtgard/ork3` when **all three** hold.

### 1 · Breadth — at least 3 kingdoms have printed a certificate

Not one enthusiastic kingdom, and not one officer. Three independent kingdoms means the
module has survived three different award catalogues, three sets of `ork_kingdomaward`
overrides and three officers who did not build it.

```sql
SELECT COUNT(DISTINCT CASE
         WHEN sp.scope_type = 'kingdom' THEN sp.scope_id
         WHEN sp.scope_type = 'park'    THEN p.kingdom_id
       END) AS kingdoms
FROM   ork_scroll_print sp
LEFT JOIN ork_park p
       ON p.park_id = sp.scope_id
      AND sp.scope_type = 'park';
```

`scope_id` is a **park** id on park-scoped rows, so the join is load-bearing; counting
`scope_id` alone mixes park ids and kingdom ids and gives a meaningless number.
Composed certificates carry `scope_type = ''` and are correctly excluded — a
hand-composed scroll is not evidence that a kingdom adopted the module.

### 2 · Depth — at least one real court, i.e. a single job of 10+ certificates

One certificate proves the happy path. A court proves the pagination, the print chunking,
the rasteriser ceiling and the officer's patience.

`ork_scroll_print` has no job id — a bulk run is N inserts by one user within a second or
two — so a "job" is defined as **rows sharing `printed_by_id` inside a one-minute
bucket**. That is a deliberately loose definition; it can only over-count by merging two
runs a printer made back to back, which is still one court.

```sql
SELECT printed_by_id,
       MIN(printed_at) AS job_start,
       COUNT(*)        AS certificates
FROM  (SELECT printed_by_id, printed_at,
              FLOOR(UNIX_TIMESTAMP(printed_at) / 60) AS minute_bucket
       FROM   ork_scroll_print) j
GROUP BY printed_by_id, minute_bucket
HAVING certificates >= 10
ORDER BY certificates DESC;
```

Any row returned satisfies the condition.

### 3 · Stability — zero unresolved rendering defects for 30 days

A *rendering defect* is anything that made the printed page wrong: missing or broken
art, text clipped or overflowing its box, a blend that printed as a flat grey or a black
page, a blank sheet, wrong pagination, wrong recipient, wrong pronoun, wrong wording.

It is **not**: a feature request, a wording preference, a browser-settings complaint
(headers/footers, margins, scale), or anything the advisory panel already warns about.

This is the one condition the print log cannot answer, so it needs a written register.
Keep it as a checklist at the bottom of this file — one line per defect, with the date
it was reported and the date it was fixed. The condition is met when **every entry has a
fix date and the most recent fix date is more than 30 days ago**.

If the register is empty *and* conditions 1 and 2 are met, the 30 days run from the date
condition 2 was first satisfied. An empty register with no printing is not stability.

---

## Who checks, and when

The **maintainer** checks, and only on being asked to — either by someone proposing the
PR, or at the next batch review after conditions 1 and 2 first pass. There is no cron, no
dashboard and no automated nag; a gate that fires by itself would just create work.

Run the two queries against production:

```sh
docker exec -i ork3-php8-db mariadb -uork -psecret ork -e "<query>"
```

Record the result and the date in the log at the bottom. Three lines of evidence, once,
is the whole ceremony.

## What passing the gate authorises

A pull request to `amtgard/ork3`. Nothing else — passing does not commit anyone to
merging it, and the attribution prerequisite in the phase-1 spec still stands
independently (`NOTICE` entry present, in-app credit visible).

## What failing looks like

Failing is the normal state and needs no action. If conditions 1 and 2 pass but 3 keeps
resetting, that is the gate working: the defect stream is telling you the module is not
finished, and the fix is to fix them, not to lower the bar.

---

## Evidence log

| Date checked | Kingdoms (≥3) | Largest job (≥10) | Register clear 30d | Verdict |
|---|---|---|---|---|
| 2026-09-06 | 8 *(local dev data — not production)* | 27 *(local dev data)* | n/a | **not assessed** — the queries were validated here, not the conditions |

## Rendering-defect register

*One line per defect that made a printed page wrong. Empty is fine; unfixed is not.*

| Reported | Defect | Fixed |
|---|---|---|
| — | *(none recorded)* | — |
