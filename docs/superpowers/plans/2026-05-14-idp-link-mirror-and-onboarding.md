# IDP Link Mirror & ORK→IDP Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the existing IDP mirror loop and add a seamless ORK→IDP onboarding banner so a legacy-logged-in ORK user can set up "Sign in with Amtgard" without leaving the linked flow.

**Architecture:** Two repos, two deltas. **Delta 1**: a new `POST /resources/link-ork-profile` endpoint on the `idp-tobias` fork that ORK's existing `Model_AmtgardIdpLink` already calls — closes the loop the prior 04-13 design committed ORK to. **Delta 2**: a dashboard banner on ORK that mints a short-lived signed JWT and hands it off to a new `/auth/connect` page on the IDP, which lets the user log in or register with their email pre-filled and writes the link using the JWT's `sub` claim. The JWT is HS256, 15 minutes, single-use via a `jti` table on the IDP.

**Tech Stack:** PHP 8 on both sides. ORK runs raw PHP (no composer) — the JWT signer is an inline ~25-line helper. IDP runs Slim + Phinx + `firebase/php-jwt` (already in composer.json) + Twig + PHPUnit. Mirror endpoint uses HTTP Basic auth with the existing confidential-client credentials — a new `ConfidentialClientBasicAuthMiddleware` accepts the same `Authorization: Basic` header ORK is already sending today.

**Source spec:** [`docs/superpowers/specs/2026-05-14-idp-link-mirror-and-onboarding-design.md`](../specs/2026-05-14-idp-link-mirror-and-onboarding-design.md).

---

## Repos and branches

Two repos. Same branch name on both, so the work correlates by name:

- **`~/GitHub/ORK-tobias/ORK3-tobias`** — branch `feature/login-with-amtgard-workflow` (already at `c494d194`)
- **`~/GitHub/idp-tobias`** — branch `feature/login-with-amtgard-workflow` (already created off `upstream/main`)

Each task tags itself with `[ORK]` or `[IDP]` so you know which working tree to be in.

---

## File Structure

**Files created**

*IDP side:*
- `db/migrations/20260514120000_add_user_ork_profiles_linked_via.php` — new `linked_via` column on `user_ork_profiles`
- `db/migrations/20260514120100_create_link_token_jti.php` — replay protection table
- `src/Middleware/ConfidentialClientBasicAuthMiddleware.php` — HTTP Basic auth gate for the mirror endpoint
- `src/Services/OrkLinkTokenService.php` — verifies the ORK-signed JWT, records `jti`
- `src/Services/RegistrationService.php` — extraction of `AuthController::register` body so the connect path can reuse it
- `src/Controllers/Client/ConnectController.php` — `GET /auth/connect` + the two POST handlers
- `templates/connect.twig` — tabbed Log In / Register page
- `tests/Services/OrkLinkTokenServiceTest.php`
- `tests/Controllers/ConnectControllerTest.php`
- `tests/Controllers/LinkOrkProfileTest.php`

*ORK side:*
- `orkui/template/default/Home_idp_nudge.tpl` — banner partial included from `default.tpl`

**Files modified**

*IDP side:*
- `src/Persistence/Client/Repositories/UserOrkProfileRepository.php` — add `linkExistingUserToMundane`; existing writes set `linked_via='self_form'`
- `src/Controllers/Resource/ResourcesController.php` — add `linkOrkProfile` method
- `src/Controllers/Client/AuthController.php` — delegate the `register` body to `RegistrationService`
- `config/routes.php` — register four new routes
- `.env.example` — add `ORK_LINK_TOKEN_SECRET` and document the confidential-client expectation

*ORK side:*
- `system/lib/ork3/class.Authorization.php` — add `mintIdpLinkToken($mundaneId, $email)` plus a tiny inline HS256 signer
- `orkui/controller/controller.Login.php` — add `start_idp_connect` and `nudge_dismiss` actions
- `system/lib/system/class.Controller.php` — load `IdpLinked` flag in the base `index()` (post-login home)
- `orkui/template/default/default.tpl` — include the banner partial near the top of the home page when conditions match

---

## Hard rules carried into every task

From project memory and the existing `2026-04-13` plan:

- **Multi-line PHP edits MUST use Python, never the Edit tool.** ORK files use tabs; the Edit tool can't reliably match tab indentation. Pattern: `python3 -c "import pathlib; p=pathlib.Path('file'); t=p.read_text(); print('found:', 'NEEDLE' in t); p.write_text(t.replace(OLD, NEW, 1))"`. The IDP files use spaces and Edit is fine there.
- **`$DB->Clear()` before every raw `Execute`** on the ORK side. Stale PDO bindings cause silent insert failures.
- **`die()` for ORK debugging goes AFTER the write**, never before — die() before a save blocks the save.
- **Never stage `class.Authorization.php` with `git add -A`** — the file has a `true ||` login bypass hack at lines 327/330. Always stage explicit files.
- **All new ORK debug output to browser console.** Pattern: `die(json_encode([...]))`. Don't add new `error_log` lines.
- **ORK migration runner**: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/<file>.sql` (MariaDB, not MySQL).
- **Headings in new ORK templates** must reset the global `h1–h6` styles per the orkui.css rule. Apply `background: transparent; border: none; padding: 0; border-radius: 0;` on any heading inside the banner.
- **Dark-mode compatible from the start.** Banner must work in dark mode without follow-up. Test before declaring done.
- **PR title convention**: `Bugfix:` or `Enhancement:` (use `Enhancement:` for everything here).
- **IDP migration runner**: `docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phinx migrate` (or whatever the dev compose command turns out to be — verify in Task 1's manual step).

---

## Configuration (do this once, before Task 1)

**Generate the shared secret:**

```bash
openssl rand -base64 48
```

Take the output and put it in both `.env` files:

- ORK: edit your local `.env` or wherever the existing IDP-related constants live (e.g., where `IDP_API_URL`, `IDP_CLIENT_ID`, `IDP_CLIENT_SECRET` are defined) and add:
  ```
  IDP_LINK_TOKEN_SECRET=<the-base64-output>
  ```
- IDP: edit `~/GitHub/idp-tobias/.env` and add:
  ```
  ORK_LINK_TOKEN_SECRET=<same-base64-output>
  ```

The two values **must match byte-for-byte**. Test in Task 12.

---

## Task 1: [IDP] Phinx migration for `linked_via` column

**Files:**
- Create: `db/migrations/20260514120000_add_user_ork_profiles_linked_via.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUserOrkProfilesLinkedVia extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_ork_profiles')
            ->addColumn('linked_via', 'enum', [
                'values' => ['self_form', 'ork_handoff', 'mirror'],
                'default' => 'self_form',
                'after' => 'user_id',
            ])
            ->update();
    }
}
```

- [ ] **Step 2: Run the migration**

```bash
cd ~/GitHub/idp-tobias
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phinx migrate
```

Expected: `AddUserOrkProfilesLinkedVia: migrated` line.

If the `phpfpm` service name is different, run `docker compose -f docker-compose.dev.yml ps` to find the right one. If Phinx isn't directly invokable, try `vendor/bin/phinx migrate` from inside the container interactively. Document the exact command used in this task before committing.

- [ ] **Step 3: Verify the column exists**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "DESCRIBE user_ork_profiles;" | grep linked_via
```

Expected: a line showing `linked_via enum('self_form','ork_handoff','mirror')`.

- [ ] **Step 4: Commit**

```bash
cd ~/GitHub/idp-tobias
git add db/migrations/20260514120000_add_user_ork_profiles_linked_via.php
git commit -m "Migration: Add linked_via column to user_ork_profiles"
```

---

## Task 2: [IDP] Phinx migration for `link_token_jti` table

**Files:**
- Create: `db/migrations/20260514120100_create_link_token_jti.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateLinkTokenJti extends AbstractMigration
{
    public function change(): void
    {
        $this->table('link_token_jti', ['id' => false, 'primary_key' => ['jti']])
            ->addColumn('jti', 'char', ['limit' => 36])
            ->addColumn('seen_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['seen_at'])
            ->create();
    }
}
```

- [ ] **Step 2: Run the migration**

```bash
cd ~/GitHub/idp-tobias
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phinx migrate
```

Expected: `CreateLinkTokenJti: migrated`.

- [ ] **Step 3: Verify the table**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "DESCRIBE link_token_jti;"
```

Expected: two columns (`jti CHAR(36) PK`, `seen_at DATETIME`).

- [ ] **Step 4: Commit**

```bash
cd ~/GitHub/idp-tobias
git add db/migrations/20260514120100_create_link_token_jti.php
git commit -m "Migration: Add link_token_jti replay-protection table"
```

---

## Task 3: [IDP] `ConfidentialClientBasicAuthMiddleware`

**Files:**
- Create: `src/Middleware/ConfidentialClientBasicAuthMiddleware.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Middleware/ConfidentialClientBasicAuthMiddlewareTest.php`:

```php
<?php

namespace Tests\Middleware;

use Amtgard\IdP\Middleware\ConfidentialClientBasicAuthMiddleware;
use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\AuthorizedClients;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class ConfidentialClientBasicAuthMiddlewareTest extends TestCase
{
    private function build($clientRepo, $authorized)
    {
        return new ConfidentialClientBasicAuthMiddleware($clientRepo, $authorized);
    }

    public function test_no_header_rejects(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $repo = $this->createMock(ClientRepository::class);
        $authorized = $this->createMock(AuthorizedClients::class);
        $this->build($repo, $authorized)->process($req, $handler);
    }

    public function test_bad_secret_rejects(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withHeader('Authorization', 'Basic ' . base64_encode('ork:wrong'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('validateClient')->willReturn(false);
        $authorized = $this->createMock(AuthorizedClients::class);
        $authorized->method('getClientIds')->willReturn(['ork']);
        $this->build($repo, $authorized)->process($req, $handler);
    }

    public function test_unauthorized_client_id_rejects(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withHeader('Authorization', 'Basic ' . base64_encode('intruder:right'));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('validateClient')->willReturn(true);
        $authorized = $this->createMock(AuthorizedClients::class);
        $authorized->method('getClientIds')->willReturn(['ork']);
        $this->build($repo, $authorized)->process($req, $handler);
    }

    public function test_authorized_client_passes_through(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withHeader('Authorization', 'Basic ' . base64_encode('ork:right'));
        $response = (new ResponseFactory())->createResponse(204);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('validateClient')->willReturn(true);
        $authorized = $this->createMock(AuthorizedClients::class);
        $authorized->method('getClientIds')->willReturn(['ork']);
        $result = $this->build($repo, $authorized)->process($req, $handler);
        $this->assertEquals(204, $result->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ~/GitHub/idp-tobias
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Middleware/ConfidentialClientBasicAuthMiddlewareTest.php
```

Expected: 4 failures with "Class Amtgard\IdP\Middleware\ConfidentialClientBasicAuthMiddleware not found".

- [ ] **Step 3: Implement the middleware**

```php
<?php

namespace Amtgard\IdP\Middleware;

use Amtgard\IdP\Persistence\Server\Repositories\ClientRepository;
use Amtgard\IdP\Utility\AuthorizedClients;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpUnauthorizedException;

class ConfidentialClientBasicAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ClientRepository $clientRepository,
        private AuthorizedClients $authorizedClients,
    ) {}

    public function process(Request $request, RequestHandler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Basic\s+(.+)$/i', $header, $matches)) {
            throw new HttpUnauthorizedException($request, 'Confidential client credentials required.');
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            throw new HttpUnauthorizedException($request, 'Malformed credentials.');
        }

        [$clientId, $clientSecret] = explode(':', $decoded, 2);

        if (!in_array($clientId, $this->authorizedClients->getClientIds(), true)) {
            throw new HttpUnauthorizedException($request, 'Client not authorized for this endpoint.');
        }

        if (!$this->clientRepository->validateClient($clientId, $clientSecret, 'confidential_basic')) {
            throw new HttpUnauthorizedException($request, 'Invalid client credentials.');
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd ~/GitHub/idp-tobias
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Middleware/ConfidentialClientBasicAuthMiddlewareTest.php
```

Expected: 4 passes.

- [ ] **Step 5: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Middleware/ConfidentialClientBasicAuthMiddleware.php tests/Middleware/ConfidentialClientBasicAuthMiddlewareTest.php
git commit -m "Enhancement: ConfidentialClientBasicAuthMiddleware for S2S endpoints"
```

---

## Task 4: [IDP] `UserOrkProfileRepository::linkExistingUserToMundane`

**Files:**
- Modify: `src/Persistence/Client/Repositories/UserOrkProfileRepository.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Persistence/UserOrkProfileRepositoryLinkTest.php`:

```php
<?php

namespace Tests\Persistence;

use Amtgard\IdP\Persistence\Client\Repositories\UserOrkProfileRepository;
use PHPUnit\Framework\TestCase;

class UserOrkProfileRepositoryLinkTest extends TestCase
{
    public function test_link_creates_row_when_none_exists(): void
    {
        $repo = $this->createUserOrkProfileRepository();
        $userId = $this->createTestUser($repo, 'link-test-1@example.com');
        $repo->linkExistingUserToMundane($userId, 42, 'mirror');
        $row = $repo->findByUserId($userId);
        $this->assertNotNull($row);
        $this->assertEquals(42, $row->getMundaneId());
    }

    public function test_link_is_idempotent_on_same_mundane(): void
    {
        $repo = $this->createUserOrkProfileRepository();
        $userId = $this->createTestUser($repo, 'link-test-2@example.com');
        $repo->linkExistingUserToMundane($userId, 42, 'mirror');
        $repo->linkExistingUserToMundane($userId, 42, 'mirror');
        $count = $this->countProfilesForUser($repo, $userId);
        $this->assertEquals(1, $count);
    }

    public function test_link_throws_conflict_on_different_mundane(): void
    {
        $repo = $this->createUserOrkProfileRepository();
        $userId = $this->createTestUser($repo, 'link-test-3@example.com');
        $repo->linkExistingUserToMundane($userId, 42, 'mirror');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflict');
        $repo->linkExistingUserToMundane($userId, 99, 'mirror');
    }

    // The fixture helpers below should use the existing test bootstrap pattern
    // in tests/Controllers/AuthControllerTest.php. If a TestCase base class
    // already provides DB setup, use it; otherwise inline a minimal PDO bootstrap
    // pointing at the test schema.
    private function createUserOrkProfileRepository(): UserOrkProfileRepository { /* per existing test bootstrap */ }
    private function createTestUser(UserOrkProfileRepository $repo, string $email): int { /* insert into users via test PDO, return id */ }
    private function countProfilesForUser(UserOrkProfileRepository $repo, int $userId): int { /* SELECT COUNT(*) FROM user_ork_profiles WHERE user_id = ? */ }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd ~/GitHub/idp-tobias
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Persistence/UserOrkProfileRepositoryLinkTest.php
```

Expected: errors about `linkExistingUserToMundane not defined`.

- [ ] **Step 3: Read the existing repository to find the right insertion point**

```bash
grep -n "function\|class " ~/GitHub/idp-tobias/src/Persistence/Client/Repositories/UserOrkProfileRepository.php
```

Add the new method after `saveOrUpdateProfile` (around line 67). The existing `saveOrUpdateProfile` does an upsert keyed by `user_id` — mirror its pattern.

- [ ] **Step 4: Implement the method**

In `src/Persistence/Client/Repositories/UserOrkProfileRepository.php`, add after `saveOrUpdateProfile`:

```php
/**
 * Idempotently link an existing IDP user to an ORK mundane.
 *
 * @throws \RuntimeException on conflict (user is already linked to a different mundane).
 */
public function linkExistingUserToMundane(int $userId, int $mundaneId, string $linkedVia): void
{
    $existing = $this->findByUserId($userId);
    if ($existing !== null) {
        if ((int)$existing->getMundaneId() === $mundaneId) {
            return; // idempotent
        }
        throw new \RuntimeException(
            "conflict: user_id={$userId} is already linked to mundane_id={$existing->getMundaneId()}, refusing to relink to {$mundaneId}"
        );
    }

    $now = (new \DateTime())->format('Y-m-d H:i:s');
    $sql = "INSERT INTO user_ork_profiles
                (user_id, linked_via, ork_token, mundane_id, username, persona, suspended, email, park_id, kingdom_id, created_at, updated_at)
            VALUES (:user_id, :linked_via, '', :mundane_id, '', '', 0, NULL, NULL, NULL, :now, :now)";
    $stmt = $this->getPdo()->prepare($sql);
    $stmt->execute([
        ':user_id'    => $userId,
        ':linked_via' => $linkedVia,
        ':mundane_id' => $mundaneId,
        ':now'        => $now,
    ]);
}
```

If `getPdo()` is not the accessor the existing Repository base class exposes, look for whatever method `saveOrUpdateProfile` uses to get the PDO/connection and reuse the same accessor.

- [ ] **Step 5: Run the test to verify it passes**

```bash
cd ~/GitHub/idp-tobias
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Persistence/UserOrkProfileRepositoryLinkTest.php
```

Expected: 3 passes.

- [ ] **Step 6: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Persistence/Client/Repositories/UserOrkProfileRepository.php tests/Persistence/UserOrkProfileRepositoryLinkTest.php
git commit -m "Enhancement: UserOrkProfileRepository::linkExistingUserToMundane"
```

---

## Task 5: [IDP] `ResourcesController::linkOrkProfile` method

**Files:**
- Modify: `src/Controllers/Resource/ResourcesController.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Controllers/LinkOrkProfileTest.php`:

```php
<?php

namespace Tests\Controllers;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class LinkOrkProfileTest extends TestCase
{
    /**
     * Boot the application using the same bootstrap as AuthControllerTest does,
     * inject the test PDO, and invoke the route handler directly.
     */
    private function app() { /* per existing AuthControllerTest pattern */ }

    public function test_happy_path_writes_link_and_returns_204(): void
    {
        $app  = $this->app();
        $userId = $this->seedUser($app, 'link-happy@example.com');
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody(['idp_user_id' => $userId, 'mundane_id' => 4242]);
        $resp = $app->handle($req);
        $this->assertEquals(204, $resp->getStatusCode());
        $this->assertEquals(4242, $this->fetchMundaneId($app, $userId));
    }

    public function test_missing_fields_returns_400(): void
    {
        $app = $this->app();
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withParsedBody(['idp_user_id' => 1]); // missing mundane_id
        $resp = $app->handle($req);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function test_unknown_user_returns_404(): void
    {
        $app = $this->app();
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withParsedBody(['idp_user_id' => 999999, 'mundane_id' => 1]);
        $resp = $app->handle($req);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function test_conflict_returns_409(): void
    {
        $app = $this->app();
        $userId = $this->seedUser($app, 'link-conflict@example.com');
        $req1 = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withParsedBody(['idp_user_id' => $userId, 'mundane_id' => 1]);
        $app->handle($req1);
        $req2 = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withParsedBody(['idp_user_id' => $userId, 'mundane_id' => 2]);
        $resp = $app->handle($req2);
        $this->assertEquals(409, $resp->getStatusCode());
    }

    public function test_idempotent_returns_204_twice(): void
    {
        $app = $this->app();
        $userId = $this->seedUser($app, 'link-idempotent@example.com');
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/resources/link-ork-profile')
            ->withParsedBody(['idp_user_id' => $userId, 'mundane_id' => 7]);
        $r1 = $app->handle($req);
        $r2 = $app->handle($req);
        $this->assertEquals(204, $r1->getStatusCode());
        $this->assertEquals(204, $r2->getStatusCode());
    }

    private function seedUser($app, string $email): int { /* INSERT into users via test PDO, return id */ }
    private function fetchMundaneId($app, int $userId): ?int { /* SELECT mundane_id ... */ }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Controllers/LinkOrkProfileTest.php
```

Expected: route not found, all five tests fail.

- [ ] **Step 3: Add the method to ResourcesController**

Open `src/Controllers/Resource/ResourcesController.php`. Inject the `UserRepository` if not already (the existing class already imports `UserOrkProfileRepository`). Add this method below `revokeAuthorization`:

```php
public function linkOrkProfile(Request $request, Response $response): Response
{
    $body = (array) $request->getParsedBody();
    $idpUserId = isset($body['idp_user_id']) ? (int)$body['idp_user_id'] : 0;
    $mundaneId = isset($body['mundane_id'])  ? (int)$body['mundane_id']  : 0;

    if ($idpUserId <= 0 || $mundaneId <= 0) {
        $response->getBody()->write(json_encode(['error' => 'idp_user_id and mundane_id are required positive integers']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    $user = $this->users->findById($idpUserId);
    if (!$user) {
        $response->getBody()->write(json_encode(['error' => 'unknown idp_user_id']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    try {
        $this->orkProfileRepository->linkExistingUserToMundane($idpUserId, $mundaneId, 'mirror');
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'conflict')) {
            $this->logger->warning('linkOrkProfile conflict', ['idp_user_id' => $idpUserId, 'mundane_id' => $mundaneId, 'msg' => $e->getMessage()]);
            $response->getBody()->write(json_encode(['error' => 'idp_user_id already linked to a different mundane_id']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }
        throw $e;
    }

    return $response->withStatus(204);
}
```

The `$this->users` reference assumes a `UserRepository` is on the controller. If it isn't, look at `linkOrkAccount` above for how to wire one in via the constructor and DI container (`bootstrap/dependencies.php`). Don't invent a new repository — use whatever is already used to look up users by id.

- [ ] **Step 4: Run the tests, iterate until green**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Controllers/LinkOrkProfileTest.php
```

Expected: 5 passes.

- [ ] **Step 5: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Controllers/Resource/ResourcesController.php tests/Controllers/LinkOrkProfileTest.php
git commit -m "Enhancement: linkOrkProfile resource for ORK→IDP mirror writes"
```

---

## Task 6: [IDP] Register the mirror route

**Files:**
- Modify: `config/routes.php`
- Modify: `bootstrap/dependencies.php` (if needed to wire the new middleware)

- [ ] **Step 1: Register the route**

Open `~/GitHub/idp-tobias/config/routes.php`. Find the existing `/resources` group (around line 40-50) and add this entry next to the others:

```php
$group->post('/link-ork-profile', [ResourcesController::class, 'linkOrkProfile'])
    ->add(ConfidentialClientBasicAuthMiddleware::class)
    ->setName('resources.link_ork_profile');
```

Also add the import at the top of the file alongside the existing middleware imports:

```php
use Amtgard\IdP\Middleware\ConfidentialClientBasicAuthMiddleware;
```

- [ ] **Step 2: Wire the middleware in DI**

Find `bootstrap/dependencies.php` (or wherever middlewares are wired — look at how `ClientRestrictedAuthMiddleware` is bound). Add a binding for `ConfidentialClientBasicAuthMiddleware` that constructs it with `ClientRepository` and `AuthorizedClients` dependencies — both already available in the container.

```php
ConfidentialClientBasicAuthMiddleware::class => function (ContainerInterface $c) {
    return new ConfidentialClientBasicAuthMiddleware(
        $c->get(ClientRepository::class),
        $c->get(AuthorizedClients::class),
    );
},
```

- [ ] **Step 3: Confirm `AuthorizedClients::getClientIds` returns the ORK confidential client ID**

```bash
grep -rn "ORK_CLIENT_ID\|getClientIds" ~/GitHub/idp-tobias/src/Utility/AuthorizedClients.php ~/GitHub/idp-tobias/config/ 2>&1 | head -10
```

The ORK confidential client's `identifier` must appear in whatever list `AuthorizedClients::getClientIds()` returns. If the list is configured via env, ensure `.env` has the right value. If hardcoded, ensure the ORK client identifier is present.

- [ ] **Step 4: Smoke test with curl**

Start the IDP locally (`docker compose -f docker-compose.dev.yml up -d`). Then:

```bash
curl -i -X POST http://localhost:37080/resources/link-ork-profile \
  -u "<ORK_CLIENT_ID>:<ORK_CLIENT_SECRET>" \
  -H "Content-Type: application/json" \
  -d '{"idp_user_id": <some-existing-id>, "mundane_id": 4242}'
```

Expected: `HTTP/1.1 204 No Content`.

Without credentials:
```bash
curl -i -X POST http://localhost:37080/resources/link-ork-profile \
  -H "Content-Type: application/json" \
  -d '{"idp_user_id": 1, "mundane_id": 4242}'
```

Expected: `HTTP/1.1 401 Unauthorized`.

- [ ] **Step 5: Commit**

```bash
cd ~/GitHub/idp-tobias
git add config/routes.php bootstrap/dependencies.php
git commit -m "Enhancement: Register POST /resources/link-ork-profile route"
```

---

## Task 7: [ORK] Verify the existing mirror caller now succeeds

This is a verification task only — the ORK-side code already exists from earlier in the branch.

- [ ] **Step 1: Trigger an ORK→IDP claim**

Sign out of ORK. Go to `http://localhost:19080/orkui/Login`. Click "Sign in with Amtgard". Complete an IDP login that matches an unlinked ORK mundane email (so auto-link fires). Land on dashboard.

- [ ] **Step 2: Check `ork_idp_auth.idp_mirror_status`**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT idp_user_id, mundane_id, idp_mirror_status, idp_mirror_last_attempt FROM ork_idp_auth ORDER BY idp_mirror_last_attempt DESC LIMIT 1;"
```

Expected: `idp_mirror_status = 'synced'`. If it shows `failed`, the bug is in Tasks 3–6 — investigate `cron/idp-mirror-retry.php` logs or `Model_AmtgardIdpLink::linkOrkProfile`'s error_log output.

- [ ] **Step 3: Check the IDP side**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "SELECT user_id, mundane_id, linked_via FROM user_ork_profiles ORDER BY updated_at DESC LIMIT 1;"
```

Expected: a row with the test's `user_id`, the right `mundane_id`, and `linked_via = 'mirror'`.

- [ ] **Step 4: No commit**

This task only verifies.

---

## Task 8: [IDP] `OrkLinkTokenService`

**Files:**
- Create: `src/Services/OrkLinkTokenService.php`
- Create: `tests/Services/OrkLinkTokenServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Services;

use Amtgard\IdP\Services\OrkLinkTokenService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

class OrkLinkTokenServiceTest extends TestCase
{
    private const SECRET = 'test-secret-must-match-ork-side-32chars-min';

    private function buildToken(array $overrides = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss' => 'ork',
            'aud' => 'idp',
            'sub' => '42',
            'email' => 'alice@example.com',
            'iat' => $now,
            'exp' => $now + 900,
            'jti' => bin2hex(random_bytes(18)),
        ], $overrides);
        return JWT::encode($payload, self::SECRET, 'HS256');
    }

    private function service(): OrkLinkTokenService
    {
        return new OrkLinkTokenService(self::SECRET, $this->jtiPdo());
    }

    public function test_valid_token_returns_claims(): void
    {
        $result = $this->service()->verify($this->buildToken());
        $this->assertNotNull($result);
        $this->assertEquals(42, $result['mundane_id']);
        $this->assertEquals('alice@example.com', $result['email']);
    }

    public function test_bad_signature_returns_null(): void
    {
        $jwt = $this->buildToken();
        $parts = explode('.', $jwt);
        $parts[2] = strtr(base64_encode('garbage'), '+/', '-_');
        $this->assertNull($this->service()->verify(implode('.', $parts)));
    }

    public function test_wrong_iss_returns_null(): void
    {
        $this->assertNull($this->service()->verify($this->buildToken(['iss' => 'not-ork'])));
    }

    public function test_wrong_aud_returns_null(): void
    {
        $this->assertNull($this->service()->verify($this->buildToken(['aud' => 'not-idp'])));
    }

    public function test_expired_returns_null(): void
    {
        $past = time() - 1000;
        $this->assertNull($this->service()->verify($this->buildToken(['iat' => $past - 900, 'exp' => $past])));
    }

    public function test_replay_returns_null_on_second_call(): void
    {
        $jwt = $this->buildToken();
        $svc = $this->service();
        $this->assertNotNull($svc->verify($jwt));
        $this->assertNull($svc->verify($jwt));
    }

    public function test_non_numeric_sub_returns_null(): void
    {
        $this->assertNull($this->service()->verify($this->buildToken(['sub' => 'not-a-number'])));
    }

    private function jtiPdo(): \PDO { /* per existing test bootstrap, return the test PDO connected to a DB with link_token_jti */ }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Services/OrkLinkTokenServiceTest.php
```

Expected: 7 failures (`Class OrkLinkTokenService not found`).

- [ ] **Step 3: Implement the service**

```php
<?php

namespace Amtgard\IdP\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Psr\Log\LoggerInterface;

class OrkLinkTokenService
{
    public function __construct(
        private string $sharedSecret,
        private \PDO $pdo,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return array{mundane_id: int, email: string}|null
     */
    public function verify(string $jwt): ?array
    {
        try {
            JWT::$leeway = 30;
            $decoded = JWT::decode($jwt, new Key($this->sharedSecret, 'HS256'));
        } catch (ExpiredException $e) {
            $this->log('expired', $e->getMessage());
            return null;
        } catch (SignatureInvalidException $e) {
            $this->log('bad_signature', $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            $this->log('decode_error', $e->getMessage());
            return null;
        }

        if (($decoded->iss ?? null) !== 'ork') {
            $this->log('wrong_iss', $decoded->iss ?? 'null');
            return null;
        }
        if (($decoded->aud ?? null) !== 'idp') {
            $this->log('wrong_aud', $decoded->aud ?? 'null');
            return null;
        }
        if (!isset($decoded->sub) || !ctype_digit((string)$decoded->sub) || (int)$decoded->sub <= 0) {
            $this->log('bad_sub', (string)($decoded->sub ?? 'null'));
            return null;
        }
        if (empty($decoded->jti) || empty($decoded->email)) {
            $this->log('missing_claim', '');
            return null;
        }

        try {
            $stmt = $this->pdo->prepare("INSERT INTO link_token_jti (jti, seen_at) VALUES (:jti, NOW())");
            $stmt->execute([':jti' => $decoded->jti]);
        } catch (\PDOException $e) {
            // 23000 = SQLSTATE for integrity constraint violation (duplicate PK = replay)
            if ($e->getCode() === '23000') {
                $this->log('replay', $decoded->jti);
                return null;
            }
            throw $e;
        }

        return [
            'mundane_id' => (int)$decoded->sub,
            'email'      => (string)$decoded->email,
        ];
    }

    private function log(string $reason, string $detail): void
    {
        $this->logger?->info("OrkLinkTokenService rejected token: reason={$reason} detail={$detail}");
    }
}
```

- [ ] **Step 4: Wire the service in DI**

In `bootstrap/dependencies.php`:

```php
OrkLinkTokenService::class => function (ContainerInterface $c) {
    $secret = $_ENV['ORK_LINK_TOKEN_SECRET'] ?? '';
    if (strlen($secret) < 32) {
        throw new \RuntimeException('ORK_LINK_TOKEN_SECRET is unset or shorter than 32 chars');
    }
    return new OrkLinkTokenService(
        $secret,
        $c->get(\PDO::class),
        $c->get(LoggerInterface::class),
    );
},
```

If `PDO` isn't in the container, look at how `UserOrkProfileRepository` obtains its PDO/connection and pass the same.

- [ ] **Step 5: Run the tests**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Services/OrkLinkTokenServiceTest.php
```

Expected: 7 passes.

- [ ] **Step 6: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Services/OrkLinkTokenService.php tests/Services/OrkLinkTokenServiceTest.php bootstrap/dependencies.php
git commit -m "Enhancement: OrkLinkTokenService verifies ORK-signed handoff JWTs"
```

---

## Task 9: [IDP] Extract registration into `RegistrationService`

Refactor: keep `AuthController::register` behaviorally identical, but move the body into a reusable service so `ConnectController::submitConnectRegister` can call the same flow.

**Files:**
- Create: `src/Services/RegistrationService.php`
- Modify: `src/Controllers/Client/AuthController.php`

- [ ] **Step 1: Read the current `register` method**

```bash
grep -n -A 60 "public function register" ~/GitHub/idp-tobias/src/Controllers/Client/AuthController.php | head -70
```

Note the dependencies it uses: `$this->users` (UserRepository), `$this->logins` (LoginRepository), `$this->finalizeAuthorization` (helper on the controller).

- [ ] **Step 2: Write `RegistrationService`**

```php
<?php

namespace Amtgard\IdP\Services;

use Amtgard\IdP\Persistence\Server\Repositories\UserRepository;
use Amtgard\IdP\Persistence\Server\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Server\Entities\User;
use Amtgard\IdP\Persistence\Server\Entities\UserLogin;

class RegistrationService
{
    public function __construct(
        private UserRepository $users,
        private UserLoginRepository $logins,
    ) {}

    /**
     * @return array{ok: true, user: User, login: UserLogin} on success
     * @return array{ok: false, error: string} on validation failure or duplicate email
     */
    public function register(string $firstName, string $lastName, string $email, string $password): array
    {
        if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
            return ['ok' => false, 'error' => 'All fields are required'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email format'];
        }
        if ($this->users->userExists($email)) {
            return ['ok' => false, 'error' => 'Email already registered'];
        }
        $user  = $this->users->createLocalUser($email, $firstName, $lastName);
        $login = $this->logins->createLocalLogin($user, $password);
        return ['ok' => true, 'user' => $user, 'login' => $login];
    }
}
```

- [ ] **Step 3: Delegate `AuthController::register` to it**

Edit `src/Controllers/Client/AuthController.php`. Inject `RegistrationService` in the constructor next to the existing `$users` / `$logins`. Replace the body of `register()` with:

```php
public function register(Request $request, Response $response): Response
{
    $data = $request->getParsedBody();
    $confirmPassword = $data['confirmPassword'] ?? '';
    $password        = $data['password']        ?? '';

    if ($password !== $confirmPassword) {
        $response->getBody()->write('
            <script>
                alert("Passwords do not match");
                window.location.href = "/auth/register";
            </script>
        ');
        return $response;
    }

    $result = $this->registrationService->register(
        $data['firstName'] ?? '',
        $data['lastName']  ?? '',
        $data['email']     ?? '',
        $password,
    );

    if (!$result['ok']) {
        $response->getBody()->write('
            <script>
                alert(' . json_encode($result['error']) . ');
                window.location.href = "/auth/register";
            </script>
        ');
        return $response;
    }

    return $this->finalizeAuthorization($result['login'], $request, $response);
}
```

- [ ] **Step 4: Wire the service in DI**

In `bootstrap/dependencies.php`:

```php
RegistrationService::class => function (ContainerInterface $c) {
    return new RegistrationService(
        $c->get(UserRepository::class),
        $c->get(UserLoginRepository::class),
    );
},
```

And update `AuthController`'s DI definition to receive it.

- [ ] **Step 5: Smoke-test the existing register flow**

Hit the IDP register form in a browser. Confirm registration still works end-to-end. No behavioral change should be visible.

- [ ] **Step 6: Run existing AuthControllerTest**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Controllers/AuthControllerTest.php
```

Expected: same green status as before this task.

- [ ] **Step 7: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Services/RegistrationService.php src/Controllers/Client/AuthController.php bootstrap/dependencies.php
git commit -m "Refactor: Extract AuthController::register into RegistrationService"
```

---

## Task 10: [IDP] `ConnectController::showConnect`

**Files:**
- Create: `src/Controllers/Client/ConnectController.php`
- Create: `templates/connect.twig`

- [ ] **Step 1: Write the connect template**

`templates/connect.twig`:

```twig
{% extends 'base.twig' %}

{% block content %}
<div class="max-w-md mx-auto mt-12 bg-white rounded-lg shadow p-8">
    <h2 class="text-2xl font-serif font-semibold text-tertiary mb-2">Connect your Amtgard sign-in</h2>
    <p class="text-sm text-gray-600 mb-6">
        Your ORK profile is ready to be linked. Sign in to an existing Amtgard account, or register a new one — either way, we'll connect it to your ORK profile.
    </p>

    {% if error %}
        <div class="bg-red-50 border border-red-200 text-red-800 rounded p-3 mb-4 text-sm">{{ error }}</div>
    {% endif %}

    <div class="flex border-b mb-4">
        <button type="button" id="tab-login" class="px-4 py-2 font-semibold border-b-2 {% if defaultTab == 'login' %}border-primary text-primary{% else %}border-transparent text-gray-500{% endif %}">Log In</button>
        <button type="button" id="tab-register" class="px-4 py-2 font-semibold border-b-2 {% if defaultTab == 'register' %}border-primary text-primary{% else %}border-transparent text-gray-500{% endif %}">Register</button>
    </div>

    <form id="form-login" action="/auth/connect/login" method="POST" class="{% if defaultTab != 'login' %}hidden{% endif %}">
        <input type="hidden" name="link_token" value="{{ link_token }}">
        <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2" for="login-email">Email</label>
            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 bg-gray-100" id="login-email" name="email" type="email" value="{{ email }}" readonly>
        </div>
        <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2" for="login-password">Password</label>
            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700" id="login-password" name="password" type="password" required>
        </div>
        <button class="bg-primary hover:bg-primary-dark text-white font-bold py-2 px-4 rounded w-full" type="submit">Log In &amp; Connect</button>
    </form>

    <form id="form-register" action="/auth/connect/register" method="POST" class="{% if defaultTab != 'register' %}hidden{% endif %}">
        <input type="hidden" name="link_token" value="{{ link_token }}">
        <div class="mb-3"><label class="block text-gray-700 text-sm font-bold mb-1" for="reg-first">First name</label><input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700" id="reg-first" name="firstName" type="text" required></div>
        <div class="mb-3"><label class="block text-gray-700 text-sm font-bold mb-1" for="reg-last">Last name</label><input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700" id="reg-last" name="lastName" type="text" required></div>
        <div class="mb-3"><label class="block text-gray-700 text-sm font-bold mb-1" for="reg-email">Email</label><input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700" id="reg-email" name="email" type="email" value="{{ email }}" required></div>
        <div class="mb-3"><label class="block text-gray-700 text-sm font-bold mb-1" for="reg-password">Password</label><input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700" id="reg-password" name="password" type="password" required></div>
        <div class="mb-4"><label class="block text-gray-700 text-sm font-bold mb-1" for="reg-confirm">Confirm password</label><input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700" id="reg-confirm" name="confirmPassword" type="password" required></div>
        <button class="bg-primary hover:bg-primary-dark text-white font-bold py-2 px-4 rounded w-full" type="submit">Register &amp; Connect</button>
    </form>

    <script>
        document.getElementById('tab-login').addEventListener('click', () => {
            document.getElementById('form-login').classList.remove('hidden');
            document.getElementById('form-register').classList.add('hidden');
            document.getElementById('tab-login').classList.add('border-primary','text-primary');
            document.getElementById('tab-login').classList.remove('border-transparent','text-gray-500');
            document.getElementById('tab-register').classList.remove('border-primary','text-primary');
            document.getElementById('tab-register').classList.add('border-transparent','text-gray-500');
        });
        document.getElementById('tab-register').addEventListener('click', () => {
            document.getElementById('form-register').classList.remove('hidden');
            document.getElementById('form-login').classList.add('hidden');
            document.getElementById('tab-register').classList.add('border-primary','text-primary');
            document.getElementById('tab-register').classList.remove('border-transparent','text-gray-500');
            document.getElementById('tab-login').classList.remove('border-primary','text-primary');
            document.getElementById('tab-login').classList.add('border-transparent','text-gray-500');
        });
    </script>
</div>
{% endblock %}
```

(The class names assume the existing Tailwind-flavored styles in `register_form.twig` / `login_form.twig`. If the existing templates use a different framework, match what they use — don't invent new utility classes.)

- [ ] **Step 2: Create the controller skeleton with `showConnect`**

`src/Controllers/Client/ConnectController.php`:

```php
<?php

namespace Amtgard\IdP\Controllers\Client;

use Amtgard\IdP\Persistence\Server\Repositories\UserRepository;
use Amtgard\IdP\Services\OrkLinkTokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class ConnectController
{
    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private OrkLinkTokenService $tokenService,
    ) {}

    public function showConnect(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $linkToken = $params['link_token'] ?? '';
        $email     = $params['email']      ?? '';

        if (empty($linkToken)) {
            return $this->renderError($response, 'This link is invalid. Return to ORK and start over.');
        }

        // Pre-flight peek WITHOUT consuming jti: decode-only verification of the email claim
        // so we can pick the default tab. The real verify+consume happens on POST.
        $peek = $this->peekToken($linkToken);
        if ($peek === null) {
            return $this->renderError($response, 'This link is invalid or expired. Return to ORK and start over.');
        }

        $emailFromToken = $peek['email'] ?? $email;
        $defaultTab = $this->users->userExists($emailFromToken) ? 'login' : 'register';

        $response->getBody()->write($this->twig->render('connect.twig', [
            'link_token' => $linkToken,
            'email'      => $emailFromToken,
            'defaultTab' => $defaultTab,
            'error'      => null,
        ]));
        return $response;
    }

    public function submitConnectLogin(Request $request, Response $response): Response
    {
        // implemented in Task 11
        return $response->withStatus(501);
    }

    public function submitConnectRegister(Request $request, Response $response): Response
    {
        // implemented in Task 12
        return $response->withStatus(501);
    }

    private function renderError(Response $response, string $message): Response
    {
        $response->getBody()->write($this->twig->render('connect.twig', [
            'link_token' => '',
            'email'      => '',
            'defaultTab' => 'login',
            'error'      => $message,
        ]));
        return $response->withStatus(400);
    }

    /**
     * Best-effort decode of the JWT WITHOUT consuming jti — used only to pick the default tab.
     * Returns null on signature failure, expiry, or wrong iss/aud.
     */
    private function peekToken(string $jwt): ?array
    {
        try {
            \Firebase\JWT\JWT::$leeway = 30;
            $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key($_ENV['ORK_LINK_TOKEN_SECRET'] ?? '', 'HS256'));
        } catch (\Throwable $e) {
            return null;
        }
        if (($decoded->iss ?? null) !== 'ork' || ($decoded->aud ?? null) !== 'idp') {
            return null;
        }
        return ['email' => $decoded->email ?? null];
    }
}
```

- [ ] **Step 3: Register the route**

In `config/routes.php`, alongside the other `/auth` routes:

```php
$app->get('/auth/connect', [ConnectController::class, 'showConnect'])->setName('auth.connect.show');
$app->post('/auth/connect/login', [ConnectController::class, 'submitConnectLogin'])->setName('auth.connect.login');
$app->post('/auth/connect/register', [ConnectController::class, 'submitConnectRegister'])->setName('auth.connect.register');
```

(Adjust path based on whether `/auth` is grouped — match the existing register/login pattern.)

- [ ] **Step 4: Wire the controller in DI**

```php
ConnectController::class => function (ContainerInterface $c) {
    return new ConnectController(
        $c->get(Environment::class),
        $c->get(UserRepository::class),
        $c->get(OrkLinkTokenService::class),
    );
},
```

- [ ] **Step 5: Smoke-test the page**

Generate a token by hand for testing:

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp php -r '
use Firebase\JWT\JWT;
require "vendor/autoload.php";
$now = time();
echo JWT::encode([
    "iss" => "ork", "aud" => "idp", "sub" => "42",
    "email" => "test-new@example.com",
    "iat" => $now, "exp" => $now+900,
    "jti" => bin2hex(random_bytes(18)),
], $_ENV["ORK_LINK_TOKEN_SECRET"], "HS256");
'
```

Take the output and visit `http://localhost:37080/auth/connect?email=test-new@example.com&link_token=<the-jwt>` in a browser.

Expected: Register tab is the default (because `test-new@example.com` doesn't exist), email pre-filled, hidden `link_token` field present.

Then try with an email of a known existing user:
Expected: Login tab is the default, email locked.

Then try with no `link_token`:
Expected: error page.

- [ ] **Step 6: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Controllers/Client/ConnectController.php templates/connect.twig config/routes.php bootstrap/dependencies.php
git commit -m "Enhancement: GET /auth/connect handoff landing page"
```

---

## Task 11: [IDP] `ConnectController::submitConnectLogin`

**Files:**
- Modify: `src/Controllers/Client/ConnectController.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Controllers/ConnectControllerTest.php`:

```php
public function test_submit_login_links_using_sub_not_form_email(): void
{
    $app = $this->app();
    $userId = $this->seedUser($app, 'real@example.com', 'p4ssword');
    $jwt = $this->mintToken(['sub' => '777', 'email' => 'real@example.com']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/auth/connect/login')
        ->withParsedBody([
            'link_token' => $jwt,
            'email'      => 'real@example.com',  // form email
            'password'   => 'p4ssword',
        ]);
    $resp = $app->handle($req);
    $this->assertEquals(302, $resp->getStatusCode());
    $location = $resp->getHeaderLine('Location');
    $this->assertStringStartsWith($_ENV['ORK_BASE_URL'] ?? 'http://ork.local', $location);
    $row = $this->fetchProfileRow($app, $userId);
    $this->assertEquals(777, $row['mundane_id']); // from JWT sub, not from form
    $this->assertEquals('ork_handoff', $row['linked_via']);
}

public function test_submit_login_bad_password_renders_page_with_error(): void
{
    $app = $this->app();
    $this->seedUser($app, 'real2@example.com', 'right');
    $jwt = $this->mintToken(['sub' => '778', 'email' => 'real2@example.com']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/auth/connect/login')
        ->withParsedBody(['link_token' => $jwt, 'email' => 'real2@example.com', 'password' => 'wrong']);
    $resp = $app->handle($req);
    $this->assertEquals(200, $resp->getStatusCode());
    $body = (string) $resp->getBody();
    $this->assertStringContainsString('incorrect', strtolower($body));
}

public function test_submit_login_expired_token_renders_error(): void
{
    $app = $this->app();
    $this->seedUser($app, 'real3@example.com', 'right');
    $jwt = $this->mintToken(['sub' => '779', 'email' => 'real3@example.com', 'iat' => time()-1000, 'exp' => time()-100]);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/auth/connect/login')
        ->withParsedBody(['link_token' => $jwt, 'email' => 'real3@example.com', 'password' => 'right']);
    $resp = $app->handle($req);
    $this->assertEquals(400, $resp->getStatusCode());
    $this->assertStringContainsString('expired', (string) $resp->getBody());
}
```

- [ ] **Step 2: Run the failing tests**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Controllers/ConnectControllerTest.php --filter test_submit_login
```

Expected: 3 failures (returns 501).

- [ ] **Step 3: Implement `submitConnectLogin`**

Replace the stub in `ConnectController.php`:

```php
public function submitConnectLogin(Request $request, Response $response): Response
{
    $body = (array) $request->getParsedBody();
    $linkToken = $body['link_token'] ?? '';
    $email     = $body['email']      ?? '';
    $password  = $body['password']   ?? '';

    $claims = $this->tokenService->verify($linkToken);
    if ($claims === null) {
        return $this->renderError($response, 'This link is invalid or expired. Return to ORK to get a fresh one.');
    }

    $login = $this->logins->findLocalByEmail($email);
    if ($login === null || $login->getPassword() === null || !password_verify($password, $login->getPassword())) {
        $response->getBody()->write($this->twig->render('connect.twig', [
            'link_token' => $linkToken,
            'email'      => $email,
            'defaultTab' => 'login',
            'error'      => 'Email or password incorrect.',
        ]));
        return $response;
    }

    $user = $login->getUser();
    $this->orkProfileRepository->linkExistingUserToMundane($user->getId(), $claims['mundane_id'], 'ork_handoff');

    // Authenticate the user (set session) so the IDP also recognizes them post-redirect.
    $_SESSION['user_id'] = $user->getId();

    $orkBase = $_ENV['ORK_BASE_URL'] ?? '/';
    return $response->withHeader('Location', $orkBase)->withStatus(302);
}
```

You'll need to inject `UserLoginRepository` (as `$this->logins`) and `UserOrkProfileRepository` (as `$this->orkProfileRepository`) — update the constructor and DI definition.

- [ ] **Step 4: Run the tests**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Controllers/ConnectControllerTest.php --filter test_submit_login
```

Expected: 3 passes.

- [ ] **Step 5: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Controllers/Client/ConnectController.php tests/Controllers/ConnectControllerTest.php bootstrap/dependencies.php
git commit -m "Enhancement: POST /auth/connect/login completes handoff with jti consumption"
```

---

## Task 12: [IDP] `ConnectController::submitConnectRegister`

**Files:**
- Modify: `src/Controllers/Client/ConnectController.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Controllers/ConnectControllerTest.php`:

```php
public function test_submit_register_creates_user_and_links(): void
{
    $app = $this->app();
    $jwt = $this->mintToken(['sub' => '888', 'email' => 'newbie@example.com']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/auth/connect/register')
        ->withParsedBody([
            'link_token'      => $jwt,
            'firstName'       => 'New',
            'lastName'        => 'Bie',
            'email'           => 'newbie@example.com',
            'password'        => 'sup3rs3cret',
            'confirmPassword' => 'sup3rs3cret',
        ]);
    $resp = $app->handle($req);
    $this->assertEquals(302, $resp->getStatusCode());
    $user = $this->findUserByEmail($app, 'newbie@example.com');
    $this->assertNotNull($user);
    $row = $this->fetchProfileRow($app, $user->getId());
    $this->assertEquals(888, $row['mundane_id']);
    $this->assertEquals('ork_handoff', $row['linked_via']);
}

public function test_submit_register_duplicate_email_re_renders(): void
{
    $app = $this->app();
    $this->seedUser($app, 'taken@example.com', 'p');
    $jwt = $this->mintToken(['sub' => '889', 'email' => 'taken@example.com']);
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/auth/connect/register')
        ->withParsedBody([
            'link_token' => $jwt,
            'firstName' => 'T', 'lastName' => 'T',
            'email' => 'taken@example.com',
            'password' => 'a', 'confirmPassword' => 'a',
        ]);
    $resp = $app->handle($req);
    $this->assertEquals(200, $resp->getStatusCode());
    $this->assertStringContainsString('already registered', (string) $resp->getBody());
}
```

- [ ] **Step 2: Verify failure**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Controllers/ConnectControllerTest.php --filter test_submit_register
```

Expected: 2 failures (returns 501).

- [ ] **Step 3: Implement `submitConnectRegister`**

```php
public function submitConnectRegister(Request $request, Response $response): Response
{
    $body = (array) $request->getParsedBody();
    $linkToken = $body['link_token'] ?? '';
    $password  = $body['password']        ?? '';
    $confirm   = $body['confirmPassword'] ?? '';

    $claims = $this->tokenService->verify($linkToken);
    if ($claims === null) {
        return $this->renderError($response, 'This link is invalid or expired. Return to ORK to get a fresh one.');
    }

    if ($password !== $confirm) {
        $response->getBody()->write($this->twig->render('connect.twig', [
            'link_token' => $linkToken,
            'email'      => $body['email'] ?? '',
            'defaultTab' => 'register',
            'error'      => 'Passwords do not match.',
        ]));
        return $response;
    }

    $result = $this->registrationService->register(
        $body['firstName'] ?? '',
        $body['lastName']  ?? '',
        $body['email']     ?? '',
        $password,
    );

    if (!$result['ok']) {
        $response->getBody()->write($this->twig->render('connect.twig', [
            'link_token' => $linkToken,
            'email'      => $body['email'] ?? '',
            'defaultTab' => 'register',
            'error'      => $result['error'],
        ]));
        return $response;
    }

    $user = $result['user'];
    $this->orkProfileRepository->linkExistingUserToMundane($user->getId(), $claims['mundane_id'], 'ork_handoff');
    $_SESSION['user_id'] = $user->getId();

    $orkBase = $_ENV['ORK_BASE_URL'] ?? '/';
    return $response->withHeader('Location', $orkBase)->withStatus(302);
}
```

Add `RegistrationService` to the constructor + DI.

- [ ] **Step 4: Run all ConnectController tests**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp vendor/bin/phpunit tests/Controllers/ConnectControllerTest.php
```

Expected: all tests pass (showConnect + submitConnectLogin + submitConnectRegister).

- [ ] **Step 5: Commit**

```bash
cd ~/GitHub/idp-tobias
git add src/Controllers/Client/ConnectController.php tests/Controllers/ConnectControllerTest.php bootstrap/dependencies.php
git commit -m "Enhancement: POST /auth/connect/register registers and links in one flow"
```

---

## Task 13: [IDP] Document the new env var

**Files:**
- Modify: `.env.example`

- [ ] **Step 1: Add the variable**

Append to `.env.example`:

```
# Shared HS256 secret with ORK3 for the /auth/connect handoff JWT and the
# /resources/link-ork-profile mirror endpoint. Must match the value in ORK's
# IDP_LINK_TOKEN_SECRET exactly. 32+ random bytes, base64-encoded.
# Generate with: openssl rand -base64 48
ORK_LINK_TOKEN_SECRET=

# Where the IDP redirects users back to after a successful /auth/connect flow.
ORK_BASE_URL=http://localhost:19080/orkui/
```

- [ ] **Step 2: Commit**

```bash
cd ~/GitHub/idp-tobias
git add .env.example
git commit -m "Docs: document ORK_LINK_TOKEN_SECRET and ORK_BASE_URL env vars"
```

---

## Task 14: [ORK] Inline HS256 JWT signer + `mintIdpLinkToken`

ORK has no composer, so the signer is ~25 lines inline.

**Files:**
- Modify: `system/lib/ork3/class.Authorization.php`

- [ ] **Step 1: Locate the right insertion point**

```bash
grep -n "mirrorLinkToIdp\|public function\|private function" system/lib/ork3/class.Authorization.php | head -20
```

The new method goes near the other IDP-link methods — right after `mirrorLinkToIdp`.

- [ ] **Step 2: Apply via Python (PHP-tabs rule)**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Authorization.php')
t = p.read_text()

NEEDLE = "\tpublic function mirrorLinkToIdp("
assert NEEDLE in t, "anchor not found"

# Insert the new method block BEFORE mirrorLinkToIdp so the file stays grouped by IDP-related methods.
NEW = """\tprivate static function b64url($data) {
\t\treturn rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
\t}

\t/**
\t * Mint a short-lived HS256 JWT used to hand off an ORK→IDP onboarding intent.
\t * Claims: iss=ork, aud=idp, sub=mundane_id, email, iat, exp(+900s), jti(uuidv4).
\t * The IDP's OrkLinkTokenService verifies this token with the same shared secret
\t * and records the jti to prevent replay.
\t */
\tpublic function mintIdpLinkToken($mundaneId, $email)
\t{
\t\t$secret = defined('IDP_LINK_TOKEN_SECRET') ? IDP_LINK_TOKEN_SECRET : ($_ENV['IDP_LINK_TOKEN_SECRET'] ?? '');
\t\tif (strlen($secret) < 32) {
\t\t\tthrow new Exception('IDP_LINK_TOKEN_SECRET unset or too short');
\t\t}
\t\t$header  = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
\t\t$now     = time();
\t\t$bytes   = random_bytes(16);
\t\t$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
\t\t$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
\t\t$uuid    = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
\t\t$payload = self::b64url(json_encode([
\t\t\t'iss'   => 'ork',
\t\t\t'aud'   => 'idp',
\t\t\t'sub'   => (string)(int)$mundaneId,
\t\t\t'email' => (string)$email,
\t\t\t'iat'   => $now,
\t\t\t'exp'   => $now + 900,
\t\t\t'jti'   => $uuid,
\t\t]));
\t\t$signing = $header . '.' . $payload;
\t\t$sig     = self::b64url(hash_hmac('sha256', $signing, $secret, true));
\t\treturn $signing . '.' . $sig;
\t}

"""

t2 = t.replace(NEEDLE, NEW + NEEDLE, 1)
assert t2 != t, "no change applied"
p.write_text(t2)
print("inserted mintIdpLinkToken + b64url helper")
PY
```

- [ ] **Step 3: Round-trip verify against the IDP**

```bash
# Set the secret first (must match IDP's ORK_LINK_TOKEN_SECRET)
SECRET=$(grep IDP_LINK_TOKEN_SECRET .env | cut -d= -f2- 2>/dev/null || echo "set this!")

docker exec ork3-php8-web php -r '
define("IDP_LINK_TOKEN_SECRET", getenv("IDP_LINK_TOKEN_SECRET"));
require_once "/var/www/html/system/lib/ork3/class.Authorization.php";
// Stub out parent::__construct deps if needed
$a = new ReflectionClass("Authorization");
$instance = $a->newInstanceWithoutConstructor();
$method = $a->getMethod("mintIdpLinkToken");
echo $method->invoke($instance, 42, "test@example.com") . "\n";
'
```

Take the output and decode it on the IDP side:

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpapp php -r '
require "vendor/autoload.php";
$jwt = "<paste-jwt-here>";
$decoded = Firebase\JWT\JWT::decode($jwt, new Firebase\JWT\Key($_ENV["ORK_LINK_TOKEN_SECRET"], "HS256"));
var_dump($decoded);
'
```

Expected: prints an object with `iss="ork"`, `aud="idp"`, `sub="42"`, `email="test@example.com"`, valid `iat`, `exp`, `jti` of 36-char UUID format.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Authorization.php
git commit -m "Enhancement: mintIdpLinkToken signs handoff JWT for IDP onboarding"
```

(Reminder: `class.Authorization.php` has the login-bypass hack — verify the diff is just the new method before committing. `git diff --cached` to inspect.)

---

## Task 15: [ORK] `Login::start_idp_connect` and `Login::nudge_dismiss` actions

**Files:**
- Modify: `orkui/controller/controller.Login.php`

- [ ] **Step 1: Read the current controller to find the right spot**

```bash
grep -n "public function" orkui/controller/controller.Login.php
```

The two new methods go at the bottom of the class, after `claim_magic_link`.

- [ ] **Step 2: Apply via Python**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Login.php')
t = p.read_text()

NEEDLE = "\tpublic function claim_magic_link("
assert NEEDLE in t, "anchor not found"

# Find the end of the claim_magic_link method by tracking braces from NEEDLE forward.
import re
start = t.index(NEEDLE)
depth = 0
i = start
saw_open = False
while i < len(t):
    if t[i] == '{':
        depth += 1
        saw_open = True
    elif t[i] == '}':
        depth -= 1
        if saw_open and depth == 0:
            end = i + 1
            break
    i += 1

NEW = """

\t/**
\t * POST target for the ORK→IDP onboarding banner.
\t * Mints a short-lived signed JWT and redirects to the IDP's /auth/connect page
\t * with the user's email prefilled. The JWT carries the mundane_id as `sub` and
\t * the IDP writes the link using that claim after the user logs in or registers.
\t */
\tpublic function start_idp_connect()
\t{
\t\tif (!isset($this->session->user_id)) {
\t\t\theader('Location: ' . UIR . 'Login');
\t\t\treturn;
\t\t}
\t\t$uid = (int)$this->session->user_id;
\t\tglobal $DB;
\t\t$DB->Clear();
\t\t$rs = $DB->DataSet("SELECT email FROM " . DB_PREFIX . "mundane WHERE mundane_id = {$uid} LIMIT 1");
\t\t$email = ($rs && $rs->Size() > 0 && $rs->Next()) ? (string)$rs->email : '';
\t\t$DB->Clear();

\t\t$jwt = Ork3::$Lib->authorization->mintIdpLinkToken($uid, $email);
\t\t$url = IDP_BASE_URL . '/auth/connect?email=' . urlencode($email) . '&link_token=' . urlencode($jwt);
\t\theader('Location: ' . $url);
\t}

\t/**
\t * POST target for the banner's "Not now" button. Sets a 30-day suppression cookie
\t * and redirects back to the dashboard. Validates referer against ORK's own host
\t * to avoid open-redirect; falls back to UIR.
\t */
\tpublic function nudge_dismiss()
\t{
\t\t$expires = time() + (30 * 24 * 60 * 60);
\t\t$secure  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
\t\tsetcookie('ork_idp_nudge_dismissed_until', (string)$expires, [
\t\t\t'expires'  => $expires,
\t\t\t'path'     => '/',
\t\t\t'httponly' => true,
\t\t\t'secure'   => $secure,
\t\t\t'samesite' => 'Lax',
\t\t]);
\t\t$ref = $_SERVER['HTTP_REFERER'] ?? '';
\t\t$host = parse_url($ref, PHP_URL_HOST);
\t\t$ownHost = parse_url(HTTP_UI_REMOTE, PHP_URL_HOST);
\t\tif ($ref && $host === $ownHost) {
\t\t\theader('Location: ' . $ref);
\t\t} else {
\t\t\theader('Location: ' . UIR);
\t\t}
\t}
"""

t2 = t[:end] + NEW + t[end:]
assert t2 != t, "no change applied"
p.write_text(t2)
print("added start_idp_connect + nudge_dismiss")
PY
```

- [ ] **Step 3: Lint check**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.Login.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.Login.php
git commit -m "Enhancement: Login/start_idp_connect mints JWT, Login/nudge_dismiss sets cookie"
```

---

## Task 16: [ORK] Load `IdpLinked` flag in base `Controller::index()`

**Files:**
- Modify: `system/lib/system/class.Controller.php`

- [ ] **Step 1: Locate the right spot inside `index()`**

The base `Controller::index()` is the post-login home page renderer (around line 115). We add an `IdpLinked` flag to `$this->data` based on:
- Logged-in user exists, AND
- `ork_idp_auth` row exists for `mundane_id = session->user_id`

- [ ] **Step 2: Apply via Python**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('system/lib/system/class.Controller.php')
t = p.read_text()

# Anchor: inside index(), right after the LoggedIn block sets UserKingdomId
NEEDLE = "\t\t} else {\n\t\t\t$this->data['UserKingdomId'] = 0;\n\t\t}"
assert NEEDLE in t, "anchor not found"

NEW = NEEDLE + """

\t\t// IDP-link nudge banner state for the post-login home page.
\t\t// Banner shows when: logged in, no ork_idp_auth row, no 30-day dismiss cookie.
\t\t$this->data['IdpLinked']        = false;
\t\t$this->data['IdpNudgeDismissed'] = isset($_COOKIE['ork_idp_nudge_dismissed_until']) && (int)$_COOKIE['ork_idp_nudge_dismissed_until'] > time();
\t\tif ($this->data['LoggedIn'] && isset($this->session->user_id)) {
\t\t\tglobal $DB;
\t\t\t$DB->Clear();
\t\t\t$_idpUid = (int)$this->session->user_id;
\t\t\t$_idpRs = $DB->DataSet("SELECT 1 FROM " . DB_PREFIX . "idp_auth WHERE mundane_id = {$_idpUid} LIMIT 1");
\t\t\t$this->data['IdpLinked'] = ($_idpRs && $_idpRs->Size() > 0);
\t\t\t$DB->Clear();
\t\t}
"""

# Replace by appending after NEEDLE (NEEDLE remains, NEW is the augmented version that adds new lines)
t2 = t.replace(NEEDLE, NEW, 1)
assert t2 != t, "no change applied"
p.write_text(t2)
print("added IdpLinked flag")
PY
```

- [ ] **Step 3: Lint check**

```bash
docker exec ork3-php8-web php -l /var/www/html/system/lib/system/class.Controller.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add system/lib/system/class.Controller.php
git commit -m "Enhancement: Load IdpLinked and IdpNudgeDismissed flags on home page"
```

---

## Task 17: [ORK] Banner partial template

**Files:**
- Create: `orkui/template/default/Home_idp_nudge.tpl`

- [ ] **Step 1: Write the partial**

```smarty
<?php
// Render only when: logged in, no IDP link yet, dismiss cookie unset/expired.
if (empty($LoggedIn) || !empty($IdpLinked) || !empty($IdpNudgeDismissed)) {
    return;
}
?>
<style>
    .idp-nudge { background:#f6efe2; border:1px solid #d8cba4; border-radius:8px; padding:18px 20px; margin:16px 0; display:flex; flex-direction:column; gap:10px; }
    /* heading reset — orkui.css applies a gray box to all h1-h6 globally */
    .idp-nudge h3 { background:transparent; border:none; padding:0; border-radius:0; text-shadow:none; font-size:1.05rem; font-weight:600; margin:0; color:#3a2e10; }
    .idp-nudge p { margin:0; color:#5b4a1f; font-size:.92rem; line-height:1.4; }
    .idp-nudge-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .idp-nudge .btn-primary { background:#7a5b1c; color:#fff; padding:8px 14px; border-radius:4px; text-decoration:none; font-weight:600; border:none; cursor:pointer; }
    .idp-nudge .btn-primary:hover { background:#5d4615; }
    .idp-nudge .btn-ghost { background:transparent; color:#5b4a1f; padding:8px 12px; border-radius:4px; text-decoration:none; font-weight:500; border:1px solid transparent; cursor:pointer; }
    .idp-nudge .btn-ghost:hover { border-color:#d8cba4; }
    /* dark mode */
    body.dark-mode .idp-nudge { background:#3a2e10; border-color:#5d4615; }
    body.dark-mode .idp-nudge h3 { color:#f6efe2; }
    body.dark-mode .idp-nudge p { color:#d8cba4; }
    body.dark-mode .idp-nudge .btn-ghost { color:#d8cba4; }
    body.dark-mode .idp-nudge .btn-ghost:hover { border-color:#7a5b1c; }
</style>
<div class="idp-nudge" role="region" aria-label="Set up Amtgard sign-in">
    <h3>Speed up next time — set up your Amtgard sign-in</h3>
    <p>Sign in faster on your next visit by connecting your ORK profile to your Amtgard sign-in. You'll be able to use Google, Discord, or a password — and we'll remember you.</p>
    <div class="idp-nudge-actions">
        <form method="POST" action="<?= UIR ?>Login/start_idp_connect" style="margin:0;">
            <button type="submit" class="btn-primary">Set it up now</button>
        </form>
        <form method="POST" action="<?= UIR ?>Login/nudge_dismiss" style="margin:0;">
            <button type="submit" class="btn-ghost">Not now</button>
        </form>
    </div>
</div>
```

- [ ] **Step 2: Manual dark-mode check**

(Done in Task 19 once the banner is wired in.)

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Home_idp_nudge.tpl
git commit -m "Enhancement: Home_idp_nudge banner partial for ORK→IDP onboarding"
```

---

## Task 18: [ORK] Wire the banner into `default.tpl`

**Files:**
- Modify: `orkui/template/default/default.tpl`

- [ ] **Step 1: Find the top of the rendered home content**

The `default.tpl` is the home page (renders the kingdoms grid, tournaments, events). We want the banner near the top — before the kingdom list.

```bash
head -100 orkui/template/default/default.tpl | tail -50
```

Pick an anchor near the start of the visible content. A good candidate is just before the kingdoms section opens. Find a stable HTML anchor (e.g., `<div class="hm-kingdoms-grid">` or similar — confirm by reading the file).

- [ ] **Step 2: Insert the include via Python**

```bash
python3 << 'PY'
import pathlib
p = pathlib.Path('orkui/template/default/default.tpl')
t = p.read_text()

# Place the banner right after the welcome banner (top of visible body content).
ANCHOR = '<div class="hm-welcome-banner">'

assert ANCHOR in t, f"anchor not found: {ANCHOR!r}"

# Find the closing </div> of the welcome banner and insert AFTER it.
import re
m = re.search(r'<div class="hm-welcome-banner">.*?</div>\s*\n', t, re.DOTALL)
assert m, "welcome banner block not found"

INCLUDE = '<?php include __DIR__ . "/Home_idp_nudge.tpl"; ?>\n'
t2 = t[:m.end()] + INCLUDE + t[m.end():]
assert t2 != t, "no change applied"
p.write_text(t2)
print("included Home_idp_nudge.tpl")
PY
```

If no convenient HTML anchor exists, fall back to placing the include immediately after the `?>` that closes the top PHP prelude (around line 75 in `default.tpl`).

- [ ] **Step 3: Browser check**

Open `http://localhost:19080/orkui/` while logged in as a user with **no** `ork_idp_auth` row. The banner should appear near the top of the home page.

Verify in dark mode (toggle the existing dark-mode switch in the UI).

Verify legacy/light mode.

Verify that clicking *Not now* hides the banner and reloads the page without it.

Verify that opening a private window and logging in as a user **with** a linked IDP shows no banner.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/default.tpl
git commit -m "Enhancement: Show IDP onboarding banner on home page"
```

---

## Task 19: [Integration] End-to-end Flow A — new user, no IDP

- [ ] **Step 1: Reset to a clean test state**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "DELETE FROM ork_idp_auth WHERE mundane_id = <test-mundane-id>;"
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "DELETE FROM user_ork_profiles; DELETE FROM users WHERE email LIKE 'flowA%';"
```

(Replace `<test-mundane-id>` with a real one. Pick an ORK profile whose email you can use for the new IDP account.)

- [ ] **Step 2: Walk the flow**

1. Sign out of ORK if needed.
2. Go to `http://localhost:19080/orkui/Login`.
3. Sign in with the legacy username/password.
4. Land on the home page. **Verify the banner shows.**
5. Click **Set it up now**.
6. Land on the IDP `/auth/connect` page. **Verify Register tab is the default**, email prefilled.
7. Fill first/last/password, submit.
8. Land back on the ORK home page. **Verify the banner is gone.**

- [ ] **Step 3: Confirm the link**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "SELECT user_id, mundane_id, linked_via FROM user_ork_profiles ORDER BY updated_at DESC LIMIT 1;"
```

Expected: a row with `linked_via = 'ork_handoff'`.

- [ ] **Step 4: Confirm the JTI was recorded**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "SELECT jti, seen_at FROM link_token_jti ORDER BY seen_at DESC LIMIT 1;"
```

Expected: one row.

- [ ] **Step 5: Confirm "Sign in with Amtgard" now works one-click**

Sign out of ORK. Click *Sign in with Amtgard* on the login page. Should land on the dashboard with no claim form interception (since the link now exists in `ork_idp_auth` from the existing IDP→ORK auto-link path, *and* the IDP knows the link via `user_ork_profiles`).

Wait — the ORK→IDP handoff writes the link on the IDP side, but ORK still has no `ork_idp_auth` row! The existing IDP→ORK flow will try to auto-link by email — which should succeed because the IDP's userinfo now includes `ork_profile.mundane_id`. So the auto-link branch in the existing code completes the picture.

Verify the `ork_idp_auth` row was created on the IDP→ORK round-trip:

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT idp_user_id, mundane_id, idp_mirror_status FROM ork_idp_auth WHERE mundane_id = <test-mundane-id>;"
```

Expected: row exists, `idp_mirror_status = 'synced'`.

- [ ] **Step 6: No commit (verification only)**

---

## Task 20: [Integration] Flow B — existing IDP account, never linked

- [ ] **Step 1: Set up state**

Pre-create an IDP user with an email that matches an ORK mundane, but ensure `user_ork_profiles` has no row for that user.

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "DELETE FROM user_ork_profiles WHERE user_id = <existing-idp-user-id>;"
```

Ensure the ORK profile has no `ork_idp_auth` row.

- [ ] **Step 2: Walk the flow**

1. Legacy login on ORK with that mundane.
2. Banner shows on home page.
3. *Set it up now* → IDP `/auth/connect`. **Verify Log In tab is the default**, email locked.
4. Sign in with the existing IDP password.
5. Land back on ORK home. Banner gone.

- [ ] **Step 3: Confirm linkage**

```bash
docker compose -f docker-compose.dev.yml exec amtgardidpdb mariadb -u root -proot idp -e "SELECT user_id, mundane_id, linked_via FROM user_ork_profiles ORDER BY updated_at DESC LIMIT 1;"
```

Expected: row with `linked_via = 'ork_handoff'` for the existing IDP user.

---

## Task 21: [Integration] Flow C — dismiss

- [ ] **Step 1: Clear state**

Ensure no `ork_idp_auth` row for the test mundane, no `ork_idp_nudge_dismissed_until` cookie in the test browser (clear cookies or use a private window).

- [ ] **Step 2: Walk the flow**

1. Legacy login. Banner shows.
2. Click *Not now*.
3. Page reloads. Banner is gone.

- [ ] **Step 3: Confirm cookie**

In dev tools → Application → Cookies → `localhost:19080`, find `ork_idp_nudge_dismissed_until`. Expected: numeric value 30 days in the future.

- [ ] **Step 4: Verify it persists**

Reload the home page. Banner stays hidden.

---

## Task 22: [Integration] Flow D regression — existing IDP→ORK + mirror

- [ ] **Step 1: Reset**

Remove `ork_idp_auth` row and `user_ork_profiles` row for the test pair, but keep the IDP user record so they can re-sign-in.

- [ ] **Step 2: Walk the existing flow**

1. Sign out of ORK.
2. Click *Sign in with Amtgard*.
3. Complete IDP login.
4. Land on ORK home page.

- [ ] **Step 3: Confirm mirror status**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT idp_mirror_status FROM ork_idp_auth ORDER BY idp_mirror_last_attempt DESC LIMIT 1;"
```

Expected: `synced` (not `failed`). **This is the test that proves Delta 1 closed the loop.**

If still `failed`, run the retry cron manually:

```bash
docker exec ork3-php8-web php /var/www/html/cron/idp-mirror-retry.php
```

Then re-check.

---

## Task 23: [Integration] Flow E regression — returning linked user

- [ ] **Step 1: State**

Test mundane is fully linked (`ork_idp_auth` row exists, `user_ork_profiles` row exists). User has the `ork_idp_autoredirect=1` cookie set.

- [ ] **Step 2: Walk**

Visit `http://localhost:19080/orkui/Login`. Should auto-redirect through IDP and land on ORK home.

Expected: no banner (because the user is linked). Existing one-click / zero-click behavior preserved.

---

## Task 24: [Integration] Replay drill

- [ ] **Step 1: Capture a token**

In dev tools network panel, capture a `/auth/connect?...link_token=...` URL during Flow A right before submission.

- [ ] **Step 2: Complete Flow A normally**

- [ ] **Step 3: Paste the same URL into a new tab**

Expected: connect page renders, but submitting either form returns "This link is invalid or expired" because `jti` is already consumed.

Bonus: try after the 15-minute window has elapsed — same error.

---

## Task 25: Push and open PRs

- [ ] **Step 1: Push both branches**

```bash
git -C ~/GitHub/idp-tobias push -u origin feature/login-with-amtgard-workflow
git push -u origin feature/login-with-amtgard-workflow   # from ORK3-tobias cwd
```

- [ ] **Step 2: Open the IDP PR**

```bash
cd ~/GitHub/idp-tobias
gh pr create --base main --title "Enhancement: IDP link-ork-profile mirror endpoint + /auth/connect handoff" --body "$(cat <<'EOF'
## Summary

Adds two complementary endpoints to support the ORK3 "Sign in with Amtgard" workflow improvements:

- **`POST /resources/link-ork-profile`** — server-to-server endpoint that lets ORK assert a link between an existing IDP user and an ORK mundane. Closes the loop that ORK3 has been calling against since the 04-13 work — replaces 404s with idempotent 204s and unblocks the `idp_mirror_status='synced'` state machine.
- **`GET /auth/connect`** + `POST /auth/connect/{login,register}` — browser handoff landing page. Accepts a short-lived signed JWT from ORK, lets the user log in to an existing IDP account or register a new one, and writes the ORK link using the JWT's `sub` claim. Replay-protected via a new `link_token_jti` table.

Spec: linked from the matching ORK3 PR.

## Test plan

- [ ] `phpunit` green (new tests for middleware, OrkLinkTokenService, ConnectController, linkOrkProfile)
- [ ] Smoke: `curl` POST to `/resources/link-ork-profile` with valid Basic auth returns 204; without auth returns 401
- [ ] Smoke: visit `/auth/connect?email=...&link_token=...` with a known email shows Login tab; with an unknown email shows Register tab
- [ ] Round-trip: ORK→IDP→ORK Flow A (new user) and Flow B (existing user)
- [ ] Replay: re-using a `link_token` after consumption returns the expired error

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Open the ORK PR**

```bash
gh pr create --base master --title "Enhancement: ORK→IDP onboarding banner + close mirror loop" --body "$(cat <<'EOF'
## Summary

- Dashboard banner offering to set up "Sign in with Amtgard" for users who logged in via legacy form and aren't linked yet
- Banner mints a short-lived signed HS256 JWT, hands off to a new `/auth/connect` page on the IDP fork (see paired bastion-idp PR), and the link gets written on both sides
- Closes the mirror loop introduced in the earlier 04-13 commits — the IDP-side endpoint now exists, so `ork_idp_auth.idp_mirror_status` actually transitions to `synced` instead of looping in `failed`
- 30-day dismiss cookie respects "Not now"

Spec: `docs/superpowers/specs/2026-05-14-idp-link-mirror-and-onboarding-design.md`
Paired PR: <bastion-idp PR URL>

## Test plan

- [ ] Flow A: legacy login → banner → register on IDP → linked
- [ ] Flow B: legacy login → banner → log in to existing IDP → linked
- [ ] Flow C: legacy login → banner → "Not now" → cookie set, banner gone for 30 days
- [ ] Flow D regression: "Sign in with Amtgard" still auto-links by email; mirror status now `synced`
- [ ] Flow E regression: returning linked user one-click and zero-click still work
- [ ] Dark mode: banner readable in both themes
- [ ] Replay: re-using a captured `link_token` URL returns the expired error

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Done

When all 25 tasks check off, the workflow described in `docs/superpowers/specs/2026-05-14-idp-link-mirror-and-onboarding-design.md` is delivered end-to-end on both repos. The existing 04-13 work is no longer dangling against a missing endpoint, and a user who's never touched the IDP can get fully linked in two clicks (banner + IDP register submit) without leaving the connected flow.
