<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The single-row branch in Player::AwardsForPlayer().
 *
 * The method used to build an UNPARENTHESISED
 * `awards.mundane_id = '0' or awards.awards_id = 'N'` whenever a caller asked for
 * one award row by id. 3,075 ork_awards rows carry mundane_id = 0, so that OR made
 * a 9-table SELECT DISTINCT return 3,076 rows, sort them on a 6-term ORDER BY and
 * hydrate 3,076 PHP arrays to keep exactly one.
 *
 * Measured on the dev mirror (MariaDB SHOW PROFILES, same joins and ORDER BY):
 *   OR form                    124.40 ms
 *   awards.awards_id = N alone   0.25 ms
 *
 * That cost was not Scrollery's alone: it ran on the award-grant success path for
 * every award granted in the ORK, and Scrollery/render loops it up to
 * Scroll::MAX_PRINT_RUN times per bulk run.
 */
final class PlayerAwardsForPlayerWhereTest extends TestCase
{
    public function testASingleAwardLookupEmitsOnlyTheAwardsIdPredicate(): void
    {
        $where = Player::awardsForPlayerWhereForTest(0, 431452);

        $this->assertSame('awards.awards_id = 431452', $where);
        // The whole point: no mundane_id = 0 clause, which is what dragged in 3,075
        // unrelated rows.
        $this->assertStringNotContainsString('mundane_id', $where);
        $this->assertStringNotContainsString(' or ', $where);
    }

    public function testAWholePlayerLookupIsUnchanged(): void
    {
        $this->assertSame('awards.mundane_id = 11956', Player::awardsForPlayerWhereForTest(11956, 0));
        $this->assertSame('awards.mundane_id = 11956', Player::awardsForPlayerWhereForTest(11956, null));
    }

    /**
     * No caller in the tree passes a real MundaneId AND an AwardsId together — the
     * SOAP contract does not even declare AwardsId (see
     * orkservice/Player/PlayerService.definitions.php) — so this branch is dead
     * today. It is kept, and PARENTHESISED, so a future caller that does pass both
     * is not silently broken the moment anything is ANDed onto the WHERE.
     */
    public function testBothIdsTogetherStillOrButAreNowParenthesised(): void
    {
        $where = Player::awardsForPlayerWhereForTest(11956, 431452);

        $this->assertSame('(awards.mundane_id = 11956 or awards.awards_id = 431452)', $where);
    }

    /**
     * mysql_real_escape_string() is a no-op shim in this codebase; the (int) casts
     * are what protect the interpolation.
     */
    public function testIdsAreCastAndCannotCarryInjection(): void
    {
        // valid_id() is is_numeric && > 0, so a hostile string is not a valid id at
        // all and never reaches the single-row branch — it falls through to the
        // mundane predicate, where it is CAST. This used to go through
        // mysql_real_escape_string(), a no-op shim, and reach the statement verbatim.
        $this->assertSame(
            'awards.mundane_id = 17',
            Player::awardsForPlayerWhereForTest("17' OR '1'='1", 0)
        );
        $this->assertSame(
            'awards.mundane_id = 42',
            Player::awardsForPlayerWhereForTest('42 UNION SELECT 1', 0)
        );
        $this->assertSame(
            'awards.mundane_id = 0',
            Player::awardsForPlayerWhereForTest(0, "17' OR '1'='1")
        );

        // A numeric-string id — how a query string arrives — still reaches the fast
        // branch, cast.
        $this->assertSame('awards.awards_id = 17', Player::awardsForPlayerWhereForTest(0, '17'));
    }

    /**
     * valid_id() is is_numeric && > 0, so a negative or non-numeric id is neither a
     * player lookup nor a row lookup. The pre-existing predicate is kept — a
     * MundaneId of 0 matched the 3,075 rows carrying mundane_id = 0 and still does —
     * but the value is cast rather than interpolated raw.
     */
    public function testNeitherIdKeepsThePreviousPredicateWithACast(): void
    {
        $this->assertSame('awards.mundane_id = 0', Player::awardsForPlayerWhereForTest(0, 0));
        $this->assertSame('awards.mundane_id = -3', Player::awardsForPlayerWhereForTest(-3, -1));
    }

    /**
     * The joins must not drift: Scroll::ResolveTitle() reads the alias,
     * kingdomaward, park, kingdom, event and giver columns this query provides, and
     * a single-row branch that quietly dropped one would print a differently-titled
     * certificate for the same award depending on which door it came through.
     */
    public function testTheSingleRowBranchIsAPredicateChangeOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.Player.php');
        $body = substr($src, (int) strpos($src, 'public function AwardsForPlayer('));
        $body = substr($body, 0, (int) strpos($body, '$r = $this->db->query($sql);'));

        foreach ([
            'kingdomaward ka on awards.kingdomaward_id = ka.kingdomaward_id',
            'award a on a.award_id = ka.award_id',
            'award alias on alias.award_id = awards.alias_award_id',
            'park p on p.park_id = awards.at_park_id',
            'kingdom k on k.kingdom_id = awards.at_kingdom_id',
            'event e on e.event_id = awards.at_event_id',
            'mundane m on m.mundane_id = awards.given_by_id',
            'mundane bwm on bwm.mundane_id = awards.by_whom_id',
        ] as $join) {
            $this->assertStringContainsString($join, $body, "AwardsForPlayer lost its join: {$join}");
        }
    }

    /**
     * ork_awards.revoked is NOT NULL DEFAULT 0 and is now surfaced on the row so
     * Model_Player's CanPrintScroll and Model_Scrollery's server-side gate share one
     * source of truth instead of re-querying. Additive only — the shared method's
     * default behaviour is deliberately NOT filtered, because four other callers
     * depend on it.
     */
    public function testTheRowShapeCarriesRevokedAndStillReturnsRevokedRows(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.Player.php');
        $body = substr($src, (int) strpos($src, 'public function AwardsForPlayer('));
        $body = substr($body, 0, (int) strpos($body, 'public function GetCircleAwardIds'));

        $this->assertStringContainsString("'Revoked' => (int) \$r->revoked", $body);
        $this->assertStringNotContainsString('revoked = 0', $body, 'the shared method must not filter revoked rows');
    }
}
