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

    protected function _select(string $sql, bool $timed = false)
    {
        if ($this->failPattern !== null && preg_match($this->failPattern, $sql)) {
            $sql = (string) preg_replace('/\bFROM ' . DB_PREFIX . '(\w+)/', 'FROM ' . DB_PREFIX . 'pe_missing_table', $sql, 1);
        }
        $this->statements[] = $sql;
        $this->timed[] = $timed;

        return parent::_select($sql, $timed);
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
