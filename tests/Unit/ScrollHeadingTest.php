<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * &heading= — how the certificate's top block is composed. It mirrors &face=: an
 * allowlist, because the value lands in an HTML attribute (data-sc-heading) and
 * selects a layout, so anything unknown has to fall back rather than get through.
 */
final class ScrollHeadingTest extends TestCase
{
    public function testTheHeadingsAreTheThreeTheSpecNames(): void
    {
        $this->assertSame(
            ['auto' => 'Automatic', 'classic' => 'Classic', 'centered' => 'Centered'],
            \Scroll::Headings()
        );
    }

    public function testHeadingsAreAllowlisted(): void
    {
        $this->assertSame('centered', \Scroll::NormaliseHeading('centered'));
        $this->assertSame('auto', \Scroll::NormaliseHeading('Centered; drop table'));
        $this->assertSame('auto', \Scroll::NormaliseHeading(null));
    }

    public function testEveryListedHeadingNormalisesToItself(): void
    {
        foreach (array_keys(\Scroll::Headings()) as $key) {
            $this->assertSame($key, \Scroll::NormaliseHeading($key));
        }
    }

    public function testCaseAndStrayWhitespaceStillResolve(): void
    {
        // A hand-typed &heading=Classic  should land on classic, not silently revert.
        $this->assertSame('classic', \Scroll::NormaliseHeading('Classic'));
        $this->assertSame('classic', \Scroll::NormaliseHeading('  CLASSIC '));
        $this->assertSame('centered', \Scroll::NormaliseHeading("centered\n"));
    }

    public function testAnythingElseFallsBackToAuto(): void
    {
        foreach (['', ' ', 'left', 'centre', 'classic centered', '<script>', '../classic', '0', 'cen tered'] as $junk) {
            $this->assertSame('auto', \Scroll::NormaliseHeading($junk), var_export($junk, true));
        }
    }

    public function testTheDefaultIsAuto(): void
    {
        $this->assertSame('auto', \Scroll::DEFAULT_HEADING);
        $this->assertArrayHasKey(\Scroll::DEFAULT_HEADING, \Scroll::Headings());
    }
}
