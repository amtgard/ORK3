<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RbacRoleFixture.php';

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
    private const KINGDOM_ID = 100064;
    /** The kingdom a caller names in the URL but holds nothing in. */
    private const FOREIGN_KINGDOM_ID = 100065;
    private const PARK_ID = 100066;

    private ?PDO $pdo = null;
    private ?RbacRoleFixture $rbac = null;
    private ?int $positionId = null;

    protected function tearDown(): void
    {
        if ($this->pdo === null) {
            return;
        }
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        if ($this->positionId !== null) {
            $this->pdo->exec('DELETE FROM ork_officer WHERE position_id = ' . $this->positionId);
            $this->pdo->exec('DELETE FROM ork_officer_history WHERE position_id = ' . $this->positionId);
            $this->pdo->exec('DELETE FROM ork_officer_position WHERE position_id = ' . $this->positionId);
            $this->positionId = null;
        }
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        $this->pdo->exec('DELETE FROM ork_park WHERE park_id = ' . self::PARK_ID);
        $this->rbac?->cleanup();
        $this->rbac = null;
    }

    private function pdoConnection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
                DB_USERNAME,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        return $this->pdo;
    }

    private function rbac(): RbacRoleFixture
    {
        if ($this->rbac === null) {
            $this->rbac = new RbacRoleFixture($this->pdoConnection(), self::MARKER, self::KINGDOM_ID);
        }

        return $this->rbac;
    }

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

                // The 'position' family is the exception, and deliberately so.
                // ork_officer_position is a per-KINGDOM registry whose rows are shared by
                // every park, and RetirePosition vacates every holder of a position across
                // every scope in the kingdom -- so a park-scoped grant would let one park's
                // officer strip officers from every other park. Those actions stay on the
                // kingdom key whatever ParkId is supplied. Occupancy (set / vacate /
                // history) is genuinely per-scope and is what this test was written for:
                // the gate used to hardcode kingdom.officer.set for everything, which
                // refused every park-only officer.
                $expectedPrefix = ($parkId > 0 && $kind !== 'position') ? 'park.' : 'kingdom.';
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
            "/has_permission_or_authority\\(\\s*\\\$uid,\\s*\\\$this->OfficerPosition->permission_key_for\\('position',\\s*0\\),\\s*'kingdom',\\s*\\\$kingdom_id,\\s*AUTH_EDIT/s",
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

        // The LEGACY authority bridge must MIRROR the domain, action for action: only
        // CreatePosition authorizes at AUTH_CREATE in class.OfficerPosition.php; edit,
        // reorder, reclassify, retire and reinstate all authorize at AUTH_EDIT there, and
        // OfficerPosition is reachable through the JSON service without this controller.
        // Bridging the whole family at AUTH_CREATE would make the console STRICTER than
        // the endpoint it fronts and lock legacy 'edit' officers out of work the API
        // still performs for them.
        self::assertMatchesRegularExpression(
            "/\\\$legacy_role\\s*=\\s*\\(\\\$action\\s*===\\s*'createposition'\\)\\s*\\?\\s*AUTH_CREATE\\s*:\\s*AUTH_EDIT;/",
            $src,
            'only createposition may bridge legacy authority at AUTH_CREATE; the rest mirror the domain at AUTH_EDIT'
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

    /**
     * The behavioural half of the Blocker 1 guard above.
     *
     * The regex tests prove the derivation is SPELLED in the controller; they cannot
     * prove it is WIRED. A refactor that keeps load_model('KingdomProfile') and
     * park_kingdom_id($park_id) present while never routing the result into the write
     * would satisfy every one of them. So drive the rule through a real park-scoped
     * request that names a foreign kingdom and check which kingdom the write landed in.
     *
     * The controller's own action echoes JSON and exits, which cannot be called
     * in-process, so this exercises the same derivation at the layer beneath it:
     * OfficerPosition::SetOccupant, which the park console posts to and which applies
     * the identical "never trust the request's KingdomId when a park is named" rule.
     */
    public function testAParkScopedWriteRecordsTheParksKingdomNotTheOneNamedInTheRequest(): void
    {
        $pdo = $this->pdoConnection();
        $rbac = $this->rbac();

        $pdo->prepare(
            'INSERT INTO ork_park
                (park_id, kingdom_id, name, abbreviation, url, address, city, province,
                 postal_code, google_geocode, latitude, longitude, location,
                 map_url, description, directions)
             VALUES (:pid, :kid, :name, "ZZG", "", "", "", "", "", "", 0, 0, "", "", "", "")'
        )->execute([
            ':pid' => self::PARK_ID,
            ':kid' => self::KINGDOM_ID,
            ':name' => self::MARKER . '_park',
        ]);

        $pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification,
                 is_pinned, is_system, rbac_role_id, has_auth_role, sort_order,
                 parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
             VALUES (:kid, :key, :title, "", "supporting", 0, 0, 0, 0, 100, NULL, 0, NULL, 0, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID,
            ':key' => self::MARKER . '_derived',
            ':title' => self::MARKER . ' Derived Office',
        ]);
        $this->positionId = (int) $pdo->lastInsertId();

        // A park officer: park.officer.set for THIS park, and nothing at kingdom scope
        // in either kingdom.
        [$actorId, $token] = $rbac->seedPlayer('parkofficer', true, self::PARK_ID, self::KINGDOM_ID);
        $roleId = $rbac->seedRoleWith('park.officer.set', 'park', self::KINGDOM_ID);
        $rbac->assignRole($actorId, $roleId, ['park_id' => self::PARK_ID]);

        $holderId = $rbac->seedUnprivilegedPlayer('holder', self::PARK_ID);

        $positions = new OfficerPosition();
        $r = $positions->SetOccupant([
            'Token' => $token,
            // The attacker-supplied half of the request: a kingdom this actor holds
            // nothing in and that does not own the park.
            'KingdomId' => self::FOREIGN_KINGDOM_ID,
            'ParkId' => self::PARK_ID,
            'PositionId' => $this->positionId,
            'MundaneId' => $holderId,
            'TermStart' => '2026-01-01',
            'TermEnd' => '',
            'Note' => self::MARKER,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'The park officer holds park.officer.set for this park and must be allowed. Error: '
            . (string) ($r['Error'] ?? '')
        );

        $recordedKingdomId = (int) $pdo->query(
            'SELECT kingdom_id FROM ork_officer
              WHERE position_id = ' . $this->positionId . ' AND mundane_id = ' . $holderId . ' LIMIT 1'
        )->fetchColumn();

        self::assertSame(
            self::KINGDOM_ID,
            $recordedKingdomId,
            'The kingdom must be derived from the park. Taking it from the request writes a park '
            . "officer's roster into a kingdom nobody authorized -- the same URL-trust defect the "
            . 'controller regexes above only prove is spelled out.'
        );
        self::assertNotSame(
            self::FOREIGN_KINGDOM_ID,
            $recordedKingdomId,
            'The kingdom named in the request must never reach the row.'
        );
    }
}
