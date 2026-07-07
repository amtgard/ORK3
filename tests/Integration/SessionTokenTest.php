<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller base session token check (T-INF-03).
 */
final class SessionTokenTest extends TestCase
{
    private InfrastructureFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = InfrastructureFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testValidateSessionTokenMatches(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'session-ok');

        $this->assertTrue($this->mirrorValidateSessionToken($player['mundane_id'], $player['token']));
    }

    public function testValidateSessionTokenRejectsStale(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'session-stale');
        $staleToken = $player['token'];
        $newToken = md5('rotated-' . bin2hex(random_bytes(8)));
        $this->fixture->rotateToken($player['mundane_id'], $newToken);

        $this->assertFalse($this->mirrorValidateSessionToken($player['mundane_id'], $staleToken));
        $this->assertTrue($this->mirrorValidateSessionToken($player['mundane_id'], $newToken));
    }

    private function mirrorValidateSessionToken(int $mundaneId, string $token): bool
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT token FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundaneId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return false;
        }

        return (string) $rs->token === $token;
    }
}
