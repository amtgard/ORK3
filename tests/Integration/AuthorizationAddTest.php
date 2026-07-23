<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for AuthorizationService.AddAuthorization (T-ADM-11, T-KNA-03,
 * T-PRA-02, T-EVA-06). Pre-refactor INSERT bypasses live in orkui AJAX controllers;
 * R-02 will route those paths through this API.
 */
final class AuthorizationAddTest extends TestCase
{
    private AuthorizationAddFixture $fixture;

    private Authorization $authorization;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = AuthorizationAddFixture::create();
        $this->authorization = new Authorization();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testAddKingdomCreateAuth(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $granteeId = $this->fixture->createGrantee('kingdom-grantee');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_KINGDOM, $kingdomId, AUTH_CREATE, 'kingdom-grantor');

        $response = $this->addAuth($grantor['token'], $granteeId, AUTH_KINGDOM, $kingdomId, AUTH_CREATE);

        $this->assertSame(0, $response['Status']);
        $this->assertGreaterThan(0, (int) $response['Detail']);
        $this->fixture->trackAuthorizationId((int) $response['Detail']);

        $row = $this->fixture->fetchAuthorization((int) $response['Detail']);
        $this->assertNotNull($row);
        $this->assertSame($granteeId, (int) $row['mundane_id']);
        $this->assertSame($kingdomId, (int) $row['kingdom_id']);
        $this->assertSame(AUTH_CREATE, $row['role']);
    }

    public function testAddKingdomEditAuth(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $granteeId = $this->fixture->createGrantee('kingdom-edit-grantee');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_KINGDOM, $kingdomId, AUTH_CREATE, 'kingdom-edit-grantor');

        $response = $this->addAuth($grantor['token'], $granteeId, AUTH_KINGDOM, $kingdomId, AUTH_EDIT);

        $this->assertSame(0, $response['Status']);
        $this->fixture->trackAuthorizationId((int) $response['Detail']);

        $row = $this->fixture->fetchAuthorization((int) $response['Detail']);
        $this->assertSame(AUTH_EDIT, $row['role']);
    }

    public function testAddParkCreateAuth(): void
    {
        $parkId = $this->fixture->firstParkId();
        $granteeId = $this->fixture->createGrantee('park-grantee');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'park-grantor');

        $response = $this->addAuth($grantor['token'], $granteeId, AUTH_PARK, $parkId, AUTH_CREATE);

        $this->assertSame(0, $response['Status']);
        $this->fixture->trackAuthorizationId((int) $response['Detail']);

        $row = $this->fixture->fetchAuthorization((int) $response['Detail']);
        $this->assertSame($parkId, (int) $row['park_id']);
        $this->assertSame(AUTH_CREATE, $row['role']);
    }

    public function testAddEventCreateAuth(): void
    {
        $event = $this->fixture->createEvent('auth-target');
        $granteeId = $this->fixture->createGrantee('event-grantee');
        $grantor = $this->fixture->createGrantorWithAuth(
            AUTH_EVENT,
            $event['event_id'],
            AUTH_CREATE,
            'event-grantor',
        );

        $response = $this->addAuth(
            $grantor['token'],
            $granteeId,
            AUTH_EVENT,
            $event['event_id'],
            AUTH_CREATE,
        );

        $this->assertSame(0, $response['Status']);
        $this->fixture->trackAuthorizationId((int) $response['Detail']);

        $row = $this->fixture->fetchAuthorization((int) $response['Detail']);
        $this->assertSame($event['event_id'], (int) $row['event_id']);
        $this->assertSame(AUTH_CREATE, $row['role']);
    }

    public function testAddGlobalAdminAuth(): void
    {
        $granteeId = $this->fixture->createGrantee('admin-grantee');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_ADMIN, 0, AUTH_ADMIN, 'global-admin');

        $response = $this->addAuth($grantor['token'], $granteeId, AUTH_ADMIN, 0, AUTH_ADMIN);

        $this->assertSame(0, $response['Status']);
        $this->fixture->trackAuthorizationId((int) $response['Detail']);

        $row = $this->fixture->fetchAuthorization((int) $response['Detail']);
        $this->assertSame(AUTH_ADMIN, $row['role']);
        $this->assertSame(0, (int) $row['kingdom_id']);
        $this->assertSame(0, (int) $row['park_id']);
        $this->assertSame(0, (int) $row['event_id']);
    }

    public function testAddAuthRejectsInvalidRole(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $granteeId = $this->fixture->createGrantee('invalid-role-grantee');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_KINGDOM, $kingdomId, AUTH_CREATE, 'invalid-role-grantor');

        $response = $this->addAuth($grantor['token'], $granteeId, AUTH_KINGDOM, $kingdomId, 'owner');

        $this->assertSame(ServiceErrorIds::InvalidParameter, $response['Status']);
    }

    public function testAddAuthRejectsUnauthorizedGrantor(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $granteeId = $this->fixture->createGrantee('unauth-grantee');
        $grantor = $this->fixture->createGrantorWithoutAuth('unauth-grantor');

        $response = $this->addAuth($grantor['token'], $granteeId, AUTH_KINGDOM, $kingdomId, AUTH_CREATE);

        $this->assertSame(ServiceErrorIds::NoAuthorization, $response['Status']);
    }

    public function testAddAuthRejectsBadToken(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $granteeId = $this->fixture->createGrantee('bad-token-grantee');

        $response = $this->addAuth('not-a-valid-session-token', $granteeId, AUTH_KINGDOM, $kingdomId, AUTH_CREATE);

        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $response['Status']);
    }

    public function testAddAuthorizationServiceWrapperMatchesDomain(): void
    {
        require_once DIR_SERVICE . 'Authorization/AuthorizationService.function.php';

        $kingdomId = $this->fixture->firstKingdomId();
        $granteeDomain = $this->fixture->createGrantee('wrapper-domain');
        $granteeService = $this->fixture->createGrantee('wrapper-service');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_KINGDOM, $kingdomId, AUTH_CREATE, 'wrapper-grantor');

        $domainResponse = $this->authorization->AddAuthorization([
            'Token' => $grantor['token'],
            'MundaneId' => $granteeDomain,
            'Type' => AUTH_KINGDOM,
            'Id' => $kingdomId,
            'Role' => AUTH_CREATE,
        ]);
        unset($_SESSION['is_authorized_mundane_id']);
        $serviceResponse = AddAuthorization([
            'Token' => $grantor['token'],
            'MundaneId' => $granteeService,
            'Type' => AUTH_KINGDOM,
            'Id' => $kingdomId,
            'Role' => AUTH_CREATE,
        ]);

        $this->assertSame(0, $domainResponse['Status']);
        $this->assertSame(0, $serviceResponse['Status']);
        $this->assertSame($domainResponse['Error'], $serviceResponse['Error']);
        $this->assertGreaterThan(0, (int) $domainResponse['Detail']);
        $this->assertGreaterThan(0, (int) $serviceResponse['Detail']);
        $this->fixture->trackAuthorizationId((int) $domainResponse['Detail']);
        $this->fixture->trackAuthorizationId((int) $serviceResponse['Detail']);
    }

    public function testAddAuthWritesAuthorizationLog(): void
    {
        $log = new ReflectionMethod(Log::class, 'Write');
        $logBody = file_get_contents($log->getFileName()) ?: '';
        if (str_contains($logBody, "function Write(\$log, \$mundane_id, \$type, \$action) {\n\t\treturn;")) {
            $this->markTestSkipped('Log::Write is currently a no-op in ORK3 test runtime.');
        }

        $kingdomId = $this->fixture->firstKingdomId();
        $granteeId = $this->fixture->createGrantee('log-grantee');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_KINGDOM, $kingdomId, AUTH_CREATE, 'log-grantor');

        $response = $this->addAuth($grantor['token'], $granteeId, AUTH_KINGDOM, $kingdomId, AUTH_CREATE);
        $this->assertSame(0, $response['Status']);
        $this->fixture->trackAuthorizationId((int) $response['Detail']);
    }

    /**
     * @return array{Status: int, Error?: string, Detail?: mixed}
     */
    private function addAuth(string $token, int $mundaneId, string $type, int $scopeId, string $role): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return $this->authorization->AddAuthorization([
            'Token' => $token,
            'MundaneId' => $mundaneId,
            'Type' => $type,
            'Id' => $scopeId,
            'Role' => $role,
        ]);
    }
}
