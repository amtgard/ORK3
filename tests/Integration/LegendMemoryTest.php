<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Memory Wall on a Legend's profile: who may leave a memory, who may remove
 * one, and that a hidden wall comes back whole.
 */
final class LegendMemoryTest extends TestCase
{
    private LegendFixture $fixture;
    private int $park = 0;
    private int $kingdom = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function player(string $suffix): int
    {
        return $this->fixture->addPlayer($this->park, $suffix)['mundane_id'];
    }

    private function legend(): int
    {
        return $this->fixture->addPlayer($this->park, 'legend', 2020)['mundane_id'];
    }

    private function memoryCount(int $legendId): int
    {
        return $this->fixture->count('legend_memory', 'mundane_id = ' . $legendId);
    }

    public function test_add_memory_records_author_and_date(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');

        $r = Legend::addMemory($author, $legend, "  He taught me to throw.  \n");

        $this->assertSame(0, $r['Status']);
        $rows = Legend::memories($legend);
        $this->assertCount(1, $rows);
        $this->assertSame('He taught me to throw.', $rows[0]['Body']);
        $this->assertSame($author, $rows[0]['AuthorId']);
        $this->assertSame((string) $this->fixture->value('mundane', 'persona', 'mundane_id = ' . $author), $rows[0]['AuthorPersona']);
        $age = (int) $this->fixture->pdo()
            ->query("SELECT ABS(TIMESTAMPDIFF(SECOND, created, NOW())) FROM " . DB_PREFIX . "legend_memory WHERE legend_memory_id = " . $rows[0]['MemoryId'])
            ->fetchColumn();
        $this->assertLessThan(5, $age);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows[0]['Created']);
        $this->assertSame((int) $r['MemoryId'], $rows[0]['MemoryId']);
    }

    public function test_add_refused_when_logged_out_or_suspended(): void
    {
        $legend = $this->legend();
        $suspended = $this->player('suspended');
        $boxed = $this->player('boxed');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET suspended = 1 WHERE mundane_id = ' . $suspended);
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET penalty_box = 1 WHERE mundane_id = ' . $boxed);

        $this->assertSame(5, Legend::addMemory(0, $legend, 'Remembered.')['Status']);
        $this->assertSame(3, Legend::addMemory($suspended, $legend, 'Remembered.')['Status']);
        $this->assertSame(3, Legend::addMemory($boxed, $legend, 'Remembered.')['Status']);
        $this->assertSame(3, Legend::addMemory(999999999, $legend, 'Remembered.')['Status']);
        $this->assertSame(3, Legend::addMemory($legend, $legend, 'Remembered.')['Status']);
        $this->assertSame(0, $this->memoryCount($legend));
    }

    public function test_add_refused_for_non_legend(): void
    {
        $living = $this->player('living');
        $author = $this->player('author');

        $r = Legend::addMemory($author, $living, 'Remembered.');

        $this->assertSame(1, $r['Status']);
        $this->assertSame(0, $this->memoryCount($living));
        $this->assertSame(1, Legend::addMemory($author, 0, 'Remembered.')['Status']);
    }

    public function test_add_refuses_empty_and_over_2000_chars(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');

        $this->assertSame(1, Legend::addMemory($author, $legend, "  \n ")['Status']);
        $this->assertSame(1, Legend::addMemory($author, $legend, str_repeat('a', Legend::MAX_MEMORY_CHARS + 1))['Status']);
        $this->assertSame(0, $this->memoryCount($legend));

        $this->assertSame(0, Legend::addMemory($author, $legend, str_repeat('é', Legend::MAX_MEMORY_CHARS))['Status']);
        $this->assertSame(1, $this->memoryCount($legend));
    }

    public function test_add_runs_profanity_filter(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');

        $r = Legend::addMemory($author, $legend, 'What a fucking shithead.');

        $this->assertSame(1, $r['Status']);
        $this->assertSame(ProfanityFilter::ERROR_MESSAGE, $r['Error']);
        $this->assertSame(0, $this->memoryCount($legend));
    }

    public function test_memories_newest_first(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $first = $this->fixture->addMemory($legend, $author);
        $second = $this->fixture->addMemory($legend, $author);
        $this->fixture->pdo()->exec("UPDATE " . DB_PREFIX . "legend_memory SET created = '2020-01-01 00:00:00' WHERE legend_memory_id = {$first}");
        $this->fixture->pdo()->exec("UPDATE " . DB_PREFIX . "legend_memory SET created = '2021-01-01 00:00:00' WHERE legend_memory_id = {$second}");

        $this->assertSame([$second, $first], array_column(Legend::memories($legend), 'MemoryId'));

        // Same instant: the later memory still sorts first.
        $this->fixture->pdo()->exec("UPDATE " . DB_PREFIX . "legend_memory SET created = '2021-01-01 00:00:00' WHERE legend_memory_id = {$first}");
        $this->assertSame([$second, $first], array_column(Legend::memories($legend), 'MemoryId'));
    }

    public function test_remove_refused_for_author_and_park_officer(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $parkOfficer = $this->fixture->addParkOfficer($this->park);
        $id = $this->fixture->addMemory($legend, $author);

        $this->assertSame(3, Legend::removeMemory($author, $id)['Status']);
        $this->assertSame(3, Legend::removeMemory($parkOfficer, $id)['Status']);
        $this->assertSame(5, Legend::removeMemory(0, $id)['Status']);
        $this->assertSame(1, $this->fixture->count('legend_memory', "legend_memory_id = {$id} AND removed_by_id = 0"));
        $this->assertCount(1, Legend::memories($legend));
    }

    public function test_remove_soft_deletes_for_kingdom_officer_and_ork_admin(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $admin = $this->fixture->addOrkAdmin($this->park);
        $byOfficer = $this->fixture->addMemory($legend, $author);
        $byAdmin = $this->fixture->addMemory($legend, $author);

        $this->assertSame(0, Legend::removeMemory($officer, $byOfficer)['Status']);
        $this->assertSame(0, Legend::removeMemory($admin, $byAdmin)['Status']);

        $this->assertSame($officer, (int) $this->fixture->value('legend_memory', 'removed_by_id', "legend_memory_id = {$byOfficer}"));
        $this->assertSame($admin, (int) $this->fixture->value('legend_memory', 'removed_by_id', "legend_memory_id = {$byAdmin}"));
        $this->assertNotNull($this->fixture->value('legend_memory', 'removed_at', "legend_memory_id = {$byOfficer}"));
        $this->assertSame(2, $this->memoryCount($legend), 'rows are kept, not deleted');

        $again = Legend::removeMemory($officer, $byOfficer);
        $this->assertSame(1, $again['Status'], 'a second removal does not restamp');
        $this->assertSame(1, Legend::removeMemory($officer, 999999999)['Status']);
    }

    public function test_removed_memory_not_listed(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $keep = $this->fixture->addMemory($legend, $author);
        $drop = $this->fixture->addMemory($legend, $author);

        Legend::removeMemory($officer, $drop);

        $this->assertSame([$keep], array_column(Legend::memories($legend), 'MemoryId'));
    }

    public function test_memories_hidden_after_undo_and_back_after_reconvert(): void
    {
        $author = $this->player('author');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $target = $this->player('target');
        $this->assertSame(0, Legend::convert($officer, $target, 2020)['Status']);
        $this->assertSame(0, Legend::addMemory($author, $target, 'Remembered.')['Status']);

        $this->assertSame(0, Legend::undo($officer, $target)['Status']);
        $this->assertSame([], Legend::memories($target));
        $this->assertSame(1, $this->memoryCount($target), 'hidden, not deleted');

        $this->assertSame(0, Legend::convert($officer, $target, 2021)['Status']);
        $this->assertCount(1, Legend::memories($target));
    }

    public function test_script_tag_stored_verbatim(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $body = '<script>alert("x")</script> & \'quotes\'';

        Legend::addMemory($author, $legend, $body);

        $this->assertSame($body, Legend::memories($legend)[0]['Body']);
        $this->assertSame($body, $this->fixture->value('legend_memory', 'body', 'mundane_id = ' . $legend));
    }

    /** Set (when given) and report the shared connection's client charset. */
    private function connectionCharset(?string $set = null): string
    {
        global $DB;
        if ($set !== null) {
            $DB->Clear();
            $DB->Execute("SET NAMES '{$set}'");
        }
        $DB->Clear();
        $rs = $DB->DataSet('SELECT @@character_set_client AS cs');
        $rs->Next();
        $DB->Clear();

        return (string) $rs->cs;
    }

    public function test_emoji_round_trips(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        // The app's connection is `SET NAMES utf8`; force it so this test means the same anywhere.
        $this->connectionCharset('utf8');
        $body = "Rest easy \u{1F622} friend \u{1F54A}\u{FE0F}";

        $r = Legend::addMemory($author, $legend, $body);

        $this->assertSame(0, $r['Status'], $r['Error'] ?? '');
        $this->assertSame($body, Legend::memories($legend)[0]['Body']);
        $hex = $this->fixture->pdo()
            ->query('SELECT HEX(CONVERT(body USING utf8mb4)) FROM ' . DB_PREFIX . 'legend_memory WHERE mundane_id = ' . $legend)
            ->fetchColumn();
        $this->assertSame(strtoupper(bin2hex($body)), $hex);
        $this->assertSame('utf8mb3', $this->connectionCharset(), 'the shared connection is left as it was');
        $this->connectionCharset('utf8mb4'); // this environment's default
    }
}
