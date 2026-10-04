<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * C-26: QualTestAjax/getreports must return reporters alongside counts (UI renderReporters).
 */
final class QualTestGetReportsTest extends TestCase
{
    private AttendanceFixture $fixture;

    private int $questionId = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AttendanceFixture::create();
    }

    protected function tearDown(): void
    {
        if ($this->questionId > 0) {
            global $DB;
            $DB->Clear();
            $DB->Execute(
                'DELETE FROM ' . DB_PREFIX . 'qual_report WHERE qual_question_id = ' . $this->questionId
            );
            $DB->Clear();
            $DB->Execute(
                'DELETE FROM ' . DB_PREFIX . 'qual_question WHERE qual_question_id = ' . $this->questionId
            );
        }
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetReportsPayloadIncludesReportersForFlaggedQuestion(): void
    {
        require_once DIR_UI . 'model/model.QualTest.php';
        $model = new Model_QualTest();
        $domain = new QualTest();
        $player = $this->createReporterAndQuestion();

        $this->assertTrue($domain->reportQuestion($this->questionId, (int) $player['mundane_id'], 'wording'));

        $counts = $model->report_counts($this->questionId);
        $reporters = $model->report_details($this->questionId);

        // Same keys as Controller_QualTestAjax::getreports jsonOut
        $payload = ['status' => 0, 'counts' => $counts, 'reporters' => $reporters];

        $this->assertSame(0, $payload['status']);
        $this->assertSame(1, $payload['counts']['total']);
        $this->assertCount(1, $payload['reporters']);
        $row = $payload['reporters'][0];
        $this->assertSame((int) $player['mundane_id'], $row['PlayerId']);
        $this->assertSame('wording', $row['Reason']);
        $this->assertArrayHasKey('Persona', $row);
        $this->assertArrayHasKey('ReportId', $row);
        $this->assertArrayHasKey('CreatedAt', $row);
    }

    public function testOtherReportKeepsTheReportersExplanationForTheTestMaker(): void
    {
        require_once DIR_UI . 'model/model.QualTest.php';
        $model = new Model_QualTest();
        $player = $this->createReporterAndQuestion();
        $comment = "The diagram doesn't match rule 4.2 \\ see p.12";

        $this->assertTrue($model->report_question($this->questionId, (int) $player['mundane_id'], 'other', "  {$comment}  "));

        $reporters = $model->report_details($this->questionId);
        $this->assertCount(1, $reporters);
        $this->assertSame('other', $reporters[0]['Reason']);
        $this->assertSame($comment, $reporters[0]['Comment']);
    }

    public function testOtherReportWithoutAnExplanationIsRefused(): void
    {
        require_once DIR_UI . 'model/model.QualTest.php';
        $model = new Model_QualTest();
        $player = $this->createReporterAndQuestion();

        $this->assertFalse($model->report_question($this->questionId, (int) $player['mundane_id'], 'other', '   '));
        $this->assertSame(0, $model->report_counts($this->questionId)['total']);
    }

    public function testOverlongExplanationIsCutToTheColumnLength(): void
    {
        require_once DIR_UI . 'model/model.QualTest.php';
        $model = new Model_QualTest();
        $player = $this->createReporterAndQuestion();

        $this->assertTrue($model->report_question($this->questionId, (int) $player['mundane_id'], 'other', str_repeat('é', 600)));

        $this->assertSame(str_repeat('é', 500), $model->report_details($this->questionId)[0]['Comment']);
    }

    public function testLineBreaksPostedAsCrlfCountOnceTowardTheLimit(): void
    {
        require_once DIR_UI . 'model/model.QualTest.php';
        $model = new Model_QualTest();
        $player = $this->createReporterAndQuestion();
        $lines = array_fill(0, 5, str_repeat('a', 98));

        $this->assertTrue($model->report_question($this->questionId, (int) $player['mundane_id'], 'other', implode("\r\n", $lines) . "\r\nend"));

        $this->assertSame(implode("\n", $lines) . "\nend", $model->report_details($this->questionId)[0]['Comment']);
    }

    /**
     * @return array{mundane_id: int, park_id: int, kingdom_id: int}
     */
    private function createReporterAndQuestion(): array
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $player = $this->fixture->createPlayer($parkId, 'qt-rep');

        global $DB;
        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'qual_question
             (kingdom_id, test_type, question_text, answer_mode, status, created_by)
             VALUES (' . (int) $kingdomId . ', \'reeve\', \'C26 test question\', \'single\', \'active\', '
            . (int) $player['mundane_id'] . ')'
        );
        $DB->Clear();
        $rs = $DB->DataSet('SELECT LAST_INSERT_ID() AS id');
        $this->assertTrue($rs && $rs->Next());
        $this->questionId = (int) $rs->id;

        return $player;
    }
}
