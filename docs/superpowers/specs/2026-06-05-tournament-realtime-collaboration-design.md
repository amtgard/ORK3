# Tournament Bracket Real-Time Collaboration — Design

**Date:** 2026-06-05
**Branch:** feature/tournament-module
**Status:** Approved design — ready for implementation plan

## Goal

Let multiple reeves work the same tournament bracket at the same time and see
each other's changes appear near-instantly, without manual refresh. Single/double
elimination see sparse, seconds-to-minutes-apart actions; **Ironman** sees many
disparate actions within the same span of seconds, so the design must stay cheap
under bursty concurrent writes.

## Decisions captured during brainstorm

- **Core need:** *Live read-sync* (peers' changes appear in ~1s) **+ optimistic
  edits** (local action applies instantly, reconciles/rolls back server-side).
- **Explicitly NOT in scope:** hard locking, claim-a-match, and full presence UI
  ("who is editing what"). Conflicts are handled quietly by the reconcile step,
  not by blocking people. A lightweight actor attribution on incoming changes
  (e.g. "Ring 3 result by Sue") is allowed; a presence roster is not.
- **Infrastructure:** **in-stack only** — no new services. PHP 8 (Apache) +
  MariaDB + Memcached, per `docker-compose.php8.yml` (`ork3-php8-app` /
  `ork3-php8-db`). (Apache's worker-per-request model can't hold WebSocket/SSE
  connections without pinning a worker per client, so push is out regardless of
  PHP version.)
- **Sync scope (actions that sync live):** *match results & advancement* and
  *participant status* (eliminations, withdrawals/forfeits, bye/walkover
  resolution). **Bracket lifecycle and config/seeding stay on the normal
  request→refresh flow** — they're rare; a coarse "something changed, refresh"
  is sufficient for them.

## Architecture — Approach C: Memcache heartbeat + DB change-log

A monotonic per-tournament `seq` cursor is the single source of "how current am
I." The high-frequency poll reads that cursor from **Memcache** (no DB hit); only
when it advances does the client fetch the actual **deltas** from an append-only
change-log table in MariaDB. Clients apply deltas incrementally and re-render only
the affected cells. Local edits apply optimistically and reconcile against the
server's authoritative response, with `action_id`-based echo-dedup so a client
never double-applies its own change when it returns in the feed.

Why C over the alternatives:
- **A ("editors poll like spectators" → full `refreshAll()` on any change):**
  heavy exactly in the Ironman case, and a client's own POST bumps the hash so its
  next poll refetches and can stomp an in-flight optimistic edit. No notion of
  *what* changed → crude reconcile. Rejected.
- **B ("sequenced change-log", every poll hits DB):** correct, but 1s × N reeves
  is a steady (small, indexed) DB load. C is B with a Memcache accelerator in
  front, and degrades to B if the cache is cold.

### Load math (the Ironman concern)

6 reeves, 1s polling, steady state = **6 Memcache GETs/sec, zero DB hits** while
nothing changes. When a result lands: 1 write + up to ~6 indexed delta SELECTs
(single-digit rows) over the next second. Negligible next to a normal page load.

---

## Section 1 — Data model & write path

### New table `ork_tournament_event` (append-only change-log)

| column | type | purpose |
|---|---|---|
| `event_id` | BIGINT AUTO_INCREMENT PK | physical insert order |
| `tournament_id` | INT NOT NULL | poll scope |
| `bracket_id` | INT NULL | affected bracket (NULL = tournament-level) |
| `seq` | BIGINT NOT NULL | per-tournament monotonic cursor clients hold |
| `type` | VARCHAR(32) | `match_result`, `advancement`, `participant_status`, `coarse` |
| `payload` | TEXT | JSON minimal delta (see below) |
| `actor_id` | INT NULL | who did it |
| `actor_name` | VARCHAR(255) NULL | for the toast/ticker |
| `action_id` | CHAR(36) NULL | client-generated UUID for echo-dedup + idempotency |
| `created` | DATETIME | retention / debugging |

Indexes: `KEY (tournament_id, seq)` (the only read path); `UNIQUE KEY
(tournament_id, action_id)` (write idempotency — a retried POST can't double-emit).

### New table `ork_tournament_seq` (cursor allocator)

| column | type | purpose |
|---|---|---|
| `tournament_id` | INT PK | one row per tournament |
| `last_seq` | BIGINT NOT NULL DEFAULT 0 | current high-water cursor |

`seq` is allocated with `UPDATE ork_tournament_seq SET last_seq = last_seq + 1
WHERE tournament_id = ?` **inside the same transaction** as the mutation, then the
event is inserted with that value. (Deliberately not reusing the global
`event_id` auto-increment, which interleaves across tournaments and would force
clients to over-fetch.) A row is lazily created on first event for a tournament.

### Memcache counter

Key `tn:trn:{tournamentId}:seq` set to the new `last_seq` **after commit**, with a
short TTL (e.g. 60s) so it self-heals from the DB. On read-miss or expiry, the
heartbeat endpoint runs `SELECT last_seq FROM ork_tournament_seq` once and
repopulates. **The cache is an accelerator, never the source of truth.**

### Payload shapes (minimal deltas — applyable without a refetch)

- `match_result`: `{match_id, result, score, bouts}`
- `advancement`: `{match_id, slot, participant_id, participant_name}` — one event
  per filled slot. A single result that cascades walkovers emits one
  `match_result` + N `advancement` events under consecutive `seq`s, in apply order.
- `participant_status`: `{participant_id, eliminated, withdrawn, bracket_side, ...}`
- `coarse`: `{bracket_id}` — emitted by lifecycle/config writes (out of
  fine-grained scope) purely to advance the cursor so peers `refreshAll()` that
  bracket.

### Where it hooks in

`class.Tournament.php::PostMatchResult()` (~1792–1891), `applyAdvancement()`, and
the participant-status methods already run inside transactions and already compute
exactly these state changes. Emit events **at the point state mutates, inside the
existing transaction**, via a single `emitEvent()` helper that also bumps the seq
allocator. An event therefore exists **iff** the change committed. No second write
path to keep in sync. Per the architecture-layers rule, all of this lives in
`system/lib/ork3/`; `model.Tournament.php` stays a thin pass-through.

### Retention

The log is **not** the system of record (`ork_match` / `ork_participant` are), so
pruning is safe. Prune events past tournament finalize + a buffer (~7 days) via the
existing one-off script pattern, or lazily on finalize. Clients that fall behind
retention get a `resync` (Section 2).

---

## Section 2 — Read / sync path

Two endpoints on `controller.TournamentAjax.php`:

### 1. Heartbeat — `TournamentAjax/tournament/{tid}/seq` (high-frequency)

Returns `{seq: N}`. One Memcache GET, no DB in the common case. On miss/expiry:
one `SELECT last_seq`, repopulate, return. This is the editor's replacement for
the old CRC `/version` hash.

### 2. Delta fetch — `TournamentAjax/tournament/{tid}/changes?since={seq}` (rare)

`SELECT * FROM ork_tournament_event WHERE tournament_id=? AND seq>? ORDER BY seq`
→ `{events: [...], seq: highWater}`. If `since` is older than retention or an
unappliable gap is detected, returns `{resync: true}` and the client falls back to
the existing full `refreshAll()` for affected brackets, then resumes delta mode.
This is the safety valve — the log never has to be guaranteed-infinite.

### Client poll loop (extends the existing adaptive `setTimeout` poller)

Holds `clientSeq` per tournament. Each tick: GET `/seq`.
- `seq === clientSeq` → do nothing (the common case; pure Memcache read).
- `seq > clientSeq` → GET `/changes?since=clientSeq`, apply ordered deltas, set
  `clientSeq = newSeq`.

**Adaptive cadence** (reusing existing tab-visibility logic):
- ~1s when a bracket is `active` **and** tab visible
- ~5s when visible but idle
- paused when hidden; snap-resync on `visibilitychange` → visible
- optional brief nudge to ~0.75s right after local activity (Ironman bursts),
  backing off when quiet.

### Lifecycle/config writes

Out of fine-grained scope, but still `seq++` the cursor and emit a `coarse` event
so peers' heartbeats fire and they `refreshAll()` that bracket. Rare → a full
refetch there is fine. Keeps **everything** converging through one cursor (and
keeps spectators correct if they migrate to this path later).

---

## Section 3 — Client-side optimistic apply, echo-dedup & reconcile

Today: POST → await → refetch → full re-render. New flow:

### Optimistic apply (local, instant)

1. Reeve records a result. Client generates an `action_id` (UUID), applies the
   change to `TnConfig.bracketData[...]` immediately, and runs a **targeted
   re-render** (affected match cell + any slots its advancement fills) — not a
   full `tnRenderBracketViz()`. The cell shows a subtle "pending" state until
   confirmed.
2. Client records `action_id` in an in-flight set and POSTs `{..., action_id}` to
   the existing match/participant endpoints.

### Server response → reconcile

- **Accept:** `{ok:true, seq:N, ...canonical fields}`. Clear "pending", reconcile
  the optimistic guess against the **server-authoritative** fields (the server
  computed the real advancement cascade), and advance `clientSeq` to `N`, skipping
  the client's own already-applied events.
- **Reject (conflict):** `{ok:false, reason, currentState}` (e.g. optimistic lock
  fired — someone already recorded that match). **Roll back** the optimistic
  change, apply `currentState`, show a non-blocking toast:
  *"Bob already recorded this match (2–1). Updated."* No data lost, no modal.

### Echo-dedup (key correctness piece)

An accepted action also lands in the change-log and returns via a peer's — or the
client's own next — delta poll. The client keeps a short-lived (time-windowed) set
of `action_id`s it originated; an incoming delta whose `action_id` is in that set
is **dropped** (already applied locally). Prevents the double-apply/flicker that
Approach A would suffer.

### Applying peers' deltas

For each incoming non-self event (`match_result` / `advancement` /
`participant_status`): mutate `TnConfig`, targeted-re-render just those cells with
a brief highlight + optional unobtrusive actor attribution ("Ring 3 result by
Sue"). `seq` ordering guarantees cascading advancements apply in order. A `coarse`
event triggers `refreshAll()` for its bracket.

### Targeted re-render

Factor `tnRenderBracketViz()` so a single-match/single-slot update repaints only
that region of the SVG/DOM — for perf and to avoid disrupting a reeve mid-action
elsewhere on the bracket. This refactor is a prerequisite for both optimistic
apply and peer-delta application.

---

## Section 4 — Conflict policy (finalized)

- **Match result:** existing optimistic lock (`UPDATE … WHERE result IS NULL` +
  `rowCount()`) remains authoritative. Race loser gets rollback + toast showing the
  result that landed. Correcting/voiding an existing result is a separate explicit
  overwrite action that emits a fresh event — not a conflict.
- **Participant status:** last-write-wins, made **idempotent on `action_id`**
  (unique key) so retries/double-clicks can't double-emit. Two reeves withdrawing
  the same participant converge to the same end state; divergent forfeit/annul
  choices resolve last-write-wins + toast.
- **Advancement:** never client-authored as a primary action — it's a
  server-computed consequence of a result, so it can't independently conflict.

## Section 5 — Edge cases & failure modes

- **Memcache cold/stale:** short TTL on the counter + DB `last_seq` fallback.
- **`since` older than retention / unappliable gap:** `resync:true` → client
  `refreshAll()` for the bracket, then resumes delta mode.
- **Sleep/offline → resume:** heartbeat shows a jump → delta-fetch or resync.
- **Network retry of a POST:** `action_id` unique key makes the write idempotent.
- **Atomicity:** `seq++`, event insert, and state mutation are one transaction;
  Memcache set is post-commit (event exists iff committed). A failed Memcache set
  self-heals via the TTL + DB fallback.
- **Bounded memory:** the client's originated-`action_id` set is time-windowed.

## Section 6 — Testing strategy

- **PHP / service layer:**
  - Event emitted **iff** the transaction commits (rolled-back txn → no event).
  - `seq` strictly monotonic per tournament under interleaved writes.
  - Rejected optimistic-lock path emits **no** event.
  - `action_id` idempotency (duplicate POST → one event, one state change).
- **Endpoints (curl-auth session, single cookie jar per the local-curl-auth
  protocol; single-device sessions so login + calls in one block):**
  - `/seq` returns the cursor and is Memcache-only when idle (verify no DB query
    in the log during idle polling).
  - `/changes?since=` returns ordered deltas; `resync` fires when `since` too old.
- **Multi-client (Chrome, post-implementation verification only):**
  - Two sessions: peer deltas appear ~1s; pending→confirm/rollback works; no echo
    double-apply.
  - Simulate concurrent Ironman posts → deterministic convergence, no lost result.

---

## File touch list

- **DB migration** `db-migrations/2026-06-05-tournament-realtime-events.sql` —
  create `ork_tournament_event` + `ork_tournament_seq`. Run via
  `docker exec -i ork3-php8-db mariadb -u root -proot ork < ...`.
- **`system/lib/ork3/class.Tournament.php`** — `emitEvent()` + seq-allocator
  helpers; hooks in `PostMatchResult()`, `applyAdvancement()`, participant-status
  methods; `GetSeq()` / `GetChangesSince()` read helpers; coarse emit on
  lifecycle/config writes.
- **`orkui/model/model.Tournament.php`** — thin pass-throughs for the new reads
  (auto-forwarded via `__call` where possible).
- **`orkui/controller/controller.TournamentAjax.php`** — new `seq` and `changes`
  actions; thread `action_id` through the existing match/participant actions;
  return `{ok, seq, canonical fields}` / `{ok:false, reason, currentState}`.
- **`orkui/template/revised-frontend/Tournametnew_index.tpl`** — refactor the poll
  loop to cursor-based delta sync; optimistic apply + `action_id` + echo-dedup;
  refactor `tnRenderBracketViz()` for targeted re-render; non-blocking toast +
  actor-attribution ticker (reuse existing toast/`tnConfirm` styling; dark-mode
  compatible).

## Out of scope (this iteration)

- Hard locks / claim-a-match / full presence roster.
- WebSocket/SSE/push transports and any new service.
- Migrating spectator polling off the CRC `/version` hash (coarse cursor bumps
  keep them correct; a later cleanup can unify them).
- Live sync of bracket lifecycle and config/seeding edits (coarse refresh only).
