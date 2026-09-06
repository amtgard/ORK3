<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * THE ALLOWLIST. "Remove all awards from the drop-down other than Order of X awards
 * (the ladders). No titles, no masters, etc. Leave Custom Award and Custom Title but
 * specifically give a space for that to be typed in to replace the 'Custom Title' or
 * 'Custom Award' wording."
 *
 * An award prints if and only if it resolves to a real, art-backed order in
 * scroll-orders.php, or its id is Custom Award (94) or Custom Title (246).
 * Everything else is refused — and refused because it was never admitted, not
 * because somebody remembered to name it.
 *
 * Three things are pinned here:
 *
 *  1. the SHAPE of the rule — one gate inside Scroll::OrderForAwardRow(), not
 *     IsCustomEligible(), and not a denylist;
 *  2. the 74 ids the old denylist named, every one of which must still be refused —
 *     defence in depth, and the regression guard if someone later loosens the
 *     allowlist;
 *  3. the rule against the LIVE catalogue, so a row drifting into a printable class
 *     is caught rather than silently printed.
 */
final class ScrollExcludedAwardsTest extends TestCase
{
    /**
     * The denylist this allowlist replaced, verbatim, as a fixture.
     *
     * It lived in Scroll::EXCLUDED_AWARD_IDS and is deliberately not a constant any
     * more: enumerating what must not print is the rule that fails OPEN, and keeping
     * it in the domain would invite a future caller to consult it as though it were
     * still the policy. Kept HERE, where its only job is to fail if any of these ever
     * becomes printable again.
     *
     * @var list<int>
     */
    private const LEGACY_DENYLIST = [
        // Knighthoods
        17, 18, 19, 20, 245,
        // Masterhoods (the Master tier of each ladder, never the ladder itself)
        1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 240, 244,
        // Paragons
        37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 241, 242,
        // Associate titles
        13, 14, 15, 16, 203,
        // Noble titles above Lord (title_class >= 40)
        54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69,
        // The same noble ladder as ORPHAN ids: no ork_award row exists for 95-187,
        // but ork_kingdomaward rows in kingdoms 3, 28 and 32 point here under the
        // names Baronet/Baron/Viscount/Count/Marquis/Duke/Archduke/Grand Duke and
        // their feminine forms. They dangle, so nothing field-keyed can catch them.
        147, 148, 149, 150, 151, 152, 153, 154, 155, 156, 157, 158, 159, 160, 161, 162,
        // Defender
        35,
    ];

    /**
     * The eight catalogue entries — 3,991 grants — that lost printability when the
     * denylist became an allowlist, with their grant counts as measured on the live
     * data. Four of them (Lord, Lady, Master, Esquire) were deliberately KEPT by the
     * previous round's decision; the scope decision supersedes it.
     *
     * @var array<int, array{0:string, 1:int}>
     */
    private const NEWLY_REFUSED = [
        52  => ['Lord', 1862],
        53  => ['Lady', 975],
        31  => ['Order of the Walker in the Middle', 379],
        36  => ['Weaponmaster', 341],
        207 => ['Dragonmaster', 289],
        226 => ['Master', 67],
        209 => ['Esquire', 58],
        231 => ['Warmaster', 20],
    ];

    /** @return array<string, mixed> */
    private function row(array $o = []): array
    {
        return array_merge(['AwardId' => 0, 'OfficerRole' => 'none', 'KingdomAwardName' => 'Something'], $o);
    }

    /**
     * DEFENCE IN DEPTH. Every id the old 74-id denylist named is still refused, by a
     * rule that never mentions any of them. If a future change loosens the allowlist,
     * this is what fails.
     */
    public function testEveryIdTheOldDenylistNamedIsStillRefused(): void
    {
        // 74 ids, of which 58 are real ork_award rows and 16 are the orphan noble
        // ladder that exists only in ork_kingdomaward. The decisions doc's "55
        // catalogue ids" counts neither figure and is wrong; the constant is the
        // record, and it held 74.
        $this->assertCount(74, self::LEGACY_DENYLIST, 'the legacy denylist held 74 ids');

        foreach (self::LEGACY_DENYLIST as $id) {
            $row = $this->row(['AwardId' => $id]);
            $this->assertTrue(Scroll::IsExcludedFromScrollery($row), "award {$id} should be excluded");
            $this->assertNull(Scroll::OrderForAwardRow($row), "award {$id} must resolve no order");
            $this->assertFalse(Scroll::HasArt($row), "award {$id} must have no art");
        }
    }

    /**
     * The orphan ids 147-162 are the noble ladder with NO ork_award row at all,
     * reached only through ork_kingdomaward in kingdoms 3, 28 and 32. Nothing
     * field-keyed can see them, which is why the denylist had to name them —
     * and why the allowlist does not: an id nothing admits is refused by default.
     */
    public function testTheOrphanNobleIdsAreRefusedWithoutBeingNamed(): void
    {
        foreach (range(147, 162) as $id) {
            $this->assertNull(
                Scroll::OrderForAwardRow($this->row(['AwardId' => $id, 'KingdomAwardName' => 'Baronet'])),
                "orphan noble id {$id} must not print"
            );
        }
    }

    /**
     * THE SCOPE CHANGE ITSELF. These eight were printable before and are not now,
     * which reverses the previous round's explicit decision to keep Lord, Lady,
     * Master and Esquire. The instruction supersedes it: "no titles, no masters".
     */
    public function testTheEightEntriesTheScopeChangeRemovedNoLongerPrint(): void
    {
        foreach (self::NEWLY_REFUSED as $id => [$name, $grants]) {
            $row = $this->row(['AwardId' => $id, 'KingdomAwardName' => $name]);
            $this->assertNull(
                Scroll::OrderForAwardRow($row),
                "{$name} ({$id}, {$grants} grants) must no longer print"
            );
            $this->assertFalse(Scroll::HasArt($row));
        }
    }

    /**
     * Award 31, "Order of the Walker in the Middle", is a genuine is_ladder award and
     * is dropped ANYWAY, because Scrollery has no artwork for it — the one ladder in
     * the catalogue with no entry in scroll-orders.php.
     *
     * It is called out separately from the seven titles above because it is the one
     * exclusion that is not about class at all, and because it is the only ladder
     * carrying off-pattern kingdom names: Crystal Groves' "Master Reeve" rides on it
     * and goes with it, three names and 33 grants in total, with no name-matching
     * heuristic anywhere.
     */
    public function testTheOneLadderWithNoArtworkIsDroppedAndTakesItsKingdomNamesWithIt(): void
    {
        $this->assertNull(Scroll::OrderForAwardId(31), 'award 31 must have no order of its own');

        foreach (['Order of the Walker in the Middle', 'Master Reeve', 'Reeve'] as $name) {
            $row = $this->row(['AwardId' => 31, 'KingdomAwardName' => $name, 'IsLadder' => 1]);
            $this->assertNull(Scroll::OrderForAwardRow($row), "{$name} (award 31) must not print");
        }
    }

    /** The two custom entries survive — they are the only non-order ids that do. */
    public function testTheTwoCustomEntriesStillPrint(): void
    {
        foreach ([94 => 'Custom Award', 246 => 'Custom Title'] as $id => $name) {
            $row = $this->row(['AwardId' => $id, 'KingdomAwardName' => $name]);
            $this->assertFalse(Scroll::IsExcludedFromScrollery($row), "{$name} ({$id}) must not be excluded");
            $this->assertSame('custom', Scroll::OrderForAwardRow($row)['id'], "{$name} ({$id}) must print");
        }

        $this->assertSame([94, 246], Scroll::CUSTOM_AWARD_IDS);
    }

    /**
     * Award::GetLadderMasterMap() keys ladder ids to SEPARATE Master ids. Only the
     * Master ids are refused, so "Master Rose" does not print while "Order of the
     * Rose" ranks 1-10 print exactly as before. If a ladder id ever fell out of
     * scroll-orders.php, an entire order would go dark — except award 31, which
     * never had artwork and is the deliberate exception.
     */
    public function testEveryLadderWithArtworkPrintsAndEveryMasterTierDoesNot(): void
    {
        foreach (Award::GetLadderMasterMap() as $ladderId => $meta) {
            $ladder = $this->row(['AwardId' => (int) $ladderId]);

            if ((int) $ladderId === 31) {
                $this->assertNull(Scroll::OrderForAwardRow($ladder), 'award 31 is the deliberate exception');
            } else {
                $this->assertNotNull(
                    Scroll::OrderForAwardRow($ladder),
                    "ladder {$ladderId} ({$meta['LadderName']}) lost its order"
                );
            }

            foreach ((array) ($meta['MasterAwardIds'] ?? []) as $masterId) {
                $this->assertNull(
                    Scroll::OrderForAwardRow($this->row(['AwardId' => (int) $masterId])),
                    "master {$masterId} ({$meta['MasterName']}) must not print"
                );
            }
        }
    }

    /** Every order in the catalogue still resolves — the allowlist took none of them out. */
    public function testNoDefinedOrderBecameUnreachable(): void
    {
        $this->assertNotEmpty(Scroll::Orders());

        foreach (Scroll::Orders() as $id => $order) {
            $this->assertIsArray($order, "order {$id} is malformed");
            if ($id === 'custom' || $id === 'hunter') {
                continue;
            }
            $this->assertSame(
                $id,
                Scroll::OrderForAwardRow($this->row(['AwardId' => (int) $order['award_id']]))['id'],
                "order {$id} no longer resolves from its own award_id"
            );
        }
    }

    /**
     * The alias half, and the one place a naive allowlist would have failed OPEN.
     *
     * All 11 alias rows in the ORK sit on Custom Title (246) and point at a real
     * title — Lord, Lady, Baronet, Countess, Baron, Archduke, Paragon Anti-Paladin.
     * The base id is admitted; the alias is what was actually given. Reading only
     * AwardId would have re-admitted every one of the titles the policy removes,
     * through the very entry the policy keeps.
     */
    public function testAnAliasOutsideTheAllowlistIsRefusedEvenWhenTheBaseIsAdmitted(): void
    {
        foreach ([52 => 'Lord', 53 => 'Lady', 54 => 'Baronet', 61 => 'Countess', 66 => 'Archduke', 37 => 'Paragon Anti-Paladin'] as $aliasId => $name) {
            $row = $this->row([
                'AwardId'          => 246,
                'AliasAwardId'     => $aliasId,
                'KingdomAwardName' => 'Custom Title',
                'AliasAwardName'   => $name,
            ]);

            $this->assertTrue(Scroll::IsExcludedFromScrollery($row), "Custom Title aliased to {$name} must be refused");
            $this->assertNull(Scroll::OrderForAwardRow($row));
        }

        // An absent or zero alias is not an alias, and must not refuse anything.
        $this->assertSame('custom', Scroll::OrderForAwardRow($this->row(['AwardId' => 94, 'AliasAwardId' => 0]))['id']);
        $this->assertSame('custom', Scroll::OrderForAwardRow($this->row(['AwardId' => 94]))['id']);
    }

    /**
     * Archery survives automatically: it has no base award id of its own, so it is
     * matched by NAME and resolves to `hunter`, a real art-backed order. The match is
     * confined to rows whose base award resolved to nothing or to a custom entry —
     * otherwise the name list would be a second, weaker door into the allowlist.
     */
    public function testArcheryStillPrintsButOnlyThroughItsOwnDoor(): void
    {
        $this->assertSame(
            'hunter',
            Scroll::OrderForAwardRow($this->row(['AwardId' => 0, 'KingdomAwardName' => 'Order of the Hunter']))['id']
        );
        $this->assertSame(
            'hunter',
            Scroll::OrderForAwardRow($this->row(['AwardId' => 94, 'KingdomAwardName' => 'Order of the Archer']))['id']
        );

        // A knighthood a kingdom happened to name "Order of the Hunter" must not
        // reach the archery certificate through the name list. No such row exists
        // today; this is what keeps that true.
        $this->assertNull(
            Scroll::OrderForAwardRow($this->row(['AwardId' => 17, 'KingdomAwardName' => 'Order of the Hunter']))
        );
    }

    /**
     * A row with no award id at all is now REFUSED, which is the change of polarity in
     * one line. Under the denylist an absent id meant "id 0", the dangling-archery
     * sentinel, and stayed printable as a custom scroll; under the allowlist a
     * dangling row prints only if its NAME is an archery name.
     */
    public function testARowWithNoAwardIdPrintsOnlyIfItIsArchery(): void
    {
        $this->assertNull(Scroll::OrderForAwardRow(['OfficerRole' => 'none']));
        $this->assertNull(Scroll::OrderForAwardRow($this->row(['AwardId' => 0, 'AliasAwardId' => 0])));
        $this->assertSame(
            'hunter',
            Scroll::OrderForAwardRow($this->row(['AwardId' => 0, 'KingdomAwardName' => 'Hunter']))['id']
        );
    }

    /**
     * The policy lives in OrderForAwardRow(), NOT in IsCustomEligible().
     *
     * IsCustomEligible() answers only "is this an honour rather than a job". Putting
     * the allowlist there would let an admitted award that is an officer position
     * through, and would leave OrderForAwardId() resolving before the gate was ever
     * consulted.
     */
    public function testTheGateIsNotInIsCustomEligible(): void
    {
        $knighthood = $this->row(['AwardId' => 17]);

        $this->assertTrue(Scroll::IsCustomEligible($knighthood), 'IsCustomEligible must not carry the policy');
        $this->assertNull(Scroll::OrderForAwardRow($knighthood), 'OrderForAwardRow must carry it');
    }

    /**
     * THE DRIFT GUARD, now far stronger than the label-derived oracle it replaces.
     *
     * The old guard asked whether a 74-id denylist still agreed with
     * Award::OptionGroupLabelFor()'s buckets plus a title_class cut plus one
     * hand-made call — an oracle with three moving parts, which could only ever
     * catch drift in the classes it already knew about.
     *
     * The allowlist admits a closed set, so the guard is simply: walk the WHOLE live
     * catalogue and assert that the printable rows are exactly the ladders with
     * artwork plus 94 and 246. Any new catalogue row is refused by default and this
     * test says so; the way to fail it is to draw artwork and forget to say why.
     */
    public function testOnlyOrdersWithArtworkAndTheTwoCustomEntriesPrintAcrossTheLiveCatalogue(): void
    {
        $pdo = $this->catalogue();
        if ($pdo === null) {
            $this->markTestSkipped('Test database is not available.');
        }

        $rows = $pdo->query(
            'SELECT award_id, name, is_ladder FROM ' . DB_PREFIX . 'award ORDER BY award_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows, 'the award catalogue is empty — did the schema render fail?');

        $printable = [];
        foreach ($rows as $r) {
            $row = $this->row(['AwardId' => (int) $r['award_id'], 'KingdomAwardName' => (string) $r['name']]);
            if (Scroll::OrderForAwardRow($row) !== null) {
                $printable[(int) $r['award_id']] = (string) $r['name'];
            }
        }

        $expected = array_values(array_filter(
            Scroll::PrintableAwardIds(),
            static fn (int $id): bool => $id !== 6969   // hunter's fabricated id; no catalogue row carries it
        ));
        sort($expected);
        $actual = array_keys($printable);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "the printable set has drifted from the catalogue:\n  " . implode("\n  ", array_map(
                static fn (int $id, string $n): string => "{$id} {$n}",
                array_keys($printable),
                array_values($printable)
            ))
        );

        // And the ladder half of that, stated as the decision states it: every
        // is_ladder award prints EXCEPT 31, which has no artwork.
        foreach ($rows as $r) {
            if ((int) $r['is_ladder'] !== 1) {
                continue;
            }
            $id = (int) $r['award_id'];
            $this->assertSame(
                $id !== 31,
                isset($printable[$id]),
                "ladder {$id} ({$r['name']}) printability is wrong"
            );
        }
    }

    private function catalogue(): ?PDO
    {
        if (!ork3_test_db_available()) {
            return null;
        }

        $host = getenv('ORK3_TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('ORK3_TEST_DB_PORT') ?: '19307';

        try {
            return new PDO("mysql:host={$host};port={$port};dbname=ork_test", 'ork', 'secret', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }
}
