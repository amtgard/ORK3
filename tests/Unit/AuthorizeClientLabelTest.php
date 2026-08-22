<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The login request's Client field becomes the session's device label.
 *
 * jsork sends { Client: 'jsork' } (and mORK, which embeds jsork, overrides it
 * with its own name) in the Authorize request body. Body transport is the
 * CORS-safe channel for embedded browser clients — a custom header would
 * trigger a preflight the JSON service has never answered. The label lands in
 * ork_session.user_agent, where the Log Out Everywhere modal reads it.
 */
final class AuthorizeClientLabelTest extends TestCase
{
    private $tokens = array();

    protected function tearDown(): void
    {
        foreach ($this->tokens as $token) {
            Ork3::$Lib->authorization->DestroySession(array('Token' => $token));
        }
        $this->tokens = array();
    }

    private function login(array $extra = array()): string
    {
        $r = Ork3::$Lib->authorization->Authorize(array_merge(array(
            'UserName' => 'megiddo',
            'Password' => 'test-db-player',
            'Token'    => null,
        ), $extra));
        $this->assertSame(0, (int)$r['Status']['Status'], 'login failed: ' . json_encode($r['Status']));
        $this->tokens[] = $r['Token'];
        return (string)$r['Token'];
    }

    private function sessionLabel(string $token): string
    {
        $DB = $GLOBALS['DB'];
        $DB->Clear();
        $rs = $DB->DataSet("SELECT user_agent FROM " . DB_PREFIX . "session WHERE token = '" . $token . "'");
        $this->assertTrue($rs && $rs->Next(), 'session row not found');
        return (string)$rs->user_agent;
    }

    public function testClientFieldBecomesSessionLabel(): void
    {
        $token = $this->login(array('Client' => 'mORK 2.3'));
        $this->assertSame('mORK 2.3', $this->sessionLabel($token));
    }

    public function testClientFieldIsSanitized(): void
    {
        $token = $this->login(array('Client' => "  jsork\x01\x02embedded  " . str_repeat('x', 300)));
        $label = $this->sessionLabel($token);
        $this->assertStringStartsWith('jsork embedded', $label);
        $this->assertLessThanOrEqual(255, strlen($label));
        $this->assertSame($label, preg_replace('/[\x00-\x1F\x7F]/', '', $label), 'control chars must not survive');
    }

    public function testAbsentClientFallsBackToHeaderChain(): void
    {
        // CLI test env has no X-ORK-Client and no User-Agent — the fallback
        // chain must yield the empty string, not an error or a null write.
        $token = $this->login();
        $this->assertSame('', $this->sessionLabel($token));
    }
}
