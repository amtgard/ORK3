# Design: Qualification-Test Question Sets (versioning)

**Status:** proposal — not built. Decisions from review on 2026-07-13 are baked in (§8).
**Branch:** `feature/qualification-tests` (unshipped — schema is still free to change)

---

## 1. The problem

Today a question is `active` or `archived`, and a live test draws **any** active question. So editing,
archiving, or adding a question changes the **live test immediately**. There is no way to build "the
8.7 bank" while 8.6 stays live — the only lever is turning the kingdom's test off, which blocks
everyone who needs to qualify.

### Why a per-question `draft` status does NOT work

Adding `draft` to the status enum and "publishing" by swapping all drafts → active / actives →
archived fails on the common case:

- **Wholesale duplication.** 30 questions, 5 real changes → you must clone all 30.
- **Loss of identity.** New rows = new `qual_question_id` = `ork_qual_question_stat` (times answered /
  times correct) resets and `ork_qual_report` flags detach. The success-rate history you use to *find*
  bad questions is fragmented every release.

The bug is treating **status as a property of the question**. Versioning is a property of the **set**.
A question isn't "draft" — it's *a member of* the 8.6 bank, the 8.7 bank, or both.

---

## 2. The model

A question belongs to **many** sets (many-to-many). That is the whole trick: **unchanged questions are
members of both versions — zero duplication, stats and identity intact.** The live test draws from the
one `published` set.

| Operation | What you do | Rows created |
|---|---|---|
| **Carry over** (most questions) | nothing — already in both sets | **none** |
| **Retire in v2** | remove from the draft (leave it in the live set) | **none** — still live until publish |
| **Add** | create question into the draft | 1 |
| **Reword for v2** | new row in the draft; old row stays in the live set | 1 — *only for questions you actually change* |
| **Fix a bogus question** (correction) | **edit in place** — it's in both sets, so both get the fix | **none** |
| **Publish** | draft → published, old published → retired | atomic |

---

## 3. Schema

```sql
CREATE TABLE ork_qual_question_set (
    qual_question_set_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kingdom_id           INT UNSIGNED NOT NULL,
    test_type            ENUM('reeve','corpora') NOT NULL,   -- BOTH tests (decision §8.3)
    name                 VARCHAR(100) NOT NULL,              -- "Spring 2026", "Wunjo's bank"
    rules_version        VARCHAR(100) NOT NULL DEFAULT '',   -- required to publish (§8.5)
    status               ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    created_by           INT UNSIGNED NOT NULL DEFAULT 0,
    created_at           DATETIME NOT NULL DEFAULT current_timestamp(),
    published_at         DATETIME NULL,

    -- Exactly one published AND at most one draft per kingdom+test, enforced by the DB.
    -- The slot is NULL for other statuses; MariaDB allows many NULLs in a UNIQUE index,
    -- so unlimited retired sets coexist while these two stay singular.
    published_slot       TINYINT UNSIGNED AS (IF(status = 'published', 1, NULL)) STORED,
    draft_slot           TINYINT UNSIGNED AS (IF(status = 'draft',     1, NULL)) STORED,

    PRIMARY KEY (qual_question_set_id),
    UNIQUE KEY uq_one_published (kingdom_id, test_type, published_slot),
    UNIQUE KEY uq_one_draft     (kingdom_id, test_type, draft_slot),
    KEY idx_kingdom_type_status (kingdom_id, test_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ork_qual_set_question (
    qual_question_set_id INT UNSIGNED NOT NULL,
    qual_question_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (qual_question_set_id, qual_question_id),
    KEY idx_question (qual_question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### `ork_qual_question.status` stays, and is orthogonal to membership

- **`status = 'archived'`** → *"this question is dead — never use it again."* Global kill switch:
  excluded from every draw and from the Global Question Library, and shows the **ARCHIVED** badge in
  attempt reviews (already built).
- **Set membership** → *"this question is part of version N."* Removing it from the draft does **not**
  archive it — it stays `active` and stays **live in the published set** until you publish.

**Draw = (member of the published set) AND (status = 'active').**

That distinction is what lets you retire a question *from v2* without killing it in *v1*.

### Stamping "what did they take" (§8.5)

Because a new GMR may publish a fresh bank under the **same** rules version, `rules_version` alone
cannot identify which test someone sat. So the **set** is stamped too:

```sql
ALTER TABLE ork_qual_attempt
    ADD COLUMN qual_question_set_id INT UNSIGNED NULL,
    ADD COLUMN set_name             VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE ork_qual_result
    ADD COLUMN qual_question_set_id INT UNSIGNED NULL,
    ADD COLUMN set_name             VARCHAR(100) NOT NULL DEFAULT '';
```

`set_name` is a free-text snapshot (like `rules_version`) so it stays truthful if the set is later
renamed. Result: an attempt/pass reads *"Rules 8.7 — 'Spring 2026' bank"*, which is unambiguous even
when two sets share a rules label. Combined with the immutable per-question snapshot, both players and
admins can see exactly what was taken.

---

## 4. Draw-path change

`QualTest::_loadQuestionsAndAnswers()` (`class.QualTest.php:566`) — the one query that decides what a
live test asks:

```sql
-- BEFORE
SELECT qual_question_id, question_text, answer_mode
FROM ork_qual_question
WHERE kingdom_id = :k AND test_type = :t AND status = 'active'
ORDER BY RAND() LIMIT :n

-- AFTER
SELECT q.qual_question_id, q.question_text, q.answer_mode
FROM ork_qual_question q
JOIN ork_qual_set_question sq ON sq.qual_question_id    = q.qual_question_id
JOIN ork_qual_question_set s  ON s.qual_question_set_id = sq.qual_question_set_id
WHERE s.kingdom_id = :k AND s.test_type = :t AND s.status = 'published'
  AND q.status = 'active'
ORDER BY RAND() LIMIT :n
```

**Also gets the set join:** `getLibraryQuestions()` must share only from the sharing kingdom's
**published** set — otherwise unpublished draft questions leak to other kingdoms.

**Untouched:** `getCorrectAnswers` (scoring), `recordQuestionStats`, `ork_qual_report`, and the attempt
snapshots — all keyed by question id, all indifferent to sets.

---

## 5. Publish flow

**Guards (hard-refuse, §8.2):**
1. Draft has **≥ `qual_config.question_count`** members with `status='active'` — otherwise the test
   would instantly break with "not enough active questions".
2. Every member has ≥ 2 answers and ≥ 1 correct (the existing validity invariant).
3. **`rules_version` is non-empty.** It is **not** required to *differ* from the previous set — a new
   GMR can publish a fresh bank under the same edition when the rules haven't changed (§8.5).

```sql
BEGIN;
  UPDATE ork_qual_question_set SET status = 'retired'
   WHERE kingdom_id = :k AND test_type = :t AND status = 'published';

  UPDATE ork_qual_question_set SET status = 'published', published_at = NOW()
   WHERE qual_question_set_id = :draft_id;

  -- keep the label everything downstream already uses in sync
  UPDATE ork_qual_config SET rules_version = :set_rules_version
   WHERE kingdom_id = :k AND test_type = :t;
COMMIT;
```

Atomic; the unique index makes a double-publish race impossible. `recordAttempt`/`recordResult` then
stamp `rules_version` + `qual_question_set_id` + `set_name` from the published set.

---

## 6. Migration (behavior-preserving)

Backfill one `published` set per kingdom+test that has questions, named **"Current"**, containing
exactly today's **active** questions:

```sql
INSERT INTO ork_qual_question_set
       (kingdom_id, test_type, name, rules_version, status, created_at, published_at)
SELECT DISTINCT q.kingdom_id, q.test_type, 'Current',
       COALESCE(c.rules_version, ''), 'published', NOW(), NOW()
FROM ork_qual_question q
LEFT JOIN ork_qual_config c
       ON c.kingdom_id = q.kingdom_id AND c.test_type = q.test_type;

INSERT INTO ork_qual_set_question (qual_question_set_id, qual_question_id)
SELECT s.qual_question_set_id, q.qual_question_id
FROM ork_qual_question q
JOIN ork_qual_question_set s
       ON s.kingdom_id = q.kingdom_id AND s.test_type = q.test_type AND s.status = 'published'
WHERE q.status = 'active';
```

**`published ∩ active` then returns exactly what `status='active'` returned before → zero behavior
change.** Nothing differs until someone creates a draft. Archived questions are deliberately not made
members (they're dead anyway).

**First question in a new kingdom:** creating a question when no published set exists auto-creates one
("Current") and adds it — so the "just start adding questions" flow keeps working and a published set
always exists once questions do. No implicit fallback in the draw path.

---

## 7. UI

### The Bank (replaces the "Unused bucket" idea — §8.1)

Nothing is ever lost, so we don't need a special orphan bucket. The questions page has two lenses:

- **In this set** — the working view for the set you're editing (published or draft).
- **The Bank** — *every* question the kingdom has ever written for this test, each showing chips for
  which sets it belongs to (`Live`, `Draft`, `Previous`, or none) plus its `%` success and report flags.
  Actions: **Add to draft** / **Remove from draft** / **Archive**.

An "orphan" (in no set) is simply a bank question with no chips — visible, reusable, and pullable into
a new draft. This directly supports "a new GMR builds a fresh bank" (curate from the bank) without
inventing a new concept.

### Versions panel

`Live: "Spring 2026" (Rules 8.7) · Draft: "8.8 rework" · Previous: 3`

- **Create draft** — clones the live set's membership (one draft at a time, §8.4).
- Rename, set `rules_version`, **Publish**, discard draft.
- **Previous versions** list (read-only history).

**Naming:** DB status is `draft | published | retired`; the UI says **"Previous versions"**. We
deliberately avoid the word *archived* for sets so it never collides with question-level `archived`.

### ⚠️ The load-bearing safety affordance

When editing a question that is a member of the **published** set, warn:
> *"This question is live — editing changes the current test immediately."*

In-place edit is **correct** for a bogus-question correction (it fixes live and draft at once), but
dangerous if the admin believes they're safely working on a draft. This warning is what makes the
correction-vs-version distinction legible.

---

## 8. Decisions (from review)

1. **Orphans** → no "Unused" bucket. A **Bank** view shows every question with set-membership chips;
   orphans are simply unchipped and reusable. Confirmed: orphans (and archived questions) **still
   appear correctly in a player's test history**, because attempt snapshots are immutable and
   independent of sets/status.
2. **Publish guard** → **hard-refuse** (not warn) if the draft is below the draw count.
3. **Both test types** → Reeve *and* Corpora, so there's one learning curve. (Corpora changes at
   Althings too.)
4. **One draft at a time** → enforced by `uq_one_draft`. Lifecycle: one **published**, at most one
   **draft**, unlimited **previous**.
5. **Version required, not unique** → `rules_version` must be non-empty to publish, but need **not**
   differ from the previous set: a new GMR may publish a fresh bank under an unchanged edition. Because
   of that, the **set** is also stamped on attempts/results (§3) so "what did they take" is unambiguous.

6. **Review indicators** → build BOTH: the existing amber **ARCHIVED** badge (question is dead) *plus*
   a quieter, distinct indicator for *"no longer in the live set"* (question is fine, just not in the
   current version). Try it and tune once visible.
7. **New questions target the currently-viewed set** → "Add Question", "Bulk Import" and "Add from
   Library" all add into whichever set the admin is viewing (draft or published). Try it and tune.

---

## 9. Cost & risk

- **Migration:** 2 tables + 4 columns + behavior-preserving backfill.
- **Model:** 1 query rewritten (draw path), 1 joined (library), new set methods
  (create/clone/publish/add/remove/list), stamping in `recordAttempt`/`recordResult`.
- **UI:** Bank view + Versions panel + per-question set actions + the live-edit warning.
- **Risk: low** — behavior is identical until someone creates a draft.
