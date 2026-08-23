<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ork_event_jsonld() builds the schema.org Event structure Google reads for
 * rich event results. Datetimes stay venue-local and offset-less on purpose —
 * the ORK stores naive local times, and Google interprets offset-less event
 * times as local to the supplied venue address.
 */
final class EventJsonLdTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../system/lib/ork3/common.php';
    }

    public function testFullEvent(): void
    {
        $ld = ork_event_jsonld(array(
            'name'        => 'Battle of the Dens 2, Clash of Monsters',
            'start'       => '2026-10-23 16:00:00',
            'end'         => '2026-10-25 16:00:00',
            'description' => 'Dens is the longest running Amtgard event',
            'image'       => 'https://ork.amtgard.com/assets/heraldry/event/18666.jpg',
            'venue'       => 'Wolven Fang',
            'street'      => '236 Woodland Rd',
            'city'        => 'Wahnapitae',
            'province'    => 'ON',
            'postal'      => 'P0M 3C0',
            'country'     => 'Canada',
            'organizer'   => 'The Kingdom of the Nine Blades',
            'organizer_url' => 'https://ork.amtgard.com/orkui/index.php?Route=Kingdom/profile/31',
            'url'         => 'https://ork.amtgard.com/orkui/index.php?Route=Event/detail/18666/9175',
        ));

        $this->assertSame('Event', $ld['@type']);
        // Offset-less, T-separated, venue-local — never a fabricated offset.
        $this->assertSame('2026-10-23T16:00:00', $ld['startDate']);
        $this->assertSame('2026-10-25T16:00:00', $ld['endDate']);
        $this->assertSame('Wolven Fang', $ld['location']['name']);
        $this->assertSame('Wahnapitae', $ld['location']['address']['addressLocality']);
        $this->assertSame('The Kingdom of the Nine Blades', $ld['organizer']['name']);
        $this->assertSame('https://ork.amtgard.com/orkui/index.php?Route=Kingdom/profile/31', $ld['organizer']['url']);
    }

    public function testEmptyFieldsAreOmittedNotBlank(): void
    {
        $ld = ork_event_jsonld(array(
            'name'  => 'Park Day',
            'start' => '2026-09-01 12:00:00',
        ));
        $this->assertArrayNotHasKey('endDate', $ld);
        $this->assertArrayNotHasKey('description', $ld);
        $this->assertArrayNotHasKey('image', $ld);
        $this->assertArrayNotHasKey('location', $ld);
        $this->assertArrayNotHasKey('organizer', $ld);
    }

    public function testSingleDayEventDropsIdenticalEnd(): void
    {
        $ld = ork_event_jsonld(array(
            'name'  => 'Tourney',
            'start' => '2026-09-01 12:00:00',
            'end'   => '2026-09-01 12:00:00',
        ));
        $this->assertArrayNotHasKey('endDate', $ld);
    }

    public function testMissingNameOrStartYieldsNothing(): void
    {
        $this->assertSame(array(), ork_event_jsonld(array('name' => 'X')));
        $this->assertSame(array(), ork_event_jsonld(array('start' => '2026-09-01 12:00:00')));
        $this->assertSame(array(), ork_event_jsonld(array('name' => 'X', 'start' => 'not-a-date')));
    }
}
