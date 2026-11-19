<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Model_Scrollery::flag_viewer_printable_awards() narrows the profile's
 * CanPrintScroll to what the render route will actually print for this viewer.
 *
 * The profile showed View/Print to anyone with park CREATE at the recipient's
 * HOME park, while print_permission() keys on where the award was GIVEN and
 * refuses unattributed rows — so an officer saw buttons whose tab then refused.
 *
 * Authority is stubbed (Ork3::$Lib->authorization swapped for a recorder) so the
 * branches and the per-call memo can be pinned without authorization fixtures,
 * and so the test can count how many HasAuthority() calls the memo saves.
 */
final class ScrolleryViewerPrintableAwardsTest extends TestCase
{
    private const RECIPIENT = 500001;
    private const OFFICER   = 500002;
    private const ADMIN     = 500003;

    private const HOME_PARK  = 9101;
    private const OTHER_PARK = 9102;
    private const KINGDOM    = 9201;

    private mixed $realAuth = null;

    /** @var object{calls: list<array>, grants: array<string, true>} */
    private object $auth;

    private Model_Scrollery $model;

    protected function setUp(): void
    {
        $this->realAuth = Ork3::$Lib->authorization ?? null;

        $this->auth = new class () {
            /** @var list<array{0: int, 1: string, 2: int, 3: string}> */
            public array $calls = [];

            /** @var array<string, true> "mundane|type|id|role" => granted */
            public array $grants = [];

            public function HasAuthority($mundaneId, $type, $id, $role, $visited = [])
            {
                $this->calls[] = [(int) $mundaneId, (string) $type, (int) $id, (string) $role];

                // Mirrors the real method's NULL-on-denial, which the model casts.
                return isset($this->grants["{$mundaneId}|{$type}|{$id}|{$role}"]) ? true : null;
            }
        };
        $this->auth->grants[self::ADMIN . '|' . AUTH_ADMIN . '|0|' . AUTH_ADMIN]                = true;
        $this->auth->grants[self::OFFICER . '|' . AUTH_PARK . '|' . self::HOME_PARK . '|' . AUTH_EDIT] = true;

        Ork3::$Lib->authorization = $this->auth;
        $this->model = new Model_Scrollery();
    }

    protected function tearDown(): void
    {
        Ork3::$Lib->authorization = $this->realAuth;
    }

    /** @return array<string, mixed> */
    private function row(int $awardsId, int $parkId, int $kingdomId, bool $canPrint = true, int $revoked = 0): array
    {
        return [
            'AwardsId'       => $awardsId,
            'MundaneId'      => self::RECIPIENT,
            'ParkId'         => $parkId,
            'KingdomId'      => $kingdomId,
            'Revoked'        => $revoked,
            'CanPrintScroll' => $canPrint,
        ];
    }

    /** @return array<int, bool> awards_id => CanPrintScroll */
    private function flags(array $rows, int $viewerId): array
    {
        $out = [];
        foreach ($this->model->flag_viewer_printable_awards($rows, $viewerId) as $row) {
            $out[(int) $row['AwardsId']] = $row['CanPrintScroll'];
        }

        return $out;
    }

    public function testAnOfficerPrintsOnlyWhereTheyHoldTheIssuingPark(): void
    {
        $flags = $this->flags([
            $this->row(1, self::HOME_PARK, self::KINGDOM),
            $this->row(2, self::OTHER_PARK, self::KINGDOM),
            $this->row(3, 0, 0),
        ], self::OFFICER);

        $this->assertTrue($flags[1], 'park edit at at_park_id prints');
        $this->assertFalse($flags[2], 'an award given at a park the officer does not hold must not print');
        $this->assertFalse($flags[3], 'an unattributed award is refused for a non-admin officer');
    }

    public function testAViewerWithNoAuthorityPrintsNothing(): void
    {
        $flags = $this->flags([
            $this->row(1, self::HOME_PARK, self::KINGDOM),
            $this->row(2, 0, self::KINGDOM),
        ], 500099);

        $this->assertSame([1 => false, 2 => false], $flags);
    }

    public function testTheRecipientPrintsTheirOwnEvenUnattributed(): void
    {
        $flags = $this->flags([
            $this->row(1, 0, 0),
            $this->row(2, self::OTHER_PARK, self::KINGDOM),
        ], self::RECIPIENT);

        $this->assertSame([1 => true, 2 => true], $flags);
    }

    public function testAnAdminPrintsEverythingAskingAuthorityOnce(): void
    {
        $flags = $this->flags([
            $this->row(1, 0, 0),
            $this->row(2, self::OTHER_PARK, self::KINGDOM),
            $this->row(3, self::HOME_PARK, 0),
        ], self::ADMIN);

        $this->assertSame([1 => true, 2 => true, 3 => true], $flags);
        $this->assertCount(1, $this->auth->calls, 'admin is constant per call and asked once');
    }

    /**
     * The flag can only be narrowed, never widened: a row that has no art or is
     * revoked stays off for every viewer, admin and recipient included.
     */
    public function testNoArtAndRevokedStayOffForEveryone(): void
    {
        foreach ([self::ADMIN, self::RECIPIENT, self::OFFICER] as $viewer) {
            $flags = $this->flags([
                $this->row(1, self::HOME_PARK, self::KINGDOM, canPrint: false),
                $this->row(2, self::HOME_PARK, self::KINGDOM, canPrint: true, revoked: 1),
            ], $viewer);

            $this->assertSame([1 => false, 2 => false], $flags, "viewer {$viewer}");
        }
    }

    public function testAZeroViewerPrintsNothing(): void
    {
        $this->assertSame([1 => false], $this->flags([$this->row(1, self::HOME_PARK, self::KINGDOM)], 0));
    }

    /**
     * The memo is keyed on the (ParkId, KingdomId) pair: repeats of a scope reuse
     * the answer, but a different park — even in the same kingdom — is asked on its
     * own, so one park's grant can never leak onto another's rows.
     */
    public function testTheMemoReusesAScopeButNeverConflatesTwoParks(): void
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = $this->row($i, $i % 2 ? self::HOME_PARK : self::OTHER_PARK, self::KINGDOM);
        }

        $flags = $this->flags($rows, self::OFFICER);

        foreach ($flags as $awardsId => $flag) {
            $this->assertSame($awardsId % 2 === 1, $flag, "row {$awardsId}");
        }

        $parkCalls = array_values(array_filter(
            $this->auth->calls,
            static fn (array $c): bool => $c[1] === AUTH_PARK
        ));
        $this->assertSame(
            [self::HOME_PARK, self::OTHER_PARK],
            array_map(static fn (array $c): int => $c[2], $parkCalls),
            'one park check per distinct park, not one per row'
        );
    }

    public function testRowsWithoutAPrintFlagAreLeftAlone(): void
    {
        $rows   = [['AwardsId' => 7, 'MundaneId' => self::RECIPIENT, 'ParkId' => self::HOME_PARK, 'KingdomId' => 0]];
        $result = $this->model->flag_viewer_printable_awards($rows, self::OFFICER);

        $this->assertSame($rows, $result);
        $this->assertSame([[self::OFFICER, AUTH_ADMIN, 0, AUTH_ADMIN]], $this->auth->calls);
    }
}
