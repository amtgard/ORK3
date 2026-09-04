<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Model_Scrollery::award_is_printable() gates the "Print this certificate" link
 * on the award-grant success path (Controller_Award::handle_success()). It must
 * never offer a link for an id that does not resolve to a real, printable row —
 * a dead link on the grant page would be worse than no link at all.
 */
final class ScrolleryAwardIsPrintableTest extends TestCase
{
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
        $this->assertFalse($this->model->award_is_printable(0));
    }

    public function testNegativeIdIsNeverPrintable(): void
    {
        $this->assertFalse($this->model->award_is_printable(-1));
    }

    public function testNonexistentIdIsNotPrintable(): void
    {
        $this->assertFalse($this->model->award_is_printable(999999));
    }

    /**
     * awards_id 1 in the seed data is a grant of award_id 245 ("Knight of
     * Battle"), a ladder award — ladder awards always resolve to an order via
     * Scroll::OrderForAwardId(), so this row must be printable.
     */
    public function testARealLadderAwardGrantIsPrintable(): void
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

        $this->assertTrue($this->model->award_is_printable($awardsId));
    }
}
