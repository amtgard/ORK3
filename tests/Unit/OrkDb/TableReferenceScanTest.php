<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\SchemaTableIndex;
use OrkDb\TableReferenceScan;
use OrkDb\ValidationException;
use PHPUnit\Framework\TestCase;

final class TableReferenceScanTest extends TestCase
{
    /** @var list<string> */
    private array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->removeTree($root);
        }
        $this->tempRoots = [];
    }

    public function testResolvesEveryReferenceSpellingUsedInTheCodebase(): void
    {
        $scan = $this->scanner(<<<'PHP'
            <?php
            $a = $db->DataSet('SELECT * FROM ' . DB_PREFIX . 'award');
            $b = $db->DataSet("SELECT * FROM " . DB_PREFIX . "attendance WHERE x = 1");
            $c = $db->DataSet('SELECT * FROM ' . DB_PREFIX.'kingdom');
            $d = new yapo($db, DB_PREFIX . 'park');
            $p = DB_PREFIX;
            $e = $db->DataSet("SELECT * FROM {$p}officer o JOIN {$p}mundane m ON 1");
            $f = $db->DataSet("SELECT * FROM `{$p}unit`");
            $g = $db->DataSet($p . 'session');
            $h = $db->DataSet('SELECT 1 FROM ork_day_convert');
            PHP);

        $tables = array_keys($scan->scan()['references']);
        sort($tables, SORT_STRING);

        $this->assertSame([
            'ork_attendance',
            'ork_award',
            'ork_day_convert',
            'ork_kingdom',
            'ork_mundane',
            'ork_officer',
            'ork_park',
            'ork_session',
            'ork_unit',
        ], $tables);
    }

    /**
     * The failure this whole gate exists to catch: code naming a table the committed
     * schema never creates.
     */
    public function testFlagsATableTheSchemaDoesNotDefine(): void
    {
        $scan = $this->scanner(
            '<?php $r = $db->DataSet("SELECT * FROM " . DB_PREFIX . "kingdomaward_ladder");'
        );

        $result = $scan->undefinedTables(['ork_award', 'ork_kingdomaward']);

        $this->assertArrayHasKey('ork_kingdomaward_ladder', $result['undefined']);
        $this->assertStringContainsString(
            'domain/class.Thing.php:1',
            $result['undefined']['ork_kingdomaward_ladder'][0]
        );
    }

    /**
     * Comments are the loudest false-positive source: a table named in a commented-out
     * block, a docblock, or a `-- ` note is not a reference. Token scanning drops them.
     */
    public function testIgnoresTablesNamedOnlyInComments(): void
    {
        $scan = $this->scanner(<<<'PHP'
            <?php
            // $db->DataSet('SELECT * FROM ' . DB_PREFIX . 'line_comment_table');
            # $db->DataSet('SELECT * FROM ' . DB_PREFIX . 'hash_comment_table');
            /**
             * Historic: ork_docblock_table used to hold this.
             */
            /*
            $dead = new yapo($db, DB_PREFIX . 'block_comment_table');
            */
            $live = new yapo($db, DB_PREFIX . 'award');
            PHP);

        $this->assertSame(['ork_award'], array_keys($scan->scan()['references']));
    }

    public function testRuntimeComputedNamesAreReportedNotGuessed(): void
    {
        $scan = $this->scanner(<<<'PHP'
            <?php
            $table = $meta['table'];
            $r = $db->DataSet('SELECT * FROM ' . DB_PREFIX . $table);
            PHP);

        $result = $scan->scan();

        $this->assertSame([], array_keys($result['references']));
        $this->assertCount(1, $result['dynamic']);
    }

    public function testAllowListSuppressesAnUndefinedTable(): void
    {
        $scan = $this->scanner(
            '<?php $r = $db->Execute("UPDATE " . DB_PREFIX . "attendance_myisam SET x = 1");',
            [
                'ork_attendance_myisam' => [
                    'kind' => 'mirror_only',
                    'reason' => 'Legacy MyISAM twin that lives only on prod.',
                ],
            ]
        );

        $result = $scan->undefinedTables(['ork_attendance']);

        $this->assertSame([], $result['undefined']);
        $this->assertArrayHasKey('ork_attendance_myisam', $result['allowed_hits']);
        $this->assertSame('mirror_only', $result['allowed_hits']['ork_attendance_myisam']['kind']);
    }

    public function testAllowListEntryWithoutAReasonIsRejected(): void
    {
        $scan = $this->scanner('<?php', ['ork_whatever' => ['kind' => 'mirror_only']]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/has no reason/');

        $scan->allowList();
    }

    public function testAllowListEntryGoesStaleWhenTheReferenceDisappears(): void
    {
        $scan = $this->scanner(
            '<?php $r = new yapo($db, DB_PREFIX . "award");',
            [
                'ork_attendance_myisam' => [
                    'kind' => 'mirror_only',
                    'reason' => 'No longer referenced anywhere.',
                ],
            ]
        );

        $result = $scan->undefinedTables(['ork_award']);

        $this->assertSame(['ork_attendance_myisam'], $result['stale_allowlist']);
    }

    public function testSchemaIndexReadsCreateDropAndRenameInOrder(): void
    {
        $repoRoot = $this->makeRepo('<?php');
        file_put_contents($repoRoot . '/ork.sql', <<<'SQL'
            -- CREATE TABLE `ork_prose_only` is only mentioned, never run.
            /* CREATE TABLE `ork_block_comment_only` ( id int ); */
            DROP TABLE IF EXISTS `ork_award`;
            CREATE TABLE `ork_award` ( `award_id` int(11) NOT NULL );
            CREATE TABLE IF NOT EXISTS ork_unquoted ( id int );
            CREATE TABLE `ork_scratch` ( id int );
            DROP TABLE `ork_scratch`;
            CREATE TABLE `ork_old_name` ( id int );
            RENAME TABLE `ork_old_name` TO `ork_new_name`;
            SQL);

        $index = new SchemaTableIndex($repoRoot, $repoRoot . '/tools/ork-db');

        $this->assertSame(
            ['ork_award', 'ork_new_name', 'ork_unquoted'],
            $index->definedTables()
        );
    }

    /**
     * @param array<string, array<string, string>> $allowed
     */
    private function scanner(string $php, array $allowed = []): TableReferenceScan
    {
        $repoRoot = $this->makeRepo($php, $allowed);

        return new TableReferenceScan($repoRoot, $repoRoot . '/tools/ork-db');
    }

    /**
     * A throwaway repo: one domain file to scan, one manifest, and the minimum
     * MigrationClassifier needs so SchemaTableIndex can run too.
     *
     * @param array<string, array<string, string>> $allowed
     */
    private function makeRepo(string $php, array $allowed = []): string
    {
        $repoRoot = sys_get_temp_dir() . '/ork-db-tableref-' . uniqid('', true);
        $this->tempRoots[] = $repoRoot;

        mkdir($repoRoot . '/domain', 0775, true);
        mkdir($repoRoot . '/db-migrations', 0775, true);
        mkdir($repoRoot . '/tools/ork-db/manifests', 0775, true);
        mkdir($repoRoot . '/tools/ork-db/templates/catalogs', 0775, true);

        file_put_contents($repoRoot . '/domain/class.Thing.php', $php);
        file_put_contents($repoRoot . '/ork.sql', '');
        file_put_contents(
            $repoRoot . '/tools/ork-db/manifests/migration-classification.json5',
            '{ "migrations": {} }'
        );
        file_put_contents(
            $repoRoot . '/tools/ork-db/manifests/table-reference-allowlist.json5',
            (string) json_encode(['scan_roots' => ['domain'], 'allowed' => $allowed])
        );

        return $repoRoot;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
