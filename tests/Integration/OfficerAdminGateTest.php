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
}
