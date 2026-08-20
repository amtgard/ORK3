<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Static lint: every YapoMysql::DataSet() call in the domain layer must be
 * preceded, within its enclosing function, by a Clear() or SetData() on the
 * database handle.
 *
 * Why this matters: DataSet() executes with whatever bindings are sitting in
 * $this->Data. The handle is the shared global $DB, so a call site that skips
 * Clear() inherits stray bindings from earlier in the request; PDO rejects
 * parameters on a placeholder-free query, and under ERRMODE_WARNING that
 * failure is SILENT — the query simply returns an empty set. This exact
 * hazard has now bitten at least seven call sites (most recently the Live
 * dashboard rendering empty on production; before that, logout-everywhere in
 * the multi-device session work). This test exists so there is no eighth.
 *
 * The check is deliberately conservative: it only requires that SOME
 * Clear()/SetData() appears between the function's opening and the DataSet()
 * call. That matches the codebase's universal convention and produces zero
 * false positives today; it will not catch every pathological interleaving,
 * but it catches the pattern that has actually caused production bugs.
 */
final class DataSetClearLintTest extends TestCase
{
    public function testEveryDataSetCallIsPrecededByClearInItsFunction(): void
    {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/system/lib/ork3/*.php');
        $this->assertNotEmpty($files, 'domain layer not found — did the tree move?');

        $violations = array();
        foreach ($files as $path) {
            $src = (string)file_get_contents($path);
            $offset = 0;
            while (($call = strpos($src, '->DataSet(', $offset)) !== false) {
                $offset = $call + 1;

                // Find the enclosing function's start.
                $fstart = 0;
                if (preg_match_all('/function\s+\w+\s*\(/', substr($src, 0, $call), $m, PREG_OFFSET_CAPTURE)) {
                    $last = end($m[0]);
                    $fstart = $last[1];
                }

                $segment = substr($src, $fstart, $call - $fstart);
                if (strpos($segment, '->Clear()') === false && strpos($segment, '->SetData(') === false) {
                    $line = substr_count($src, "\n", 0, $call) + 1;
                    $violations[] = basename($path) . ':' . $line;
                }
            }
        }

        $this->assertSame(
            array(),
            $violations,
            "DataSet() without a preceding Clear()/SetData() in the same function.\n"
            . "The shared \$DB may carry stray bindings from earlier in the request;\n"
            . "on a placeholder-free query PDO fails SILENTLY under ERRMODE_WARNING\n"
            . "and the query returns empty (see the Live dashboard bug, PR #509).\n"
            . "Add \$this->db->Clear(); before the call.\n"
            . "Violations: " . implode(', ', $violations)
        );
    }
}
