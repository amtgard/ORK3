<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * compose() and the Ajax preview build a scroll from client-supplied fields — an
 * award id and a hand-typed title, nothing that carries OfficerRole — so they
 * cannot resolve eligibility through Scroll::OrderForAwardRow() directly (a
 * missing OfficerRole there defaults to "none" and would wrongly treat every
 * officer position as custom-eligible). Model_Scrollery::award_order_for_compose()
 * instead re-searches the kingdom's own filtered choice list, which is what keeps
 * the officer-position exclusion in force for a hand-edited request.
 *
 * orderForChoiceForTest() is the pure search behind that method, split out the
 * same way bulkSqlForTest() keeps the bulk query testable without a database.
 */
final class ScrolleryAwardOrderForComposeTest extends TestCase
{
    private function choice(array $o = []): array
    {
        return array_merge([
            'KingdomAwardId' => 0,
            'AwardId'        => 0,
            'Name'           => '',
            'Order'          => 'custom',
            'MinRank'        => 0,
            'MaxRank'        => 0,
            'Variants'       => [],
        ], $o);
    }

    public function testASpecificOrderMatchesByIdAndName(): void
    {
        $choices = [$this->choice(['AwardId' => 33, 'Name' => 'Order of the Griffon', 'Order' => 'griffon'])];

        $order = Model_Scrollery::orderForChoiceForTest($choices, 33, 'Order of the Griffon');

        $this->assertSame('griffon', $order['id']);
    }

    public function testACustomEligibleAwardMatches(): void
    {
        $choices = [$this->choice(['AwardId' => 1, 'Name' => 'Master Rose', 'Order' => 'custom'])];

        $order = Model_Scrollery::orderForChoiceForTest($choices, 1, 'Master Rose');

        $this->assertSame('custom', $order['id']);
    }

    /**
     * Archery's real grants carry no base award of their own, so award_id 0 (also
     * 94, 131) is shared by several distinct catalog entries told apart only by
     * name. Matching on id alone would silently print whichever same-id entry the
     * search reached first, regardless of what was actually requested.
     */
    public function testASharedSentinelIdIsDisambiguatedByName(): void
    {
        $choices = [
            $this->choice(['AwardId' => 0, 'Name' => 'Order of the Hunter', 'Order' => 'hunter']),
            $this->choice(['AwardId' => 0, 'Name' => 'Some Other Title', 'Order' => 'custom']),
        ];

        $this->assertSame('hunter', Model_Scrollery::orderForChoiceForTest($choices, 0, 'Order of the Hunter')['id']);
        $this->assertSame('custom', Model_Scrollery::orderForChoiceForTest($choices, 0, 'Some Other Title')['id']);
    }

    public function testMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        $choices = [$this->choice(['AwardId' => 0, 'Name' => 'Order of the Hunter', 'Order' => 'hunter'])];

        $order = Model_Scrollery::orderForChoiceForTest($choices, 0, '  ORDER of THE hunter  ');

        $this->assertSame('hunter', $order['id']);
    }

    /**
     * A hand-edited request naming an award/title pair the kingdom's own catalog
     * never offered — an officer position, a typo, or a different kingdom's award
     * entirely — must not fall back to printing anything.
     */
    public function testAnUnlistedPairMatchesNothing(): void
    {
        $choices = [$this->choice(['AwardId' => 1, 'Name' => 'Master Rose', 'Order' => 'custom'])];

        $this->assertNull(Model_Scrollery::orderForChoiceForTest($choices, 70, 'Sheriff'));
        $this->assertNull(Model_Scrollery::orderForChoiceForTest($choices, 1, 'Sheriff'));
        $this->assertNull(Model_Scrollery::orderForChoiceForTest([], 1, 'Master Rose'));
    }

    /** The kingdomId guard short-circuits before the model would need a database. */
    public function testAwardOrderForComposeRejectsAMissingKingdom(): void
    {
        $this->assertNull((new Model_Scrollery())->award_order_for_compose(0, 1, 'Master Rose'));
        $this->assertNull((new Model_Scrollery())->award_order_for_compose(-1, 1, 'Master Rose'));
    }
}
