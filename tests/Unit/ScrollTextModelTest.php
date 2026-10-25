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
        $got = \ScrollText::IssuerLines($in['title'], $in['name'], $in['useThe'], $in['useOf'], $in['scope']);
        $this->assertSame($want, array_intersect_key($got, $want));
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

        $shire = $this->scopeContext(
            'park',
            987650004,
            [],
            ['ParkName' => 'Everglade Sentinels', 'ParkTitle' => 'Shire', 'KingdomId' => 4, 'HasHeraldry' => 0]
        );
        $this->assertSame('Shire', $shire['Title']);

        $untitled = $this->scopeContext(
            'park',
            987650005,
            [],
            ['ParkName' => 'Bar', 'ParkTitle' => '', 'KingdomId' => 4, 'HasHeraldry' => 0]
        );
        $this->assertSame('Barony', $untitled['Title']);
    }
}
