<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * The officer admin's controller gate.
 *
 * It gated every action on ('kingdom.officer.set','kingdom',$kingdom_id), so a
 * park-only officer was refused before reaching the scope-aware domain gate Plan 1
 * built. This test is the regression guard that makes the edit-pad removals safe:
 * if it fails, deleting the park pad locks every Sheriff out of their own park.
 *
 * `list` MUST stay gated. actionList's personaLabel falls back to
 * GivenName + Surname when a persona is blank, so an ungated list emits real
 * legal names.
 */
final class OfficerAdminGateTest extends TestCase
{
    private const MARKER = 'zzgate';

    public function testEveryActionHasAScopeAwarePermissionKey(): void
    {
        // The map must cover every dispatchable action, and every key must exist.
        $actions = [
            'list' => 'set', 'transition' => 'set',
            'vacate' => 'vacate', 'vacateholder' => 'vacate', 'vacateall' => 'vacate',
            'createposition' => 'position', 'editposition' => 'position',
            'reorderpositions' => 'position', 'reclassify' => 'position',
            'retire' => 'position', 'reinstate' => 'position',
            'roles' => 'position', 'permissions' => 'position',
            'addhistory' => 'history', 'edithistory' => 'history', 'deletehistory' => 'history',
        ];
        foreach ($actions as $action => $kind) {
            foreach ([0, 42] as $parkId) {
                $key = OfficerPosition::PermissionKeyFor($kind, $parkId);
                self::assertTrue(
                    PermissionRegistry::Exists($key),
                    "action {$action} (scope park={$parkId}) maps to undefined permission {$key}"
                );
                $expectedPrefix = $parkId > 0 ? 'park.' : 'kingdom.';
                self::assertStringStartsWith(
                    $expectedPrefix,
                    $key,
                    "action {$action} must use a {$expectedPrefix}* key at that scope"
                );
            }
        }
    }

    public function testListIsNotInAnUngatedAllowlist(): void
    {
        // Guards against a future "reads don't need gating" simplification.
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertMatchesRegularExpression(
            "/'list'\s*=>\s*'set'/",
            $src,
            'list must remain gated -- personaLabel falls back to GivenName + Surname'
        );
    }

    public function testUnknownActionStillFailsClosed(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertStringContainsString(
            'Unknown action',
            $src,
            'an action absent from the map must be refused, not defaulted'
        );
    }

    public function testActionListEmitsOnlyTheSingularOccupantKey(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertStringNotContainsString(
            "'Occupants'",
            $src,
            'one office holds one person; the plural array has no referent'
        );
        self::assertStringContainsString("'Occupant'", $src);
    }

    /**
     * Companion to the Blocker 2 fix: Admin_park.tpl hardcodes $mo_can_manage = true
     * (the route's own authority check already gated the page), so a park-ONLY
     * officer can open Manage Officers and see Create/Edit/Reclassify/Retire/reorder
     * controls that the now-kingdom-scoped 'position' gate will refuse. actionList
     * must report whether THIS user actually holds that kingdom-scope authority, via
     * the SAME check the gate uses, so the client can hide rather than leave dead
     * controls that 400 on click.
     */
    public function testActionListEmitsCanManagePositionsFromTheKingdomScopeGate(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');

        // list must be dispatched with $uid so actionList can run the check.
        self::assertMatchesRegularExpression(
            "/case 'list':\\s*\\\$this->actionList\\(\\\$kingdom_id,\\s*\\\$park_id,\\s*\\\$uid\\)/",
            $src,
            'actionList needs $uid to compute the per-user CanManagePositions capability'
        );

        // The capability check must reuse the exact gate the 'position' family uses:
        // kingdom scope, kingdom id, PermissionKeyFor('position', 0) -- not the
        // request's own $park_id/$scope, which is what made the buttons dead in the
        // first place.
        self::assertMatchesRegularExpression(
            "/has_permission_or_authority\\(\\s*\\\$uid,\\s*OfficerPosition::PermissionKeyFor\\('position',\\s*0\\),\\s*'kingdom',\\s*\\\$kingdom_id,/s",
            $src,
            "actionList's capability check must match the position family's kingdom-scope gate exactly"
        );

        self::assertStringContainsString(
            "'CanManagePositions'",
            $src,
            'the list payload must expose the capability so the client can hide dead controls'
        );
    }

    public function testNoTemplateConsumerReadsOccupants(): void
    {
        $tpl = file_get_contents(dirname(__DIR__, 2)
            . '/orkui/template/revised-frontend/partials/_manage_officers.tpl');
        self::assertStringNotContainsString(
            'Occupants',
            $tpl,
            'the normalizer, the vacancy count and the crown/supporting split all collapse together'
        );
    }

    public function testThePartialDeclaresAParkIdInItsIncludeContract(): void
    {
        $tpl = file_get_contents(dirname(__DIR__, 2)
            . '/orkui/template/revised-frontend/partials/_manage_officers.tpl');
        self::assertStringContainsString(
            '$mo_park_id',
            $tpl,
            'one partial must serve both the kingdom and park consoles'
        );
        self::assertMatchesRegularExpression(
            '/parkId\s*:/',
            $tpl,
            'MoConfig must carry parkId so every POST can scope itself'
        );
    }

    /**
     * Blocker 2 (final whole-branch review): ork_officer_position is a per-KINGDOM
     * registry -- RetirePosition vacates every holder of a position ACROSS EVERY
     * SCOPE in the kingdom. But PermissionKeyFor('position', $park_id) resolves to
     * the PARK key whenever ParkId is present, so gating the 'position' family the
     * same way as 'set'/'vacate'/'history' let a park-only officer reach
     * createposition/editposition/reorderpositions/reclassify/retire/reinstate and
     * disrupt every other park in the kingdom. The controller must special-case
     * 'position' to kingdom scope regardless of ParkId.
     */
    public function testPositionFamilyResolvesToAKingdomKeyEvenWhenParkScoped(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');

        self::assertMatchesRegularExpression(
            "/\\\$action_kind\\[\\\$action\\]\\s*===\\s*'position'/",
            $src,
            "the controller must special-case the 'position' family away from the default park/kingdom split"
        );
        self::assertMatchesRegularExpression(
            "/\\\$scope\\s*=\\s*'kingdom';/",
            $src,
            'the position family override must force kingdom scope'
        );
        self::assertMatchesRegularExpression(
            "/\\\$permission_park_id\\s*=\\s*0;/",
            $src,
            "the position family override must force PermissionKeyFor's park id to 0, i.e. a kingdom.* key"
        );

        // And confirm the underlying static resolves as expected at that input --
        // this is the key the controller's override is required to produce.
        self::assertStringStartsWith(
            'kingdom.',
            OfficerPosition::PermissionKeyFor('position', 0),
            "PermissionKeyFor('position', 0) must be a kingdom.* key"
        );
    }

    /**
     * The 'set' (list/transition), 'vacate' (vacate/vacateholder/vacateall) and
     * 'history' (addhistory/edithistory/deletehistory) families are genuinely
     * per-scope -- occupancy of a specific office in a specific park -- and must
     * be unaffected by the Blocker 2 fix above: a park officer must keep
     * transitioning, vacating, and correcting the rolls in their own park.
     */
    public function testOccupancyFamiliesStayParkScopedWhenParkScoped(): void
    {
        foreach (['set', 'vacate', 'history'] as $kind) {
            $key = OfficerPosition::PermissionKeyFor($kind, 42);
            self::assertStringStartsWith(
                'park.',
                $key,
                "family '{$kind}' must still resolve to a park.* key when park-scoped"
            );
        }

        // The default scope resolution (park scope when ParkId is present) must
        // still stand for these families -- only 'position' gets the override.
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertMatchesRegularExpression(
            "/\\\$scope\\s*=\\s*\\(\\\$park_id\\s*>\\s*0\\)\\s*\\?\\s*'park'\\s*:\\s*'kingdom';/",
            $src,
            'the default park/kingdom scope split must remain for non-position families'
        );
    }

    /**
     * Blocker 1 (final whole-branch review): $kingdom_id is the URL route segment
     * (attacker-named); $park_id is authorized. When park-scoped, nothing
     * previously validated that the URL's kingdom actually owned that park, so a
     * caller holding legitimate authority over their OWN park could read ANY OTHER
     * kingdom's position registry and RBAC role catalogue by naming it in the URL.
     * The controller must derive the kingdom from the park (never trust the URL
     * value) whenever a park is named, through the model layer -- never a direct
     * domain/Ork3::$Lib call from a controller.
     */
    public function testParkScopedRequestsDeriveKingdomFromTheParkThroughTheModelLayer(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');

        self::assertMatchesRegularExpression(
            "/if\\s*\\(\\\$park_id\\s*>\\s*0\\)\\s*\\{[^}]*load_model\\('KingdomProfile'\\)/s",
            $src,
            'a park-scoped request must derive its kingdom via the model layer, not trust the URL kingdom id'
        );
        self::assertStringContainsString(
            '$this->KingdomProfile->park_kingdom_id($park_id)',
            $src,
            'the derivation must go through Model_KingdomProfile, never Ork3::$Lib or a domain class directly'
        );
        // Layering: the derivation call itself must not reach around the model layer
        // (a bare "Ork3::$Lib" mention in the file's own architecture docblock, one
        // line above the class, is fine and expected -- it names the service this
        // controller orchestrates via the model. Only executable code is checked here.)
        self::assertDoesNotMatchRegularExpression(
            '/officer\([^)]*\)\s*\{.*Ork3::\$Lib/s',
            $src,
            'the officer() dispatcher body may not call Ork3::$Lib directly -- go through the model layer'
        );
    }
}
