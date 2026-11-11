<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GbComposeConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testProdGreenblueExampleContainsRequiredGbHevKeys(): void
    {
        $path = $this->root . '/.greenblue.example.prod';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->assertStringContainsString('GB_MODE="prod"', $contents);
        $this->assertStringContainsString('GB_NGINX_STRATEGY="color-symlink"', $contents);
        $this->assertStringContainsString('GB_NGINX_SITES_AVAILABLE_DIR="/etc/nginx/sites-available"', $contents);
        $this->assertStringContainsString('GB_NGINX_SITES_ENABLED_DEFAULT="/etc/nginx/sites-enabled/default"', $contents);
        $this->assertStringContainsString('GB_BLUE_PORT="29080"', $contents);
        $this->assertStringContainsString('GB_GREEN_PORT="19080"', $contents);
        $this->assertStringContainsString('GB_BLUE_CONTAINER="ork3-php8-app-blue"', $contents);
        $this->assertStringContainsString('GB_GREEN_CONTAINER="ork3-php8-app-green"', $contents);
        $this->assertStringContainsString('GB_HEV_PROFILES="ork-web"', $contents);
        $this->assertStringContainsString('GB_HEV_MOUNT_MAP="ork-web:ORK_HEV_WEB_SECRETS"', $contents);
        $this->assertStringContainsString('GB_DEV_STACKS="web"', $contents);
        $this->assertStringContainsString('GB_DEPLOY_STACKS="web"', $contents);
        $this->assertStringContainsString('GB_HEV_SYNC_ON_DEPLOY="1"', $contents);
        $this->assertStringContainsString('GB_HEV_RENDER_ON_DEPLOY="1"', $contents);
    }

    public function testDevGreenblueExampleSourcesSecretsModeAndHelpers(): void
    {
        $path = $this->root . '/.greenblue.example.dev';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->assertStringContainsString('GB_MODE="dev"', $contents);
        $this->assertStringContainsString('scripts/gb-greenblue-helpers.sh', $contents);
        $this->assertStringContainsString('host-env/load-secrets-mode.sh', $contents);
        $this->assertStringContainsString('scripts/gb-pre-hev-sync.sh', $contents);
        $this->assertStringContainsString('GB_HEV_PROFILES="ork-web"', $contents);
    }

    public function testGreenComposePublishesPort19080(): void
    {
        $path = $this->root . '/docker/compose.green.yml';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->assertStringContainsString('19080:80', $contents);
        $this->assertStringContainsString('ork3-php8-app-green', $contents);
        $this->assertStringNotContainsString('ENVIRONMENT=DEV', $contents);
    }

    public function testBlueComposePublishesPort29080WithoutDevEnvironment(): void
    {
        $path = $this->root . '/docker/compose.blue.yml';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->assertStringContainsString('29080:80', $contents);
        $this->assertStringContainsString('ork3-php8-app-blue', $contents);
        $this->assertStringNotContainsString('ENVIRONMENT=DEV', $contents);
    }

    public function testProdComposeMountsOrkHevWebSecrets(): void
    {
        $path = $this->root . '/docker/compose.prod.yml';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        $this->assertStringContainsString('ORK_HEV_WEB_SECRETS', $contents);
        $this->assertStringContainsString(':/run/secrets:ro', $contents);
        $this->assertStringContainsString('HOST_ENV_SECRETS_DIR=/run/secrets', $contents);
        $this->assertStringNotContainsString('ENVIRONMENT=DEV', $contents);
    }
}
