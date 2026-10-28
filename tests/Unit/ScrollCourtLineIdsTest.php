<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The court print route's line selection: &courtLines= is the parameter, and an old
 * link or open tab carrying &lines=12,13 still means "just those lines". On the render
 * page &lines=0/1 is the Reason-lines switch, never a line id.
 */
final class ScrollCourtLineIdsTest extends TestCase
{
    public function testCourtLinesIsRead(): void
    {
        $this->assertSame([12, 13], Scroll::CourtLineIds(['courtLines' => '12,13']));
    }

    public function testOldLinesParameterIsTreatedAsCourtLines(): void
    {
        $this->assertSame([12, 13], Scroll::CourtLineIds(['lines' => '12,13']));
        $this->assertSame([12], Scroll::CourtLineIds(['lines' => '12']));
    }

    public function testReasonLinesSwitchIsNotALineId(): void
    {
        $this->assertSame([], Scroll::CourtLineIds(['lines' => '0']));
        $this->assertSame([], Scroll::CourtLineIds(['lines' => '1']));
    }

    public function testCourtLinesWinsOverLines(): void
    {
        $this->assertSame([5], Scroll::CourtLineIds(['courtLines' => '5', 'lines' => '12,13']));
    }

    public function testJunkAndDuplicatesAreDropped(): void
    {
        $this->assertSame([7, 9], Scroll::CourtLineIds(['courtLines' => '7,x,0,7,-3,9']));
        $this->assertSame([], Scroll::CourtLineIds([]));
    }
}
