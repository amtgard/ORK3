<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for Court Planner Thread 0 tests.
 * Every row created here is tracked and removed by cleanup().
 */
final class CourtFixture
{
    private const MARKER = 'T0CRT';

    /** @var list<int> */
    private array $mundaneIds = [];
    /** @var list<int> */
    private array $courtIds = [];
    /** @var list<int> */
    private array $awardIds = [];
    /** @var list<int> */
    private array $officerIds = [];
    /** @var list<array{officer_id: int, mundane_id: int}> */
    private array $officerSeats = [];
    /** @var list<int> */
    private array $recIds = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function create(): self
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function firstKingdomId(): int
    {
        return (int) $this->pdo->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE active = 'Active' ORDER BY kingdom_id ASC LIMIT 1"
        )->fetchColumn();
    }

    public function firstParkId(int $kingdomId): int
    {
        return (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ' . $kingdomId . ' ORDER BY park_id ASC LIMIT 1'
        )->fetchColumn();
    }

    /**
     * Clone an existing mundane row, then override what we care about.
     * ork_mundane has ~15 NOT NULL columns with no defaults (given_name,
     * surname, username, email, token, ...), so a bare three-column INSERT
     * throws. Mirrors KingdomProfileFixture's INSERT ... SELECT approach,
     * building the column list dynamically so it survives schema drift.
     */
    public function createPlayer(string $tag, int $kingdomId, int $parkId = 0): array
    {
        $template = (int) $this->pdo->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id ASC LIMIT 1'
        )->fetchColumn();

        if ($template <= 0) {
            throw new RuntimeException('No template mundane row to clone.');
        }

        $persona = self::MARKER . '-' . $tag . '-' . bin2hex(random_bytes(3));

        // ork_mundane.username carries a UNIQUE key, so it must be supplied
        // fresh in the INSERT itself — cloning it and fixing it up afterwards
        // fails on the INSERT with "Duplicate entry ... for key 'username'".
        $cols = $this->pdo->query('SHOW COLUMNS FROM ' . DB_PREFIX . 'mundane')
            ->fetchAll(PDO::FETCH_COLUMN, 0);
        $cols = array_values(array_diff($cols, ['mundane_id', 'username', 'persona', 'kingdom_id', 'park_id']));
        $list = '`' . implode('`, `', $cols) . '`';

        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'mundane (`username`, `persona`, `kingdom_id`, `park_id`, ' . $list . ')
             SELECT ?, ?, ?, ?, ' . $list . ' FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?'
        );
        $st->execute([$persona, $persona, $kingdomId, $parkId, $template]);
        $id = (int) $this->pdo->lastInsertId();
        $this->mundaneIds[] = $id;

        return ['mundane_id' => $id, 'persona' => $persona];
    }

    public function createCourt(array $overrides = []): int
    {
        $row = array_merge([
            'kingdom_id'   => 0,
            'park_id'      => 0,
            'name'         => self::MARKER . '-court',
            'court_date'   => null,
            'status'       => 'published',
            'mode'         => 'plan',
            'created_by'   => 0,
        ], $overrides);

        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'court (kingdom_id, park_id, name, court_date, status, mode, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $row['kingdom_id'], $row['park_id'], $row['name'], $row['court_date'],
            $row['status'], $row['mode'], $row['created_by'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->courtIds[] = $id;

        return $id;
    }

    public function createAward(int $courtId, int $mundaneId, array $overrides = []): int
    {
        $row = array_merge([
            'kingdomaward_id' => 1,
            'rank'            => 0,
            'status'          => 'planned',
            'sort_order'      => 0,
            'notes'           => '',
            'public_comment'  => '',
            'pass_to_local'   => 0,
            'scroll_maker_id' => null,
            'regalia_maker_id' => null,
        ], $overrides);

        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'court_award
             (court_id, mundane_id, kingdomaward_id, rank, status, sort_order, notes, public_comment,
              pass_to_local, scroll_maker_id, regalia_maker_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $courtId, $mundaneId, $row['kingdomaward_id'], $row['rank'], $row['status'],
            $row['sort_order'], $row['notes'], $row['public_comment'], $row['pass_to_local'],
            $row['scroll_maker_id'], $row['regalia_maker_id'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->awardIds[] = $id;

        return $id;
    }

    /**
     * Seat a player in an officer role.
     *
     * ork_officer is UNIQUE on (kingdom_id, park_id, role) — one holder per seat —
     * and the sandbox already seats most roles, so a blind INSERT collides. Take
     * over an occupied seat instead and remember who held it, so cleanup() puts
     * the original officer back rather than leaving the shared sandbox altered.
     */
    public function insertOfficer(int $mundaneId, int $kingdomId, int $parkId, string $role): void
    {
        $st = $this->pdo->prepare(
            'SELECT officer_id, mundane_id FROM ' . DB_PREFIX . 'officer
             WHERE kingdom_id = ? AND park_id = ? AND role = ?'
        );
        $st->execute([$kingdomId, $parkId, $role]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $this->officerSeats[] = [
                'officer_id' => (int) $existing['officer_id'],
                'mundane_id' => (int) $existing['mundane_id'],
            ];
            $up = $this->pdo->prepare(
                'UPDATE ' . DB_PREFIX . 'officer SET mundane_id = ? WHERE officer_id = ?'
            );
            $up->execute([$mundaneId, (int) $existing['officer_id']]);

            return;
        }

        // system and authorization_id are NOT NULL without defaults — match
        // ReportsFixture and pass 0 for both.
        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'officer
             (kingdom_id, park_id, mundane_id, role, system, authorization_id)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $st->execute([$kingdomId, $parkId, $mundaneId, $role]);
        $this->officerIds[] = (int) $this->pdo->lastInsertId();
    }

    /**
     * First kingdomaward in a kingdom — recommendations need a real one to join to.
     */
    public function firstKingdomAwardId(int $kingdomId): array
    {
        $st = $this->pdo->prepare(
            'SELECT kingdomaward_id, award_id FROM ' . DB_PREFIX . 'kingdomaward
             WHERE kingdom_id = ? ORDER BY kingdomaward_id ASC LIMIT 1'
        );
        $st->execute([$kingdomId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Create an award recommendation. Pass dismissed_by to soft-delete it the way
     * Player::DeleteAwardRecommendation does (both columns together — the active
     * queries filter on deleted_by while the deleted-recs report keys on deleted_at,
     * so a row with only one set would be invisible to both).
     */
    public function createRecommendation(int $mundaneId, int $kaId, int $awardId, array $overrides = []): int
    {
        $row = array_merge([
            'rank'              => 1,
            'recommended_by_id' => $mundaneId,
            'date_recommended'  => date('Y-m-d'),
            'mask_giver'        => 0,
            'reason'            => 'Fixture recommendation.',
            'dismissed_by'      => null,
        ], $overrides);

        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'recommendations
             (mundane_id, kingdomaward_id, award_id, rank, recommended_by_id,
              date_recommended, mask_giver, reason, deleted_at, deleted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $mundaneId, $kaId, $awardId, $row['rank'], $row['recommended_by_id'],
            $row['date_recommended'], $row['mask_giver'], $row['reason'],
            $row['dismissed_by'] ? date('Y-m-d H:i:s') : null,
            $row['dismissed_by'] ?: null,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->recIds[] = $id;

        return $id;
    }

    public function fetchRecommendation(int $recId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ?');
        $st->execute([$recId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchCourt(int $courtId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'court WHERE court_id = ?');
        $st->execute([$courtId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchAward(int $courtAwardId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'court_award WHERE court_award_id = ?');
        $st->execute([$courtAwardId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchNote(int $courtNoteId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . DB_PREFIX . 'court_note WHERE court_note_id = ?');
        $st->execute([$courtNoteId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * The court's running order across BOTH line tables, read straight from the
     * database: 'a:<court_award_id>' for an award, 'n:<court_note_id>' for a note.
     *
     * @return list<string>
     */
    public function lineOrder(int $courtId): array
    {
        $st = $this->pdo->prepare(
            "SELECT CONCAT('a:', court_award_id) AS line, sort_order, 0 AS kind, court_award_id AS id
               FROM " . DB_PREFIX . "court_award WHERE court_id = ?
             UNION ALL
             SELECT CONCAT('n:', court_note_id), sort_order, 1, court_note_id
               FROM " . DB_PREFIX . 'court_note WHERE court_id = ?
             ORDER BY sort_order, kind, id'
        );
        $st->execute([$courtId, $courtId]);

        return $st->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function cleanup(): void
    {
        // Notes are created through Court::addNote, so they are swept by court.
        $this->deleteIn('court_note', 'court_id', $this->courtIds);
        $this->deleteIn('court_award', 'court_id', $this->courtIds);
        $this->deleteIn('court_award', 'court_award_id', $this->awardIds);
        $this->deleteIn('court', 'court_id', $this->courtIds);
        $this->deleteIn('recommendations', 'recommendations_id', $this->recIds);

        // Put borrowed officer seats back before the stand-in players go away.
        $restore = $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'officer SET mundane_id = ? WHERE officer_id = ?'
        );
        foreach ($this->officerSeats as $seat) {
            $restore->execute([$seat['mundane_id'], $seat['officer_id']]);
        }
        $this->officerSeats = [];

        $this->deleteIn('officer', 'officer_id', $this->officerIds);
        $this->deleteIn('mundane', 'mundane_id', $this->mundaneIds);
        $this->awardIds = $this->courtIds = $this->officerIds = $this->mundaneIds = $this->recIds = [];
    }

    private function deleteIn(string $table, string $pk, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $this->pdo->exec(
            'DELETE FROM ' . DB_PREFIX . $table . ' WHERE ' . $pk . ' IN (' . implode(',', array_map('intval', $ids)) . ')'
        );
    }
}
