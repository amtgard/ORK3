<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SearchService RSVP count fields in event search (backend characterization for T-INF-06 / search widget).
 */
final class EventRsvpSearchTest extends TestCase
{
    private EventRsvpFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = EventRsvpFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testSearchEventIncludesRsvpCounts(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('search-counts');
        $otherId = $this->fixture->insertSecondPlayer($ctx['park_id'], $ctx['kingdom_id'], 'search-b');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');
        $this->fixture->insertRsvp($ctx['detail_id'], $otherId, 'interested');

        $search = new SearchService();
        $results = $search->Event('T01RSVP Event search-counts', null, null, null, null, 10, $ctx['event_id']);

        $this->assertNotEmpty($results);
        $match = null;
        foreach ($results as $row) {
            if ((int) $row['EventId'] === $ctx['event_id']) {
                $match = $row;
                break;
            }
        }

        $this->assertNotNull($match, 'Expected seeded event in search results');
        $this->assertSame(1, $match['RsvpGoing']);
        $this->assertSame(1, $match['RsvpInterested']);
        $this->assertSame(2, $match['RsvpTotal']);
    }
}
