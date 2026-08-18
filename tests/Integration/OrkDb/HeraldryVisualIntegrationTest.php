<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\DeployAssets;
use OrkDb\Render;
use PHPUnit\Framework\TestCase;

final class HeraldryVisualIntegrationTest extends TestCase
{
    public function testDeployedAssetsAreReachableOnKingdomAndParkPages(): void
    {
        if (!ork3_app_reachable()) {
            $this->markTestSkipped('ORK3 app is not reachable — start docker compose php8 stack.');
        }

        $kingdomHtml = $this->fetchAppPage('index.php?Route=Kingdom/profile/100001');
        $this->assertMatchesRegularExpression(
            '/heraldry\/kingdom\/100001\.(jpg|png)(\?[^"\']*)?"/',
            $kingdomHtml
        );

        $parkHtml = $this->fetchAppPage('index.php?Route=Park/profile/1000001');
        $this->assertMatchesRegularExpression(
            '/heraldry\/park\/1000001\.(jpg|png)(\?[^"\']*)?"/',
            $parkHtml
        );
    }

    public function testHeraldryFlaggedFakePlayersExposeAvatarUrls(): void
    {
        if (!ork3_sandbox_db_available()) {
            $this->markTestSkipped('Sandbox database is not available.');
        }
        if (!ork3_app_reachable()) {
            $this->markTestSkipped('ORK3 app is not reachable — start docker compose php8 stack.');
        }

        $render = new Render(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        $heraldryIds = $render->fakeMundaneHeraldryIdsForSeed(42);
        $this->assertNotEmpty($heraldryIds);

        $payload = json_decode(
            $this->fetchAppPage('index.php?Route=Kingdom/players_json/100001'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $playersById = [];
        foreach ($payload['players'] ?? [] as $player) {
            $playersById[(int) $player['id']] = $player;
        }

        $sampleId = $heraldryIds[0];
        $this->assertArrayHasKey($sampleId, $playersById);
        $avatarUrl = (string) ($playersById[$sampleId]['avatarUrl'] ?? '');
        $this->assertNotSame('', $avatarUrl);
        $this->assertStringContainsString('/assets/heraldry/player/', $avatarUrl);
        $this->assertSame(200, $this->httpStatus($this->resolveReachableAssetUrl($avatarUrl)));

        $assetBasename = sprintf('%06d', $sampleId);
        $this->assertTrue(
            is_readable(ORK3_ROOT . '/assets/heraldry/player/' . $assetBasename . '.jpg')
            || is_readable(ORK3_ROOT . '/assets/heraldry/player/' . $assetBasename . '.png')
        );
    }

    public function testDeployAssetsPublishesDefaultPhoenixPlaceholder(): void
    {
        $deploy = new DeployAssets(ORK3_ROOT . '/tools/ork-db', ORK3_ROOT);
        $deploy->run(['verify_manifest' => true]);

        $heraldryDefault = ORK3_ROOT . '/assets/heraldry/player/000000.png';
        $portraitDefault = ORK3_ROOT . '/assets/players/000000.png';
        $this->assertFileExists($heraldryDefault);
        $this->assertFileExists($portraitDefault);

        if (!ork3_app_reachable()) {
            $this->markTestSkipped('ORK3 app is not reachable — start docker compose php8 stack.');
        }

        $this->assertSame(200, $this->httpStatus('http://127.0.0.1:19080/assets/heraldry/player/000000.png'));
        $this->assertSame(200, $this->httpStatus('http://127.0.0.1:19080/assets/players/000000.png'));
    }

    private function fetchAppPage(string $route): string
    {
        $base = ork3_app_base_url();
        if (!str_ends_with($base, '/')) {
            $base .= '/';
        }

        $url = $base . ltrim($route, '/');
        $body = @file_get_contents($url);
        if (!is_string($body) || $body === '') {
            $this->fail("Failed to fetch app page: {$url}");
        }

        return $body;
    }

    private function httpStatus(string $url): int
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $headers = @get_headers($url, true, $context);
        if (!is_array($headers) || !isset($headers[0])) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', (string) $headers[0], $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function normalizeLocalUrl(string $url): string
    {
        return str_replace('://localhost:', '://127.0.0.1:', $url);
    }

    private function resolveReachableAssetUrl(string $url): string
    {
        $url = $this->normalizeLocalUrl($url);
        if ($this->httpStatus($url) === 200) {
            return $url;
        }

        if (str_ends_with($url, '.png')) {
            $alternate = substr($url, 0, -4) . '.jpg';
            if ($this->httpStatus($alternate) === 200) {
                return $alternate;
            }
        }

        return $url;
    }
}
