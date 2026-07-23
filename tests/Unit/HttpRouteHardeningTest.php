<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Route hardening for HTTP reachability fails on PR #492 Rev3:
 * Admin/tournament missing method, Authorization/*_auth string path segments,
 * Kingdom/recommendations_panel bare id.
 */
final class HttpRouteHardeningTest extends TestCase
{
    public function testAdminTournamentActionExistsWithOptionalId(): void
    {
        require_once DIR_UI . 'controller/controller.Admin.php';

        $this->assertTrue(method_exists(Controller_Admin::class, 'tournament'));
        $method = new ReflectionMethod(Controller_Admin::class, 'tournament');
        // Bare Admin/tournament must not trip index.php required_parameter_count → 500.
        $this->assertSame(0, $method->getNumberOfRequiredParameters());
    }

    public function testAuthorizationAuthActionsAcceptOptionalRequest(): void
    {
        require_once DIR_UI . 'controller/controller.Authorization.php';

        foreach (['add_auth', 'del_auth'] as $name) {
            $this->assertTrue(method_exists(Controller_Authorization::class, $name));
            $method = new ReflectionMethod(Controller_Authorization::class, $name);
            $this->assertSame(0, $method->getNumberOfRequiredParameters());
        }
    }

    public function testAuthorizationDomainRejectsStringRequest(): void
    {
        // Documents why Controller_Authorization must not forward path segments:
        // AddAuthorization expects an array request (Token, MundaneId, …).
        $auth = new Authorization();
        $this->expectException(TypeError::class);
        $auth->AddAuthorization('1');
    }

    public function testRecommendationsPanelMissingIdRedirectsNotHttp400(): void
    {
        $source = file_get_contents(DIR_UI . 'controller/controller.Kingdom.php');
        $this->assertNotFalse($source);
        // Bare Kingdom/recommendations_panel must degrade like profile(), not 400.
        $this->assertStringContainsString(
            "recommendations_panel(\$kingdom_id = null)",
            $source
        );
        $panelPos = strpos($source, 'function recommendations_panel');
        $this->assertNotFalse($panelPos);
        $snippet = substr($source, $panelPos, 500);
        $this->assertStringContainsString("header('Location: ' . UIR)", $snippet);
        $this->assertStringNotContainsString('http_response_code(400)', $snippet);
    }
}
