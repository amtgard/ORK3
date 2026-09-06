<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Model_Scrollery::print_decision() replaced a bare bool with a reason, so the
 * render route can tell an officer WHY nothing printed instead of redirecting to
 * the landing page in silence.
 *
 * The authority branches themselves need real authorization rows and are exercised
 * against the live mirror (see the workstream report); what is pinned here is the
 * contract the controller renders against — the reason vocabulary, the shape, and
 * the fail-closed default — plus the two refusals that need no authority at all.
 */
final class ScrolleryPrintDecisionTest extends TestCase
{
    private Model_Scrollery $model;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->model = new Model_Scrollery();
    }

    /** @param array<string, mixed> $d */
    private function assertDecisionShape(array $d): void
    {
        $this->assertArrayHasKey('Allowed', $d);
        $this->assertArrayHasKey('Reason', $d);
        $this->assertArrayHasKey('Message', $d);
        $this->assertIsBool($d['Allowed']);
        $this->assertIsString($d['Reason']);
        $this->assertIsString($d['Message']);
        // A denial always carries something to render. A silent denial is the bug.
        if (!$d['Allowed']) {
            $this->assertNotSame('', $d['Reason']);
            $this->assertNotSame('', $d['Message']);
        }
    }

    /**
     * Fail closed. A missing viewer or a missing award is a refusal, never a
     * fallthrough, and every reason constant must resolve to a real message — a new
     * reason added without one would render an empty denial page.
     */
    public function testEveryRefusalCarriesARenderableMessage(): void
    {
        foreach ([
            Model_Scrollery::DENIED_MISSING,
            Model_Scrollery::DENIED_REVOKED,
            Model_Scrollery::DENIED_UNATTRIBUTED,
            Model_Scrollery::DENIED_NO_ART,
            Model_Scrollery::DENIED_EXCLUDED,
            Model_Scrollery::DENIED_AUTH,
        ] as $reason) {
            $this->assertNotSame('', $reason);
            $this->assertNotSame(Model_Scrollery::PRINT_ALLOWED, $reason);
        }

        $this->assertSame('', Model_Scrollery::PRINT_ALLOWED);
    }

    public function testAZeroOrNegativeIdIsRefusedNotAllowed(): void
    {
        foreach ([[0, 1], [-1, 1], [5, 0], [5, -1]] as [$awardsId, $viewerId]) {
            $d = $this->model->print_decision($awardsId, $viewerId);
            $this->assertDecisionShape($d);
            $this->assertFalse($d['Allowed']);
            $this->assertSame(Model_Scrollery::DENIED_AUTH, $d['Reason']);
        }
    }

    public function testAnAwardThatDoesNotExistSaysSo(): void
    {
        $d = $this->model->print_decision(999999999, 1);

        $this->assertDecisionShape($d);
        $this->assertFalse($d['Allowed']);
        $this->assertSame(Model_Scrollery::DENIED_MISSING, $d['Reason']);
    }

    /**
     * Revocation is checked FIRST, before identity and before the admin branch — a
     * recipient must not be able to reprint an award that was stripped from them,
     * and neither may an ORK administrator. 2,856 rows are revoked; 59 still carry a
     * live mundane_id, which is exactly that hole.
     */
    public function testRevocationOutranksEveryGrantingBranch(): void
    {
        $awardsId = $this->insertGrant(21, 100000057, 100001, revoked: true);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 21 in the seed data.');
        }

        try {
            foreach ([100000057, 1, 2] as $viewerId) {
                $d = $this->model->print_decision($awardsId, $viewerId);
                $this->assertDecisionShape($d);
                $this->assertFalse($d['Allowed'], "viewer {$viewerId} must not print a revoked award");
                $this->assertSame(Model_Scrollery::DENIED_REVOKED, $d['Reason']);
            }

            $this->assertNull(
                $this->model->scroll_for_awards_id($awardsId, 100000057),
                'the render route must build nothing for a revoked award'
            );
        } finally {
            $this->deleteAward($awardsId);
        }
    }

    /**
     * "Nothing to print" and "we deliberately do not print this" are different
     * answers. A knighthood is not missing artwork; it is off the menu.
     */
    public function testAnExcludedClassSaysExcludedNotNoArt(): void
    {
        $awardsId = $this->insertGrant(245, 100000057, 100001);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 245 in the seed data.');
        }

        try {
            $d = $this->model->print_decision($awardsId, 100000057);
            $this->assertDecisionShape($d);
            $this->assertFalse($d['Allowed']);
            $this->assertSame(Model_Scrollery::DENIED_EXCLUDED, $d['Reason']);
        } finally {
            $this->deleteAward($awardsId);
        }
    }

    /**
     * 120,284 rows (30.74% of 391,296) carry neither at_park_id nor at_kingdom_id,
     * so there is no issuing body for the certificate to name and no scope for an
     * officer's authority to attach to. A viewer who is neither the recipient nor an
     * ORK admin gets told that, rather than a generic "not authorised" that sends an
     * officer hunting for a permission they can never be granted.
     */
    public function testAnUnattributedAwardSaysWhyRatherThanJustNo(): void
    {
        $awardsId = $this->insertGrant(21, 100000057, 0);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 21 in the seed data.');
        }

        try {
            // The recipient still prints their own.
            $this->assertTrue($this->model->print_decision($awardsId, 100000057)['Allowed']);

            $d = $this->model->print_decision($awardsId, 100000058);
            $this->assertDecisionShape($d);
            $this->assertFalse($d['Allowed']);
            $this->assertSame(Model_Scrollery::DENIED_UNATTRIBUTED, $d['Reason']);
            $this->assertStringContainsString('where given', $d['Message']);
        } finally {
            $this->deleteAward($awardsId);
        }
    }

    /**
     * An attributed award a stranger holds no authority over is a plain refusal —
     * and the message names the three ways in, so the officer knows what to ask for.
     */
    public function testAStrangerIsRefusedOnAnAttributedAward(): void
    {
        $awardsId = $this->insertGrant(21, 100000057, 100001);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 21 in the seed data.');
        }

        try {
            $this->assertTrue($this->model->print_decision($awardsId, 100000057)['Allowed']);

            $d = $this->model->print_decision($awardsId, 100000058);
            $this->assertDecisionShape($d);
            $this->assertFalse($d['Allowed']);
            $this->assertSame(Model_Scrollery::DENIED_AUTH, $d['Reason']);
        } finally {
            $this->deleteAward($awardsId);
        }
    }

    private function insertGrant(int $awardId, int $mundaneId, int $kingdomId, bool $revoked = false): int
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT kingdomaward_id FROM ' . DB_PREFIX . 'kingdomaward WHERE award_id = ' . $awardId . ' LIMIT 1'
        );
        $rs->Next();
        $kingdomAwardId = (int) ($rs->kingdomaward_id ?? 0);
        if ($kingdomAwardId <= 0) {
            return 0;
        }

        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . "awards
                (kingdomaward_id, mundane_id, rank, date, given_by_id, note,
                 at_park_id, at_kingdom_id, at_event_id, custom_name, award_id,
                 by_whom_id, entered_at, revoked)
             VALUES ({$kingdomAwardId}, {$mundaneId}, 3, '2026-05-01', 1, '',
                 0, {$kingdomId}, 0, '', {$awardId}, 0, NOW(), " . ($revoked ? 1 : 0) . ')'
        );

        return (int) $DB->GetLastInsertId();
    }

    private function deleteAward(int $awardsId): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'awards WHERE awards_id = ' . (int) $awardsId);
    }
}
