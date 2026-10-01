<?php

declare(strict_types=1);

/**
 * Test seam for PopulationExplorer's query path: records every statement and can
 * force a real DB failure on chosen statements (by pointing them at a missing
 * table) or shrink the statement timeout so MariaDB kills the query.
 */
final class PopulationExplorerProbe extends PopulationExplorer
{
    /** @var list<string> */
    public array $statements = [];

    /** @var list<bool> parallel to $statements: run under the statement timeout */
    public array $timed = [];

    /** Regex; matching statements are rewritten to read a table that does not exist. */
    public ?string $failPattern = null;

    public ?float $timeout = null;

    /** Kingdom-only ladder ids (kingdomaward ids) to use instead of the real list. */
    public ?array $kingdomLadderIds = null;

    /** @var list<int> parallel to $statements: CONNECTION_ID() each statement ran on */
    public array $connections = [];

    /** Called as fn(string $sql, self $probe) after each statement has run. */
    public ?Closure $afterStatement = null;

    protected function _select(string $sql, bool $timed = false)
    {
        if ($this->failPattern !== null && preg_match($this->failPattern, $sql)) {
            $sql = (string) preg_replace('/\bFROM ' . DB_PREFIX . '(\w+)/', 'FROM ' . DB_PREFIX . 'pe_missing_table', $sql, 1);
        }
        $this->statements[] = $sql;
        $this->timed[] = $timed;
        $this->connections[] = $this->connectionId();

        try {
            return parent::_select($sql, $timed);
        } finally {
            if ($this->afterStatement !== null) {
                ($this->afterStatement)($sql, $this);
            }
        }
    }

    /** CONNECTION_ID() of the connection the queries run on (not recorded as a statement). */
    public function connectionId(): int
    {
        $r = parent::_select('SELECT CONNECTION_ID() AS c');

        return $r->next() ? (int) $r->c : 0;
    }

    /** Whether a write on the queries' own connection succeeds right now. */
    public function canWrite(): bool
    {
        return (bool) @$this->db->ExecuteChecked('UPDATE ' . DB_PREFIX . 'mundane SET persona = persona WHERE mundane_id = 0');
    }

    protected function _kingdomOnlyLadderIds(): array
    {
        return $this->kingdomLadderIds ?? parent::_kingdomOnlyLadderIds();
    }

    protected function _statementTimeout(): float
    {
        return $this->timeout ?? parent::_statementTimeout();
    }

    public function timeoutClause(): string
    {
        return $this->_timeoutClause();
    }
}
