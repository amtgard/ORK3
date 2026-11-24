<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The In Memoriam roll (Legend::roll): Legends only, the latest year first, each
 * with their home and the memories still standing on their wall.
 */
final class LegendRollTest extends TestCase
{
    private LegendFixture $fixture;
    private int $park = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->park    = $this->fixture->firstParkId();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function test_roll_lists_legends_latest_year_first_with_home_and_memories(): void
    {
        $older  = $this->fixture->addPlayer($this->park, 'older', 2019)['mundane_id'];
        $newer  = $this->fixture->addPlayer($this->park, 'newer', 2024)['mundane_id'];
        $living = $this->fixture->addPlayer($this->park, 'living')['mundane_id'];
        $this->fixture->addMemory($older, $living);

        $mine = array_values(array_filter(
            Legend::roll(),
            static fn (array $r): bool => in_array($r['MundaneId'], [$older, $newer, $living], true)
        ));

        $this->assertSame([$newer, $older], array_column($mine, 'MundaneId'));
        $this->assertSame([2024, 2019], array_column($mine, 'Year'));
        $this->assertSame([0, 1], array_column($mine, 'Memories'));
        $this->assertNotSame('', $mine[0]['Persona']);
        $this->assertNotSame('', $mine[0]['ParkName']);
        $this->assertNotSame('', $mine[0]['KingdomName']);
    }

    public function test_a_removed_memory_is_not_counted(): void
    {
        $legend = $this->fixture->addPlayer($this->park, 'legend', 2020)['mundane_id'];
        $author = $this->fixture->addPlayer($this->park, 'author')['mundane_id'];
        $this->fixture->addMemory($legend, $author);
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'legend_memory SET removed_by_id = ' . $author . ' WHERE mundane_id = ' . $legend);

        $row = array_values(array_filter(Legend::roll(), static fn (array $r): bool => $r['MundaneId'] === $legend))[0];

        $this->assertSame(0, $row['Memories']);
    }
}
