<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * printable_award_choices() feeds the create page's award dropdown from
 * Kingdom::GetAwardList(). Its LEFT JOIN from ork_kingdomaward to ork_award misses
 * whenever a kingdomaward's award_id points at nothing — archery's real grants
 * live here on purpose, under base award_id 0 and 94, since no ork_award row exists
 * for the dangling ids at all — leaving BOTH AwardId and OfficerRole NULL on the
 * row. Scroll::OrderForAwardRow() stays open for that shape when the NAME is an
 * archery name (or archery could never print), but a name is a weak key: it cannot
 * tell an actual archery row apart from an officer position that happens to share
 * the word. Model_Award::isDanglingRowAnOfficer() is what closes that.
 *
 * WHAT THE ALLOWLIST CHANGED HERE. A dangling row used to be offered as a custom
 * scroll whatever it was called, which is why the sibling rule had to catch
 * kingdom 28's second "Sheriff" (kingdomaward_id 5028, award_id 163, no ork_award
 * row). Now a dangling row is refused unless its name is an archery name, so the
 * sibling rule's remaining job is exactly the archery names — which is what the
 * cases below exercise.
 *
 * choicesForAwardListForTest() is the per-row body of printable_award_choices(),
 * split out DB-free so this fixture can exercise it directly — the same
 * convention as bulkSqlForTest() and matchingChoiceForTest().
 */
final class ScrolleryChoicesForAwardListTest extends TestCase
{
    private function row(array $o = []): array
    {
        return array_merge([
            'KingdomAwardId'   => 1,
            // Custom Award (94). Under the allowlist a fixture has to be something
            // the composer can actually offer, and the two custom entries plus the
            // ladders with artwork are the whole of that list — Lord (52), which
            // used to stand here, is refused for the policy reason and would prove
            // nothing about the dangling-sibling rule these cases are about.
            'AwardId'          => 94,
            'AwardName'        => 'Custom Award',
            'KingdomAwardName' => 'Custom Award',
            'OfficerRole'      => 'none',
        ], $o);
    }

    /**
     * The exact shape from the review: a dangling row (AwardId/OfficerRole both
     * null) whose name matches a resolved sibling that IS an officer position must
     * be dropped, not offered as a custom scroll.
     */
    public function testADanglingRowWithAnOfficerSiblingIsDropped(): void
    {
        $awards = [
            // Resolved: kingdomaward_id 4935, award_id 70, officer_role='park'.
            $this->row(['KingdomAwardId' => 4935, 'AwardId' => 70, 'KingdomAwardName' => 'Sheriff', 'OfficerRole' => 'park']),
            // Dangling: kingdomaward_id 5028, award_id 163 — no ork_award row.
            $this->row(['KingdomAwardId' => 5028, 'AwardId' => null, 'KingdomAwardName' => 'Sheriff', 'OfficerRole' => null]),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        $this->assertCount(0, array_filter($choices, fn ($c) => $c['KingdomAwardId'] === 5028));
    }

    /**
     * The sharp version of the case above, and the one the sibling rule still exists
     * for: the dangling row is named "Hunter", which is in Scroll::ARCHERY_NAMES, so
     * the allowlist WOULD admit it. Only the officer sibling stops it. If
     * isDanglingRowAnOfficer() were removed, a kingdom's Hunter officer position
     * would start printing the archery certificate.
     */
    public function testADanglingArcheryNamedRowWithAnOfficerSiblingIsDropped(): void
    {
        $awards = [
            $this->row(['KingdomAwardId' => 30, 'AwardId' => 70, 'KingdomAwardName' => 'Hunter', 'OfficerRole' => 'park']),
            $this->row(['KingdomAwardId' => 31, 'AwardId' => null, 'KingdomAwardName' => 'Hunter', 'OfficerRole' => null]),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        $this->assertSame([], array_column($choices, 'KingdomAwardId'));
    }

    /**
     * A dangling ARCHERY row whose same-named sibling is a genuine award
     * (officer_role='none') must stay open.
     *
     * This used to be written with "Warlord" at AwardId 40, then with Lord (52).
     * Both are gone for the same reason: the allowlist refuses a dangling row unless
     * its name is an archery name, so a non-archery fixture is now dropped by the
     * policy rather than surviving the sibling rule this case exists to pin.
     */
    public function testADanglingArcheryRowWithANonOfficerSiblingIsKept(): void
    {
        $awards = [
            $this->row(['KingdomAwardId' => 10, 'AwardId' => 94, 'KingdomAwardName' => 'Order of the Archer']),
            $this->row(['KingdomAwardId' => 11, 'AwardId' => null, 'KingdomAwardName' => 'Order of the Archer', 'OfficerRole' => null]),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        $kept = array_filter($choices, fn ($c) => $c['KingdomAwardId'] === 11);
        $this->assertCount(1, $kept);
        $this->assertSame('hunter', array_values($kept)[0]['Order']);
    }

    /** No archery name anywhere has a resolved officer sibling — must stay open. */
    public function testADanglingArcheryRowWithNoSiblingAtAllIsKept(): void
    {
        $awards = [
            $this->row(['KingdomAwardId' => 20, 'AwardId' => null, 'KingdomAwardName' => 'Order of the Hunter', 'OfficerRole' => null]),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        $this->assertCount(1, $choices);
        $this->assertSame('hunter', $choices[0]['Order']);
    }

    /** A resolved (non-dangling) row is never touched by the sibling map. */
    public function testAResolvedRowIsUnaffectedByAnyOfficerSibling(): void
    {
        $awards = [
            $this->row(['KingdomAwardId' => 1, 'AwardId' => 94, 'KingdomAwardName' => 'Order of the Emerald']),
            $this->row(['KingdomAwardId' => 2, 'AwardId' => 200, 'AwardName' => 'Champion', 'KingdomAwardName' => 'Order of the Emerald', 'OfficerRole' => 'kingdom']),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        // The resolved Custom Award row at KingdomAwardId 1 survives even though a
        // same-named row elsewhere is a real officer position: it is not dangling,
        // so Scroll::OrderForAwardRow() decides it on its own OfficerRole alone.
        $this->assertContains(1, array_column($choices, 'KingdomAwardId'));
    }

    /**
     * Kingdom 21's real data: "Shire Champion" (kingdomaward_id 6437, award_id 0)
     * is the ONLY "Shire Champion" row that kingdom has — no same-kingdom sibling
     * exists to check against — but the name matches
     * Kingdom::awardNameLooksLikeOfficer()'s pattern unambiguously (every OTHER
     * kingdom spelling the same position resolves to award_id 206,
     * officer_role='principality'). A same-kingdom sibling search alone cannot
     * catch this; Model_Award::isDanglingRowAnOfficer()'s pattern fallback must.
     */
    public function testADanglingRowWithNoSiblingAtAllButAMatchingNamePatternIsDropped(): void
    {
        $awards = [
            $this->row(['KingdomAwardId' => 6437, 'AwardId' => null, 'KingdomAwardName' => 'Shire Champion', 'OfficerRole' => null]),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        $this->assertCount(0, $choices);
    }

    /**
     * THE SCOPE DECISION, on the surface it was made about: "remove all awards from
     * the drop-down other than Order of X awards (the ladders). No titles, no
     * masters, etc. Leave Custom Award and Custom Title."
     *
     * A catalogue fixture in the shape Kingdom::GetAwardList() returns, holding one
     * of each thing a kingdom really has. Only the ladders with artwork and the two
     * custom entries come out.
     */
    public function testOnlyLadderOrdersAndTheTwoCustomEntriesSurvive(): void
    {
        $catalogue = [
            $this->row(['KingdomAwardId' => 1, 'AwardId' => 21, 'AwardName' => 'Order of the Rose', 'KingdomAwardName' => 'Order of the Rose', 'IsLadder' => 1]),
            $this->row(['KingdomAwardId' => 2, 'AwardId' => 27, 'AwardName' => 'Order of the Warrior', 'KingdomAwardName' => 'Order of the Warrior', 'IsLadder' => 1]),
            $this->row(['KingdomAwardId' => 3, 'AwardId' => 94, 'AwardName' => 'Custom Award', 'KingdomAwardName' => 'Custom Award']),
            $this->row(['KingdomAwardId' => 4, 'AwardId' => 246, 'AwardName' => 'Custom Title', 'KingdomAwardName' => 'Custom Title', 'IsTitle' => 1]),
            // Everything the decision removes.
            $this->row(['KingdomAwardId' => 5, 'AwardId' => 17, 'AwardName' => 'Knight of the Sword', 'KingdomAwardName' => 'Knight of the Sword', 'Peerage' => 'Knight']),
            $this->row(['KingdomAwardId' => 6, 'AwardId' => 1, 'AwardName' => 'Master Rose', 'KingdomAwardName' => 'Master Rose', 'Peerage' => 'Master']),
            $this->row(['KingdomAwardId' => 7, 'AwardId' => 37, 'AwardName' => 'Paragon Anti-Paladin', 'KingdomAwardName' => 'Paragon Anti-Paladin', 'Peerage' => 'Paragon']),
            $this->row(['KingdomAwardId' => 8, 'AwardId' => 52, 'AwardName' => 'Lord', 'KingdomAwardName' => 'Lord', 'IsTitle' => 1, 'TitleClass' => 30]),
            $this->row(['KingdomAwardId' => 9, 'AwardId' => 53, 'AwardName' => 'Lady', 'KingdomAwardName' => 'Lady', 'IsTitle' => 1, 'TitleClass' => 30]),
            $this->row(['KingdomAwardId' => 10, 'AwardId' => 226, 'AwardName' => 'Master', 'KingdomAwardName' => 'Master']),
            $this->row(['KingdomAwardId' => 11, 'AwardId' => 209, 'AwardName' => 'Esquire', 'KingdomAwardName' => 'Esquire']),
            $this->row(['KingdomAwardId' => 12, 'AwardId' => 36, 'AwardName' => 'Weaponmaster', 'KingdomAwardName' => 'Weaponmaster', 'IsTitle' => 1, 'TitleClass' => 10]),
            $this->row(['KingdomAwardId' => 13, 'AwardId' => 207, 'AwardName' => 'Dragonmaster', 'KingdomAwardName' => 'Dragonmaster', 'IsTitle' => 1]),
            $this->row(['KingdomAwardId' => 14, 'AwardId' => 231, 'AwardName' => 'Warmaster', 'KingdomAwardName' => 'Warmaster']),
            $this->row(['KingdomAwardId' => 15, 'AwardId' => 35, 'AwardName' => 'Defender', 'KingdomAwardName' => 'Defender', 'IsTitle' => 1, 'TitleClass' => 10]),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($catalogue);

        $this->assertSame([1, 2, 3, 4], array_column($choices, 'KingdomAwardId'));
        $this->assertSame(['Ladder Awards', 'Ladder Awards', 'Custom', 'Custom'], array_column($choices, 'Group'));
    }

    /**
     * CRYSTAL GROVES (kingdom 17), the case the maintainer flagged by name.
     *
     * "Master Reeve" is a kingdomaward hung on award 31, Order of the Walker in the
     * Middle — a genuine is_ladder award with 379 grants, and the ONE ladder with no
     * entry in scroll-orders.php. Dropping 31 for want of artwork drops Master Reeve
     * with it, with no name-matching heuristic anywhere: 31 is the only ladder in the
     * whole catalogue carrying off-pattern kingdom names, three of them, 33 grants.
     */
    public function testCrystalGrovesMasterReeveIsGone(): void
    {
        $choices = Model_Scrollery::choicesForAwardListForTest([
            $this->row(['KingdomAwardId' => 2902, 'AwardId' => 94, 'AwardName' => 'Custom Award', 'KingdomAwardName' => 'Custom Award']),
            $this->row(['KingdomAwardId' => 2854, 'AwardId' => 31, 'AwardName' => 'Order of the Walker in the Middle', 'KingdomAwardName' => 'Master Reeve', 'IsLadder' => 1]),
        ]);

        $this->assertNotContains('Master Reeve', array_column($choices, 'Name'));
        $this->assertSame([2902], array_column($choices, 'KingdomAwardId'));
    }

    /**
     * The composer's picker is sectioned like the grant/recommend ones, so every
     * choice carries the section Award::OptionGroupLabelFor() puts it in — the same
     * call those dropdowns make, never a restatement of the rule.
     *
     * Only two sections can appear now. This used to assert on Noble Titles; the
     * allowlist refuses those before they can reach a section at all, so the fixture
     * would have asserted on a row the composer can never show.
     */
    public function testEachChoiceCarriesItsAwardSection(): void
    {
        $choices = Model_Scrollery::choicesForAwardListForTest([
            $this->row(['KingdomAwardId' => 10, 'KingdomAwardName' => 'Order of the Rose', 'AwardId' => 21, 'AwardName' => 'Order of the Rose', 'IsLadder' => 1]),
            $this->row(['KingdomAwardId' => 11, 'KingdomAwardName' => 'Custom Title', 'AwardId' => 246, 'AwardName' => 'Custom Title', 'IsTitle' => 1]),
        ]);

        $sections = array_column($choices, 'Group', 'KingdomAwardId');
        $this->assertSame('Ladder Awards', $sections[10]);
        $this->assertSame('Custom', $sections[11]);
    }

    /**
     * Sorted by SECTION first, then alphabetically inside it — the template emits
     * optgroups by walking the list once, so a name-only sort would interleave the
     * sections and produce a group header per row. 'Custom' sorts last and is
     * rendered as bare options rather than a section.
     */
    public function testChoicesSortBySectionThenName(): void
    {
        $choices = Model_Scrollery::choicesForAwardListForTest([
            // Deliberately reverse-alphabetical to the intended section order, so a
            // surviving name-only sort would put Zeta's ladder after the custom entry.
            $this->row(['KingdomAwardId' => 20, 'KingdomAwardName' => 'Zeta Ladder', 'AwardId' => 27, 'AwardName' => 'Order of the Warrior', 'IsLadder' => 1]),
            $this->row(['KingdomAwardId' => 21, 'KingdomAwardName' => 'Alpha Custom', 'AwardId' => 94, 'AwardName' => 'Custom Award']),
            $this->row(['KingdomAwardId' => 22, 'KingdomAwardName' => 'Alpha Ladder', 'AwardId' => 21, 'AwardName' => 'Order of the Rose', 'IsLadder' => 1]),
        ]);

        $this->assertSame(
            ['Alpha Ladder', 'Zeta Ladder', 'Alpha Custom'],
            array_column($choices, 'Name')
        );
    }
}
