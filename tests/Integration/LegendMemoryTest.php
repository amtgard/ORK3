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
        $this->assertSame('Our filter stopped the word "fucking". Please reword it; your text has been kept.', $r['Error']);
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

        $this->assertSame(3, Legend::removeMemory($author, $id, 'Harassment.')['Status']);
        $this->assertSame(3, Legend::removeMemory($parkOfficer, $id, 'Harassment.')['Status']);
        $this->assertSame(5, Legend::removeMemory(0, $id, 'Harassment.')['Status']);
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

        $this->assertSame(0, Legend::removeMemory($officer, $byOfficer, 'Harassment.')['Status']);
        $this->assertSame(0, Legend::removeMemory($admin, $byAdmin, 'Harassment.')['Status']);

        $this->assertSame($officer, (int) $this->fixture->value('legend_memory', 'removed_by_id', "legend_memory_id = {$byOfficer}"));
        $this->assertSame($admin, (int) $this->fixture->value('legend_memory', 'removed_by_id', "legend_memory_id = {$byAdmin}"));
        $this->assertNotNull($this->fixture->value('legend_memory', 'removed_at', "legend_memory_id = {$byOfficer}"));
        $this->assertSame(2, $this->memoryCount($legend), 'rows are kept, not deleted');

        $again = Legend::removeMemory($officer, $byOfficer, 'Harassment.');
        $this->assertSame(1, $again['Status'], 'a second removal does not restamp');
        $this->assertSame(1, Legend::removeMemory($officer, 999999999, 'Harassment.')['Status']);
    }

    public function test_removed_memory_not_listed(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $keep = $this->fixture->addMemory($legend, $author);
        $drop = $this->fixture->addMemory($legend, $author);

        Legend::removeMemory($officer, $drop, 'Harassment.');

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

    public function test_remove_records_reason_and_audits(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $this->connectionCharset('utf8');
        $body = "Mean words \u{1F622}";
        $id = (int) Legend::addMemory($author, $legend, $body)['MemoryId'];

        $this->assertSame(1, Legend::removeMemory($officer, $id, "  \n")['Status']);
        $this->assertSame(1, Legend::removeMemory($officer, $id, str_repeat('a', Legend::MAX_REMOVAL_REASON_CHARS + 1))['Status']);
        $this->assertSame(1, $this->fixture->count('legend_memory', "legend_memory_id = {$id} AND removed_by_id = 0"));

        $r = Legend::removeMemory($officer, $id, "  Asked by the family \u{1F54A}  ");
        $this->connectionCharset('utf8mb4');

        $this->assertSame(0, $r['Status'], $r['Error'] ?? '');
        // In hex: the fixture's own connection is utf8 and can't read an emoji back.
        $hex = $this->fixture->pdo()
            ->query('SELECT HEX(CONVERT(removed_reason USING utf8mb4)) FROM ' . DB_PREFIX . "legend_memory WHERE legend_memory_id = {$id}")
            ->fetchColumn();
        $this->assertSame(strtoupper(bin2hex("Asked by the family \u{1F54A}")), $hex);
        $prior = json_decode((string) $this->fixture->value('danger_audit', 'prior_state', "method_call = 'Legend::removeMemory' AND entity_id = {$legend}"), true);
        $this->assertSame($id, $prior['MemoryId']);
        $this->assertSame($author, $prior['AuthorId']);
        $this->assertSame($legend, $prior['LegendId']);
        $this->assertSame($body, $prior['Body']);
        $this->assertSame("Asked by the family \u{1F54A}", $prior['Reason']);
    }

    public function test_remove_refused_once_not_a_legend(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $id = $this->fixture->addMemory($legend, $author);
        $this->fixture->setLegend($legend, 0, false);
        Legend::clearMemo();

        foreach ([Legend::removeMemory($officer, $id, 'Harassment.'), Legend::removeAuthorMemories($officer, $legend, $author, 'Harassment.')] as $r) {
            $this->assertSame(1, $r['Status']);
            $this->assertSame('This player is not a Legend.', $r['Error']);
        }
        $this->assertSame(1, $this->fixture->count('legend_memory', "legend_memory_id = {$id} AND removed_by_id = 0"));
    }

    public function test_removed_memories_for_managers_only(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $parkOfficer = $this->fixture->addParkOfficer($this->park);
        $id = $this->fixture->addMemory($legend, $author);
        Legend::removeMemory($officer, $id, 'Harassment.');

        $r = Legend::removedMemories($officer, $legend);

        $this->assertSame(0, $r['Status']);
        $this->assertSame($id, $r['Memories'][0]['MemoryId']);
        $this->assertSame('Harassment.', $r['Memories'][0]['RemovedReason']);
        $this->assertSame((string) $this->fixture->value('mundane', 'persona', 'mundane_id = ' . $officer), $r['Memories'][0]['RemovedBy']);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+ \d{1,2}, \d{4}$/', $r['Memories'][0]['RemovedOn']);

        $this->assertSame(3, Legend::removedMemories($author, $legend)['Status']);
        $this->assertSame(3, Legend::removedMemories($parkOfficer, $legend)['Status']);
        $this->assertSame(5, Legend::removedMemories(0, $legend)['Status']);
        $this->assertCount(1, Legend::profileView($officer, $legend, $this->park)['RemovedMemories']);
        $this->assertSame([], Legend::profileView($author, $legend, $this->park)['RemovedMemories']);
        $this->assertSame([], Legend::profileView(0, $legend, $this->park)['RemovedMemories']);
        $this->assertSame([], Legend::profileView($parkOfficer, $legend, $this->park)['RemovedMemories']);
    }

    public function test_a_failed_audit_undoes_the_removal(): void
    {
        $legend = $this->legend();
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $id = (int) Legend::addMemory($this->player('author'), $legend, 'Kept when the audit fails.')['MemoryId'];

        $r = $this->withBrokenAudit(fn (): array => Legend::removeMemory($officer, $id, 'Off topic'));

        $this->assertSame(1, $r['Status']);
        $this->assertSame(1, $this->fixture->count('legend_memory', "legend_memory_id = {$id} AND removed_by_id = 0"));
    }

    public function test_remove_author_memories_on_one_wall_with_one_audit_row(): void
    {
        $legend = $this->legend();
        $other = $this->legend();
        $author = $this->player('author');
        $bystander = $this->player('bystander');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $parkOfficer = $this->fixture->addParkOfficer($this->park);
        $a = $this->fixture->addMemory($legend, $author);
        $b = $this->fixture->addMemory($legend, $author);
        $already = $this->fixture->addMemory($legend, $author);
        $keep = $this->fixture->addMemory($legend, $bystander);
        $elsewhere = $this->fixture->addMemory($other, $author);
        Legend::removeMemory($officer, $already, 'First.');

        $this->assertSame(5, Legend::removeAuthorMemories(0, $legend, $author, 'Harassment.')['Status']);
        $this->assertSame(3, Legend::removeAuthorMemories($author, $legend, $author, 'Harassment.')['Status']);
        $this->assertSame(3, Legend::removeAuthorMemories($parkOfficer, $legend, $author, 'Harassment.')['Status']);
        $this->assertSame(1, Legend::removeAuthorMemories($officer, $legend, $author, '  ')['Status']);

        $r = Legend::removeAuthorMemories($officer, $legend, $author, 'Harassment.');

        $this->assertSame(0, $r['Status'], $r['Error'] ?? '');
        $this->assertSame(2, $r['Removed']);
        $this->assertSame([$keep], array_column(Legend::memories($legend), 'MemoryId'));
        $this->assertSame([$elsewhere], array_column(Legend::memories($other), 'MemoryId'));
        $this->assertSame('First.', $this->fixture->value('legend_memory', 'removed_reason', "legend_memory_id = {$already}"));
        $this->assertSame('Harassment.', $this->fixture->value('legend_memory', 'removed_reason', "legend_memory_id = {$a}"));
        $this->assertSame(1, $this->fixture->count('danger_audit', "method_call = 'Legend::removeAuthorMemories' AND entity_id = {$legend}"));
        $prior = json_decode((string) $this->fixture->value('danger_audit', 'prior_state', "method_call = 'Legend::removeAuthorMemories' AND entity_id = {$legend}"), true);
        $post = json_decode((string) $this->fixture->value('danger_audit', 'post_state', "method_call = 'Legend::removeAuthorMemories' AND entity_id = {$legend}"), true);
        $this->assertEqualsCanonicalizing([$a, $b], $prior['MemoryIds']);
        $this->assertSame(2, $post['RemovedCount']);

        $this->assertSame(1, Legend::removeAuthorMemories($officer, $legend, $author, 'Harassment.')['Status'], 'nothing left');
    }

    public function test_filter_lets_a_bastard_sword_through_and_stores_it(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $body = 'A Bastard-Sword in his hand, and two bastard swords on the wall.';

        $this->assertSame(0, Legend::addMemory($author, $legend, $body)['Status']);
        $this->assertSame($body, Legend::memories($legend)[0]['Body']);

        $r = Legend::addMemory($author, $legend, 'A bastard sword and a bastard too.');
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('"bastard"', $r['Error']);
    }

    public function test_first_match_names_the_typed_word(): void
    {
        $filter = new ProfanityFilter();

        $this->assertNull($filter->firstMatch('He taught me to throw.'));
        $this->assertNull($filter->firstMatch(''));
        $this->assertNull($filter->firstMatch(null));
        $this->assertSame('Fucking', $filter->firstMatch('What a Fucking day'));
        $this->assertSame("bastard's", $filter->firstMatch("That bastard's shield"));
        $this->assertTrue($filter->containsProfanity('What a Fucking day'));
        $this->assertFalse($filter->containsProfanity('He taught me to throw.'));
        // Punctuation splits the typed text into more words than the filter saw: the innocent word is never named.
        $this->assertSame('shit', $filter->firstMatch('sh!t happens to my friend'));
        $this->assertSame('fuck', $filter->firstMatch('a!b sh happens to my friend fuck'));
    }

    public function test_rate_limit_counts_a_rolling_day_per_wall_on_the_database_clock(): void
    {
        $legend = $this->legend();
        $other = $this->legend();
        $author = $this->player('author');
        $admin = $this->fixture->addOrkAdmin($this->park);
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);

        for ($i = 1; $i <= Legend::MAX_MEMORIES_PER_DAY; $i++) {
            $this->assertSame(0, Legend::addMemory($author, $legend, "Memory {$i}")['Status']);
            $this->assertSame(0, Legend::addMemory($admin, $legend, "Memory {$i}")['Status']);
        }

        $r = Legend::addMemory($author, $legend, 'One more');
        $this->assertSame(1, $r['Status']);
        $this->assertStringContainsString('your text has been kept', $r['Error']);
        $this->assertSame(1, Legend::addMemory($admin, $legend, 'One more')['Status'], 'ORK admins are not exempt');
        $this->assertSame(0, Legend::addMemory($author, $other, 'One more')['Status'], 'each wall counts alone');

        // A removed memory still counts, or removal would refill the allowance.
        $this->assertSame(0, Legend::removeAuthorMemories($officer, $legend, $author, 'Harassment.')['Status']);
        $this->assertSame(1, Legend::addMemory($author, $legend, 'One more')['Status']);

        // Backdated in SQL: `created` is on the database's clock, not PHP's.
        $where = 'mundane_id = ' . $legend . ' AND author_id = ' . $author;
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . "legend_memory SET created = NOW() - INTERVAL 23 HOUR WHERE {$where}");
        $this->assertSame(1, Legend::addMemory($author, $legend, 'One more')['Status']);
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . "legend_memory SET created = NOW() - INTERVAL 25 HOUR WHERE {$where}");
        $this->assertSame(0, Legend::addMemory($author, $legend, 'One more')['Status']);
    }

    public function test_identical_body_refused_even_after_removal(): void
    {
        $legend = $this->legend();
        $other = $this->legend();
        $author = $this->player('author');
        $bystander = $this->player('bystander');
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $id = (int) Legend::addMemory($author, $legend, "Rest easy \u{1F339}")['MemoryId'];

        $this->assertSame(1, Legend::addMemory($author, $legend, "  Rest easy \u{1F339} ")['Status']);
        // The column's collation calls these two equal; they are not.
        $this->assertSame(0, Legend::addMemory($author, $legend, "Rest easy \u{1F33B}")['Status']);
        $this->assertSame(0, Legend::addMemory($bystander, $legend, "Rest easy \u{1F339}")['Status'], 'another author');
        $this->assertSame(0, Legend::addMemory($author, $other, "Rest easy \u{1F339}")['Status'], 'another wall');

        Legend::removeMemory($officer, $id, 'Harassment.');
        $this->assertSame(1, Legend::addMemory($author, $legend, "Rest easy \u{1F339}")['Status']);
    }

    public function test_memories_page_and_clamp(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        for ($i = 0; $i < Legend::MEMORIES_PER_PAGE + 2; $i++) {
            $this->fixture->addMemory($legend, $author);
        }

        $this->assertCount(Legend::MEMORIES_PER_PAGE, Legend::memories($legend));
        $this->assertCount(2, Legend::memories($legend, Legend::MEMORIES_PER_PAGE, Legend::MEMORIES_PER_PAGE));
        $this->assertCount(1, Legend::memories($legend, 1));
        $this->assertCount(Legend::MEMORIES_PER_PAGE, Legend::memories($legend, 100000));
        $this->assertCount(Legend::MEMORIES_PER_PAGE, Legend::memories($legend, 0));
        $this->assertCount(Legend::MEMORIES_PER_PAGE, Legend::memories($legend, -5, -5));
        $this->assertSame([], Legend::memories($legend, 25, PHP_INT_MAX));
        $this->assertSame(Legend::MEMORIES_PER_PAGE + 2, Legend::memories($legend)[0]['AuthorMemoryCount']);
        $this->assertSame(Legend::MEMORIES_PER_PAGE + 2, Legend::memoryCount($legend));
        $this->assertSame(Legend::MEMORIES_PER_PAGE + 2, Legend::profileView(0, $legend, $this->park)['MemoryCount']);

        $this->fixture->setLegend($legend, 0, false);
        Legend::clearMemo();
        $this->assertSame(0, Legend::memoryCount($legend));
    }

    public function test_byline_keeps_the_persona_as_posted_with_the_current_home(): void
    {
        $legend = $this->legend();
        $author = $this->player('author');
        $posted = (string) $this->fixture->value('mundane', 'persona', 'mundane_id = ' . $author);
        Legend::addMemory($author, $legend, 'Remembered.');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . "mundane SET persona = 'Sir Renamed' WHERE mundane_id = " . $author);

        $row = Legend::memories($legend)[0];

        $this->assertSame($posted, $row['AuthorPersona']);
        $this->assertSame($posted, $this->fixture->value('legend_memory', 'author_persona', 'mundane_id = ' . $legend));
        $this->assertSame((string) $this->fixture->value('park', 'abbreviation', 'park_id = ' . $this->park), $row['ParkAbbr']);
        $this->assertSame((string) $this->fixture->value('kingdom', 'abbreviation', 'kingdom_id = ' . $this->kingdom), $row['KingdomAbbr']);
        $this->assertSame($row['KingdomAbbr'] . ':' . $row['ParkAbbr'], $row['AuthorHome']);

        // The date part as stored, whatever PHP's zone makes of the time.
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . "legend_memory SET created = '2026-10-12 23:30:00' WHERE mundane_id = " . $legend);
        $this->assertSame('October 12, 2026', Legend::memories($legend)[0]['CreatedOn']);
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . "legend_memory SET created = '2026-10-12 00:30:00' WHERE mundane_id = " . $legend);
        $this->assertSame('October 12, 2026', Legend::memories($legend)[0]['CreatedOn']);

        // A row with no stored persona falls back to the live one; no home, no bracket.
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . "legend_memory SET author_persona = '' WHERE mundane_id = " . $legend);
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET park_id = 0 WHERE mundane_id = ' . $author);
        $row = Legend::memories($legend)[0];
        $this->assertSame('Sir Renamed', $row['AuthorPersona']);
        $this->assertSame('', $row['ParkAbbr']);
        $this->assertSame('', $row['AuthorHome']);
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

    /** Run $work with every danger_audit insert refused: on the app's connection a TEMPORARY table lacking the other columns shadows the real one. */
    private function withBrokenAudit(callable $work): array
    {
        global $DB;
        $table = DB_PREFIX . 'danger_audit';
        $DB->Clear();
        $DB->Execute("CREATE TEMPORARY TABLE {$table} (danger_audit_id INT, method_call VARCHAR(100))");
        try {
            return $work();
        } finally {
            $DB->Clear();
            $DB->Execute("DROP TEMPORARY TABLE {$table}");
            $DB->Clear();
        }
    }
}
