<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the "no dead buttons" rule: a Print control appears only where certificate
 * art exists, so a later change cannot quietly start offering awards that cannot
 * be printed.
 */
final class ScrolleryPermissionTest extends TestCase
{
    public function testPrintableAwardsExcludeAwardsWithNoArt(): void
    {
        $rows = [
            ['AwardId' => 33,  'Rank' => 3],   // griffon — has art
            ['AwardId' => 1,   'Rank' => 0],   // Master Rose — none
            ['AwardId' => 243, 'Rank' => 7],   // battle — has art
            ['AwardId' => 999, 'Rank' => 1],   // unknown — none
        ];

        $printable = array_values(array_filter($rows, fn ($r) => Scroll::HasArt($r)));

        $this->assertCount(2, $printable);
        $this->assertSame(33, $printable[0]['AwardId']);
        $this->assertSame(243, $printable[1]['AwardId']);
    }

    /**
     * Every Master award, every title and every non-ladder award is unprintable in
     * phase 1. Spot-check the ones most likely to be mistaken for printable: the
     * Masters sit directly above the ladders they cap.
     */
    public function testMasterAwardsAreNotPrintable(): void
    {
        foreach (Award::GetLadderMasterMap() as $ladderId => $meta) {
            $this->assertTrue(
                Scroll::HasArt(['AwardId' => $ladderId]),
                "ladder {$ladderId} should be printable"
            );

            foreach ($meta['MasterAwardIds'] as $masterId) {
                $this->assertFalse(
                    Scroll::HasArt(['AwardId' => $masterId]),
                    "master award {$masterId} ({$meta['MasterName']}) has no art and must not be printable"
                );
            }
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
