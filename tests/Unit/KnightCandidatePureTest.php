<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of KnightCandidate: the stage graph, the system-only moves,
 * vote-tally normalisation and text clipping. No database.
 */
final class KnightCandidatePureTest extends TestCase
{
    public function testEveryStageHasATransitionRowWithValidTargets(): void
    {
        $this->assertEqualsCanonicalizing(KnightCandidate::STAGES, array_keys(KnightCandidate::TRANSITIONS));
        foreach (KnightCandidate::TRANSITIONS as $from => $tos) {
            foreach ($tos as $to) {
                $this->assertContains($to, KnightCandidate::STAGES);
                $this->assertNotSame($from, $to);
            }
        }
    }

    public function testBoardStagesAreStagesAndExcludeDismissed(): void
    {
        foreach (KnightCandidate::BOARD_STAGES as $s) {
            $this->assertContains($s, KnightCandidate::STAGES);
        }
        $this->assertNotContains('dismissed', KnightCandidate::BOARD_STAGES);
    }

    public function testPeopleMayWalkThePathAndTheSideColumn(): void
    {
        $this->assertTrue(KnightCandidate::canTransition('watchlist', 'discussion'));
        $this->assertTrue(KnightCandidate::canTransition('discussion', 'voting'));
        $this->assertTrue(KnightCandidate::canTransition('voting', 'elect'));
        $this->assertTrue(KnightCandidate::canTransition('voting', 'holding'));
        $this->assertTrue(KnightCandidate::canTransition('holding', 'discussion'));
        $this->assertTrue(KnightCandidate::canTransition('dismissed', 'watchlist'));
    }

    public function testPeopleCannotSkipOrKnight(): void
    {
        $this->assertFalse(KnightCandidate::canTransition('watchlist', 'voting'));
        $this->assertFalse(KnightCandidate::canTransition('discussion', 'elect'));
        $this->assertFalse(KnightCandidate::canTransition('elect', 'knighted'));
        $this->assertFalse(KnightCandidate::canTransition('voting', 'dismissed'));
        $this->assertFalse(KnightCandidate::canTransition('knighted', 'watchlist'));
        $this->assertFalse(KnightCandidate::canTransition('dismissed', 'discussion'));
    }

    public function testNoSelfOrUnknownTransitions(): void
    {
        $this->assertFalse(KnightCandidate::canTransition('voting', 'voting'));
        $this->assertFalse(KnightCandidate::canTransition('voting', 'nonsense'));
        $this->assertFalse(KnightCandidate::canTransition('nonsense', 'voting'));
        $this->assertFalse(KnightCandidate::canTransition('knighted', 'knighted', true));
    }

    public function testOnlyTheSystemEntersKnightedFromAnywhere(): void
    {
        foreach (KnightCandidate::STAGES as $from) {
            if ($from === 'knighted') {
                continue;
            }
            $this->assertFalse(KnightCandidate::canTransition($from, 'knighted', false), $from);
            $this->assertTrue(KnightCandidate::canTransition($from, 'knighted', true), $from);
        }
        $this->assertFalse(KnightCandidate::canTransition('knighted', 'watchlist', true), 'the system never moves a card backwards');
    }

    public function testSystemPromotesWatchlistToMasterhoodOnly(): void
    {
        $this->assertTrue(KnightCandidate::canTransition('watchlist', 'masterhood', true));
        $this->assertTrue(KnightCandidate::canTransition('watchlist', 'masterhood', false), 'a person may move it by hand too');
        $this->assertFalse(KnightCandidate::canTransition('discussion', 'masterhood', true));
        $this->assertFalse(KnightCandidate::canTransition('dismissed', 'masterhood', true));
    }

    public function testNormalizeVotesAcceptsNoTally(): void
    {
        $this->assertSame(['ok' => true, 'votes' => null, 'error' => ''], KnightCandidate::normalizeVotes(null));
        $this->assertSame(['ok' => true, 'votes' => null, 'error' => ''], KnightCandidate::normalizeVotes([]));
    }

    public function testNormalizeVotesReadsEitherKeyStyleAndDefaultsToZero(): void
    {
        $a = KnightCandidate::normalizeVotes(['For' => '7', 'Against' => 2]);
        $this->assertTrue($a['ok']);
        $this->assertSame(['For' => 7, 'Against' => 2, 'Abstain' => 0], $a['votes']);

        $b = KnightCandidate::normalizeVotes(['votes_for' => 5, 'votes_against' => '0', 'votes_abstain' => 1]);
        $this->assertSame(['For' => 5, 'Against' => 0, 'Abstain' => 1], $b['votes']);

        $c = KnightCandidate::normalizeVotes(['for' => '', 'abstain' => 3]);
        $this->assertSame(['For' => 0, 'Against' => 0, 'Abstain' => 3], $c['votes']);
    }

    #[DataProvider('badVotes')]
    public function testNormalizeVotesRejectsBadNumbers(array $votes): void
    {
        $r = KnightCandidate::normalizeVotes($votes);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['votes']);
        $this->assertNotSame('', $r['error']);
    }

    public static function badVotes(): array
    {
        return [
            'negative' => [['For' => -1]],
            'fraction' => [['For' => 2.5]],
            'text'     => [['Against' => 'lots']],
            'huge'     => [['Abstain' => 10000]],
            'bool'     => [['For' => true]],
        ];
    }

    public function testClipTextTrimsAndCaps(): void
    {
        $this->assertSame('hi', KnightCandidate::clipText("  hi \n", 10));
        $this->assertSame('abc', KnightCandidate::clipText('abcdef', 3));
        $this->assertSame('', KnightCandidate::clipText('   ', 5));
        $this->assertSame(500, strlen(KnightCandidate::clipText(str_repeat('x', 900), 500)));
    }

    public function testAddStagesAreAllValidStagesAndSourcesAreKnown(): void
    {
        foreach (KnightCandidate::ADD_STAGES as $s) {
            $this->assertContains($s, KnightCandidate::STAGES);
        }
        $this->assertSame(['manual', 'suggested', 'master_auto'], KnightCandidate::SOURCES);
    }
}
