<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Legend's Memory Wall can be left off or closed by those who manage the status:
 * the wall and its memories are hidden everywhere, nothing is deleted, and opening
 * it again brings them back.
 */
final class LegendWallTest extends TestCase
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

    private function rollRow(int $legendId): array
    {
        return array_values(array_filter(Legend::roll(), static fn (array $r): bool => $r['MundaneId'] === $legendId))[0];
    }

    public function test_a_conversion_opens_the_wall_unless_told_not_to(): void
    {
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $with    = $this->player('with');
        $without = $this->player('without');

        $this->assertSame(0, Legend::convert($officer, $with, 2024)['Status']);
        $this->assertSame(0, Legend::convert($officer, $without, 2024, false)['Status']);

        $this->assertTrue(Legend::wallOpen($with));
        $this->assertFalse(Legend::wallOpen($without));
        $this->assertTrue(Legend::profileView($officer, $with, $this->park)['WallOpen']);
        $this->assertFalse(Legend::profileView($officer, $without, $this->park)['WallOpen']);
    }

    public function test_no_wall_for_someone_who_is_not_a_legend(): void
    {
        $this->assertFalse(Legend::wallOpen($this->player('living')));
    }

    public function test_a_closed_wall_takes_no_memories(): void
    {
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $legend  = $this->player('legend');
        $author  = $this->player('author');
        Legend::convert($officer, $legend, 2024, false);

        $r = Legend::addMemory($author, $legend, 'He taught me to throw.');

        $this->assertSame(1, $r['Status']);
        $this->assertSame(0, $this->fixture->count('legend_memory', 'mundane_id = ' . $legend));
    }

    public function test_closing_hides_the_memories_and_opening_brings_them_back(): void
    {
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $legend  = $this->fixture->addPlayer($this->park, 'legend', 2020)['mundane_id'];
        $author  = $this->player('author');
        $this->assertSame(0, Legend::addMemory($author, $legend, 'He taught me to throw.')['Status']);
        $this->assertSame(1, $this->rollRow($legend)['Memories']);

        $this->assertSame(0, Legend::setWall($officer, $legend, false)['Status']);

        $this->assertFalse(Legend::wallOpen($legend));
        $this->assertSame([], Legend::memories($legend));
        $this->assertSame(0, Legend::memoryCount($legend));
        $this->assertSame(0, $this->rollRow($legend)['Memories']);
        $view = Legend::profileView($officer, $legend, $this->park);
        $this->assertSame([], $view['Memories']);
        $this->assertSame([], $view['RemovedMemories']);
        $this->assertSame(1, $this->fixture->count('legend_memory', 'mundane_id = ' . $legend));

        $this->assertSame(0, Legend::setWall($officer, $legend, true)['Status']);

        $this->assertCount(1, Legend::memories($legend));
        $this->assertSame(1, $this->rollRow($legend)['Memories']);
    }

    public function test_a_change_is_audited_and_a_repeat_is_not(): void
    {
        $officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $legend  = $this->fixture->addPlayer($this->park, 'legend', 2020)['mundane_id'];
        $audits  = fn (): int => $this->fixture->count('danger_audit', "method_call = 'Legend::setWall' AND entity_id = " . $legend);

        Legend::setWall($officer, $legend, false);
        $this->assertSame(1, $audits());

        Legend::setWall($officer, $legend, false);
        $this->assertSame(1, $audits());
    }

    public function test_only_those_who_manage_the_legend_may_change_it(): void
    {
        $legend      = $this->fixture->addPlayer($this->park, 'legend', 2020)['mundane_id'];
        $parkOfficer = $this->fixture->addParkOfficer($this->park);
        $officer     = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);

        $this->assertNotSame(0, Legend::setWall($parkOfficer, $legend, false)['Status']);
        $this->assertNotSame(0, Legend::setWall($this->player('anyone'), $legend, false)['Status']);
        $this->assertTrue(Legend::wallOpen($legend));

        $this->assertSame(1, Legend::setWall($officer, $this->player('living'), false)['Status']);
    }
}
