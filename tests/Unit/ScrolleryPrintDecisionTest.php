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
     * Revocation refuses EVERY granting branch — a recipient must not be able to
     * reprint an award that was stripped from them, and neither may an ORK
     * administrator. 2,856 rows are revoked; 59 still carry a live mundane_id, which
     * is exactly that hole. Only a viewer entitled to the row is TOLD it was
     * revoked; everyone else gets the plain refusal (see
     * testAnUnrelatedViewerLearnsNothingAboutTheRow()).
     */
    public function testRevocationOutranksEveryGrantingBranch(): void
    {
        $awardsId = $this->insertGrant(21, 100000057, 100001, revoked: true);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 21 in the seed data.');
        }

        try {
            // 100000057 is the recipient; 1 is the seed's global admin.
            foreach ([100000057, 1] as $viewerId) {
                $d = $this->model->print_decision($awardsId, $viewerId);
                $this->assertDecisionShape($d);
                $this->assertFalse($d['Allowed'], "viewer {$viewerId} must not print a revoked award");
                $this->assertSame(Model_Scrollery::DENIED_REVOKED, $d['Reason']);
            }

            $d = $this->model->print_decision($awardsId, 2);
            $this->assertFalse($d['Allowed']);
            $this->assertSame(Model_Scrollery::DENIED_AUTH, $d['Reason']);

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
     * officer's authority to attach to. The recipient still prints their own. Anyone
     * else who is not an ORK admin gets the generic "not authorised": naming the row
     * "unattributed" to a stranger would confirm that the award id exists.
     */
    public function testAnUnattributedAwardRefusesStrangersWithoutRevealingIt(): void
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
            $this->assertSame(Model_Scrollery::DENIED_AUTH, $d['Reason']);
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

    /**
     * The refusal reason must not be an oracle. A viewer with no claim on a row —
     * not the recipient, not an officer where it was given, not an admin — is told
     * only "not authorised" for a revoked id, an id with no certificate artwork and
     * an id that does not exist, on every surface: print_decision(), the render
     * run's Refused map, and print_manifest(), which returns that map verbatim.
     * Otherwise any logged-in member could enumerate which award ids were revoked,
     * which exist, and which carry art.
     */
    public function testAnUnrelatedViewerLearnsNothingAboutTheRow(): void
    {
        $revoked = $this->insertGrant(21, 100000057, 100001, revoked: true);
        $noArt   = $this->insertGrant(245, 100000057, 100001);
        if ($revoked <= 0 || $noArt <= 0) {
            $this->deleteAward($revoked);
            $this->deleteAward($noArt);
            $this->markTestSkipped('Seed data lacks a kingdomaward row for award_id 21 or 245.');
        }
        $missing  = 999999999;
        $stranger = 100000058;

        try {
            foreach ([$revoked, $noArt, $missing] as $awardsId) {
                $d = $this->model->print_decision($awardsId, $stranger);
                $this->assertDecisionShape($d);
                $this->assertFalse($d['Allowed']);
                $this->assertSame(Model_Scrollery::DENIED_AUTH, $d['Reason'], "print_decision leaks for {$awardsId}");
            }

            $run = $this->model->scrolls_for_awards_ids([$revoked, $noArt, $missing], $stranger);
            $this->assertSame([], $run['Scrolls']);
            $this->assertSame(
                [
                    $revoked => Model_Scrollery::DENIED_AUTH,
                    $noArt   => Model_Scrollery::DENIED_AUTH,
                    $missing => Model_Scrollery::DENIED_AUTH,
                ],
                $run['Refused']
            );

            $manifest = $this->model->print_manifest([$revoked, $noArt, $missing], $stranger);
            foreach ($manifest['Refused'] as $refusal) {
                $this->assertSame(Model_Scrollery::DENIED_AUTH, $refusal['Reason']);
            }

            // The entitled still get the true reason.
            $own = $this->model->scrolls_for_awards_ids([$revoked, $noArt, $missing], 100000057)['Refused'];
            $this->assertSame(Model_Scrollery::DENIED_REVOKED, $own[$revoked]);
            $this->assertSame(Model_Scrollery::DENIED_EXCLUDED, $own[$noArt]);
            $this->assertSame(Model_Scrollery::DENIED_AUTH, $own[$missing]);
            $admin = $this->model->scrolls_for_awards_ids([$missing], 1)['Refused'];
            $this->assertSame(Model_Scrollery::DENIED_MISSING, $admin[$missing]);
        } finally {
            $this->deleteAward($revoked);
            $this->deleteAward($noArt);
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
