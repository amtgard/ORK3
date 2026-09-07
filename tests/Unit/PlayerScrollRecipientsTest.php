<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Item 38. A bulk render resolved its recipients one yapo find() at a time — 200
 * round trips for a full run. GetScrollRecipients() is the same five fields for
 * every id in ONE statement.
 *
 * The awkward part, and the reason this test touches a real database:
 * `pronoun_freetext` ships on another branch and is genuinely absent from the
 * rendered test schema. Naming a missing column would fail the whole statement and
 * take the personas down with it, so the column list is PROBED. That probe is only
 * meaningful against a schema that actually lacks the column — which is exactly
 * what the test database is.
 */
final class PlayerScrollRecipientsTest extends TestCase
{
    private const A = 990001;
    private const B = 990002;
    private const C = 990003;

    private Player $player;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->player = new Player();
        $this->wipe();
    }

    protected function tearDown(): void
    {
        if (isset($this->player)) {
            $this->wipe();
        }
    }

    private function wipe(): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id IN ('
            . self::A . ',' . self::B . ',' . self::C . ')'
        );
    }

    private function seed(int $id, string $persona, int $pronounId, int $suspended, int $active): void
    {
        global $DB;
        $DB->Clear();
        // ork_mundane.username is UNIQUE and NOT NULL DEFAULT '', so two seeded
        // rows that both leave it blank collide on the second insert — silently,
        // because YapoMysql::Execute() reports nothing.
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'mundane (mundane_id, username, persona, pronoun_id, suspended, active)'
            . " VALUES ({$id}, 'ws1-test-{$id}', '{$persona}', {$pronounId}, {$suspended}, {$active})"
        );
    }

    public function testTheBatchReturnsOneEntryPerFoundIdKeyedByMundaneId(): void
    {
        $this->seed(self::A, 'Haliax Duskstrider', 5, 0, 1);
        $this->seed(self::B, 'Acaben HighShield', 2, 1, 1);

        $rows = $this->player->GetScrollRecipients([self::A, self::B, self::C]);

        $this->assertCount(2, $rows, 'an id with no mundane row is simply absent');
        $this->assertArrayHasKey(self::A, $rows);
        $this->assertArrayHasKey(self::B, $rows);
        $this->assertArrayNotHasKey(self::C, $rows);

        $this->assertSame('Haliax Duskstrider', $rows[self::A]['Persona']);
        $this->assertSame(5, $rows[self::A]['PronounId']);
        $this->assertSame(self::A, $rows[self::A]['MundaneId']);
    }

    /**
     * The batch has to hand back exactly what GetScrollRecipient() does per row, or
     * a caller has two shapes to reason about — and possessive_pronoun() reads them
     * both.
     */
    public function testTheBatchRowMatchesTheSingleRowLookup(): void
    {
        $this->seed(self::A, 'Haliax Duskstrider', 4, 0, 1);

        $single = $this->player->GetScrollRecipient(self::A);
        $batch  = $this->player->GetScrollRecipients([self::A])[self::A];

        foreach (['MundaneId', 'Persona', 'PronounId', 'PronounCustom', 'PronounFreetext'] as $key) {
            $this->assertArrayHasKey($key, $batch, $key . ' is missing from the batch shape');
            $this->assertSame($single[$key], $batch[$key], $key . ' disagrees between the two readers');
        }
    }

    /**
     * Item 46, third part. Recipient standing is carried so a bulk list can MARK a
     * suspended or inactive recipient — never to filter one out, because a
     * suspended member's past award is still theirs.
     */
    public function testRecipientStandingIsCarriedAndNothingIsFilteredOut(): void
    {
        $this->seed(self::A, 'Suspended Person', 5, 1, 1);
        $this->seed(self::B, 'Inactive Person', 5, 0, 0);

        $rows = $this->player->GetScrollRecipients([self::A, self::B]);

        $this->assertCount(2, $rows, 'neither flag may remove a recipient from the result');
        $this->assertSame(1, $rows[self::A]['Suspended']);
        $this->assertSame(1, $rows[self::A]['Active']);
        $this->assertSame(0, $rows[self::B]['Suspended']);
        $this->assertSame(0, $rows[self::B]['Active']);
    }

    /**
     * The statement must be well formed on a schema WITHOUT pronoun_freetext — the
     * rendered test schema is one — and the key must still be present and empty
     * rather than absent, so a caller need not test for it.
     */
    public function testItSurvivesASchemaWithoutPronounFreetext(): void
    {
        $this->seed(self::A, 'Haliax Duskstrider', 5, 0, 1);

        $rows = $this->player->GetScrollRecipients([self::A]);

        $this->assertArrayHasKey('PronounFreetext', $rows[self::A]);
        $this->assertIsString($rows[self::A]['PronounFreetext']);
    }

    public function testNothingUsableInMeansNoQueryAndAnEmptyResult(): void
    {
        $this->assertSame([], $this->player->GetScrollRecipients([]));
        $this->assertSame([], $this->player->GetScrollRecipients([0, -1, 'nope']));
    }

    /**
     * mysql_real_escape_string() is a no-op shim here, so valid_id()'s
     * is_numeric-and-positive filter is what keeps the IN () list safe.
     */
    public function testANonNumericIdCannotReachTheStatement(): void
    {
        $this->seed(self::A, 'Haliax Duskstrider', 5, 0, 1);

        $rows = $this->player->GetScrollRecipients([self::A, '1 OR 1=1', '); DROP TABLE ork_mundane; --']);

        $this->assertSame([self::A], array_keys($rows));
    }
}
