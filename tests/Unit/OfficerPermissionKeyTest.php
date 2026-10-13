<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OfficerPermissionKeyTest extends TestCase
{
    public function testKingdomScopeUsesKingdomKeys(): void
    {
        self::assertSame('kingdom.officer.set', OfficerPosition::PermissionKeyFor('set', 0));
        self::assertSame('kingdom.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 0));
        self::assertSame('kingdom.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 0));
        self::assertSame('kingdom.officer_history.manage', OfficerPosition::PermissionKeyFor('history', 0));
    }

    public function testParkScopeUsesParkKeysForOccupancy(): void
    {
        self::assertSame('park.officer.set', OfficerPosition::PermissionKeyFor('set', 42));
        self::assertSame('park.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 42));
        self::assertSame('park.officer_history.manage', OfficerPosition::PermissionKeyFor('history', 42));
    }

    /**
     * The position family is kingdom-scoped whatever ParkId is passed.
     *
     * ork_officer_position is a per-KINGDOM registry whose rows are shared by every park,
     * and RetirePosition vacates every holder of a position across every scope in the
     * kingdom -- so a park-scoped key here would let one park's officer strip officers
     * from every other park. OfficerAdminAjax forced the kingdom key for the browser
     * path, but the domain is reachable through the JSON service, which bypasses it.
     */
    public function testPositionFamilyIsAlwaysKingdomScoped(): void
    {
        self::assertSame('kingdom.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 42));
        self::assertSame(
            OfficerPosition::PermissionKeyFor('position', 0),
            OfficerPosition::PermissionKeyFor('position', 42),
            'A ParkId must not change which permission governs officer positions.'
        );
    }

    public function testParkScopedPositionKeyNoLongerExists(): void
    {
        self::assertFalse(
            PermissionRegistry::Exists('park.officer.position.manage'),
            'park.officer.position.manage must stay out of the registry: it names a '
            . 'kingdom-wide capability that no park-scoped grant may confer.'
        );
    }

    public function testEveryKeyExistsInTheRegistry(): void
    {
        foreach (['set', 'vacate', 'position', 'history'] as $action) {
            foreach ([0, 42] as $parkId) {
                $key = OfficerPosition::PermissionKeyFor($action, $parkId);
                self::assertTrue(
                    PermissionRegistry::Exists($key),
                    "Permission key {$key} is not defined in PermissionRegistry"
                );
            }
        }
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OfficerPosition::PermissionKeyFor('nonsense', 0);
    }
}
