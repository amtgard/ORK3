<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The per-player live-session cap (Authorization::MAX_SESSIONS_PER_USER) and
 * the order sessions are evicted in once a player is over it.
 *
 *   - the oldest-used session goes first (LRU), never the one just created;
 *   - an APP signing in over the cap replaces its own earlier session rather
 *     than a browser the player uses less often;
 *   - browser labels never count as "the same client": two devices with the
 *     same UA string must both survive until plain LRU says otherwise.
 *
 * Runs against the sandbox DB through the real Authorize() path, as the
 * other session tests do, with the test player's sessions cleared first so
 * leftovers from other tests cannot change the counts.
 */
final class SessionCapTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 SessionCapTest';
    private const APP     = 'SessionCapTestApp/1.0';

    /** @var string[] */
    private $tokens = array();
    /** @var int */
    private $mundaneId = 0;

    protected function setUp(): void
    {
        $this->assertGreaterThanOrEqual(4, Authorization::MAX_SESSIONS_PER_USER, 'these scenarios assume a cap of at least 4');
        // Learn the test player's id from one login, then wipe their sessions.
        $first = $this->login(self::BROWSER);
        $this->mundaneId = $this->mundaneIdOf($first);
        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
    }

    private function login(string $label): string
    {
        $r = Ork3::$Lib->authorization->Authorize(array(
            'UserName' => 'megiddo',
            'Password' => 'test-db-player',
            'Token'    => null,
            'Client'   => $label,
        ));
        $this->assertSame(0, (int)$r['Status']['Status'], 'login failed: ' . json_encode($r['Status']));
        $this->tokens[] = (string)$r['Token'];
        return (string)$r['Token'];
    }

    private function mundaneIdOf(string $token): int
    {
        $DB = $GLOBALS['DB'];
        $DB->Clear();
        $rs = $DB->DataSet("SELECT mundane_id FROM " . DB_PREFIX . "session WHERE token = '" . $token . "'");
        $this->assertTrue($rs && $rs->Next(), 'session row not found');
        return (int)$rs->mundane_id;
    }

    private function wipe(): void
    {
        if ($this->mundaneId > 0) {
            $DB = $GLOBALS['DB'];
            $DB->Clear();
            $DB->Execute("DELETE FROM " . DB_PREFIX . "session WHERE mundane_id = " . $this->mundaneId);
        }
        $this->tokens = array();
    }

    /** @return string[] live tokens for the test player, oldest first */
    private function liveTokens(): array
    {
        $DB = $GLOBALS['DB'];
        $DB->Clear();
        $rs = $DB->DataSet("SELECT token FROM " . DB_PREFIX . "session WHERE mundane_id = " . $this->mundaneId . " ORDER BY session_id");
        $out = array();
        while ($rs && $rs->Next()) {
            $out[] = (string)$rs->token;
        }
        return $out;
    }

    public function testOldestSessionIsEvictedOnceOverTheCap(): void
    {
        $cap = Authorization::MAX_SESSIONS_PER_USER;
        $tokens = array();
        for ($i = 0; $i < $cap + 1; $i++) {
            $tokens[] = $this->login(self::BROWSER);
        }
        $live = $this->liveTokens();
        $this->assertCount($cap, $live, 'exactly the cap survives');
        $this->assertNotContains($tokens[0], $live, 'the first (oldest) session is the one evicted');
        $this->assertSame(array_slice($tokens, 1), $live, 'every later session survives, in order');
    }

    public function testAppReloginOverTheCapReplacesItsOwnSessionNotABrowser(): void
    {
        $cap = Authorization::MAX_SESSIONS_PER_USER;
        $app1 = $this->login(self::APP);              // oldest of all
        $browsers = array();
        for ($i = 0; $i < $cap - 1; $i++) {           // fill to the cap with browsers
            $browsers[] = $this->login(self::BROWSER);
        }
        $this->assertCount($cap, $this->liveTokens());

        $app2 = $this->login(self::APP);              // over the cap: the app signs in again
        $live = $this->liveTokens();
        $this->assertCount($cap, $live);
        $this->assertNotContains($app1, $live, 'the app\'s earlier session is the one replaced');
        $this->assertContains($app2, $live);
        foreach ($browsers as $b) {
            $this->assertContains($b, $live, 'no browser session was sacrificed');
        }
    }

    public function testAppOnTwoDevicesKeepsBothWhileUnderTheCap(): void
    {
        $a = $this->login(self::APP);
        $b = $this->login(self::APP);
        $live = $this->liveTokens();
        $this->assertContains($a, $live);
        $this->assertContains($b, $live, 'same app label under the cap must not replace itself');
    }

    public function testIdenticalBrowserLabelsFallBackToPlainLru(): void
    {
        $cap = Authorization::MAX_SESSIONS_PER_USER;
        $tokens = array();
        for ($i = 0; $i < $cap + 1; $i++) {
            $tokens[] = $this->login(self::BROWSER);  // all share one UA string
        }
        $live = $this->liveTokens();
        $this->assertCount($cap, $live);
        $this->assertNotContains($tokens[0], $live, 'only the oldest goes');
        $this->assertContains($tokens[1], $live, 'a same-UA browser is not treated as the same client');
    }
}
