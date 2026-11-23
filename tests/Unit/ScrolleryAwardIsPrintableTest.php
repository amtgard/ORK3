<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Model_Scrollery::award_is_printable() gates the "Print this certificate" link
 * offered immediately after an award is granted — now from
 * Controller_PlayerAjax (controller.PlayerAjax.php:184), which is the live call
 * site. It must never offer a link for an id that does not resolve to a real,
 * printable row: a dead link on the grant page would be worse than no link.
 *
 * It used to ALSO be called from Controller_Award::handle_success(), which set
 * $this->data['PrintAwardsId'] — a key that appeared nowhere else in the repo.
 * That dead assignment has been removed, which as a side effect stopped a
 * load_model('Scrollery') plus a full award lookup running on every award grant
 * in the ORK. Before the single-row branch in Player::AwardsForPlayer(), that
 * lookup materialised 3,076 rows to keep one, at ~124 ms a grant.
 *
 * It now also takes the viewer and runs print_permission(): the officer granting
 * need not hold the park/kingdom the award was given AT, and a link the render
 * route then refuses is exactly the dead link this exists to prevent.
 */
final class ScrolleryAwardIsPrintableTest extends TestCase
{
    /** insertLadderGrant()'s recipient — print_permission()'s recipient branch. */
    private const RECIPIENT = 100000057;

    /** Holds no authority over the seed kingdom (see ScrolleryPrintDecisionTest). */
    private const STRANGER = 100000058;

    private Model_Scrollery $model;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->model = new Model_Scrollery();
    }

    public function testZeroIdIsNeverPrintable(): void
    {
        $this->assertFalse($this->model->award_is_printable(0, self::RECIPIENT));
    }

    public function testNegativeIdIsNeverPrintable(): void
    {
        $this->assertFalse($this->model->award_is_printable(-1, self::RECIPIENT));
    }

    public function testNonexistentIdIsNotPrintable(): void
    {
        $this->assertFalse($this->model->award_is_printable(999999, self::RECIPIENT));
    }

    /**
     * awards_id 1 in the seed data is a grant of award_id 245, "Knight of
     * Battle" — the KNIGHTHOOD of the Order of Battle ladder, not the ladder.
     * It used to be asserted printable here on the mistaken reading that it was
     * a ladder award. Knighthoods are now refused outright, so this is the
     * regression test that it stays refused.
     */
    public function testAKnighthoodGrantIsNotPrintable(): void
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT awards_id FROM ' . DB_PREFIX . 'awards WHERE award_id = 245 LIMIT 1'
        );
        $rs->Next();
        $awardsId = (int) ($rs->awards_id ?? 0);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No award_id 245 grant in seed data.');
        }

        $this->assertFalse(
            $this->model->award_is_printable($awardsId, self::RECIPIENT),
            'award_id 245 is Knight of Battle, a knighthood — Scrollery must not offer it.'
        );
    }

    /**
     * A genuine ladder grant (award_id 21, Order of the Rose) resolves an order
     * via Scroll::OrderForAwardId() and must be printable. Written rather than
     * found: the rendered test schema seeds exactly one ork_awards row, and it is
     * the knighthood above.
     */
    public function testARealLadderAwardGrantIsPrintable(): void
    {
        $awardsId = $this->insertLadderGrant(21);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 21 in the seed data.');
        }

        try {
            $this->assertTrue($this->model->award_is_printable($awardsId, self::RECIPIENT));
        } finally {
            $this->deleteAward($awardsId);
        }
    }

    /**
     * The revoked gate, on the same row that is printable when it is not revoked —
     * so this cannot pass for the wrong reason. ork_awards.revoked is NOT NULL
     * DEFAULT 0; 2,856 production rows carry a 1, and 59 of them still hold a live
     * mundane_id, which is the "reprint your own stripped award" hole.
     */
    public function testARevokedGrantIsNotPrintable(): void
    {
        $awardsId = $this->insertLadderGrant(21);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 21 in the seed data.');
        }

        try {
            $this->assertTrue($this->model->award_is_printable($awardsId, self::RECIPIENT), 'precondition');

            global $DB;
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'awards SET revoked = 1 WHERE awards_id = ' . $awardsId
            );

            $this->assertFalse(
                $this->model->award_is_printable($awardsId, self::RECIPIENT),
                'A revoked award must never be printable.'
            );
        } finally {
            $this->deleteAward($awardsId);
        }
    }

    /**
     * A printable row is still not printable for a viewer the render route would
     * refuse — the grant-modal link must not open onto a denial.
     */
    public function testAPrintableGrantIsNotPrintableForAViewerWithoutAuthority(): void
    {
        $awardsId = $this->insertLadderGrant(21);
        if ($awardsId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 21 in the seed data.');
        }

        try {
            $this->assertTrue($this->model->award_is_printable($awardsId, self::RECIPIENT), 'precondition');
            $this->assertFalse($this->model->award_is_printable($awardsId, self::STRANGER));
            $this->assertFalse($this->model->award_is_printable($awardsId, 0));
        } finally {
            $this->deleteAward($awardsId);
        }
    }

    /** @return int the new awards_id, or 0 when the catalog row is missing */
    private function insertLadderGrant(int $awardId): int
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT kingdomaward_id, kingdom_id FROM ' . DB_PREFIX . 'kingdomaward
              WHERE award_id = ' . (int) $awardId . ' LIMIT 1'
        );
        $rs->Next();
        $kingdomAwardId = (int) ($rs->kingdomaward_id ?? 0);
        $kingdomId      = (int) ($rs->kingdom_id ?? 0);
        if ($kingdomAwardId <= 0) {
            return 0;
        }

        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . "awards
                (kingdomaward_id, mundane_id, rank, date, given_by_id, note,
                 at_park_id, at_kingdom_id, at_event_id, custom_name, award_id,
                 by_whom_id, entered_at, revoked)
             VALUES ({$kingdomAwardId}, " . self::RECIPIENT . ", 3, '2026-05-01', 1, '',
                 0, {$kingdomId}, 0, '', " . (int) $awardId . ", 0, NOW(), 0)"
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
