<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RbacRoleFixture.php';

/**
 * The ORK Administration dashboard (Admin::index) as the home of the global.* keys.
 *
 * The ork_admin role and its global.* keys exist so the ORK team can delegate one
 * installation capability without delegating them all -- but the dashboard, the global
 * permissions page and the nav entry all used to admit only a true all-zero admin row,
 * so a holder of one key had no way to reach the tool they held. Pinned here:
 *   - Controller::global_capabilities() answers each key through the real gate, and a
 *     player holding one global key (no ork_authorization row) is admitted while an
 *     unprivileged one is not;
 *   - the dashboard shows that holder only the tile for their key, and drops sections
 *     with nothing in them;
 *   - the global permissions page renders read-only (no Grant card, no Revoke buttons)
 *     for a global.admin.grant holder, since the server refuses anyone but a true admin.
 */
final class OrkAdminDashboardAccessTest extends TestCase
{
    private const MARKER     = 'zzorkadmindash';
    private const KINGDOM_ID = 100047;

    private ?PDO $pdo = null;
    private ?RbacRoleFixture $rbac = null;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            self::markTestSkipped('ork_test database is not reachable.');
        }
    }

    protected function tearDown(): void
    {
        $this->rbac?->cleanup();
        $this->rbac = null;
    }

    private function rbac(): RbacRoleFixture
    {
        if ($this->rbac === null) {
            $this->pdo ??= new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
                DB_USERNAME,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->rbac = new RbacRoleFixture($this->pdo, self::MARKER, self::KINGDOM_ID);
        }

        return $this->rbac;
    }

    /** A Controller with its protected capability helpers exposed, built without the request-bound constructor. */
    private static function controller(): object
    {
        $probe = new class () extends Controller {
            public function __construct()
            {
            }

            public function caps(int $uid): array
            {
                return $this->global_capabilities($uid);
            }

            public function standing(int $uid): bool
            {
                return $this->has_ork_admin_standing($uid);
            }

            public static function keys(): array
            {
                return self::ORK_ADMIN_CAPABILITY_KEYS;
            }

            public static function keysWithoutTile(): array
            {
                return self::ORK_ADMIN_KEYS_WITHOUT_TILE;
            }
        };

        return $probe;
    }

    private function seedGlobalKeyHolder(string $key): int
    {
        $uid = $this->rbac()->seedUnprivilegedPlayer('holder');
        $this->rbac()->assignRole($uid, $this->rbac()->seedRoleWith($key, 'global'));

        return $uid;
    }

    /** Render Admin_index.tpl with the flags Admin::index() assembles. */
    private static function renderDashboard(bool $isOrkAdmin, array $caps, array $trend = []): string
    {
        $IsOrkAdmin           = $isOrkAdmin;
        $GlobalCaps           = $caps;
        $ActiveKingdomSummary = ['ActiveKingdomsSummaryList' => []];
        $TotalActivePlayers   = 0;
        $TrendStats           = $trend;
        $PrevWeekly           = [];
        $PrevMonthly          = [];
        ob_start();
        include ORK3_ROOT . '/orkui/template/revised-frontend/Admin_index.tpl';
        return (string) ob_get_clean();
    }

    private static function renderGlobalPermissions(bool $canGrant): string
    {
        $PermCanGrantAdmin = $canGrant;
        $AdminAuths = [[
            'AuthorizationId' => 9001, 'MundaneId' => 42, 'Persona' => 'Some Admin',
            'GivenName' => '', 'Surname' => '', 'UserName' => 'someadmin',
            'LastLogin' => null, 'LastCredit' => null, 'Modified' => null,
        ]];
        $Kingdoms = [['KingdomId' => 1, 'KingdomName' => 'Test Kingdom']];
        ob_start();
        include ORK3_ROOT . '/orkui/template/default/Admin_permissions_global.tpl';
        return (string) ob_get_clean();
    }

    /**
     * Every registry global key is classified: it opens the dashboard (it has a tile)
     * or it is explicitly parked as tile-less. A new key landing in neither list fails.
     */
    public function testCapabilityKeysMirrorTheRegistryGlobalBlock(): void
    {
        $keys = self::controller()::keys();
        $parked = self::controller()::keysWithoutTile();
        self::assertSame([], array_values(array_intersect($keys, $parked)), 'a key cannot be both');
        $all = array_merge($keys, $parked);
        $registry = PermissionRegistry::GetByScope('global');
        sort($all);
        sort($registry);
        self::assertSame($registry, $all);
    }

    /** A key with no tile must not open a dashboard that would show its holder nothing. */
    public function testTilelessGlobalKeyDoesNotAdmit(): void
    {
        foreach (self::controller()::keysWithoutTile() as $key) {
            $uid = $this->seedGlobalKeyHolder($key);
            self::assertFalse(self::controller()->standing($uid), $key . ' has no dashboard tile');
        }
    }

    public function testOneGlobalKeyAdmitsWithoutAnAuthorizationRow(): void
    {
        $uid  = $this->seedGlobalKeyHolder('global.player.merge');
        $caps = self::controller()->caps($uid);

        self::assertTrue($caps['global.player.merge']);
        foreach ($caps as $key => $held) {
            if ($key !== 'global.player.merge') {
                self::assertFalse($held, $key . ' must not follow from a global.player.merge grant');
            }
        }
        self::assertTrue(self::controller()->standing($uid), 'a global-key holder must reach Admin::index()');
    }

    public function testUnprivilegedPlayerIsNotAdmitted(): void
    {
        $uid = $this->rbac()->seedUnprivilegedPlayer('nobody');

        self::assertNotContains(true, self::controller()->caps($uid));
        self::assertFalse(self::controller()->standing($uid));
    }

    /** A scoped (kingdom) assignment of a role carrying a global key is not a global grant. */
    public function testKingdomScopedAssignmentDoesNotAdmit(): void
    {
        $uid = $this->rbac()->seedUnprivilegedPlayer('scoped');
        $this->rbac()->assignRole(
            $uid,
            $this->rbac()->seedRoleWith('global.health.view', 'global'),
            ['kingdom_id' => self::KINGDOM_ID]
        );

        self::assertFalse(self::controller()->standing($uid));
    }

    public function testTrueAdminHoldsEveryCapability(): void
    {
        $uid = $this->rbac()->seedUnprivilegedPlayer('admin');
        $this->rbac()->grantLegacyAuthorization($uid, [], 'admin');

        self::assertNotContains(false, self::controller()->caps($uid));
    }

    public function testMergeHolderSeesOnlyTheMergeTile(): void
    {
        $uid  = $this->seedGlobalKeyHolder('global.player.merge');
        $html = self::renderDashboard(false, self::controller()->caps($uid));

        self::assertStringContainsString("cpOpenModal('cp-mergeplayer-overlay')", $html);
        foreach ([
            "cpOpenModal('cp-createkingdom-overlay')",
            "cpOpenModal('cp-createpark-overlay')",
            "cpOpenModal('cp-createplayer-overlay')",
            "cpOpenModal('cp-moveplayer-overlay')",
            'onclick="bcOpen()"',
            'cpOpenTransferPark()',
            "cpOpenModal('cp-mergepark-overlay')",
            "cpOpenModal('cp-mergeunit-overlay')",
            'href="' . UIR . 'Admin/inactivekingdoms"',
            'href="' . UIR . 'Admin/inactiveparks"',
            'href="' . UIR . 'Admin/permissions"',
            'href="' . UIR . 'Admin/auditlog"',
            'href="' . UIR . 'Admin/serverhealth"',
            'href="' . UIR . 'Reports/release_utilization"',
        ] as $tile) {
            self::assertStringNotContainsString($tile, $html, $tile . ' rendered for a global.player.merge-only holder');
        }

        // Sections with nothing in them do not render at all.
        self::assertStringContainsString('<i class="fas fa-user"></i> Players</div>', $html);
        foreach (['Kingdoms', 'Parks', 'Units &amp; Authorization'] as $section) {
            self::assertStringNotContainsString('</i> ' . $section . '</div>', $html, $section . ' section rendered empty');
        }
        // The trend row is fed by a global.health.view-gated report; empty means not shown.
        self::assertStringNotContainsString('class="cp-ts-row"', $html);
    }

    public function testTrueAdminSeesEveryTile(): void
    {
        $html = self::renderDashboard(true, [], ['awards_cur' => 1]);

        foreach ([
            "cpOpenModal('cp-createkingdom-overlay')",
            "cpOpenModal('cp-createpark-overlay')",
            "cpOpenModal('cp-createplayer-overlay')",
            "cpOpenModal('cp-mergeplayer-overlay')",
            'onclick="bcOpen()"',
            'cpOpenTransferPark()',
            "cpOpenModal('cp-mergeunit-overlay')",
            'href="' . UIR . 'Admin/auditlog"',
            'href="' . UIR . 'Admin/serverhealth"',
            'href="' . UIR . 'Admin/permissions"',
        ] as $tile) {
            self::assertStringContainsString($tile, $html);
        }
        self::assertStringContainsString('class="cp-ts-row"', $html);
    }

    public function testGlobalPermissionsPageIsReadOnlyForAViewHolder(): void
    {
        $html = self::renderGlobalPermissions(false);

        self::assertStringContainsString('Some Admin', $html, 'the holder must still see who holds admin');
        foreach (['Grant System Administrator', 'id="ap-add-btn"', 'id="ap-player-input"', 'class="ap-del" data-id="9001"', 'class="gp-kd-tile"'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle . ' rendered for a view-only holder');
        }
    }

    public function testGlobalPermissionsPageKeepsGrantAndRevokeForATrueAdmin(): void
    {
        $html = self::renderGlobalPermissions(true);

        foreach (['Grant System Administrator', 'id="ap-add-btn"', 'class="ap-del" data-id="9001"', 'class="gp-kd-tile"'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    /**
     * The nav pre-check answers "no" exactly for a player with nothing, and "yes" for
     * each kind of holder, so skipping the standing checks never hides a real link.
     */
    public function testAdministrativeFootprintSeparatesHoldersFromPlayers(): void
    {
        $gate = new AuthorizationGate();

        $nobody = $this->rbac()->seedUnprivilegedPlayer('fp-nobody');
        self::assertFalse($gate->hasAdministrativeFootprint($nobody));

        self::assertTrue($gate->hasAdministrativeFootprint($this->seedGlobalKeyHolder('global.player.merge')), 'an RBAC role holder');

        $legacy = $this->rbac()->seedUnprivilegedPlayer('fp-legacy');
        $this->rbac()->grantLegacyAuthorization($legacy, [], 'admin');
        self::assertTrue($gate->hasAdministrativeFootprint($legacy), 'a legacy authorization row');

        self::assertFalse($gate->hasAdministrativeFootprint(0));
    }

    /** The front doors must ask the capability, not the bare admin row. */
    public function testFrontDoorsUseTheCapabilityGates(): void
    {
        $admin = (string) file_get_contents(ORK3_ROOT . '/orkui/controller/controller.Admin.php');
        $base  = (string) file_get_contents(ORK3_ROOT . '/system/lib/system/class.Controller.php');

        $index = substr($admin, strpos($admin, 'public function index('), 900);
        self::assertStringContainsString('$this->global_capabilities($_uid)', $index);

        $perms = substr($admin, strpos($admin, 'public function permissions('), 1400);
        self::assertStringContainsString("has_permission_or_authority(\$uid, 'global.admin.grant', 'global', 0, AUTH_ADMIN)", $perms);

        self::assertStringContainsString("\$this->data['NavIsOrkAdmin'] = \$_navStanding && \$this->has_ork_admin_standing(\$_uid);", $base);
    }
}
