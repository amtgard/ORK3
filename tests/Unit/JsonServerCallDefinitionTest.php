<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Transport-level coverage for JsonServer's DERIVED zero-call parameter lists.
 *
 * A zero call ("Player/AddAward0") has no declared signature: JsonServer derives
 * its public parameter list by tokenizing the domain method's source for
 * $request['X'] reads, and wrangle_parameters() rejects the call outright when a
 * non-optional derived key is missing.
 *
 * The consequence is a standing backwards-compatibility trap: adding one new
 * $request['X'] read to an existing domain method silently makes X a REQUIRED
 * public API parameter and breaks every client written before it existed. That is
 * exactly what happened when ZodiacMonth / IsLadder / MaxLevel / IncludeDisabled
 * were added, and worse for ApiClient -- a transport-internal flag stamped on by
 * the dispatcher itself, which no client could ever supply, making the affected
 * calls impossible to satisfy from outside.
 *
 * Every existing API test calls the domain methods in-process, which never
 * exercises this derivation. These tests assert the DEFINITION, not the method.
 */
final class JsonServerCallDefinitionTest extends TestCase
{
    /**
     * The calls this branch's additive $request reads broke.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function affectedCallProvider(): array
    {
        return [
            // The read path -- the one most likely to be wired into a report or a
            // spreadsheet, and the one that must never regress.
            'Kingdom/GetAwardList0' => ['Kingdom', 'GetAwardList0', ['IsLadder', 'IncludeDisabled']],
            'Player/AddAward0' => ['Player', 'AddAward0', ['ZodiacMonth']],
            'Player/UpdateAward0' => ['Player', 'UpdateAward0', ['ZodiacMonth']],
            'Player/ReconcileAward0' => ['Player', 'ReconcileAward0', ['ZodiacMonth']],
            'Player/AddAwardRecommendation0' => ['Player', 'AddAwardRecommendation0', ['ZodiacMonth']],
            'Kingdom/CreateAward0' => ['Kingdom', 'CreateAward0', ['IsLadder', 'MaxLevel']],
            'Kingdom/EditAward0' => ['Kingdom', 'EditAward0', ['IsLadder', 'MaxLevel']],
        ];
    }

    /** @return array<string, mixed>|false */
    private function definitionFor(string $class, string $method)
    {
        $server = new JsonServer();
        $reflected = new ReflectionMethod(JsonServer::class, 'get_call_definition');
        $reflected->setAccessible(true);
        return $reflected->invoke($server, $class, $method);
    }

    /** @return list<string> */
    private function derivedRequestKeys(string $class, string $method): array
    {
        $server = new JsonServer();
        $reflected = new ReflectionMethod(JsonServer::class, 'translate_static_analysis');
        $reflected->setAccessible(true);
        return array_values($reflected->invoke($server, $class, $method));
    }

    public function testAddAwardMarksZodiacMonthOptionalAndOmitsApiClient(): void
    {
        $definition = $this->definitionFor('Player', 'AddAward0');

        $this->assertIsArray($definition, 'Player/AddAward0 must derive a parameter definition.');

        $this->assertArrayHasKey('ZodiacMonth', $definition, 'ZodiacMonth should still be a known parameter.');
        $this->assertTrue(
            $definition['ZodiacMonth']['Optional'],
            'ZodiacMonth was added after clients were written; requiring it rejects every legacy AddAward call.'
        );

        $this->assertArrayNotHasKey(
            'ApiClient',
            $definition,
            'ApiClient is stamped on by the dispatcher and can never be client-supplied; deriving it makes the call unsatisfiable.'
        );
    }

    /**
     * The ApiClient exclusion is only load-bearing because the tokenizer really
     * does find it in Player::AddAward()'s source. Without this assertion the test
     * above would keep passing if the carve-out were silently removed upstream.
     */
    public function testApiClientIsDerivedFromSourceButExcludedFromTheDefinition(): void
    {
        $this->assertContains(
            JsonServer::API_CLIENT_FLAG,
            $this->derivedRequestKeys('Player', 'AddAward'),
            'Player::AddAward() is expected to read $request[ApiClient] -- the carve-out that keeps an inbound Zodiac Rank.'
        );

        $this->assertArrayNotHasKey(
            JsonServer::API_CLIENT_FLAG,
            $this->definitionFor('Player', 'AddAward0'),
            'A derived ApiClient parameter must be filtered out of the public definition.'
        );
    }

    /**
     * @param list<string> $additiveKeys
     * @dataProvider affectedCallProvider
     */
    public function testAdditiveParametersAreOptionalOnEveryAffectedCall(string $class, string $method, array $additiveKeys): void
    {
        $definition = $this->definitionFor($class, $method);
        $this->assertIsArray($definition, "$class/$method must derive a parameter definition.");

        foreach ($additiveKeys as $key) {
            $this->assertArrayHasKey($key, $definition, "$class/$method should still expose $key.");
            $this->assertTrue(
                $definition[$key]['Optional'],
                "$class/$method rejects clients that predate $key unless $key is optional."
            );
        }

        $this->assertArrayNotHasKey(
            JsonServer::API_CLIENT_FLAG,
            $definition,
            "$class/$method must not expose the transport-internal ApiClient flag."
        );
    }

    /**
     * Every key in the registry must be optional wherever it is derived -- the
     * registry is the single place a future additive parameter gets registered.
     *
     * @dataProvider affectedCallProvider
     */
    public function testRegisteredAdditiveKeysAreOptionalWhereverTheyAppear(string $class, string $method): void
    {
        $definition = $this->definitionFor($class, $method);
        $this->assertIsArray($definition);

        foreach (JsonServer::ADDITIVE_OPTIONAL_PARAMETERS as $key) {
            if (!array_key_exists($key, $definition)) {
                continue;
            }
            $this->assertTrue($definition[$key]['Optional'], "$class/$method: registered additive key $key must be optional.");
        }
    }

    /**
     * The fix must NOT make everything optional. Flipping every derived parameter
     * would let missing keys reach domain methods that are not null-safe, trading a
     * clean BAD_ARGUMENTS for a 500.
     *
     * @dataProvider affectedCallProvider
     */
    public function testGenuinelyRequiredParametersAreStillEnforced(string $class, string $method): void
    {
        $definition = $this->definitionFor($class, $method);
        $this->assertIsArray($definition);

        $required = [];
        foreach ($definition as $parameter => $details) {
            if (!$details['Optional']) {
                $required[] = $parameter;
            }
        }

        $this->assertNotEmpty($required, "$class/$method must still require its pre-existing parameters.");

        // Nothing outside the registry may be optional in a derived definition.
        foreach ($definition as $parameter => $details) {
            if ($details['Optional']) {
                $this->assertContains(
                    $parameter,
                    JsonServer::ADDITIVE_OPTIONAL_PARAMETERS,
                    "$class/$method: $parameter is optional but is not a registered additive parameter."
                );
            }
        }
    }

    public function testKingdomAwardListStillRequiresKingdomId(): void
    {
        $definition = $this->definitionFor('Kingdom', 'GetAwardList0');
        $this->assertIsArray($definition);
        $this->assertArrayHasKey('KingdomId', $definition);
        $this->assertFalse(
            $definition['KingdomId']['Optional'],
            'KingdomId is genuinely required; the read path must still reject a call that omits it.'
        );
    }

    public function testApiClientFlagConstantMatchesTheKeyDomainCodeReads(): void
    {
        $this->assertSame('ApiClient', JsonServer::API_CLIENT_FLAG);
        $this->assertNotContains(
            JsonServer::API_CLIENT_FLAG,
            JsonServer::ADDITIVE_OPTIONAL_PARAMETERS,
            'ApiClient is excluded outright, never merely optional.'
        );
    }
}
