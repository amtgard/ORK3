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

    public function testParkScopeUsesParkKeys(): void
    {
        self::assertSame('park.officer.set', OfficerPosition::PermissionKeyFor('set', 42));
        self::assertSame('park.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 42));
        self::assertSame('park.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 42));
        self::assertSame('park.officer_history.manage', OfficerPosition::PermissionKeyFor('history', 42));
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
