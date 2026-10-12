<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Court Planner notes: non-award line items ("autocrat announcements", "officer
 * changeover") that share the court's running order with its awards but live in
 * their own table, so nothing in the grant pipeline can ever see one.
 */
final class CourtNoteTest extends TestCase
{
    private CourtFixture $fixture;
    private Court $court;
    private int $kid;
    private int $courtId;
    private int $playerId;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fixture  = CourtFixture::create();
        $this->court    = new Court();
        $this->kid      = $this->fixture->firstKingdomId();
        $this->courtId  = $this->fixture->createCourt(['kingdom_id' => $this->kid, 'status' => 'draft']);
        $this->playerId = $this->fixture->createPlayer('note', $this->kid)['mundane_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testAddNoteStoresItsTitleAndDetails(): void
    {
        $note = $this->court->addNote($this->courtId, 'Officer Changeover', "Outgoing sheriff\nIncoming sheriff", 'bottom', 7);

        $this->assertIsArray($note);
        $row = $this->fixture->fetchNote($note['CourtNoteId']);
        $this->assertSame('Officer Changeover', $row['title']);
        $this->assertSame("Outgoing sheriff\nIncoming sheriff", $row['details']);
        $this->assertSame($this->courtId, (int)$row['court_id']);
        $this->assertSame(7, (int)$row['created_by']);
    }

    public function testAddNoteToBottomLandsAfterEveryExistingLine(): void
    {
        $a1 = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 10]);
        $a2 = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 20, 'rank' => 2]);
        $n1 = $this->court->addNote($this->courtId, 'First', '', 'bottom', 0)['CourtNoteId'];
        $n2 = $this->court->addNote($this->courtId, 'Second', '', 'bottom', 0)['CourtNoteId'];

        $this->assertSame(["a:$a1", "a:$a2", "n:$n1", "n:$n2"], $this->fixture->lineOrder($this->courtId));
    }

    public function testAddNoteToTopLandsBeforeEveryExistingLine(): void
    {
        $a1 = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 10]);
        $n1 = $this->court->addNote($this->courtId, 'First', '', 'top', 0)['CourtNoteId'];
        $n2 = $this->court->addNote($this->courtId, 'Second', '', 'top', 0)['CourtNoteId'];

        $this->assertSame(["n:$n2", "n:$n1", "a:$a1"], $this->fixture->lineOrder($this->courtId));
    }

    public function testAddNoteRefusesABlankTitle(): void
    {
        $this->assertFalse($this->court->addNote($this->courtId, "  \t ", 'details only', 'bottom', 0));
        $this->assertSame([], $this->fixture->lineOrder($this->courtId));
    }

    public function testGetCourtNotesReturnsOnlyThisCourtsNotesInRunningOrder(): void
    {
        $other = $this->fixture->createCourt(['kingdom_id' => $this->kid, 'status' => 'draft']);
        $this->court->addNote($other, 'Elsewhere', '', 'bottom', 0);
        $this->court->addNote($this->courtId, 'Closing', 'Thank the autocrat', 'bottom', 0);
        $this->court->addNote($this->courtId, 'Opening', '', 'top', 0);

        $notes = $this->court->getCourtNotes($this->courtId);

        $this->assertSame(['Opening', 'Closing'], array_column($notes, 'Title'));
        $this->assertSame('Thank the autocrat', $notes[1]['Details']);
        $this->assertLessThan($notes[1]['SortOrder'], $notes[0]['SortOrder']);
    }

    public function testReorderPlacesAwardsAndNotesInOneSharedOrder(): void
    {
        $a1 = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 10]);
        $a2 = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 20, 'rank' => 2]);
        $n1 = $this->court->addNote($this->courtId, 'Announcements', '', 'bottom', 0)['CourtNoteId'];

        $this->court->reorderAwards($this->courtId, [$a2, 'n' . $n1, $a1]);

        $this->assertSame(["a:$a2", "n:$n1", "a:$a1"], $this->fixture->lineOrder($this->courtId));
    }

    public function testReorderNeverMovesANoteOnAnotherCourt(): void
    {
        $other   = $this->fixture->createCourt(['kingdom_id' => $this->kid, 'status' => 'draft']);
        $foreign = $this->court->addNote($other, 'Not yours', '', 'bottom', 0);

        $this->court->reorderAwards($this->courtId, ['n' . $foreign['CourtNoteId']]);

        $this->assertSame(
            $foreign['SortOrder'],
            (int)$this->fixture->fetchNote($foreign['CourtNoteId'])['sort_order']
        );
    }

    public function testNewAwardLandsAfterATrailingNote(): void
    {
        $ka = $this->fixture->firstKingdomAwardId($this->kid);
        $a1 = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 10]);
        $n1 = $this->court->addNote($this->courtId, 'Closing remarks', '', 'bottom', 0)['CourtNoteId'];

        $added = $this->court->addAward(
            $this->courtId,
            $this->kid,
            $this->playerId,
            (int)$ka['kingdomaward_id'],
            3,
            0,
            0,
            '',
            '',
            false
        );

        $this->assertIsArray($added);
        $this->assertSame(
            ["a:$a1", "n:$n1", 'a:' . $added['CourtAwardId']],
            $this->fixture->lineOrder($this->courtId)
        );
    }

    public function testUpdateNoteRewritesTitleAndDetails(): void
    {
        $id = $this->court->addNote($this->courtId, 'Anouncements', 'old', 'bottom', 0)['CourtNoteId'];

        $this->assertTrue($this->court->updateNote($id, 'Announcements', 'new'));

        $row = $this->fixture->fetchNote($id);
        $this->assertSame('Announcements', $row['title']);
        $this->assertSame('new', $row['details']);
    }

    public function testUpdateNoteRefusesABlankTitleAndKeepsTheOldOne(): void
    {
        $id = $this->court->addNote($this->courtId, 'Announcements', '', 'bottom', 0)['CourtNoteId'];

        $this->assertFalse($this->court->updateNote($id, '   ', 'x'));
        $this->assertSame('Announcements', $this->fixture->fetchNote($id)['title']);
    }

    public function testRemoveNoteDeletesOnlyThatNote(): void
    {
        $keep = $this->court->addNote($this->courtId, 'Keep', '', 'bottom', 0)['CourtNoteId'];
        $drop = $this->court->addNote($this->courtId, 'Drop', '', 'bottom', 0)['CourtNoteId'];

        $this->court->removeNote($drop);

        $this->assertSame(["n:$keep"], $this->fixture->lineOrder($this->courtId));
    }

    public function testNoteCourtIdResolvesTheOwningCourtForAuthorization(): void
    {
        $id = $this->court->addNote($this->courtId, 'Announcements', '', 'bottom', 0)['CourtNoteId'];

        $this->assertSame($this->courtId, $this->court->getCourtNoteCourtId($id));
        $this->assertSame(0, $this->court->getCourtNoteCourtId(999999999));
    }

    /**
     * The heartbeat only re-sends the planner payload when `version` moves, so a
     * note another reeve adds, edits, moves or removes must move it.
     */
    public function testCourtStateVersionMovesOnEveryNoteChange(): void
    {
        $a1 = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 10]);
        $seen = [$this->court->getCourtState($this->courtId)['version']];

        $id = $this->court->addNote($this->courtId, 'Announcements', '', 'bottom', 0)['CourtNoteId'];
        $seen[] = $this->court->getCourtState($this->courtId)['version'];

        $this->court->updateNote($id, 'Announcements', 'Feast is at six');
        $seen[] = $this->court->getCourtState($this->courtId)['version'];

        $this->court->reorderAwards($this->courtId, ['n' . $id, $a1]);
        $seen[] = $this->court->getCourtState($this->courtId)['version'];

        $this->court->removeNote($id);
        $seen[] = $this->court->getCourtState($this->courtId)['version'];

        $this->assertCount(4, array_unique(array_slice($seen, 0, 4)), 'add, edit and move must each change the version');
        $this->assertNotSame($seen[3], $seen[4], 'removing the note must change the version');
    }

    /**
     * A note is not an award: it must not be staged by "Record grants", must not
     * appear among the rows Finalize commits, and must not trip the printed-packet
     * drift warning, which exists because AWARD numbering changed.
     */
    public function testANoteIsInvisibleToTheGrantPipelineAndThePrintDriftCheck(): void
    {
        $giver = $this->fixture->createPlayer('giver', $this->kid)['mundane_id'];
        $a1    = $this->fixture->createAward($this->courtId, $this->playerId, ['sort_order' => 10]);
        $this->court->markCourtPrinted($this->courtId);

        $this->court->addNote($this->courtId, 'Announcements', '', 'top', 0);

        $this->assertFalse($this->court->courtChangedSincePrint($this->courtId));
        $this->assertSame(1, $this->court->bulkStagePlanned($this->courtId, $giver));
        $this->assertSame([$a1], array_column($this->court->getStagedAwards($this->courtId), 'CourtAwardId'));
    }
}
