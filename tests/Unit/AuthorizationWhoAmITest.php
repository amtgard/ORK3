<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Authorization::WhoAmI -- the read-only token check for third-party
 * verification (Listmancer). Modelled on AuthorizeClientLabelTest: real
 * logins against the sandbox DB as the test player, tokens destroyed after.
 *
 * The contract:
 *   - a live token answers with the owner's UserId, the session's Timeout
 *     and the Client label the login stored;
 *   - the token is NOT rotated: it keeps working afterwards, and Authorize()
 *     can still rotate it later;
 *   - a bad or destroyed token fails, and the failure never echoes the token.
 */
final class AuthorizationWhoAmITest extends TestCase
{
    /** @var string[] */
    private $tokens = array();

    protected function tearDown(): void
    {
        foreach ($this->tokens as $token) {
            Ork3::$Lib->authorization->DestroySession(array('Token' => $token));
        }
        $this->tokens = array();
    }

    /** @return array{Token: string, UserId: int} */
    private function login(string $label = 'WhoAmITest/1.0'): array
    {
        $r = Ork3::$Lib->authorization->Authorize(array(
            'UserName' => 'megiddo',
            'Password' => 'test-db-player',
            'Token'    => null,
            'Client'   => $label,
        ));
        $this->assertSame(0, (int)$r['Status']['Status'], 'login failed: ' . json_encode($r['Status']));
        $this->tokens[] = (string)$r['Token'];
        return array('Token' => (string)$r['Token'], 'UserId' => (int)$r['UserId']);
    }

    public function testLiveTokenAnswersWithOwnerTimeoutAndClient(): void
    {
        $login = $this->login('WhoAmITest/1.0');
        $r = Ork3::$Lib->authorization->WhoAmI(array('Token' => $login['Token']));

        $this->assertSame(0, (int)$r['Status']['Status'], json_encode($r['Status']));
        $this->assertSame($login['UserId'], (int)$r['UserId']);
        $this->assertSame('WhoAmITest/1.0', $r['Client']);
        $this->assertNotEmpty($r['Timeout']);
        $this->assertGreaterThan(time(), strtotime($r['Timeout']), 'Timeout is the session expiry, in the future');
        $this->assertArrayNotHasKey('Token', $r, 'the response must never carry a token');
    }

    public function testTokenIsNotRotated(): void
    {
        $login = $this->login();
        $first = Ork3::$Lib->authorization->WhoAmI(array('Token' => $login['Token']));
        $this->assertSame(0, (int)$first['Status']['Status']);

        // The SAME token still works.
        $second = Ork3::$Lib->authorization->WhoAmI(array('Token' => $login['Token']));
        $this->assertSame(0, (int)$second['Status']['Status'], 'a second WhoAmI with the same token must succeed');
        $this->assertSame($login['UserId'], (int)$second['UserId']);

        // ...and Authorize(Token) can still rotate it, proving WhoAmI left it live.
        $rotated = Ork3::$Lib->authorization->Authorize(array('Token' => $login['Token']));
        $this->assertSame(0, (int)$rotated['Status']['Status'], json_encode($rotated['Status']));
        $this->assertNotSame($login['Token'], (string)$rotated['Token'], 'Authorize still rotates');
        $this->tokens[] = (string)$rotated['Token'];

        // The old token is now dead, as Authorize intends; the new one answers.
        $old = Ork3::$Lib->authorization->WhoAmI(array('Token' => $login['Token']));
        $this->assertNotSame(0, (int)$old['Status']['Status']);
        $new = Ork3::$Lib->authorization->WhoAmI(array('Token' => (string)$rotated['Token']));
        $this->assertSame(0, (int)$new['Status']['Status']);
    }

    public function testUnknownTokenFailsWithoutEchoingIt(): void
    {
        $bogus = bin2hex(random_bytes(16)); // 32 hex chars, the token length
        $r = Ork3::$Lib->authorization->WhoAmI(array('Token' => $bogus));

        $this->assertNotSame(0, (int)$r['Status']['Status']);
        $this->assertStringNotContainsString($bogus, (string)($r['Status']['Detail'] ?? ''));
        $this->assertStringNotContainsString($bogus, json_encode($r));
        $this->assertArrayNotHasKey('UserId', $r);
    }

    public function testDestroyedTokenFails(): void
    {
        $login = $this->login();
        Ork3::$Lib->authorization->DestroySession(array('Token' => $login['Token']));
        $r = Ork3::$Lib->authorization->WhoAmI(array('Token' => $login['Token']));
        $this->assertNotSame(0, (int)$r['Status']['Status']);
        $this->assertArrayNotHasKey('UserId', $r);
    }

    public function testMissingOrMalformedTokenFails(): void
    {
        $this->assertNotSame(0, (int)Ork3::$Lib->authorization->WhoAmI(array())['Status']['Status']);
        $this->assertNotSame(0, (int)Ork3::$Lib->authorization->WhoAmI(array('Token' => 'short'))['Status']['Status']);
    }
}
