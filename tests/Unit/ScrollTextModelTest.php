<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The issuer text model, PHP half.
 *
 * ScrollText::IssuerLines() and the browser's ScrolleryText.issuerLines() must split
 * an issuer the same way or the composer previews one certificate and prints another.
 * Both are driven from tests/fixtures/scroll-text-model.json — the JS half is
 * tools/scroll-sweep/tests/textmodel.spec.ts — so neither can change a rule without
 * the other's tests going red.
 */
final class ScrollTextModelTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, array<string, string>}> */
    public static function cases(): iterable
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/scroll-text-model.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($fixture['cases'] as $case) {
            yield $case['name'] => [$case['in'], $case['want']];
        }
    }

    #[DataProvider('cases')]
    public function testIssuerLinesMatchTheSharedFixture(array $in, array $want): void
    {
        // The title goes through IssuerTitle() first, as BuildScroll does: the Barony floor and the
        // Inactive rule live there, and the browser's issuerLines() applies them itself.
        $title = \ScrollText::IssuerTitle($in['title'], $in['scope']);
        $got   = \ScrollText::IssuerLines($title, $in['name'], $in['useThe'], $in['useOf'], $in['scope']);
        $this->assertSame($want, array_intersect_key($got, $want));
    }

    public function testIssuerTitleFloorsOnlyAParkAndNeverKeepsInactive(): void
    {
        $this->assertSame('Barony', \ScrollText::IssuerTitle('', 'park'));
        $this->assertSame('Barony', \ScrollText::IssuerTitle('  ', 'park'));
        $this->assertSame('Barony', \ScrollText::IssuerTitle('Inactive', 'park'));
        $this->assertSame('Barony', \ScrollText::IssuerTitle(' iNaCtIvE ', 'park'));
        $this->assertSame('Shire', \ScrollText::IssuerTitle(' Shire ', 'park'));
        $this->assertSame('', \ScrollText::IssuerTitle('', 'kingdom'));
        $this->assertSame('', \ScrollText::IssuerTitle('Inactive', 'kingdom'));
        $this->assertSame('', \ScrollText::IssuerTitle('', ''));
    }

    /** @return iterable<string, array{string, string}> */
    public static function repairCases(): iterable
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/scroll-text-model.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($fixture['repair'] as $case) {
            yield $case['name'] => [$case['in'], $case['want']];
        }
    }

    /** Double-encoded text prints as written; clean text is never touched. */
    #[DataProvider('repairCases')]
    public function testRepairMojibakeMatchesTheSharedFixture(string $in, string $want): void
    {
        $this->assertSame($want, \ScrollText::RepairMojibake($in));
    }

    /** Case s20's award title, spelled out by byte so the test does not depend on its own encoding. */
    public function testRepairMojibakeFixesTheCorpusCaseS20Title(): void
    {
        $stored = "The true \xC3\xA2\xE2\x82\xAC\xC5\x93Head\xC3\xA2\xE2\x82\xAC\xC2\x9D man of the East";
        $this->assertSame("The true \u{201C}Head\u{201D} man of the East", \ScrollText::RepairMojibake($stored));
    }

    /** Repairing twice changes nothing more: the output is not itself mojibake. */
    public function testRepairMojibakeIsIdempotent(): void
    {
        $once = \ScrollText::RepairMojibake("Caf\u{C3}\u{A9} \u{E2}\u{20AC}\u{153}x\u{E2}\u{20AC}\u{9D}");
        $this->assertSame($once, \ScrollText::RepairMojibake($once));
    }

    /** Text that is not valid UTF-8 at all is not ours to guess at. */
    public function testRepairMojibakeLeavesInvalidUtf8Alone(): void
    {
        $this->assertSame("caf\xE9", \ScrollText::RepairMojibake("caf\xE9"));
    }

    /**
     * The closing quote's last byte is U+009D, a control character, and a typed custom
     * name is stripped of those. Repairing after the strip left "â€" and a gap (corpus s20).
     */
    public function testACustomNameIsRepairedBeforeItsControlCharactersAreStripped(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['AwardId' => Scroll::CUSTOM_TITLE_AWARD_ID, 'Name' => 'Custom Title', 'KingdomAwardName' => 'Custom Title']),
            $this->context(['customName' => "The true \u{E2}\u{20AC}\u{153}Head\u{E2}\u{20AC}\u{9D} man of the East"])
        );

        $this->assertSame("The true \u{201C}Head\u{201D} man of the East", $s['title']);
    }

    public function testBuildScrollRepairsEveryStringTheCertificatePrints(): void
    {
        $bad = static fn (string $good): string => strtr($good, [
            "\u{201C}" => "\u{E2}\u{20AC}\u{153}",
            "\u{201D}" => "\u{E2}\u{20AC}\u{9D}",
            "\u{E9}"   => "\u{C3}\u{A9}",
        ]);

        $s = Scroll::BuildScroll(
            $this->row([
                'Note'      => $bad("a \u{201C}fine\u{201D} caf\u{E9}"),
                'GivenBy'   => $bad("Ren\u{E9}"),
                'ParkName'  => $bad("Caf\u{E9} Keep"),
                'Name'      => $bad("The true \u{201C}Head\u{201D} man"),
                'KingdomAwardName' => $bad("The true \u{201C}Head\u{201D} man"),
            ]),
            $this->context(['player' => $bad("Ren\u{E9}e")])
        );

        $this->assertSame('a “fine” café', $s['reason']);
        $this->assertSame('René', $s['givenBy']);
        $this->assertSame('Café Keep', $s['issuerName']);
        $this->assertSame('Renée', $s['player']);
        $this->assertStringContainsString('“Head”', $s['title']);
    }

    /**
     * startup.php runs `new ClassName()` over every file in system/lib/ork3/, and
     * Ork3's own bootstrap is not something a pure text model should drag in.
     */
    public function testTheModelIsAPlainClassWithNoConstructorArguments(): void
    {
        $model = new \ScrollText();

        $this->assertNotInstanceOf(\Ork3::class, $model);
        $this->assertNull((new \ReflectionClass(\ScrollText::class))->getConstructor());
    }

    /**
     * The last four keys are the form the composer fields should show, so feeding
     * them straight back in must print the same lines. That only holds if `name`
     * keeps the article ("the Wetlands"): the place alone would drop it.
     */
    public function testTheEffectiveFormRoundTripsThroughTheModel(): void
    {
        $first  = \ScrollText::IssuerLines('', 'The Kingdom of the Wetlands', false, false, 'kingdom');

        $this->assertSame('Kingdom', $first['title']);
        $this->assertTrue($first['useThe']);
        $this->assertTrue($first['useOf']);
        $this->assertSame('the Wetlands', $first['name']);

        $second = \ScrollText::IssuerLines($first['title'], $first['name'], $first['useThe'], $first['useOf'], 'kingdom');
        $this->assertSame($first, $second);
    }

    /** A name the split leaves alone comes back as it went in, so nothing is invented. */
    public function testAnUnsplitNameKeepsItsEffectiveFormUnchanged(): void
    {
        $got = \ScrollText::IssuerLines('', 'The Celestial Kingdom', false, false, 'kingdom');

        $this->assertSame('', $got['title']);
        $this->assertFalse($got['useThe']);
        $this->assertFalse($got['useOf']);
        $this->assertSame('The Celestial Kingdom', $got['name']);
    }

    /**
     * @return iterable<string, array{string, string, bool, bool, string, string}>
     */
    public static function phrases(): iterable
    {
        // title, name, useThe, useOf, scope, phrase
        yield 'unsplit kingdom' => ['', 'The Kingdom of the Wetlands', false, false, 'kingdom', 'the Kingdom of the Wetlands'];
        yield 'composed kingdom, already split' => ['Kingdom', 'the Wetlands', true, true, 'kingdom', 'the Kingdom of the Wetlands'];
        yield 'kingdom without the leading article' => ['', 'Kingdom of 13 Roads', false, false, 'kingdom', 'the Kingdom of 13 Roads'];
        yield 'kingdom whose place keeps a capital The' => ['', 'The Principality of The Painted Skies', false, false, 'kingdom', 'the Principality of the Painted Skies'];
        yield 'kingdom with a bare place' => ['', 'The Empire of Rivermoor', false, false, 'kingdom', 'the Empire of Rivermoor'];
        yield 'plural rank word' => ['', 'The Freeholds of Amtgard', false, false, 'kingdom', 'the Freeholds of Amtgard'];
        yield 'whole kingdom name keeps its own article' => ['', 'The Celestial Kingdom', false, false, 'kingdom', 'The Celestial Kingdom'];
        yield 'whole kingdom name with no rank word' => ['', 'Uncharted Wastelands', false, false, 'kingdom', 'Uncharted Wastelands'];
        yield 'park is the stored name' => ['Shire', 'The Everglade Sentinels', true, true, 'park', 'The Everglade Sentinels'];
        yield 'park with no article' => ['Barony', 'Iron Keep', true, true, 'park', 'Iron Keep'];
        yield 'park whose title is Inactive' => ['Inactive', 'Foo', true, true, 'park', 'Foo'];
        yield 'park exactly as stored, spacing included' => ['Shire', "  The  River's End ", true, true, 'park', "  The  River's End "];
        yield 'composed kingdom, of unticked: the name keeps its of, the phrase says it once' => ['Principality', 'The Principality of the Crimson Sands', true, false, 'kingdom', 'the Principality of the Crimson Sands'];
        yield 'unknown scope is the stored name' => ['', 'The Kingdom of the Wetlands', false, false, '', 'The Kingdom of the Wetlands'];
    }

    /**
     * The zodiac flavour reads "a shining star of <<ISSUER>>", so the issuer has to read
     * well after "of": "the Kingdom of the Wetlands", not "The Kingdom of the Wetlands"
     * and not the composer's split "the Wetlands". A park never changed and does not now.
     */
    #[DataProvider('phrases')]
    public function testPhraseIsTheIssuerAsItReadsAfterOf(
        string $title,
        string $name,
        bool $useThe,
        bool $useOf,
        string $scope,
        string $phrase
    ): void {
        $this->assertSame($phrase, \ScrollText::IssuerLines($title, $name, $useThe, $useOf, $scope)['phrase']);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'AwardId'          => 33,
            'OfficerRole'      => 'none',
            'Rank'             => 3,
            'Date'             => '2024-05-13',
            'Note'             => 'valour in the field',
            'GivenBy'          => 'Sir Someone',
            'Name'             => 'Order of the Griffin',
            'KingdomAwardName' => 'Order of the Griffin',
            'CustomAwardName'  => '',
            'AliasAwardName'   => '',
            'ParkName'         => 'Rising Sun Station',
            'KingdomName'      => 'The Kingdom of Crystal Groves',
        ], $overrides);
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'player'   => 'Testerly',
            'pronoun'  => 'her',
            'heraldry' => '/assets/heraldry/park/00435.png',
        ], $overrides);
    }

    public function testBuildScrollCarriesTheSplitLinesAndKeepsTheStoredName(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['ParkName' => '', 'KingdomName' => 'The Kingdom of the Wetlands']),
            $this->context()
        );

        $this->assertSame('and the Kingdom of', $s['issuerLead']);
        $this->assertSame('the', $s['issuerArticle']);
        $this->assertSame('Wetlands', $s['issuerPlace']);
        // The stored name, title and flags are untouched: the split is display only.
        $this->assertSame('The Kingdom of the Wetlands', $s['issuerName']);
        $this->assertSame('', $s['issuerTitle']);
        $this->assertFalse($s['useThe']);
        $this->assertFalse($s['useOf']);
    }

    public function testAParkIssuerCarriesItsLinesToo(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['ParkName' => 'The Everglade Sentinels']),
            $this->context(['issuerTitle' => 'Shire'])
        );

        $this->assertSame('and the Shire of', $s['issuerLead']);
        $this->assertSame('the', $s['issuerArticle']);
        $this->assertSame('Everglade Sentinels', $s['issuerPlace']);
        $this->assertSame('The Everglade Sentinels', $s['issuerName']);
    }

    /** An officer's explicit wording is what prints, split or not. */
    public function testAnExplicitKingdomOverrideIsUsedAsGiven(): void
    {
        $s = Scroll::BuildScroll(
            $this->row(['ParkName' => '', 'KingdomName' => 'Crystal Groves']),
            $this->context(['issuerTitle' => 'Kingdom', 'useThe' => true, 'useOf' => true])
        );

        $this->assertSame('and the Kingdom of', $s['issuerLead']);
        $this->assertSame('', $s['issuerArticle']);
        $this->assertSame('Crystal Groves', $s['issuerPlace']);
    }

    /**
     * 5 parks have "Inactive" in ork_parktitle.title: a status that leaked into a
     * rank-word column. Left alone it prints "and the Inactive of".
     */
    public function testAnInactiveParkTitleFallsBackToBarony(): void
    {
        $s = Scroll::BuildScroll($this->row(), $this->context(['issuerTitle' => 'Inactive ']));

        $this->assertSame('Barony', $s['issuerTitle']);
        $this->assertSame('and the Barony of', $s['issuerLead']);
        $this->assertSame('Rising Sun Station', $s['issuerPlace']);
    }

    /** The fallback is case-blind, like the model's own rule. */
    public function testTheInactiveFallbackIgnoresCase(): void
    {
        $s = Scroll::BuildScroll($this->row(), $this->context(['issuerTitle' => '  INACTIVE']));

        $this->assertSame('Barony', $s['issuerTitle']);
    }

    /**
     * Zodiac ranks 6, 9 and 10 carry <<ISSUER>>. Rank 6 reads "a shining star of
     * <<ISSUER>>, and an invaluable member of our community", which with the stored
     * kingdom name printed "star of The Kingdom of the Wetlands" and, after the
     * composer's split, "star of the Wetlands".
     */
    public function testZodiacFlavourNamesAKingdomAfterOf(): void
    {
        $stored = Scroll::BuildScroll(
            $this->row(['AwardId' => 30, 'Rank' => 6, 'ParkName' => '', 'KingdomName' => 'The Kingdom of the Wetlands']),
            $this->context()
        );
        $this->assertStringContainsString('shining star of the Kingdom of the Wetlands,', $stored['flavour']);
        $this->assertStringNotContainsString('<<', $stored['flavour']);
        // The stored name is still what the certificate carries elsewhere.
        $this->assertSame('The Kingdom of the Wetlands', $stored['issuerName']);

        $composed = Scroll::BuildScroll(
            $this->row(['AwardId' => 30, 'Rank' => 6, 'ParkName' => '', 'KingdomName' => 'the Wetlands']),
            $this->context(['issuerTitle' => 'Kingdom', 'useThe' => true, 'useOf' => true])
        );
        $this->assertSame($stored['flavour'], $composed['flavour']);
    }

    /** Ranks 9 and 10 put the issuer in other positions; all three use the same phrase. */
    public function testEveryZodiacRankThatNamesTheIssuerUsesThePhrase(): void
    {
        foreach ([6, 9, 10] as $rank) {
            $s = Scroll::BuildScroll(
                $this->row(['AwardId' => 30, 'Rank' => $rank, 'ParkName' => '', 'KingdomName' => 'The Empire of the Iron Mountains']),
                $this->context()
            );
            $this->assertStringContainsString('the Empire of the Iron Mountains', $s['flavour'], "rank {$rank}");
            $this->assertStringNotContainsString('The Empire', $s['flavour'], "rank {$rank}");
        }
    }

    /** A park's flavour is unchanged: its stored name, with no rank word added. */
    public function testZodiacFlavourNamesAParkByItsStoredName(): void
    {
        $s = Scroll::BuildScroll($this->row(['AwardId' => 30, 'Rank' => 6]), $this->context());

        $this->assertStringContainsString('shining star of Rising Sun Station,', $s['flavour']);

        $titled = Scroll::BuildScroll(
            $this->row(['AwardId' => 30, 'Rank' => 6]),
            $this->context(['issuerTitle' => 'Shire'])
        );
        $this->assertSame($s['flavour'], $titled['flavour']);
    }

    /**
     * Run scope_context() with the kingdom and park lookups stubbed, so it can be
     * exercised without a database. Its static caches are keyed by id, hence the
     * ids no real row has.
     *
     * @param array<string, mixed> $kingdomInfo
     * @param array<string, mixed> $parkDetails
     * @return array<string, mixed>
     */
    private function scopeContext(string $type, int $id, array $kingdomInfo, array $parkDetails): array
    {
        $realKingdom = Ork3::$Lib->kingdom ?? null;
        $realPark    = Ork3::$Lib->park ?? null;

        Ork3::$Lib->kingdom = new class ($kingdomInfo) {
            public function __construct(private array $info)
            {
            }

            public function GetKingdomShortInfo(array $request): array
            {
                return ['KingdomInfo' => $this->info];
            }
        };
        Ork3::$Lib->park = new class ($parkDetails) {
            public function __construct(private array $details)
            {
            }

            public function GetParkDetails(array $request): array
            {
                return $this->details;
            }
        };

        try {
            return (new \ReflectionClass(Model_Scrollery::class))
                ->newInstanceWithoutConstructor()
                ->scope_context($type, $id);
        } finally {
            Ork3::$Lib->kingdom = $realKingdom;
            Ork3::$Lib->park    = $realPark;
        }
    }

    /**
     * The composer's fields should show what will print, so a stored kingdom is
     * offered split: "Kingdom", both boxes ticked, "the Wetlands". Posted back
     * as-is, that prints the same lines as the stored name does.
     */
    public function testScopeContextOffersAKingdomInItsSplitForm(): void
    {
        $scope = $this->scopeContext(
            'kingdom',
            987650001,
            ['KingdomName' => 'The Kingdom of the Wetlands', 'HasHeraldry' => 0],
            []
        );

        $this->assertSame('Kingdom', $scope['Title']);
        $this->assertTrue($scope['UseThe']);
        $this->assertTrue($scope['UseOf']);
        $this->assertSame('the Wetlands', $scope['Name']);
        // Title and Name are the composer's fields; a label must read as the stored name.
        $this->assertSame('The Kingdom of the Wetlands', $scope['Display']);

        $lines = \ScrollText::IssuerLines($scope['Title'], $scope['Name'], $scope['UseThe'], $scope['UseOf'], 'kingdom');
        $this->assertSame('and the Kingdom of', $lines['lead']);
        $this->assertSame('the', $lines['article']);
        $this->assertSame('Wetlands', $lines['place']);
    }

    /** A kingdom whose name is not "<rank> of <place>" is offered whole, as stored. */
    public function testScopeContextLeavesAWholeKingdomNameAlone(): void
    {
        $scope = $this->scopeContext(
            'kingdom',
            987650002,
            ['KingdomName' => 'The Celestial Kingdom', 'HasHeraldry' => 0],
            []
        );

        $this->assertSame('', $scope['Title']);
        $this->assertFalse($scope['UseThe']);
        $this->assertFalse($scope['UseOf']);
        $this->assertSame('The Celestial Kingdom', $scope['Name']);
        $this->assertSame('The Celestial Kingdom', $scope['Display']);
    }

    /** A park keeps its own rank word, and an "Inactive" one takes the Barony floor. */
    public function testScopeContextTreatsAnInactiveParkTitleAsNoTitle(): void
    {
        $inactive = $this->scopeContext(
            'park',
            987650003,
            [],
            ['ParkName' => 'Foo', 'ParkTitle' => 'Inactive', 'KingdomId' => 4, 'HasHeraldry' => 0]
        );
        $this->assertSame('Barony', $inactive['Title']);
        $this->assertSame('Foo', $inactive['Name']);
        $this->assertSame('Barony Foo', $inactive['Display']);

        $shire = $this->scopeContext(
            'park',
            987650004,
            [],
            ['ParkName' => 'Everglade Sentinels', 'ParkTitle' => 'Shire', 'KingdomId' => 4, 'HasHeraldry' => 0]
        );
        $this->assertSame('Shire', $shire['Title']);
        $this->assertSame('Shire Everglade Sentinels', $shire['Display']);

        $untitled = $this->scopeContext(
            'park',
            987650005,
            [],
            ['ParkName' => 'Bar', 'ParkTitle' => '', 'KingdomId' => 4, 'HasHeraldry' => 0]
        );
        $this->assertSame('Barony', $untitled['Title']);
        $this->assertSame('Barony Bar', $untitled['Display']);
    }

    /**
     * The chip and door labels used to be trim(Title . ' ' . Name) of the UNSPLIT values.
     * Display must stay exactly that, so a kingdom whose stored name has stray
     * whitespace still prints it trimmed, as before.
     */
    public function testDisplayIsExactlyWhatTheOldTitlePlusNameLabelWas(): void
    {
        $kingdom = $this->scopeContext(
            'kingdom',
            987650006,
            ['KingdomName' => '  The Kingdom of the Wetlands ', 'HasHeraldry' => 0],
            []
        );
        $this->assertSame(trim('' . ' ' . '  The Kingdom of the Wetlands '), $kingdom['Display']);

        $park = $this->scopeContext(
            'park',
            987650007,
            [],
            ['ParkName' => 'Mordengaard', 'ParkTitle' => 'Barony', 'KingdomId' => 4, 'HasHeraldry' => 0]
        );
        $this->assertSame('Barony Mordengaard', $park['Display']);
    }
}
