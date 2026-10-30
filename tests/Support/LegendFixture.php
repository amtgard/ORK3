<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for the Legend (In Memoriam) status tests. Wraps
 * AttendanceFixture, which clones a template player and cleans up the mundane,
 * authorization and session rows it created; this adds the officers and the
 * Legend tables.
 */
final class LegendFixture
{
    /** @var list<int> */
    private array $mundaneIds = [];

    public function __construct(
        private readonly AttendanceFixture $base,
        private readonly PDO $pdo,
    ) {
    }

    public static function create(): self
    {
        $base = AttendanceFixture::create();
        $r = new ReflectionProperty(AttendanceFixture::class, 'pdo');
        $r->setAccessible(true);

        return new self($base, $r->getValue($base));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function firstParkId(): int
    {
        return $this->base->firstParkId();
    }

    public function kingdomIdForPark(int $parkId): int
    {
        return $this->base->kingdomIdForPark($parkId);
    }

    /** A park in a kingdom other than $kingdomId (0 when none). */
    public function parkOutsideKingdom(int $kingdomId): int
    {
        return (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' AND kingdom_id <> " . (int) $kingdomId
            . ' AND kingdom_id > 0 ORDER BY park_id LIMIT 1'
        )->fetchColumn();
    }

    /**
     * A plain player at a park. $legendYear > 0 makes them a Legend.
     *
     * @return array{mundane_id: int, park_id: int, kingdom_id: int}
     */
    public function addPlayer(int $parkId, string $suffix = 'player', int $legendYear = 0): array
    {
        $p = $this->base->createPlayer($parkId, $suffix);
        $this->mundaneIds[] = (int) $p['mundane_id'];
        if ($legendYear > 0) {
            $this->setLegend((int) $p['mundane_id'], $legendYear);
        }

        return [
            'mundane_id' => (int) $p['mundane_id'],
            'park_id' => (int) $p['park_id'],
            'kingdom_id' => (int) $p['kingdom_id'],
        ];
    }

    /** A player with EDIT (or $role) on a kingdom, homed at $homeParkId. Returns the mundane id. */
    public function addKingdomOfficer(int $kingdomId, int $homeParkId, string $suffix = 'kofficer', string $role = AUTH_EDIT): int
    {
        $uid = $this->addPlayer($homeParkId, $suffix)['mundane_id'];
        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, 0, ?, 0, 0, ?)'
        )->execute([$uid, $kingdomId, $role]);

        return $uid;
    }

    /** A player with EDIT on one park only. Returns the mundane id. */
    public function addParkOfficer(int $parkId, string $suffix = 'pofficer'): int
    {
        return (int) $this->base->createParkOfficer($parkId, $suffix)['mundane_id'];
    }

    /** A global ORK admin (unscoped admin row). Returns the mundane id. */
    public function addOrkAdmin(int $homeParkId, string $suffix = 'admin'): int
    {
        $uid = $this->addPlayer($homeParkId, $suffix)['mundane_id'];
        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, 0, 0, 0, 0, ?)'
        )->execute([$uid, AUTH_ADMIN]);

        return $uid;
    }

    public function setLegend(int $mundaneId, int $year, bool $isLegend = true): void
    {
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = ?, legend_year = ? WHERE mundane_id = ?')
            ->execute([$isLegend ? 1 : 0, $year, $mundaneId]);
        Legend::clearMemo();
    }

    public function cleanup(): void
    {
        foreach ($this->mundaneIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'legend_log WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'legend_memory WHERE mundane_id = ' . (int) $id);
        }
        $this->base->cleanup();
        $this->mundaneIds = [];
        Legend::clearMemo();
    }
}
