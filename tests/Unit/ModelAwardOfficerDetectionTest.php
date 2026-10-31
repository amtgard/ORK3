<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Model_Award::officerNamesFromForTest() and isDanglingRowAnOfficer() are the
 * shared "is this dangling award row actually an officer position" predicate
 * used by Model_Scrollery (create dropdown, bulk print, reprint list) AND
 * Model_Player (the profile Awards tab's Print button) — moved here, out of
 * Model_Scrollery, specifically so neither feature model has to reach into the
 * other's to get it.
 *
 * Two signals feed isDanglingRowAnOfficer(), and each catches a real case the
 * other misses:
 *  - a resolved SIBLING of the same name in the same catalog (kingdom 28's
 *    second "Sheriff" kingdomaward, pointing at a since-orphaned award_id);
 *  - Kingdom::awardNameLooksLikeOfficer()'s body-prefix + role-suffix pattern,
 *    for a name with NO sibling anywhere in its own kingdom at all (kingdom 21's
 *    "Shire Champion" — the only "Shire Champion" row that kingdom has).
 */
final class ModelAwardOfficerDetectionTest extends TestCase
{
    private function row(array $o = []): array
    {
        return array_merge([
            'KingdomAwardName' => 'Master Rose',
            'OfficerRole'      => 'none',
        ], $o);
    }

    public function testOfficerNamesFromForTestIgnoresRowsWithNoOfficerRoleKeyAtAll(): void
    {
        $names = Model_Award::officerNamesFromForTest([
            ['KingdomAwardName' => 'Mystery Row'],
        ]);

        $this->assertSame([], $names);
    }

    public function testOfficerNamesFromForTestIgnoresNoneAndBlankRoles(): void
    {
        $names = Model_Award::officerNamesFromForTest([
            $this->row(['KingdomAwardName' => 'Warlord', 'OfficerRole' => 'none']),
            $this->row(['KingdomAwardName' => 'Something Blank', 'OfficerRole' => '']),
        ]);

        $this->assertSame([], $names);
    }

    public function testOfficerNamesFromForTestIsCaseAndSpaceInsensitive(): void
    {
        $names = Model_Award::officerNamesFromForTest([
            $this->row(['KingdomAwardName' => '  SHERIFF  ', 'OfficerRole' => 'park']),
        ]);

        $this->assertArrayHasKey('sheriff', $names);
    }

    /** The sibling signal: a resolved same-name row in the same catalog. */
    public function testIsDanglingRowAnOfficerMatchesByResolvedSibling(): void
    {
        $officerNames = Model_Award::officerNamesFromForTest([
            $this->row(['KingdomAwardName' => 'Sheriff', 'OfficerRole' => 'park']),
        ]);

        $this->assertTrue(Model_Award::isDanglingRowAnOfficer('Sheriff', $officerNames));
        $this->assertTrue(Model_Award::isDanglingRowAnOfficer('  sheriff  ', $officerNames));
    }

    /**
     * The pattern signal: kingdom 21's "Shire Champion" has no sibling of its own
     * kingdom to check, but the name unambiguously matches
     * Kingdom::awardNameLooksLikeOfficer()'s pattern.
     */
    public function testIsDanglingRowAnOfficerMatchesByNamePatternWithNoSiblingAtAll(): void
    {
        $this->assertTrue(Model_Award::isDanglingRowAnOfficer('Shire Champion', []));
        $this->assertTrue(Model_Award::isDanglingRowAnOfficer('Baronial Seneschal', []));
        $this->assertTrue(Model_Award::isDanglingRowAnOfficer('Kingdom Monarch', []));
    }

    /**
     * Neither signal fires for a genuine award/title with no sibling and no
     * pattern match: Warlord, Defender, and every archery name.
     */
    public function testIsDanglingRowAnOfficerStaysFalseWithNoSiblingAndNoPatternMatch(): void
    {
        $this->assertFalse(Model_Award::isDanglingRowAnOfficer('Warlord', []));
        $this->assertFalse(Model_Award::isDanglingRowAnOfficer('Defender', []));
        $this->assertFalse(Model_Award::isDanglingRowAnOfficer('Order of the Hunter', []));
        $this->assertFalse(Model_Award::isDanglingRowAnOfficer('', []));
    }

    /** Bare "Sheriff"/"Defender" match no pattern — the sibling signal is required for them. */
    public function testTheNamePatternAloneMissesBareOfficerNames(): void
    {
        $this->assertFalse(Model_Award::isDanglingRowAnOfficer('Sheriff', []));
        $this->assertFalse(Model_Award::isDanglingRowAnOfficer('Defender', []));
    }
}
