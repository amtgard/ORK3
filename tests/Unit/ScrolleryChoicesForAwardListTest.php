<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * printable_award_choices() feeds the create page's award dropdown from
 * Kingdom::GetAwardList(). Its LEFT JOIN from ork_kingdomaward to ork_award misses
 * whenever a kingdomaward's award_id points at nothing — archery's real grants
 * live here on purpose, under base award_id 0/94/131, since no ork_award row
 * exists for those ids at all — leaving BOTH AwardId and OfficerRole NULL on the
 * row. Scroll::OrderForAwardRow() correctly stays open for that shape (or archery
 * could never print), but that also means it cannot tell an actual archery row
 * apart from an unrelated officer position whose base award has simply gone
 * missing (kingdom 28's real data: kingdomaward_id 5028 is a second "Sheriff" row
 * pointing at award_id 163, for which no ork_award row exists — before this fix it
 * dangled exactly like archery and was offered as a printable custom scroll).
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
            'AwardId'          => 1,
            'KingdomAwardName' => 'Master Rose',
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
     * A dangling row whose sibling is a genuine award (officer_role='none') must
     * stay open — Warlord and Defender in the real data.
     */
    public function testADanglingRowWithANonOfficerSiblingIsKept(): void
    {
        $awards = [
            $this->row(['KingdomAwardId' => 10, 'AwardId' => 40, 'KingdomAwardName' => 'Warlord', 'OfficerRole' => 'none']),
            $this->row(['KingdomAwardId' => 11, 'AwardId' => null, 'KingdomAwardName' => 'Warlord', 'OfficerRole' => null]),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        $this->assertCount(1, array_filter($choices, fn ($c) => $c['KingdomAwardId'] === 11));
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
            $this->row(['KingdomAwardId' => 1, 'AwardId' => 1, 'KingdomAwardName' => 'Master Rose', 'OfficerRole' => 'none']),
            $this->row(['KingdomAwardId' => 2, 'AwardId' => 200, 'KingdomAwardName' => 'Master Rose', 'OfficerRole' => 'kingdom']),
        ];

        $choices = Model_Scrollery::choicesForAwardListForTest($awards);

        // The resolved 'Master Rose' at KingdomAwardId 1 survives even though a
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
}
