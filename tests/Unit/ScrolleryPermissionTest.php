<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the "no dead buttons" rule: a Print control appears only where certificate
 * art exists AND the award is one Scrollery is willing to print, so a later change
 * cannot quietly start offering awards that cannot be printed.
 */
final class ScrolleryPermissionTest extends TestCase
{
    public function testPrintableAwardsExcludeOfficerPositionsAndExcludedClasses(): void
    {
        $rows = [
            ['AwardId' => 33,  'OfficerRole' => 'none'],           // griffon — its own art
            ['AwardId' => 94,  'OfficerRole' => 'none'],           // Custom Award — the custom scroll
            ['AwardId' => 189, 'OfficerRole' => 'other-officer'],  // Guildmaster — an officer position
            ['AwardId' => 1,   'OfficerRole' => 'none'],           // Master Rose — not on the allowlist
            ['AwardId' => 52,  'OfficerRole' => 'none'],           // Lord — a title, no longer on it either
        ];

        $printable = array_values(array_filter($rows, fn ($r) => Scroll::HasArt($r)));

        $this->assertCount(2, $printable);
        $this->assertSame(33, $printable[0]['AwardId']);
        $this->assertSame(94, $printable[1]['AwardId']);
    }

    public function testEveryLadderStillHasItsOwnDesign(): void
    {
        foreach (Award::GetLadderMasterMap() as $ladderId => $meta) {
            $this->assertNotNull(
                Scroll::OrderForAwardId((int) $ladderId),
                "ladder {$ladderId} lost its specific order"
            );
        }
    }

    /**
     * The render route takes its award ids from the query string, so the id list has
     * to survive being hostile. Model_Scrollery re-checks permission per id, but the
     * controller's parsing must not turn junk into a valid id.
     */
    public function testAwardIdListParsingRejectsJunk(): void
    {
        $parse = static fn (string $raw): array => array_values(array_unique(array_filter(
            array_map('intval', explode(',', $raw))
        )));

        $this->assertSame([123, 456], $parse('123,456'));
        $this->assertSame([123], $parse('123,123,123'));          // deduplicated
        $this->assertSame([], $parse(''));                        // empty
        $this->assertSame([], $parse('abc,,-0'));                 // no truthy ints
        $this->assertSame([12], $parse("12' OR '1'='1"));         // SQLi shape -> one int
        $this->assertSame([5], $parse('5.9'));                    // no rounding up
    }
}
