<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins Report::NormalizeProvinceList, the cleanup behind the Kingdoms Teaser's
 * "show states/provinces" line.
 *
 * ork_park.province is free text: most rows hold a full name, but some hold a
 * postal code ("OH" beside "Ohio"), stray whitespace, or nothing at all. The
 * teaser must render one clean, distinct, sorted list per kingdom.
 */
final class ReportProvinceListTest extends TestCase
{
    public function testSortsDistinctFullNames(): void
    {
        $this->assertSame(
            ['Delaware', 'Maryland', 'Virginia'],
            Report::NormalizeProvinceList(['Virginia', 'Delaware', 'Maryland', 'Virginia'])
        );
    }

    public function testExpandsUsAndCanadianPostalCodes(): void
    {
        $this->assertSame(
            ['British Columbia', 'California', 'Missouri', 'Ohio'],
            Report::NormalizeProvinceList(['OH', 'mo', 'CA', 'BC'])
        );
    }

    public function testCodeAndFullNameCollapseToOne(): void
    {
        $this->assertSame(['Ohio'], Report::NormalizeProvinceList(['OH', 'Ohio', 'ohio']));
    }

    public function testDropsBlankAndWhitespaceEntries(): void
    {
        $this->assertSame(['Texas'], Report::NormalizeProvinceList(['', '   ', ' Texas ', null]));
    }

    public function testKeepsUnrecognizedValuesVerbatim(): void
    {
        // Non-US/CA regions (and anything we can't map) pass through untouched.
        $this->assertSame(['Bavaria', 'Québec'], Report::NormalizeProvinceList(['Québec', 'Bavaria']));
    }

    public function testEmptyInputYieldsEmptyList(): void
    {
        $this->assertSame([], Report::NormalizeProvinceList([]));
    }
}
