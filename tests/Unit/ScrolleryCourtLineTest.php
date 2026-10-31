<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Court Planner line becomes a Scrollery certificate through one pure mapping:
 * Model_Scrollery::courtLineScrollRowForTest() turns (line, catalog choice, court,
 * giver) into the row Scroll::BuildScroll() draws from.
 *
 * Pinned here because the planner line carries text that must not reach paper —
 * a Monarchy Only recommendation's reason can sit in public_comment if the line
 * was saved while the rec was still public — and because the court, not the line,
 * owns the date the award is given on.
 */
final class ScrolleryCourtLineTest extends TestCase
{
    /** @param array<string, mixed> $o */
    private function line(array $o = []): array
    {
        return array_merge([
            'CourtAwardId'    => 11,
            'MundaneId'       => 501,
            'Persona'         => 'Sir Test',
            'KingdomAwardId'  => 77,
            'AwardId'         => 27,
            'AwardName'       => 'Order of the Rose',
            'Rank'            => 1,
            'Status'          => 'planned',
            'ScrollStatus'    => Court::SCROLL_SCROLLERY,
            'PublicComment'   => '',
            'RecReason'       => '',
            'RecMonarchyOnly' => false,
            'PublicCommentCleared' => false,
            'IsLadder'        => true,
        ], $o);
    }

    private function choice(): array
    {
        return ['KingdomAwardId' => 77, 'AwardId' => 21, 'Name' => 'Order of the Rose', 'Order' => 'rose'];
    }

    /** @param array<string, mixed> $o */
    private function court(array $o = []): array
    {
        return array_merge([
            'Date'        => '2026-11-07',
            'ParkId'      => 0,
            'KingdomId'   => 100001,
            'ParkName'    => '',
            'KingdomName' => 'The Kingdom of the Wetlands',
        ], $o);
    }

    public function testRowCarriesCourtDateRankAndCatalogName(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(['Rank' => 4]),
            ['AwardId' => 27, 'Name' => 'Order of the Rose'],
            $this->court(['Date' => '2026-11-07']),
            ''
        );

        $this->assertSame(4, $row['Rank']);
        $this->assertSame('2026-11-07', $row['Date']);
        $this->assertSame('Order of the Rose', $row['KingdomAwardName']);
        $this->assertSame('none', $row['OfficerRole']);
        $this->assertSame(27, $row['AwardId']);
    }

    /** Review focus 2: a hidden rec's reason saved as the citation never prints. */
    public function testCitationWithheldWhenItIsAHiddenRecReason(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(['PublicComment' => 'secret', 'RecReason' => 'secret', 'RecMonarchyOnly' => true]),
            $this->choice(),
            $this->court(),
            ''
        );

        $this->assertSame('', $row['Note']);
    }

    public function testOfficerWrittenCitationSurvives(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(['PublicComment' => 'For valor', 'RecReason' => 'secret', 'RecMonarchyOnly' => true]),
            $this->choice(),
            $this->court(),
            ''
        );

        $this->assertSame('For valor', $row['Note']);
    }

    /**
     * Scrolls print BEFORE court, when most lines still have no typed citation:
     * the scroll carries the rec reason the commit will inherit and publish.
     */
    public function testEmptyCitationInheritsAPublicRecReason(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(['PublicComment' => '', 'RecReason' => 'For holding the bridge', 'RecMonarchyOnly' => false]),
            $this->choice(),
            $this->court(),
            ''
        );

        $this->assertSame('For holding the bridge', $row['Note']);
    }

    /** The officer's explicit "(Clear)" promises nothing of the rec is published. */
    public function testClearedCitationDoesNotInheritTheRecReason(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest(
            $this->line([
                'PublicComment'        => '',
                'PublicCommentCleared' => true,
                'RecReason'            => 'For holding the bridge',
                'RecMonarchyOnly'      => false,
            ]),
            $this->choice(),
            $this->court(),
            ''
        );

        $this->assertSame('', $row['Note']);
    }

    /** An empty citation never inherits a Monarchy Only rec's reason. */
    public function testEmptyCitationDoesNotInheritAHiddenRecReason(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(['PublicComment' => '', 'RecReason' => 'secret', 'RecMonarchyOnly' => true]),
            $this->choice(),
            $this->court(),
            ''
        );

        $this->assertSame('', $row['Note']);
    }

    /** The shared rule, against the commit path's own precedence table. */
    public function testEffectiveCitationPrecedence(): void
    {
        // [publicComment, cleared, recReason, monarchyOnly] => expected
        $cases = [
            [['Typed', false, 'Rec', false], 'Typed'],
            [['Typed', true, 'Rec', false], 'Typed'],
            [['', false, 'Rec', false], 'Rec'],
            [['', true, 'Rec', false], ''],
            [['', false, 'Rec', true], ''],
            [['Rec', false, 'Rec', true], ''],
            [[' Rec ', false, 'Rec', true], ''],
            [['Officer wrote this', false, 'Rec', true], 'Officer wrote this'],
            [['', false, '', false], ''],
        ];
        foreach ($cases as [$args, $expected]) {
            $this->assertSame($expected, Court::effectiveCitation(...$args), json_encode($args));
        }
    }

    public function testUnnamedCustomChoicePrintsNoName(): void
    {
        $this->assertFalse(Model_Scrollery::courtChoicePrintsANameForTest(['AwardId' => 94, 'Name' => 'Custom Award']));
        $this->assertFalse(Model_Scrollery::courtChoicePrintsANameForTest(['AwardId' => 94, 'Name' => '']));
        $this->assertFalse(Model_Scrollery::courtChoicePrintsANameForTest(['AwardId' => 246, 'Name' => 'Custom Title']));
        $this->assertTrue(Model_Scrollery::courtChoicePrintsANameForTest(['AwardId' => 94, 'Name' => 'Order of the Hellrider']));
        $this->assertTrue(Model_Scrollery::courtChoicePrintsANameForTest(['AwardId' => 21, 'Name' => 'Order of the Rose']));
    }

    public function testGiverPassesThrough(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest($this->line(), $this->choice(), $this->court(), 'Queen Alys');

        $this->assertSame('Queen Alys', $row['GivenBy']);
    }

    public function testUndatedCourtLeavesDateBlank(): void
    {
        $row = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(),
            $this->choice(),
            $this->court(['Date' => null]),
            ''
        );

        $this->assertSame('', $row['Date']);
    }

    /**
     * A park court issues under the park and a kingdom court under the kingdom,
     * the way compose() fills them: only the issuing body's name is set.
     */
    public function testIssuerFollowsTheCourtsLevel(): void
    {
        $park = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(),
            $this->choice(),
            $this->court(['ParkId' => 9, 'ParkName' => 'Mordengaard']),
            ''
        );
        $this->assertSame(9, $park['ParkId']);
        $this->assertSame('Mordengaard', $park['ParkName']);
        $this->assertSame('', $park['KingdomName']);

        $kingdom = Model_Scrollery::courtLineScrollRowForTest($this->line(), $this->choice(), $this->court(), '');
        $this->assertSame(0, $kingdom['ParkId']);
        $this->assertSame(100001, $kingdom['KingdomId']);
        $this->assertSame('', $kingdom['ParkName']);
        $this->assertSame('The Kingdom of the Wetlands', $kingdom['KingdomName']);
    }

    /** The row is one BuildScroll() accepts as-is. */
    public function testRowBuildsACertificate(): void
    {
        $row    = Model_Scrollery::courtLineScrollRowForTest(
            $this->line(['Rank' => 3]),
            $this->choice(),
            $this->court(),
            'Queen Alys'
        );
        $scroll = Scroll::BuildScroll($row, ['player' => 'Sir Test']);

        $this->assertSame('rose', $scroll['order']);
        $this->assertSame('Queen Alys', $scroll['givenBy']);
        $this->assertSame('Order of the Rose', $scroll['title']);
    }
}
