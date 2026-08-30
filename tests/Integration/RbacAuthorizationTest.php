<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class RbacAuthorizationTest extends TestCase
{
    public function testEveryUserFacingMutatorRejectsAnInvalidToken(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $rbac = new RBACService();
        foreach (['GrantRole', 'RevokeRole', 'CreateRole', 'EditRole', 'DeleteRole'] as $method) {
            $r = $rbac->{$method}(['Token' => 'not-a-real-token', 'KingdomId' => 100041]);
            self::assertSame(5, (int) $r['Status'], $method . ' must reject an invalid token');
        }
    }

    public function testTheActorCannotBeNamedByTheCaller(): void
    {
        $rbac = new RBACService();
        // Every one of these took the actor as a parameter. Passing one must not
        // grant standing -- the token is the only thing that establishes identity.
        $r = $rbac->GrantRole([
            'Token' => '', 'GranterId' => 1, 'KingdomId' => 100041,
            'MundaneId' => 2, 'RoleId' => 3, 'ScopeType' => 'kingdom', 'ScopeId' => 100041,
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    public function testInternalSyncMethodsAreUnreachableByTheDispatcher(): void
    {
        foreach (['SyncOfficerRole', 'SyncOfficerRoleByPositionId', 'SyncNewOfficerSlot'] as $old) {
            self::assertFalse(method_exists('RBACService', $old), $old . ' must be renamed with underscores');
        }
        foreach (['sync_officer_role', 'sync_officer_role_by_position_id', 'sync_new_officer_slot'] as $new) {
            self::assertTrue(method_exists('RBACService', $new));
        }
    }
}
