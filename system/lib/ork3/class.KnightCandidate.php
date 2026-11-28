<?php

/**
 * Circle of Knights Manager, the candidate pipeline half.
 *
 * *** CHUNK 1 STUB. *** The stage constants and the pure canTransition() rule are
 * final; every other method returns ['Status' => 1, 'Error' => 'Not implemented']
 * (onAwardGranted does nothing) until chunk 2 replaces the bodies. Keep the
 * constants and canTransition() intact.
 *
 * Candidacy is one row per (kingdom, player, order) in ork_knight_candidate. The
 * suggested watchlist is computed, never stored. Dismissal is a stage, so a
 * reconcile never re-adds a dismissed player. Candidates are never hard-deleted.
 *
 * Callers reach this through Model_KnightCircle; it must never be added to the
 * class list in orkservice/Json/index.php.
 */
class KnightCandidate
{
    public const STAGES = ['watchlist', 'masterhood', 'discussion', 'voting', 'holding', 'elect', 'knighted', 'dismissed'];

    /** The stages the board shows as lanes: the path, then Holding as the side column. Dismissed hides behind a toggle. */
    public const BOARD_STAGES = ['watchlist', 'masterhood', 'discussion', 'voting', 'elect', 'knighted', 'holding'];

    /** from => allowed to (for a person moving a card). The system may also do anything -> knighted and watchlist -> masterhood. */
    public const TRANSITIONS = [
        'watchlist'  => ['masterhood', 'discussion', 'dismissed'],
        'masterhood' => ['watchlist', 'discussion', 'dismissed'],
        'discussion' => ['voting', 'holding', 'watchlist', 'dismissed'],
        'voting'     => ['elect', 'discussion', 'holding'],
        'holding'    => ['discussion', 'voting', 'dismissed'],
        'elect'      => ['voting', 'discussion', 'holding'],
        'knighted'   => [],
        'dismissed'  => ['watchlist'],
    ];

    /**
     * May a card move $from -> $to? Pure. Knighted is entered by the system only
     * (the knighthood being granted); the system may also promote watchlist ->
     * masterhood when the player earns the Master.
     */
    public static function canTransition(string $from, string $to, bool $system = false): bool
    {
        if (!in_array($from, self::STAGES, true) || !in_array($to, self::STAGES, true) || $from === $to) {
            return false;
        }
        if ($system) {
            if ($to === 'knighted') {
                return true;
            }
            if ($from === 'watchlist' && $to === 'masterhood') {
                return true;
            }
        }
        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public function __construct()
    {
    }

    public function board(int $uid, int $kingdomId): array
    {
        return $this->notImplemented();
    }

    public function suggestions(int $kingdomId, int $minRank = 0): array
    {
        return $this->notImplemented();
    }

    public function reconcile(int $kingdomId): array
    {
        return $this->notImplemented();
    }

    public function addCandidate(int $uid, int $kingdomId, int $mundaneId, int $orderAwardId, string $comment = ''): array
    {
        return $this->notImplemented();
    }

    public function moveCandidate(int $uid, int $candidateId, string $toStage, string $comment = '', ?array $votes = null): array
    {
        return $this->notImplemented();
    }

    public function candidate(int $uid, int $candidateId): array
    {
        return $this->notImplemented();
    }

    public function addNote(int $uid, int $candidateId, string $body): array
    {
        return $this->notImplemented();
    }

    public function deleteNote(int $uid, int $noteId): array
    {
        return $this->notImplemented();
    }

    /** Best-effort hook from Player::AddAward. Never throws, never creates rows. */
    public function onAwardGranted(int $mundaneId, int $awardId, int $awardsId): void
    {
    }

    private function notImplemented(): array
    {
        return ['Status' => 1, 'Error' => 'Not implemented'];
    }
}
