-- Team-Bracket Test Fixture
-- Tournament : TID=162  KID=17  PID=0
-- Mundane IDs used: 38,44 (Alpha)  45,47,48 (Bravo)
-- Run:  docker exec -i ork3-php8-db mariadb -u root -proot ork < scripts/seed_team_bracket.sql

-- ── 1. Create the team bracket ────────────────────────────────────────────────
INSERT INTO ork_bracket
    (tournament_id, style,        style_note, method,   rings, participants, seeding,   status)
VALUES
    (162,           'Single Sword','',         'single', 1,     'team',       'warrior', 'setup');

SET @BID = LAST_INSERT_ID();

-- ── 2. Team Alpha (2 members) — participant row ───────────────────────────────
INSERT INTO ork_participant
    (tournament_id, bracket_id, alias,        unit_id, park_id, kingdom_id, seed, eliminated, bracket_side, participant_number, status, im_wins, im_current_streak, im_max_streak, warrior_level)
VALUES
    (162,           @BID,       'Team Alpha',  0,       0,       17,         1,    0,          '',           1,                  'active',0,       0,                  0,             0);

SET @PA = LAST_INSERT_ID();

-- ── 3. Team Bravo (3 members) — participant row ───────────────────────────────
INSERT INTO ork_participant
    (tournament_id, bracket_id, alias,        unit_id, park_id, kingdom_id, seed, eliminated, bracket_side, participant_number, status, im_wins, im_current_streak, im_max_streak, warrior_level)
VALUES
    (162,           @BID,       'Team Bravo',  0,       0,       17,         2,    0,          '',           2,                  'active',0,       0,                  0,             0);

SET @PB = LAST_INSERT_ID();

-- ── 4. ork_participant_teams rows ─────────────────────────────────────────────
INSERT INTO ork_participant_teams (tournament_id, bracket_id, participant_id, name)
VALUES
    (162, @BID, @PA, 'Team Alpha'),
    (162, @BID, @PB, 'Team Bravo');

SET @TA = (SELECT team_id FROM ork_participant_teams WHERE participant_id = @PA LIMIT 1);
SET @TB = (SELECT team_id FROM ork_participant_teams WHERE participant_id = @PB LIMIT 1);

-- ── 5. ork_participant_team_members — Alpha: mundane 38, 44 ───────────────────
INSERT INTO ork_participant_team_members (team_id, mundane_id, tournament_id)
VALUES
    (@TA, 38, 162),
    (@TA, 44, 162);

-- ── 6. ork_participant_team_members — Bravo: mundane 45, 47, 48 ──────────────
INSERT INTO ork_participant_team_members (team_id, mundane_id, tournament_id)
VALUES
    (@TB, 45, 162),
    (@TB, 47, 162),
    (@TB, 48, 162);

-- ── 7. ork_participant_mundane (backward-compat) — Alpha ─────────────────────
INSERT INTO ork_participant_mundane (participant_id, mundane_id, tournament_id, bracket_id)
VALUES
    (@PA, 38, 162, @BID),
    (@PA, 44, 162, @BID);

-- ── 8. ork_participant_mundane (backward-compat) — Bravo ─────────────────────
INSERT INTO ork_participant_mundane (participant_id, mundane_id, tournament_id, bracket_id)
VALUES
    (@PB, 45, 162, @BID),
    (@PB, 47, 162, @BID),
    (@PB, 48, 162, @BID);

-- ── 9. Verification output ────────────────────────────────────────────────────
SELECT
    @BID  AS team_bracket_id,
    @PA   AS participant_id_alpha,
    @PB   AS participant_id_bravo,
    @TA   AS team_id_alpha,
    @TB   AS team_id_bravo;
