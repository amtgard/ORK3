<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Award::OptionGroupLabelFor() is the single bucketing rule behind every sectioned
 * award dropdown: the grant/recommend pickers reach it through
 * GetAwardOptionGroups(), and the Scrollery composer reaches it directly from
 * Model_Scrollery::choicesForAwardListForTest().
 *
 * It was extracted from GetAwardOptionGroups()'s inline if/elseif ladder. These
 * cases pin the PRECEDENCE that ladder encoded, because most of it is order-
 * dependent and would survive a careless reordering unnoticed:
 *
 * - a pseudo-ladder id wins over everything, including the custom names;
 * - 'Defender' and 'Master' are Noble Titles BY NAME, ahead of the peerage checks
 *   that would otherwise file 'Master' under Masterhoods;
 * - 'Weaponmaster' is forced to Other ahead of those same checks;
 * - is_title + title_class 10 reaches Masterhoods, but only after peerage.
 */
final class AwardOptionGroupLabelTest extends TestCase
{
    /** @var list<int> */
    private array $pseudo = [7067, 6628];

    private function row(array $o = []): array
    {
        return array_merge([
            'KingdomAwardId'   => 1,
            'AwardId'          => 1,
            'KingdomAwardName' => 'Something',
            'AwardName'        => 'Something',
            'IsLadder'         => 0,
            'IsTitle'          => 0,
            'TitleClass'       => 0,
            'Peerage'          => '',
        ], $o);
    }

    private function label(array $o = []): string
    {
        return Award::OptionGroupLabelFor($this->row($o), $this->pseudo);
    }

    public function testPseudoLadderIdWinsOverEveryOtherSignal(): void
    {
        // Carries the custom name AND a Knight peerage, and is still a ladder award:
        // the pseudo-ladder check is first in the rule for exactly this reason.
        self::assertSame('Ladder Awards', $this->label([
            'KingdomAwardId' => 7067,
            'AwardName'      => 'Custom Award',
            'Peerage'        => 'Knight',
        ]));
    }

    public function testCustomEntriesAreTheirOwnBucketNotASection(): void
    {
        self::assertSame('Custom', $this->label(['AwardName' => 'Custom Award']));
        self::assertSame('Custom', $this->label(['AwardName' => 'Custom Title']));
    }

    public function testIsLadderFlagReachesLadderAwards(): void
    {
        self::assertSame('Ladder Awards', $this->label(['IsLadder' => 1]));
    }

    /**
     * The two name-keyed overrides that MUST outrank the peerage checks below them.
     * 'Master' carrying peerage 'Master' is the case that proves the ordering: the
     * name wins, so it files as a Noble Title rather than a Masterhood.
     */
    public function testDefenderAndMasterAreNobleTitlesByName(): void
    {
        self::assertSame('Noble Titles', $this->label(['AwardName' => 'Defender']));
        self::assertSame('Noble Titles', $this->label([
            'AwardName' => 'Master',
            'Peerage'   => 'Master',
        ]));
    }

    public function testWeaponmasterIsForcedToOther(): void
    {
        self::assertSame('Other', $this->label([
            'AwardName' => 'Weaponmaster',
            'Peerage'   => 'Master',
        ]));
    }

    public function testPeerageBuckets(): void
    {
        self::assertSame('Knighthoods', $this->label(['Peerage' => 'Knight']));
        self::assertSame('Paragons', $this->label(['Peerage' => 'Paragon']));
        self::assertSame('Masterhoods', $this->label(['Peerage' => 'Master']));

        foreach (['Squire', 'Man-At-Arms', 'Page', 'Lords-Page'] as $peerage) {
            self::assertSame('Associate Titles', $this->label(['Peerage' => $peerage]), $peerage);
        }
        self::assertSame('Associate Titles', $this->label(['AwardName' => 'Apprentice']));
    }

    public function testTitleClassBuckets(): void
    {
        // 10 is a masterhood-equivalent title; 30 and up are noble titles.
        self::assertSame('Masterhoods', $this->label(['IsTitle' => 1, 'TitleClass' => 10]));
        self::assertSame('Noble Titles', $this->label(['IsTitle' => 1, 'TitleClass' => 30]));
        self::assertSame('Noble Titles', $this->label(['IsTitle' => 1, 'TitleClass' => 40]));
        self::assertSame('Noble Titles', $this->label(['AwardName' => 'Esquire']));

        // A title class between the two thresholds matches neither and falls through.
        self::assertSame('Other', $this->label(['IsTitle' => 1, 'TitleClass' => 20]));
    }

    public function testAnUnremarkableAwardFallsThroughToOther(): void
    {
        self::assertSame('Other', $this->label(['AwardName' => 'Order of the Owl']));
    }

    /**
     * A row from Kingdom::GetAwardList() whose award_id join missed carries neither
     * AwardName nor any peerage/title signal (archery's real grants have this
     * shape). It must still classify rather than notice-crash on the missing key.
     */
    public function testADanglingRowClassifiesWithoutItsAwardName(): void
    {
        self::assertSame('Other', Award::OptionGroupLabelFor([
            'KingdomAwardId'   => 6437,
            'KingdomAwardName' => 'Archery Marksman',
            'AwardId'          => null,
            'OfficerRole'      => null,
        ], $this->pseudo));
    }

    /**
     * The section order the dropdowns render in. Pinned because Model_Scrollery
     * sorts its choices by this array's index, so a reordering silently reshuffles
     * the composer's picker.
     */
    public function testSectionOrderIsStableAndExcludesCustom(): void
    {
        self::assertSame([
            'Ladder Awards',
            'Knighthoods',
            'Masterhoods',
            'Paragons',
            'Noble Titles',
            'Associate Titles',
            'Other',
        ], Award::OptionGroupOrder());
    }

    /**
     * Every label the rule can return must have a home in the render order (or be
     * the standalone 'Custom' bucket) — otherwise GetAwardOptionGroups() would
     * build a bucket it never emits, and Model_Scrollery's sort would read a null
     * index for it.
     */
    public function testEveryLabelTheRuleReturnsHasARenderSlot(): void
    {
        $slots = array_merge(Award::OptionGroupOrder(), ['Custom']);

        $probes = [
            ['KingdomAwardId' => 7067], ['AwardName' => 'Custom Title'], ['IsLadder' => 1],
            ['AwardName' => 'Defender'], ['AwardName' => 'Weaponmaster'], ['Peerage' => 'Knight'],
            ['Peerage' => 'Paragon'], ['Peerage' => 'Master'], ['Peerage' => 'Squire'],
            ['IsTitle' => 1, 'TitleClass' => 30], ['AwardName' => 'Order of the Owl'],
        ];
        foreach ($probes as $probe) {
            self::assertContains($this->label($probe), $slots, json_encode($probe));
        }
    }
}
